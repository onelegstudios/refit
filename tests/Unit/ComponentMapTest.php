<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
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
        ->and(mapTags('<flux:sidebar.profile icon:trailing="chevrons-up-down" />'))
        // Unmapped tag, so the attribute pass never reaches it.
        ->toBe('<flux:sidebar.profile icon:trailing="chevrons-up-down" />');
});

it('keeps the colon on a bound attribute it renames', function (): void {
    expect(mapTags('<flux:button :icon-trailing="$icon" />'))
        ->toBe('<x-ui.button :iconAfter="$icon" />');
});

it('translates variant values per component', function (): void {
    expect(mapTags('<flux:button variant="primary" />'))
        ->toBe('<x-ui.button variant="solid" />')
        ->and(mapTags('<flux:button variant="subtle" />'))
        ->toBe('<x-ui.button variant="ghost" />');
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
