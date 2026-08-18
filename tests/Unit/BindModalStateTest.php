<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\BindModalState;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * Run the sweep over a snippet, without a filesystem.
 */
function bindModals(string $source, ?Report $report = null): string
{
    $action = new BindModalState;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $report ??= new Report;

    $rewritten = (fn (): string => $this->transform($source, 'resources/views/test.blade.php', $project, $report))
        ->call($action);

    // The sweep collects across files and reports once at the end, so a test
    // asserting on the report has to let it finish.
    (fn () => $this->finish($report))->call($action);

    return $rewritten;
}

it('drives Sheaf\'s open state from the property Flux bound', function (): void {
    // The teams variants' pending-invitations dialog: a property and no trigger,
    // so without this the modal has nothing that can ever open it.
    expect(bindModals('<x-ui.modal id="pending-invitations" wire:model="showPendingInvitationsModal" class="max-w-lg">'))
        ->toBe(
            '<x-ui.modal x-effect="$wire.showPendingInvitationsModal ? $modal.open(modalId) : $modal.close(modalId)"'
            .' x-on:modal-closed="$wire.showPendingInvitationsModal = false"'
            .' id="pending-invitations" class="max-w-lg">',
        );
});

it('renames the close listener onto the event Sheaf dispatches', function (): void {
    expect(bindModals('<x-ui.modal id="two-factor-setup-modal" @close="closeModal">'))
        ->toBe('<x-ui.modal id="two-factor-setup-modal" @modal-closed="closeModal">')
        ->and(bindModals('<x-ui.modal id="m" x-on:close="closeModal">'))
        ->toBe('<x-ui.modal id="m" @modal-closed="closeModal">');
});

it('spells the two close listeners differently so both survive', function (): void {
    // The passkey modal has a `wire:model` and an `@close`, and both concerns
    // want the same event. One attribute name twice is a duplicate attribute —
    // the browser keeps the first and the other concern fails silently.
    $rewritten = bindModals(<<<'BLADE'
    <x-ui.modal
        id="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
    BLADE);

    expect($rewritten)->toContain('x-on:modal-closed="$wire.showDeleteModal = false"')
        ->and($rewritten)->toContain('@modal-closed="closeDeleteModal"')
        ->and(substr_count($rewritten, 'modal-closed='))->toBe(2);
});

it('keeps a multi-line modal multi-line', function (): void {
    expect(bindModals(<<<'BLADE'
    <x-ui.modal
        id="delete-passkey-modal"
        wire:model="showDeleteModal"
    >
    BLADE))->toBe(<<<'BLADE'
    <x-ui.modal
        x-effect="$wire.showDeleteModal ? $modal.open(modalId) : $modal.close(modalId)"
        x-on:modal-closed="$wire.showDeleteModal = false"
        id="delete-passkey-modal"
    >
    BLADE);
});

it('takes the binding away once it has been translated', function (): void {
    // Left in place it would claim a relationship Sheaf's modal does not have.
    expect(bindModals('<x-ui.modal id="m" wire:model="show">'))
        ->not->toContain('wire:model');
});

it('reads the id out of the component rather than the tag', function (): void {
    // The teams variants write `:id="$modalName"`. `modalId` is the id the
    // component resolved for itself, so a bound id needs no special case.
    expect(bindModals('<x-ui.modal :id="$modalName" wire:model="show">'))
        ->toContain('$modal.open(modalId)')
        ->and(bindModals('<x-ui.modal :id="$modalName" wire:model="show">'))
        ->toContain(':id="$modalName"');
});

it('opens a modal on render the way `:show` did', function (): void {
    // The kit writes this on every confirm dialog: come up by yourself if the
    // last submit failed validation. Evaluated once, server-side, so it lands in
    // the markup as a literal rather than something the page keeps watching.
    expect(bindModals('<x-ui.modal id="confirm-user-deletion" :show="$errors->isNotEmpty()" class="max-w-lg">'))
        ->toBe(
            '<x-ui.modal x-init="{{ ($errors->isNotEmpty()) ? \'true\' : \'false\' }} && $modal.open(modalId)"'
            .' id="confirm-user-deletion" class="max-w-lg">',
        );
});

it('parenthesises the expression it inlines', function (): void {
    // Without the parentheses an expression with its own ternary would rebind
    // against the one refit wraps it in.
    expect(bindModals('<x-ui.modal id="m" :show="$a ? $b : $c">'))
        ->toContain('{{ ($a ? $b : $c) ? \'true\' : \'false\' }}');
});

it('refuses a `:show` it cannot re-quote, and says so', function (): void {
    // A double quote in the expression would close the attribute early and emit
    // markup broken in a way the rest of the run would sail straight past.
    $report = new Report;
    $source = '<x-ui.modal id="m" :show=\'$errors->has("name")\'>';

    expect(bindModals($source, $report))->toBe($source)
        ->and(implode(' ', $report->warnings()))->toContain(':show');
});

it('leaves `:show` to a person when a property governs the same state', function (): void {
    // Two answers to one question. Translating both would have x-init open the
    // modal and the effect shut it again a tick later.
    $report = new Report;
    $rewritten = bindModals('<x-ui.modal id="m" :show="$errors->isNotEmpty()" wire:model="show">', $report);

    expect($rewritten)->toContain(':show="$errors->isNotEmpty()"')
        ->and($rewritten)->toContain('x-effect=')
        ->and($rewritten)->not->toContain('x-init=')
        ->and(implode(' ', $report->warnings()))->toContain('wire:model');
});

it('leaves a trigger-driven modal completely alone', function (): void {
    $source = '<x-ui.modal id="confirm-user-deletion" class="max-w-lg">';

    expect(bindModals($source))->toBe($source);
});

it('does not fight a binding somebody already wrote', function (): void {
    $source = '<x-ui.modal id="m" x-effect="mine()" wire:model="show">';

    expect(bindModals($source))->toBe($source);
});

it('leaves the trigger and every other component untouched', function (): void {
    $source = '<x-ui.modal.trigger id="m"><x-ui.input wire:model="code" @close="x" /></x-ui.modal.trigger>';

    expect(bindModals($source))->toBe($source);
});
