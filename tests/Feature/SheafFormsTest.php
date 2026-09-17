<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\WireSheafRuntimes;
use Onelegstudios\Refit\Project\ProjectDetector;

it('lets the two-factor QR keep the colour Sheaf\'s icon would paint over', function (): void {
    $root = sheafKit('livewire');

    // Sheaf's icon component, as `sheaf:install` writes it: a colour of its own,
    // at the same specificity as the caller's, added to the bag the icon set's
    // `<svg>` renders.
    $icon = SheafLibrary::COMPONENT_DIRECTORY.'/icon/index.blade.php';

    file_put_contents(
        $root.'/'.$icon,
        '<x-dynamic-component :component="$component"'
        ." {{ \$attributes->class(['text-neutral-700 dark:text-neutral-300']) }}"
        .' data-slot="icon" />'."\n",
    );

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'phosphor',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // Tailwind sorts a same-specificity tie by name, and `dark:text-neutral-300`
    // is written after `dark:text-accent-foreground` — so the QR glyph asked to
    // stay dark on a disc that is light in both appearances and came out white on
    // white. A zero-specificity default loses that tie instead.
    expect($project->get($icon))
        ->toContain("\$attributes->merge(['class' => '[:where(&)]:text-neutral-700 dark:[:where(&)]:text-neutral-300'], escape: false)")
        ->not->toContain('->class(');

    // And the view is left saying exactly what it said: the fix is the component
    // yielding, not every caller shouting over it with `!`.
    expect($project->get('resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'))
        ->toContain('<x-ui.icon name="ps:qr-code" class="relative z-20 dark:text-accent-foreground"/>');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('labels the form controls Sheaf renders bare, and says why they were rejected', function (): void {
    $root = migratedSheafKit('livewire-teams');

    $project = (new ProjectDetector)->detect($root);

    // Flux's input was the label, the control and the space between them. Sheaf's
    // is the control alone, and the label it was handed goes nowhere — so every
    // field of every auth page arrived as an unlabelled box.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('<x-ui.label :text="__(\'Email address\')" />')
        ->toContain('<x-ui.label :text="__(\'Password\')" />')
        ->toContain('<x-ui.field>')
        // The label came off the control rather than being copied onto a second
        // tag — from there it renders nowhere.
        ->not->toContain('<x-ui.input name="email" :label=');

    // The select declares a `label` prop and then never renders it, which looks
    // like the one case a rename would have got right and is not.
    expect($project->get('resources/views/pages/teams/⚡invite-member-modal.blade.php'))
        ->toContain('<x-ui.label :text="__(\'Role\')" />')
        ->toContain('<x-ui.select wire:model="inviteRole" data-test="invite-role">');

    // And the code field keeps its label hidden, the way `label:sr-only` had it.
    expect($project->get('resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'))
        ->toContain('<x-ui.label class="sr-only" text="OTP Code" />')
        ->not->toContain('label:sr-only');

    // The checkbox is not a target: Sheaf's own renders the label it is given.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('<x-ui.checkbox name="remember" :label="__(\'Remember me\')"');

    // Flux's input drew the validation message too, and the kit's auth pages have
    // no @error block of their own — so a failed login said nothing at all.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('<x-ui.error name="email" />');

    // Keyed off the Livewire property where the control has no name.
    expect($project->get('resources/views/pages/settings/⚡security.blade.php'))
        ->toContain('<x-ui.error name="current_password" />')
        ->toContain('<x-ui.error name="password" />');

    // The recovery code field is the one input the kit labels nowhere and errors
    // itself, and it is left exactly as it was.
    expect($project->get('resources/views/pages/auth/two-factor-challenge.blade.php'))
        ->toContain('@error(\'recovery_code\')')
        ->not->toContain('<x-ui.error name="recovery_code" />');

    // And the password fields keep the eye Flux drew from `viewable`.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('revealable')
        ->not->toContain('viewable');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('gives the select the runtime and the primitive that make it open', function (): void {
    $root = migratedSheafKit('livewire-teams');

    $project = (new ProjectDetector)->detect($root);

    // Sheaf writes both halves of a select and imports neither: the Alpine.data()
    // its `x-data` names, and the `$rover` plugin that runtime drives the option
    // list with. Without them the browser throws `selectComponent is not defined`
    // as it walks the page, and the invite modal's Role never opens — a team
    // member cannot be given a role at all.
    expect($project->get(WireSheafRuntimes::ENTRYPOINT))
        ->toContain("import rover from '@sheaf/rover';")
        ->toContain("import './components/select.js';")
        ->toContain('Alpine.plugin(rover);')
        ->toContain("import './globals/modals.js';");

    // The plugin is registered before Alpine walks anything, and the primitive is
    // imported ahead of the runtime that reads it.
    $entrypoint = $project->get(WireSheafRuntimes::ENTRYPOINT);

    expect(strpos($entrypoint, 'import rover'))->toBeLessThan(strpos($entrypoint, './components/select.js'));
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('posts the two-factor code Sheaf would have left out of the form', function (string $kit, string $challenge, string $setup): void {
    $root = migratedSheafKit($kit);

    $project = (new ProjectDetector)->detect($root);

    // Flux's <ui-otp> kept a hidden input holding the joined digits, so the
    // challenge page's plain POST carried the whole code. Sheaf has no such
    // input, and spends `name` on every digit box instead — six inputs called
    // `code`, of which PHP keeps the last, so Fortify rejected every login.
    expect($project->get($challenge))
        ->toContain('<input type="hidden" name="code" x-bind:value="code" />')
        ->not->toContain('<x-ui.otp name="code"')
        // The error is still keyed, because the field wrapping reads the name
        // before this sweep takes it off.
        ->toContain('<x-ui.error name="code" />');

    // Sheaf's digit boxes are unconditionally `required`, and the recovery form
    // posts from the same <form> with the OTP merely x-show'd away — a hidden
    // required control the browser refuses to submit past at all.
    expect($project->get($challenge))
        ->toContain('<fieldset class="contents" x-bind:disabled="showRecoveryInput">');

    // The kit's other OTP binds through Livewire, which carries its own value and
    // names the boxes after the binding on purpose. Nothing to fix there.
    expect($project->get($setup))
        ->toContain('wire:model="code"')
        ->not->toContain('x-bind:value');
})->with([
    [
        'livewire',
        'resources/views/pages/auth/two-factor-challenge.blade.php',
        'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php',
    ],
    [
        'livewire-teams',
        'resources/views/pages/auth/two-factor-challenge.blade.php',
        'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php',
    ],
    [
        'livewire-class-components',
        'resources/views/livewire/auth/two-factor-challenge.blade.php',
        'resources/views/livewire/settings/security.blade.php',
    ],
])->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('gives the two-factor errors the heading Sheaf reads them from', function (string $kit, string $codes): void {
    $root = migratedSheafKit($kit);

    $page = (new ProjectDetector)->detect($root)->get($codes);

    // Flux takes a callout's heading as an attribute and expands it into a child
    // itself; Sheaf's alert only ever reads the child. A rename alone left the
    // word on the wrapper div as a stray HTML attribute, so the reason a code was
    // rejected rendered as an empty box.
    expect($page)->toContain('<x-ui.alerts.heading>{{$message}}</x-ui.alerts.heading>')
        ->not->toContain('heading="{{$message}}"')
        // And Sheaf files red under `error`, falling back to blue for a word it
        // does not know — which `danger` is.
        ->toContain('<x-ui.alerts variant="error"');
})->with([
    ['livewire', 'resources/views/pages/settings/two-factor/⚡recovery-codes.blade.php'],
    ['livewire-teams', 'resources/views/pages/settings/two-factor/⚡recovery-codes.blade.php'],
    ['livewire-class-components', 'resources/views/livewire/settings/two-factor/recovery-codes.blade.php'],
])->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('keeps the OTP centred once it is wrapped in a field', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // The kit centres both its OTPs with `mx-auto`, which worked because Flux's
    // <ui-otp> was `w-fit`. Sheaf's is an ordinary block inside a `w-full` field,
    // so the auto margins collapse and the boxes go hard left — 48px off centre on
    // the challenge page, measured against the row that is still centring them.
    expect($project->get('resources/views/pages/auth/two-factor-challenge.blade.php'))
        ->toContain('class="mx-auto w-fit"');

    expect($project->get('resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'))
        ->toContain('class="mx-auto w-fit"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('gives a segmented group the row and the bare segments Flux implied', function (): void {
    $root = migratedSheafKit('livewire');

    // Flux reads "segmented" as the whole shape. Sheaf reads it as the pill
    // background, and defaults the rest the other way: `direction` vertical puts
    // `space-y-2` on the group, `indicator` true draws a radio dot in every
    // segment. The appearance control came out as a grey column of dotted rows.
    expect((new ProjectDetector)->detect($root)->get('resources/views/pages/settings/⚡appearance.blade.php'))
        ->toContain('<x-ui.radio.group direction="horizontal" :indicator="false" x-data variant="segmented"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('leaves a segmented group that has already decided its own shape', function (): void {
    $root = sheafKit('livewire');

    file_put_contents($root.'/resources/views/dashboard.blade.php', <<<'BLADE'
        <x-ui.radio.group variant="segmented" direction="vertical" :indicator="true" />
        <x-ui.radio.group :variant="$variant" />
        BLADE);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    // Said for itself, and a bound variant is not a value to read at all.
    expect((new ProjectDetector)->detect($root)->get('resources/views/dashboard.blade.php'))
        ->toContain('<x-ui.radio.group variant="segmented" direction="vertical" :indicator="true" />')
        ->toContain('<x-ui.radio.group :variant="$variant" />');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
