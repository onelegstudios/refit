<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\AcceptOtpAutofill;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * The action's own anchor table.
 *
 * Read back rather than copied, because a second copy of Sheaf's source in the
 * tests is one more thing to keep in step — and none of Sheaf's source belongs in
 * this repository beyond the lines the patch has to name to replace them.
 *
 * @return array<string, array<string, array{string, string}>>
 */
function otpPatches(): array
{
    /** @var array<string, array<string, array{string, string}>> */
    return (new ReflectionMethod(AcceptOtpAutofill::class, 'patches'))->invoke(null);
}

/**
 * A stand-in for the file Sheaf installs: every block the patch looks for, at the
 * depth Sheaf writes them, in one file.
 */
function otpSourceFor(string $path, string $indent = '                '): string
{
    $blocks = [];

    foreach (otpPatches()[$path] as [$find, $ignored]) {
        $blocks[] = implode("\n", array_map(
            fn (string $line): string => $line === '' ? '' : $indent.$line,
            explode("\n", $find),
        ));
    }

    return implode("\n\n", $blocks)."\n";
}

/**
 * Run the action over a temporary project holding the given component files.
 *
 * @param  array<string, string>  $files  Project-relative path mapped to contents.
 * @return array<string, string>
 */
function patchOtp(array $files, ?Report $report = null): array
{
    $root = sys_get_temp_dir().'/refit-otp-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        @mkdir(dirname($root.'/'.$path), 0755, true);
        file_put_contents($root.'/'.$path, $contents);
    }

    register_shutdown_function(static fn () => deleteDirectory($root));

    (new AcceptOtpAutofill)->apply(new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), $report ?? new Report);

    $patched = [];

    foreach (array_keys($files) as $path) {
        $patched[$path] = (string) file_get_contents($root.'/'.$path);
    }

    return $patched;
}

/**
 * The two files the action patches, built from its own anchors.
 *
 * @return array<string, string>
 */
function otpComponent(): array
{
    $files = [];

    foreach (array_keys(otpPatches()) as $path) {
        $files[$path] = otpSourceFor($path);
    }

    return $files;
}

it('finds every block it means to patch', function (): void {
    $report = new Report;

    patchOtp(otpComponent(), $report);

    // The whole point of the anchors: a miss is reported rather than half-applied,
    // so an empty report is the action saying it recognised all of Sheaf's source.
    expect($report->warnings())->toBe([]);
});

it('gives the code to one box rather than all six', function (): void {
    $component = SheafLibrary::COMPONENT_DIRECTORY.'/otp/index.blade.php';
    $box = SheafLibrary::COMPONENT_DIRECTORY.'/otp/input.blade.php';

    $patched = patchOtp(otpComponent());

    // Six inputs all answering to `one-time-code` is what leaves a password
    // manager with no single field to aim at, so the markup claims nothing and
    // the component hands it to the first box at runtime.
    expect($patched[$box])->toContain('autocomplete="off"')
        ->not->toContain('autocomplete="one-time-code"');

    expect($patched[$component])
        ->toContain("input.setAttribute('autocomplete', index === 0 ? 'one-time-code' : 'off');");
});

it('spreads a code filled into one box across the rest', function (): void {
    $component = SheafLibrary::COMPONENT_DIRECTORY.'/otp/index.blade.php';

    $patched = patchOtp(otpComponent());

    // A password manager sets `.value` and dispatches `input`, never `paste`, so
    // Sheaf's paste handler never sees the code and its input handler threw all
    // but one character of it away.
    expect($patched[$component])->toContain('this.fillFrom(value, index);')
        ->toContain('fillFrom(text, startIndex) {')
        ->not->toContain('value = value.slice(-1);');
});

it('holds the caret with tabindex instead of disabling the boxes', function (): void {
    $component = SheafLibrary::COMPONENT_DIRECTORY.'/otp/index.blade.php';

    $patched = patchOtp(otpComponent());

    // A disabled input is one an extension cannot write to at all, which is what
    // stopped the fill after the first digit.
    expect($patched[$component])->toContain('input.tabIndex = index >= enableCount ? -1 : 0;')
        ->not->toContain('input.disabled = index >= enableCount;')
        // And the two places that read the flag back have to stop relying on it.
        ->not->toContain('input.disabled = true;')
        ->not->toContain('!clickedInput.disabled');
});

it('matches Sheaf\'s source however far in it is written', function (): void {
    $component = SheafLibrary::COMPONENT_DIRECTORY.'/otp/index.blade.php';
    $box = SheafLibrary::COMPONENT_DIRECTORY.'/otp/input.blade.php';

    $report = new Report;

    // Sheaf writes this inside an `x-data` attribute, four and five levels deep.
    // Reformatting there should not be what stops the patch landing.
    $patched = patchOtp([
        $component => otpSourceFor($component, '  '),
        $box => otpSourceFor($box, '  '),
    ], $report);

    expect($report->warnings())->toBe([])
        ->and($patched[$component])->toContain('input.tabIndex = index >= enableCount ? -1 : 0;');

    // And the replacement is set down at the depth it was found at, not at the
    // depth it was written at here.
    expect($patched[$component])->toContain("\n  this._inputs.forEach((input, index) => {");
});

it('reports the blocks it no longer recognises rather than mangling them', function (): void {
    $component = SheafLibrary::COMPONENT_DIRECTORY.'/otp/index.blade.php';
    $box = SheafLibrary::COMPONENT_DIRECTORY.'/otp/input.blade.php';

    // A Sheaf that has renamed the flag: everything else still matches, this one
    // does not.
    $drifted = str_replace(
        'input.disabled = index >= enableCount;',
        'input.locked = index >= enableCount;',
        otpSourceFor($component),
    );

    $report = new Report;

    $patched = patchOtp([$component => $drifted, $box => otpSourceFor($box)], $report);

    expect(implode("\n", $report->warnings()))
        ->toContain('holding the caret')
        ->toContain('https://github.com/sheafui/components');

    // The block it did not recognise is left exactly as it was, and the ones it
    // did are still applied — a partial patch beats no patch and beats a guess.
    expect($patched[$component])->toContain('input.locked = index >= enableCount;')
        ->toContain('this.fillFrom(value, index);');
});

it('says so when there is no component to patch', function (): void {
    $report = new Report;

    patchOtp([], $report);

    expect(implode("\n", $report->warnings()))
        ->toContain(SheafLibrary::COMPONENT_DIRECTORY.'/otp/index.blade.php')
        ->toContain('one digit');
});

it('is planned only for a project that writes an OTP', function (): void {
    $root = sys_get_temp_dir().'/refit-otp-used-'.bin2hex(random_bytes(6));
    @mkdir($root.'/resources/views', 0755, true);
    register_shutdown_function(static fn () => deleteDirectory($root));

    $project = new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    file_put_contents($root.'/resources/views/page.blade.php', '<flux:input name="email" />');

    expect(AcceptOtpAutofill::used($project))->toBeFalse();

    // Read before the rename, so the tag it looks for is still Flux's.
    file_put_contents($root.'/resources/views/page.blade.php', '<flux:otp name="code" length="6" />');

    expect(AcceptOtpAutofill::used($project))->toBeTrue();
});
