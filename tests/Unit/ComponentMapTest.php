<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Sheaf\Components;
use Onelegstudios\Refit\Plan\Actions\MapComponentTags;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * Run the sweep over a snippet, without a filesystem.
 *
 * The action is a BladeSweep, so its transform is protected — reaching it through
 * a closure keeps the test on the real code path rather than a reimplementation
 * of it.
 */
function mapTags(string $source, ?Report $report = null): string
{
    $action = new MapComponentTags;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, 'resources/views/test.blade.php', $project, $report ?? new Report))
        ->call($action);
}

it('renames a paired tag at both ends', function (): void {
    expect(mapTags('<flux:button variant="ghost">Save</flux:button>'))
        ->toBe('<x-ui.button variant="ghost">Save</x-ui.button>');
});

it('folds a dotted icon tag down into an attribute', function (): void {
    expect(mapTags('<flux:icon.key class="size-6" />'))
        ->toBe('<x-ui.icon name="key" class="size-6" />');
});

it('keeps the dotted form when the suffix is a component in its own right', function (): void {
    // Sheaf ships icon/loading.blade.php, so the spinner is a tag rather than a
    // name — folding it into name="loading" would ask for artwork that is not
    // in any icon set.
    expect(mapTags('<flux:icon.loading />'))->toBe('<x-ui.icon.loading />');
});

it('closes a dotted icon tag the kit never balanced', function (): void {
    // The two-factor setup modal writes this exact shape, opening a dotted tag
    // and closing the bare one.
    expect(mapTags('<flux:icon.document-duplicate class="size-4" ></flux:icon>'))
        ->toBe('<x-ui.icon name="document-duplicate" class="size-4" ></x-ui.icon>');
});

it('renames the attributes Sheaf spells differently', function (): void {
    expect(mapTags('<flux:button icon-trailing="chevron-down" />'))
        ->toBe('<x-ui.button iconAfter="chevron-down" />')
        // Both libraries draw the eye on a password field; only the prop differs,
        // and left alone it lands on the wrapper div instead.
        ->and(mapTags('<flux:input type="password" viewable />'))
        ->toBe('<x-ui.input type="password" revealable />')
        ->and(mapTags('<flux:sidebar.profile icon:trailing="chevrons-up-down" />'))
        // Unmapped tag, so the attribute pass never reaches it.
        ->toBe('<flux:sidebar.profile icon:trailing="chevrons-up-down" />');
});

it('spells a tooltip\'s position the way Sheaf declares it', function (): void {
    // Both libraries take the same four words for where the bubble goes, and only
    // the prop differs — so left alone every tooltip points up, and the ones in
    // the header overlap the bar they hang from.
    expect(mapTags('<flux:tooltip position="bottom"><flux:tooltip.content>Hi</flux:tooltip.content></flux:tooltip>'))
        ->toBe('<x-ui.tooltip placement="bottom"><x-ui.tooltip.content>Hi</x-ui.tooltip.content></x-ui.tooltip>');
});

it('marks the nav item you are on with the prop Sheaf reads', function (): void {
    // Sheaf falls back to `url($href) === url()->current()` when nothing is
    // passed, which is why this hides on the dashboard: the fallback and the
    // expression agree there. `teams.*` is where they part — it covers
    // `teams.edit` too, so left untranslated the settings sidebar drops the
    // Teams highlight the moment you open a team.
    expect(mapTags('<flux:navlist.item :href="route(\'teams.index\')" :current="request()->routeIs(\'teams.*\')" wire:navigate>Teams</flux:navlist.item>'))
        ->toBe('<x-ui.navlist.item :href="route(\'teams.index\')" :active="request()->routeIs(\'teams.*\')" wire:navigate>Teams</x-ui.navlist.item>')
        ->and(mapTags('<flux:navbar.item :current="request()->routeIs(\'dashboard\')" />'))
        ->toBe('<x-ui.navbar.item :active="request()->routeIs(\'dashboard\')" />')
        // Keying the table by the Sheaf tag is what collects this one: the kit
        // writes `current` on `flux:sidebar.item` ten times over, and that tag
        // renames into `x-ui.navlist.item` before this pass reads it.
        ->and(mapTags('<flux:sidebar.item :current="request()->routeIs(\'settings.*\')" />'))
        ->toBe('<x-ui.navlist.item :active="request()->routeIs(\'settings.*\')" />');
});

it('keeps the colon on a bound attribute it renames', function (): void {
    expect(mapTags('<flux:button :icon-trailing="$icon" />'))
        ->toBe('<x-ui.button :iconAfter="$icon" />');
});

it('pairs a modal with its trigger on the prop Sheaf reads', function (): void {
    // Flux calls both halves by `name`; Sheaf pairs them on `id` and reads
    // nothing from `name`. Left alone the trigger fires `$modal.open(null)` and
    // the modal waits on a generated id, so both render and neither is wired to
    // the other — the kit's "Enable 2FA" button, doing nothing when clicked.
    expect(mapTags('<flux:modal.trigger name="two-factor-setup-modal">'))
        ->toBe('<x-ui.modal.trigger id="two-factor-setup-modal">')
        ->and(mapTags('<flux:modal name="confirm-user-deletion" class="max-w-lg">'))
        ->toBe('<x-ui.modal id="confirm-user-deletion" class="max-w-lg">');
});

it('leaves `name` alone on every component that is not a modal', function (): void {
    // The reason the modal rename is scoped to its tag rather than added to the
    // table matched by name alone: `name` is the artwork on an icon, the field on
    // an input, and the bag key on an error.
    expect(mapTags('<flux:icon name="qr-code" />'))
        ->toBe('<x-ui.icon name="qr-code" />')
        ->and(mapTags('<flux:input name="code" wire:model="code" />'))
        ->toBe('<x-ui.input name="code" wire:model="code" />');
});

it('translates variant values per component', function (): void {
    expect(mapTags('<flux:button variant="filled" />'))
        ->toBe('<x-ui.button variant="soft" />')
        ->and(mapTags('<flux:button variant="subtle" />'))
        ->toBe('<x-ui.button variant="ghost" />');
});

it('names a heading\'s level the way Sheaf reads it', function (): void {
    // Flux casts the level to an integer and switches on it; Sheaf matches it
    // against `h1` through `h6` and falls back to `h2`. So every `level="1"` was
    // rendering an `<h2>`, and the settings pages had no `<h1>` at all.
    expect(mapTags('<flux:heading size="xl" level="1">Settings</flux:heading>'))
        ->toBe('<x-ui.heading size="xl" level="h1">Settings</x-ui.heading>')
        // The recovery-codes panel, which flattened into the level above it.
        ->and(mapTags('<flux:heading size="lg" level="3">2FA recovery codes</flux:heading>'))
        ->toBe('<x-ui.heading size="sm" level="h3">2FA recovery codes</x-ui.heading>');
});

it('puts a heading\'s size back where it was on Sheaf\'s scale', function (): void {
    // The same words at different points on the scale. Flux's `lg` is
    // `text-base`; Sheaf's is `text-xl`, two steps further up, and `sm` is the
    // word for the size Flux drew — so the kit's 35 `size="lg"` headings grew
    // from 16px to 20px on a rename alone.
    expect(mapTags('<flux:heading size="lg">Delete account?</flux:heading>'))
        ->toBe('<x-ui.heading size="sm">Delete account?</x-ui.heading>')
        // `xl` is `text-2xl` in both, and listed for the same reason
        // `flux:button`'s `danger` is: to record that the match was checked.
        ->and(mapTags('<flux:heading size="xl">Settings</flux:heading>'))
        ->toBe('<x-ui.heading size="xl">Settings</x-ui.heading>')
        // And Flux's own default, which the sweep only ever sees because
        // `AddAttribute` wrote it out ahead of the rename.
        ->and(mapTags('<flux:heading size="base">Passkeys</flux:heading>'))
        ->toBe('<x-ui.heading size="xs">Passkeys</x-ui.heading>');
});

it('leaves a heading that already names its level alone', function (): void {
    // Idempotence is not the point — refit runs once — but a project that has
    // already moved to Sheaf's spelling must not have it translated twice.
    expect(mapTags('<flux:heading level="h4">Nested</flux:heading>'))
        ->toBe('<x-ui.heading level="h4">Nested</x-ui.heading>');
});

it('lists every heading level, not only the two the kit writes', function (): void {
    // The argument for going past what the kit writes: Sheaf discards a level it
    // does not recognise instead of passing it through, so a gap in this column
    // costs a page its <h1> with nothing in the rendered markup to show for it.
    // Read through the accessor rather than the constant, because PHP folds the
    // numeric keys down to ints on the way in — the lookup coerces back the same
    // way, and that round trip is the part worth pinning.
    $levels = array_map(
        static fn (int $level): ?string => ComponentMap::value('flux:heading', 'level', (string) $level),
        range(1, 6),
    );

    expect($levels)->toBe(['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);
});

it('reports a bound level it has no way of reading', function (): void {
    // `:level="$depth"` lands in the same silent fallback, and the value pass
    // reads literals only — so the one thing refit can do is say so.
    $report = new Report;
    $action = new MapComponentTags;

    (fn (): string => $this->transform('<flux:heading :level="$depth" />', 'resources/views/a.blade.php', new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), $report))->call($action);

    (fn () => $this->finish($report))->call($action);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0])->toContain(':level')
        ->toContain('<x-ui.heading>')
        ->toContain('resources/views/a.blade.php')
        ->toContain('names heading levels');
});

it('says nothing about the bound values it has no opinion on', function (): void {
    // Sheaf sends a variant it does not know through to classes, so a bound one
    // is not a gap — warning about it would be noise.
    $report = new Report;
    $action = new MapComponentTags;

    (fn (): string => $this->transform('<flux:button :variant="$style" />', 'resources/views/a.blade.php', new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), $report))->call($action);

    (fn () => $this->finish($report))->call($action);

    expect($report->warnings())->toBe([]);
});

it('names a callout\'s danger after the state Sheaf files it under', function (): void {
    // Sheaf's alert knows info, success, warning and error, and falls back to
    // blue for anything else — so the kit's danger callouts came out as calm
    // notices saying a two-factor code was rejected.
    expect(mapTags('<flux:callout variant="danger" />'))
        ->toBe('<x-ui.alerts variant="error" />');
});

it('translates the callout parts a heading was written out into', function (): void {
    expect(mapTags('<flux:callout.heading>Nope</flux:callout.heading>'))
        ->toBe('<x-ui.alerts.heading>Nope</x-ui.alerts.heading>')
        ->and(mapTags('<flux:callout.text>Why</flux:callout.text>'))
        ->toBe('<x-ui.alerts.description>Why</x-ui.alerts.description>');
});

it('leaves the prominent button prominent', function (): void {
    // `primary` means the same thing in both libraries — and in Sheaf it is also
    // the button's default. Sheaf's `solid` is a 5% neutral wash, the quiet one
    // of the set, so translating the word demotes every submit in the kit.
    expect(mapTags('<flux:button variant="primary" type="submit" />'))
        ->toBe('<x-ui.button variant="primary" type="submit" />');
});

it('leaves a variant it has no opinion about alone', function (): void {
    // Sheaf passes an unknown variant through to classes rather than throwing,
    // so guessing would be worse than doing nothing.
    expect(mapTags('<flux:button variant="outline" />'))
        ->toBe('<x-ui.button variant="outline" />');
});

it('leaves a tag it cannot translate exactly as it found it', function (): void {
    expect(mapTags('<flux:profile :initials="$x" />'))
        ->toBe('<flux:profile :initials="$x" />');
});

it('reports every tag it left behind, with the file and the reason', function (): void {
    $report = new Report;
    $action = new MapComponentTags;

    (fn (): string => $this->transform('<flux:profile /><flux:spacer />', 'resources/views/a.blade.php', new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), $report))->call($action);

    (fn () => $this->finish($report))->call($action);

    expect($report->warnings())->toHaveCount(2)
        ->and($report->warnings()[0])->toContain('flux:profile')
        ->toContain('resources/views/a.blade.php')
        ->toContain('ships no profile component')
        ->and($report->warnings()[1])->toContain('flux:spacer');
});

it('maps every Flux tag the starter kits actually write', function (): void {
    // Everything the five variants use, from a grep over the fixtures. A tag
    // missing here means a kit ships something the map has never seen.
    $used = [
        'flux:avatar', 'flux:badge', 'flux:brand', 'flux:button', 'flux:callout',
        'flux:checkbox', 'flux:dropdown', 'flux:header', 'flux:heading', 'flux:icon',
        'flux:input', 'flux:link', 'flux:main', 'flux:menu.heading', 'flux:menu.item',
        'flux:menu', 'flux:menu.radio.group', 'flux:menu.separator', 'flux:modal',
        'flux:modal.close', 'flux:modal.trigger', 'flux:navbar', 'flux:navbar.item',
        'flux:navlist', 'flux:navlist.item', 'flux:otp', 'flux:profile',
        'flux:radio', 'flux:radio.group', 'flux:select', 'flux:select.option',
        'flux:separator', 'flux:sidebar', 'flux:sidebar.brand', 'flux:sidebar.collapse',
        'flux:sidebar.group', 'flux:sidebar.header', 'flux:sidebar.item',
        'flux:sidebar.nav', 'flux:sidebar.profile', 'flux:sidebar.toggle', 'flux:spacer',
        'flux:subheading', 'flux:text', 'flux:toast', 'flux:toast.group', 'flux:tooltip',
    ];

    // Reshaped before the rename ever sees them, so the map has nothing to say.
    $restructured = ['flux:modal.close'];

    $unknown = array_values(array_filter(
        $used,
        fn (string $tag): bool => ComponentMap::tag($tag) === null
            && ComponentMap::whyUnmapped($tag) === null
            && ! in_array($tag, $restructured, true),
    ));

    expect($unknown)->toBe([]);
});

it('sends both of Flux\'s secondary text tags to the one component Sheaf has', function (): void {
    // Sheaf has no `subheading`, and Flux styles its own as muted text rather
    // than as a heading — so `x-ui.heading` would be the worse of the two homes.
    // MuteSecondaryText is what restates the contrast the rename loses.
    expect(ComponentMap::tag('flux:text'))->toBe('x-ui.text')
        ->and(ComponentMap::tag('flux:subheading'))->toBe('x-ui.text');
});

it('keeps `description` out of the map, because it is a form element', function (): void {
    // Sheaf's `description` is styled entirely through sibling selectors on
    // `data-slot="description"` inside `field`, so outside a `<x-ui.field>` its
    // rules never fire. It belongs in the stack WrapControlsInFields builds and
    // nowhere else — and nothing in the kit asks for it anyway: `flux:description`
    // appears zero times across all five fixtures.
    expect(ComponentMap::components())->not->toContain('description')
        ->and(ComponentMap::TAGS)->not->toContain('x-ui.description');
});

it('asks for a component by its top-level install name', function (): void {
    expect(ComponentMap::componentFor('x-ui.navlist.item'))->toBe('navlist')
        ->and(ComponentMap::componentFor('x-ui.button'))->toBe('button')
        ->and(ComponentMap::components())->toContain('navlist')
        ->and(ComponentMap::components())->not->toContain('navlist.item');
});

it('follows the dependency graph the whole way down', function (): void {
    // Sheaf's sidebar needs navlist, which needs badge. Nothing names badge
    // directly, so a single hop would miss it.
    expect(Components::closure(['sidebar']))
        ->toContain('sidebar')
        ->toContain('navlist')
        ->toContain('badge');
});

it('closes over what the map names, so nothing installed can reach for a stranger', function (): void {
    $installed = Components::closure(ComponentMap::components());
    $missing = [];

    foreach ($installed as $component) {
        foreach (Components::dependencies()[$component] ?? [] as $need) {
            if (! in_array($need, $installed, true)) {
                $missing[] = $component.' -> '.$need;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('records that the dropdown needs a kbd, whatever its own config claims', function (): void {
    // The regression: Sheaf declares `internal: [icon]` for dropdown and its
    // item.blade.php then renders <x-ui.kbd>, so the recorder reads the source
    // too. If this ever empties out, the recorder has stopped scanning Blade.
    expect(Components::dependencies()['dropdown'] ?? [])->toContain('kbd')
        ->and(Components::names())->toContain('kbd');
});
