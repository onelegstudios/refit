<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\WrapControlsInFields;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * Run the sweep over a snippet, without a filesystem.
 */
function wrapControls(string $source, string $path = 'resources/views/pages/auth/login.blade.php', ?Report $report = null): string
{
    $action = new WrapControlsInFields;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, $path, $project, $report ?? new Report))
        ->call($action);
}

it('gives a centred OTP a width to be centred at', function (): void {
    // `mx-auto` centres a block only if it has width to give back. Flux's <ui-otp>
    // was `w-fit`; Sheaf's is an ordinary block, and the field wrapped around it
    // here is `w-full` — so the auto margins collapse and the boxes sit against
    // the left edge of a row that is still centred.
    $source = '<x-ui.otp name="code" length="6" label="OTP Code" label:sr-only class="mx-auto" />';

    expect(wrapControls($source))->toContain('class="mx-auto w-fit"');
});

it('leaves a width the project chose alone', function (): void {
    $source = '<x-ui.otp name="code" label="OTP Code" class="mx-auto w-64" />';

    expect(wrapControls($source))->toContain('class="mx-auto w-64"')
        ->not->toContain('w-fit');
});

it('widens nothing that was not asking to be centred', function (): void {
    expect(wrapControls('<x-ui.otp name="code" label="OTP Code" class="my-5" />'))
        ->toContain('class="my-5"')
        ->not->toContain('w-fit');

    // And not the controls meant to fill their field, which Flux never centred.
    expect(wrapControls('<x-ui.input name="email" label="Email" class="mx-auto" />'))
        ->toContain('class="mx-auto"')
        ->not->toContain('w-fit');
});

it('lifts a bound label into a label of its own, and names the error Flux was drawing', function (): void {
    $source = '<x-ui.input name="email" :label="__(\'Email address\')" type="email" required />';

    expect(wrapControls($source))->toBe(<<<'BLADE'
    <x-ui.field>
        <x-ui.label :text="__('Email address')" />
        <x-ui.input name="email" type="email" required />
        <x-ui.error name="email" />
    </x-ui.field>
    BLADE);
});

it('keys the error to the Livewire property when the control has no name', function (): void {
    $source = '<x-ui.input wire:model.live="password" :label="__(\'Password\')" type="password" />';

    expect(wrapControls($source))->toContain('<x-ui.error name="password" />');
});

it('leaves out an error it has no key for', function (): void {
    // Bound to Alpine and nothing else, so there is no bag to ask and an
    // <x-ui.error> with no name would render nothing on every page.
    $source = '<x-ui.input label="{{ __(\'Passkey name\') }}" x-model="name" />';

    expect(wrapControls($source))->not->toContain('x-ui.error');
});

it('lifts a literal label the same way', function (): void {
    // The passkey component writes its label as an echo inside a literal, which
    // moves across as the string it is rather than being read as an expression.
    $source = '<x-ui.input label="{{ __(\'Passkey name\') }}" x-model="name" />';

    expect(wrapControls($source))->toContain('<x-ui.label text="{{ __(\'Passkey name\') }}" />')
        ->and(wrapControls($source))->toContain('<x-ui.input x-model="name" />');
});

it('keeps a screen-reader-only label hidden', function (): void {
    // Flux's label:sr-only is how the two-factor pages label their code field
    // without showing the words. Sheaf has no such modifier, so it becomes the
    // class that does the same thing.
    $source = '<x-ui.otp name="code" wire:model="code" length="6" label="OTP Code" label:sr-only class="mx-auto" />';

    expect(wrapControls($source))
        ->toContain('<x-ui.label class="sr-only" text="OTP Code" />')
        // The width rides along; see the centring test above.
        ->toContain('<x-ui.otp name="code" wire:model="code" length="6" class="mx-auto w-fit" />');
});

it('wraps a control that holds its own children', function (): void {
    $source = <<<'BLADE'
    <x-ui.select wire:model="inviteRole" :label="__('Role')">
        <x-ui.select.option value="member">{{ __('Member') }}</x-ui.select.option>
    </x-ui.select>
    BLADE;

    expect(wrapControls($source))->toBe(<<<'BLADE'
    <x-ui.field>
        <x-ui.label :text="__('Role')" />
        <x-ui.select wire:model="inviteRole">
            <x-ui.select.option value="member">{{ __('Member') }}</x-ui.select.option>
        </x-ui.select>
        <x-ui.error name="inviteRole" />
    </x-ui.field>
    BLADE);
});

it('keeps a tag written a line per attribute, one level further in', function (): void {
    $source = <<<'BLADE'
            <x-ui.input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
            />
    BLADE;

    expect(wrapControls($source))->toBe(<<<'BLADE'
            <x-ui.field>
                <x-ui.label :text="__('Email address')" />
                <x-ui.input
                    name="email"
                    :value="old('email')"
                    type="email"
                />
                <x-ui.error name="email" />
            </x-ui.field>
    BLADE);
});

it('adds the label alone when a field is already there', function (): void {
    // A project that wrote its own field has said how it wants errors shown.
    $source = <<<'BLADE'
    <x-ui.field>
        <x-ui.input name="email" :label="__('Email address')" />
    </x-ui.field>
    BLADE;

    expect(wrapControls($source))->toBe(<<<'BLADE'
    <x-ui.field>
        <x-ui.label :text="__('Email address')" />
        <x-ui.input name="email" />
    </x-ui.field>
    BLADE);
});

it('leaves a control with no label alone', function (): void {
    $source = '<x-ui.input name="email" type="email" />';

    expect(wrapControls($source))->toBe($source);
});

it('leaves the controls that label themselves alone', function (): void {
    // Sheaf's checkbox renders its label through checkbox.label, and the nav and
    // radio items are PromoteContentsToLabel's business.
    $source = '<x-ui.checkbox name="remember" :label="__(\'Remember me\')" />'
        .'<x-ui.radio.item value="light" :label="__(\'Light\')" />';

    expect(wrapControls($source))->toBe($source);
});

it('leaves Sheaf\'s own components alone', function (): void {
    $source = '<x-ui.input name="email" :label="__(\'Email\')" />';

    expect(wrapControls($source, 'resources/views/components/ui/field/field.blade.php'))->toBe($source);
});

it('reports a control it could not find the end of', function (): void {
    $source = '<x-ui.select :label="__(\'Role\')">';
    $report = new Report;

    $action = new WrapControlsInFields;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $rewritten = (function () use ($source, $project, $report): string {
        $result = $this->transform($source, 'resources/views/pages/teams/edit.blade.php', $project, $report);

        $this->finish($report);

        return $result;
    })->call($action);

    expect($rewritten)->toBe($source)
        ->and($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0])->toContain('resources/views/pages/teams/edit.blade.php: <x-ui.select>');
});
