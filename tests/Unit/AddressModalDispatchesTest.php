<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\AddressModalDispatches;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * A throwaway project holding one file, run through the action.
 *
 * The action reads the tree rather than transforming a string, because a
 * `$this->dispatch()` is as likely to be in a Livewire class as in a Volt view.
 *
 * @return array{0: string, 1: Report}
 */
function readdress(string $source, string $path = 'resources/views/pages/teams/⚡invite-member-modal.blade.php'): array
{
    $root = sys_get_temp_dir().'/refit-modal-dispatch-'.bin2hex(random_bytes(8));

    @mkdir($root.'/'.dirname($path), 0755, true);
    file_put_contents($root.'/'.$path, $source);

    $project = new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $report = new Report;

    (new AddressModalDispatches)->apply($project, $report);

    $rewritten = $project->get($path);

    deleteDirectory($root);

    return [$rewritten, $report];
}

it('closes the modal the kit dispatches at', function (): void {
    [$rewritten, $report] = readdress("<?php \$this->dispatch('close-modal', name: 'invite-member'); ?>");

    expect($rewritten)->toBe("<?php \$this->dispatch('close-modal', id: 'invite-member'); ?>")
        ->and($report->changedFiles())->toHaveCount(1);
});

it('keeps an expression that names the modal at runtime', function (): void {
    // The teams kit dispatches at a modal whose id is a property, which is also
    // the only thing `:id="$modalName"` could have paired with.
    [$rewritten] = readdress("<?php \$this->dispatch('close-modal', name: \$this->modalName); ?>");

    expect($rewritten)->toContain("dispatch('close-modal', id: \$this->modalName)");
});

it('addresses an open the same way, though the kit never sends one', function (): void {
    [$rewritten] = readdress('<?php $this->dispatch("open-modal", name: "create-team"); ?>');

    expect($rewritten)->toContain('dispatch("open-modal", id: "create-team")');
});

it('leaves every other event alone', function (): void {
    // `notify` is the toast, and it really does take a `name`-shaped payload of
    // its own — re-addressing by event name is the whole point.
    $source = "<?php \$this->dispatch('notify', type: 'success', content: 'Saved.'); ?>";

    [$rewritten, $report] = readdress($source);

    expect($rewritten)->toBe($source)
        ->and($report->changedFiles())->toBe([]);
});

it('leaves a dispatch that already says id alone', function (): void {
    $source = "<?php \$this->dispatch('close-modal', id: 'invite-member'); ?>";

    expect(readdress($source)[0])->toBe($source);
});

it('drops the open the trigger already does', function (): void {
    $source = <<<'BLADE'
    <x-ui.modal.trigger id="create-team">
        <x-ui.button variant="primary" x-data="" x-on:click.prevent="$dispatch('open-modal', 'create-team')" data-test="new-team">
            New team
        </x-ui.button>
    </x-ui.modal.trigger>
    BLADE;

    // The trigger opens the modal by id on its own, so the dispatch is redundant
    // as well as mis-addressed — and `x-data=""` was only ever there to give the
    // magic a scope to run in.
    [$rewritten] = readdress($source, 'resources/views/pages/teams/⚡index.blade.php');

    expect($rewritten)->toContain('<x-ui.button variant="primary" data-test="new-team">')
        ->not->toContain('open-modal')
        ->not->toContain('x-data');
});

it('keeps an empty scope the rest of the tag is using', function (): void {
    $source = <<<'BLADE'
    <x-ui.modal.trigger id="create-team">
        <x-ui.button x-data="" x-show="ready" x-on:click="$dispatch('open-modal', 'create-team')" />
    </x-ui.modal.trigger>
    BLADE;

    [$rewritten] = readdress($source, 'resources/views/pages/teams/⚡index.blade.php');

    expect($rewritten)->toContain('x-data=""')
        ->toContain('x-show="ready"')
        ->not->toContain('open-modal');
});

it('translates a dispatch no trigger is covering', function (): void {
    $source = '<button type="button" x-data="" x-on:click="$dispatch(\'open-modal\', \'create-team\')">New</button>';

    // Nothing else opens this one, so removing the handler would be removing the
    // feature. Sheaf's store is the call that does what the event was asking for.
    [$rewritten] = readdress($source, 'resources/views/pages/teams/⚡index.blade.php');

    expect($rewritten)->toContain('x-on:click="$modal.open(\'create-team\')"')
        // And the scope it needs to run in stays.
        ->toContain('x-data=""');
});

it('leaves a handler that does more than raise the event', function (): void {
    $source = '<button x-data="" x-on:click="reset(); $dispatch(\'close-modal\', \'x\')">Go</button>';

    expect(readdress($source, 'resources/views/pages/teams/⚡index.blade.php')[0])->toBe($source);
});

it('says what it re-addressed and why', function (): void {
    [, $report] = readdress("<?php \$this->dispatch('close-modal', name: 'invite-member'); ?>");

    expect(implode(' ', $report->notes()))->toContain('detail.id');
});
