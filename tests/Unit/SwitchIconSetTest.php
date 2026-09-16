<?php

declare(strict_types=1);

use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\SwitchIconSet;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function phosphorSwitch(): SwitchIconSet
{
    return new SwitchIconSet(
        'ps:',
        IconMap::HEROICONS_TO_PHOSPHOR,
        'Phosphor',
        (new SheafLibrary)->vocabulary(),
    );
}

function switchIcons(string $source, Report $report = new Report, string $path = 'resources/views/pages/auth/login.blade.php'): string
{
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, $path, $project, $report))
        ->call(phosphorSwitch());
}

it('spells a name Phosphor\'s way rather than only prefixing it', function (): void {
    // The crash this table exists for: prefixed but not translated, this is
    // `phosphor.icons::regular.finger-print`, a component that does not exist.
    expect(switchIcons('<x-ui.icon name="finger-print" variant="outline" />'))
        ->toBe('<x-ui.icon name="ps:fingerprint" variant="outline" />');

    expect(switchIcons('<x-ui.button icon="cog" iconAfter="chevron-down" />'))
        ->toBe('<x-ui.button icon="ps:gear" iconAfter="ps:caret-down" />');
});

it('still prefixes the names both sets spell the same', function (): void {
    expect(switchIcons('<x-ui.icon name="eye-slash" />'))
        ->toBe('<x-ui.icon name="ps:eye-slash" />');
});

it('leaves a name it cannot translate as the Heroicon it already is', function (): void {
    // Heroicons still draws it, so the page renders with two sets on it. The
    // alternative — `ps:rocket-launch` — is a component that does not exist.
    expect(switchIcons('<x-ui.icon name="rocket-launch" />'))
        ->toBe('<x-ui.icon name="rocket-launch" />');
});

it('reports the names it left behind, once each, with their files', function (): void {
    $action = phosphorSwitch();
    $report = new Report;

    $transform = fn (string $source, string $path): string => (fn (): string => $this->transform(
        $source,
        $path,
        new Project(sys_get_temp_dir(), ComponentStyle::SingleFile, [], [], false),
        $report,
    ))->call($action);

    $transform('<x-ui.icon name="rocket-launch" />', 'resources/views/one.blade.php');
    $transform('<x-ui.icon name="rocket-launch" /><x-ui.icon name="home" />', 'resources/views/two.blade.php');

    (fn () => $this->finish($report))->call($action);

    expect($report->warnings())->toBe([
        'No Phosphor translation for "rocket-launch" — still Heroicons in resources/views/one.blade.php, resources/views/two.blade.php.',
    ]);
});

it('leaves a name that already belongs to a set alone, so a second pass is a no-op', function (): void {
    $source = '<x-ui.icon name="ps:fingerprint" /><x-ui.icon name="bk:heroicon-o-home" />';

    expect(switchIcons($source))->toBe($source);

    $report = new Report;

    switchIcons($source, $report);

    (fn () => $this->finish($report))->call(phosphorSwitch());

    expect($report->warnings())->toBe([]);
});

it('leaves a name the component takes from its caller alone', function (): void {
    // Sheaf's own alert glues its caller's name into the attribute. Prefixing
    // here would prefix a value the caller's own tag has already been given.
    $source = '<x-ui.icon name="{{ $icon }}" /><x-ui.icon :name="$icon" />';

    expect(switchIcons($source))->toBe($source);
});

it('reads name as an icon only on the icon component', function (): void {
    expect(switchIcons('<x-ui.input name="email" type="email" />'))
        ->toBe('<x-ui.input name="email" type="email" />');
});
