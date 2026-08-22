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
