<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Plan\Actions\IconLabelledButtons;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * The sweep as the task builds it for a project staying on Flux, with a table
 * small enough to read in the assertions.
 *
 * @param  array<string, string>  $icons
 */
function buttonSweep(array $icons = ['save' => 'check', 'cancel' => 'x-mark', 'delete account' => 'trash']): IconLabelledButtons
{
    return new IconLabelledButtons(
        'flux:button',
        'icon',
        $icons,
        (new FluxLibrary)->vocabulary()->nameAttributes,
    );
}

function iconButtons(string $source, ?IconLabelledButtons $action = null, ?Report $report = null): string
{
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $path = 'resources/views/pages/settings/profile.blade.php';

    return (fn (): string => $this->transform($source, $path, $project, $report ?? new Report))
        ->call($action ?? buttonSweep());
}

it('gives a button the icon its label asks for', function (): void {
    expect(iconButtons('<flux:button variant="primary" type="submit">{{ __(\'Save\') }}</flux:button>'))
        ->toBe('<flux:button icon="check" variant="primary" type="submit">{{ __(\'Save\') }}</flux:button>');
});

it('reads a label written across lines, and plain text as well as a translation', function (): void {
    expect(iconButtons("<flux:button\n    variant=\"danger\"\n>\n    {{ __('Delete account') }}\n</flux:button>"))
        ->toBe("<flux:button icon=\"trash\"\n    variant=\"danger\"\n>\n    {{ __('Delete account') }}\n</flux:button>")
        ->and(iconButtons('<flux:button>Cancel</flux:button>'))
        ->toBe('<flux:button icon="x-mark">Cancel</flux:button>');
});

it('matches a label whatever its case and however the tag wraps it', function (): void {
    expect(iconButtons("<flux:button>{{ __('DELETE   account') }}</flux:button>"))
        ->toContain('icon="trash"');
});

it('leaves a button that already named an icon alone', function (): void {
    $leading = '<flux:button icon="plus">{{ __(\'Save\') }}</flux:button>';
    $trailing = '<flux:button icon-trailing="chevron-down">{{ __(\'Save\') }}</flux:button>';
    $bound = '<flux:button :icon="$saving ? \'clock\' : \'check\'">{{ __(\'Save\') }}</flux:button>';

    expect(iconButtons($leading))->toBe($leading)
        ->and(iconButtons($trailing))->toBe($trailing)
        ->and(iconButtons($bound))->toBe($bound);
});

it('leaves an icon-only button alone, having no label to read', function (): void {
    $selfClosing = '<flux:button variant="ghost" size="sm" />';
    $empty = "<flux:button variant=\"ghost\">\n</flux:button>";

    expect(iconButtons($selfClosing))->toBe($selfClosing)
        ->and(iconButtons($empty))->toBe($empty);
});

it('leaves contents it cannot read as a label alone', function (): void {
    $markup = "<flux:button>\n    <span x-show=\"!loading\">{{ \$label }}</span>\n</flux:button>";
    $bound = '<flux:button>{{ $this->config[\'buttonText\'] }}</flux:button>';
    $arguments = '<flux:button>{{ __(\'Save :name\', [\'name\' => $team->name]) }}</flux:button>';

    expect(iconButtons($markup))->toBe($markup)
        ->and(iconButtons($bound))->toBe($bound)
        ->and(iconButtons($arguments))->toBe($arguments);
});

it('is not fooled by the button group, which is a row rather than a button', function (): void {
    $group = '<flux:button.group>{{ __(\'Save\') }}</flux:button.group>';

    expect(iconButtons($group))->toBe($group);
});

it('reads the icons it would write without writing them', function (): void {
    $source = "<flux:button>{{ __('Save') }}</flux:button>\n"
        .'<flux:button>Cancel</flux:button>'
        ."<flux:button>{{ __('Save') }}</flux:button>"
        ."<flux:button icon=\"plus\">{{ __('Delete account') }}</flux:button>";

    expect(buttonSweep()->names($source))->toBe(['check', 'x-mark']);
});

it('says which labels it left bare, in one note at the end of the sweep', function (): void {
    $report = new Report;
    $action = buttonSweep(['save' => 'check']);

    foreach (['Keep invitation', 'Continue', 'keep invitation', 'Save'] as $label) {
        iconButtons(sprintf('<flux:button>{{ __(\'%s\') }}</flux:button>', $label), $action, $report);
    }

    // The sweep collects as it goes and reports once the tree is walked, so one
    // label said twice is one finding rather than two.
    expect($report->notes())->toBeEmpty();

    (function () use ($report): void {
        $this->finish($report);
    })->call($action);

    expect($report->notes())->toHaveCount(1)
        ->and($report->notes()[0])
        ->toContain('"Continue", "Keep invitation"')
        ->toContain('refit has no icon to match them');
});
