<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\CarryOtpValue;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * Run the sweep over a snippet, without a filesystem.
 */
function carryOtp(string $source, string $path = 'resources/views/pages/auth/two-factor-challenge.blade.php', ?Report $report = null): string
{
    $action = new CarryOtpValue;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $report ??= new Report;

    $rewritten = (fn (): string => $this->transform($source, $path, $project, $report))
        ->call($action);

    // The sweep collects across files and reports once at the end, so a test
    // asserting on the report has to let it finish.
    (fn () => $this->finish($report))->call($action);

    return $rewritten;
}

it('posts the OTP value through an input of its own', function (): void {
    // Sheaf's otp renders no hidden input holding the joined digits the way
    // Flux's <ui-otp> did, so a plain form has nothing to submit.
    $rewritten = carryOtp(<<<'BLADE'
    <form method="POST">
        <x-ui.otp x-model="code" length="6" name="code" class="mx-auto" />
    </form>
    BLADE);

    expect($rewritten)->toContain('<input type="hidden" name="code" x-bind:value="code" />');
});

it('takes the name off the tag, because Sheaf spends it on every digit box', function (): void {
    // `name` is a declared prop that otp.input reads back with @aware, so left in
    // place it names all six boxes `code` and PHP keeps the last one — the whole
    // reason the challenge rejects every code it is given.
    $rewritten = carryOtp(<<<'BLADE'
    <form method="POST">
        <x-ui.otp x-model="code" length="6" name="code" class="mx-auto" />
    </form>
    BLADE);

    expect($rewritten)->toContain('<x-ui.otp x-model="code" length="6" class="mx-auto" />')
        ->and(substr_count($rewritten, 'name="code"'))->toBe(1);
});

it('leaves a Livewire binding alone, because Livewire carries that value itself', function (): void {
    // The kit's two-factor setup modal. Sheaf entangles the property and names
    // the boxes after the binding on purpose, so there is nothing to fix.
    $source = <<<'BLADE'
    <form method="POST">
        <x-ui.otp name="code" wire:model="code" length="6" class="mx-auto" />
    </form>
    BLADE;

    expect(carryOtp($source))->toBe($source);
});

it('disables the OTP from above while the page has it hidden', function (): void {
    // Sheaf's digit boxes are unconditionally `required`, which Flux's were not.
    // A required control behind x-show is display:none but still validated, and
    // the browser refuses the whole submit — which is what stops the recovery
    // code form, since it posts from the same <form>.
    $rewritten = carryOtp(<<<'BLADE'
    <form method="POST">
        <div x-show="!showRecoveryInput">
            <x-ui.otp x-model="code" length="6" name="code" />
        </div>
    </form>
    BLADE);

    expect($rewritten)->toContain('<fieldset class="contents" x-bind:disabled="showRecoveryInput">')
        ->toContain('</fieldset>');
});

it('spells the fieldset condition as a person would read it', function (): void {
    // The negation of `!showRecoveryInput` is `showRecoveryInput`, not
    // `!(!showRecoveryInput)`.
    expect(carryOtp(<<<'BLADE'
    <form method="POST">
        <div x-show="!showRecoveryInput">
            <x-ui.otp x-model="code" name="code" />
        </div>
    </form>
    BLADE))->toContain('x-bind:disabled="showRecoveryInput"');

    // Anything that is not a plain negated name is negated as written.
    expect(carryOtp(<<<'BLADE'
    <form method="POST">
        <div x-show="step === 'otp'">
            <x-ui.otp x-model="code" name="code" />
        </div>
    </form>
    BLADE))->toContain('x-bind:disabled="!(step === \'otp\')"');
});

it('reads the innermost x-show, not the first one it is inside', function (): void {
    $rewritten = carryOtp(<<<'BLADE'
    <form method="POST">
        <div x-show="open">
            <div x-show="!showRecoveryInput">
                <x-ui.otp x-model="code" name="code" />
            </div>
        </div>
    </form>
    BLADE);

    expect($rewritten)->toContain('x-bind:disabled="showRecoveryInput"');
});

it('writes no fieldset when nothing hides the OTP', function (): void {
    $rewritten = carryOtp(<<<'BLADE'
    <form method="POST">
        <x-ui.otp x-model="code" name="code" />
    </form>
    BLADE);

    expect($rewritten)->not->toContain('<fieldset')
        ->and($rewritten)->toContain('<input type="hidden" name="code" x-bind:value="code" />');
});

it('leaves an OTP that posts no form alone', function (): void {
    $source = '<x-ui.otp x-model="code" length="6" name="code" />';

    expect(carryOtp($source))->toBe($source);
});

it('leaves a developer\'s own digit boxes alone', function (): void {
    // A slot means the boxes were written by hand, and named by hand with them.
    $source = <<<'BLADE'
    <form method="POST">
        <x-ui.otp x-model="code" name="code">
            <x-ui.otp.input />
        </x-ui.otp>
    </form>
    BLADE;

    expect(carryOtp($source))->toBe($source);
});

it('says so when there is no value to carry', function (): void {
    // No wire:model and no x-model: nothing holds the joined digits, so there is
    // nothing to post and taking the name off would only make it quieter.
    $source = <<<'BLADE'
    <form method="POST">
        <x-ui.otp length="6" name="code" />
    </form>
    BLADE;

    $report = new Report;

    expect(carryOtp($source, report: $report))->toBe($source);

    expect(implode("\n", $report->warnings()))
        ->toContain('name="code"')
        ->toContain('nothing to submit');
});

it('leaves Sheaf\'s own components alone', function (): void {
    $source = <<<'BLADE'
    <form method="POST">
        <x-ui.otp x-model="code" name="code" />
    </form>
    BLADE;

    expect(carryOtp($source, 'resources/views/components/ui/otp/index.blade.php'))->toBe($source);
});

it('keeps a bound name bound', function (): void {
    $rewritten = carryOtp(<<<'BLADE'
    <form method="POST">
        <x-ui.otp x-model="code" :name="$field" />
    </form>
    BLADE);

    expect($rewritten)->toContain('<input type="hidden" :name="$field" x-bind:value="code" />')
        ->and($rewritten)->toContain('<x-ui.otp x-model="code" />');
});
