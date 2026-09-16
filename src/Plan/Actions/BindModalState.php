<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Reconnect the modals the kit opens from PHP rather than from a trigger.
 *
 * Most of the kit's modals are opened by a `<x-ui.modal.trigger>`, and the id
 * pairing is all they need. A handful are not: the component holds a boolean and
 * the modal follows it, so `confirmDelete()` sets `$showDeleteModal = true` and
 * the modal is expected to appear. Flux supports that directly —
 * `wire:model="showDeleteModal"` on the modal, and `@close="closeDeleteModal"`
 * for the trip back.
 *
 * Sheaf's modal has neither. It owns its open state in an Alpine `isOpen` flag,
 * opens when a window `open-modal` event carries its id, and announces itself
 * with `modal-opened` / `modal-closed` — never `close`. So `wire:model` lands in
 * the attribute bag on a wrapper div where nothing reads it, `@close` waits on an
 * event Sheaf does not fire, and the modal becomes unreachable: in the kit, the
 * trash button on a passkey row, and the pending-invitations dialog on the teams
 * variants.
 *
 * Both halves are restored explicitly:
 *
 * - `x-effect` watches the Livewire property and drives Sheaf's own store, so
 *   setting the property from PHP still opens and closes the modal;
 * - `x-on:modal-closed` writes `false` back, so a modal dismissed with escape or
 *   the backdrop leaves the server agreeing that it is shut — without which it
 *   would refuse to reopen;
 * - `@close` is renamed to the event Sheaf actually dispatches.
 *
 * `:show` is the third way the kit opens a modal from PHP, and the simplest:
 * `:show="$errors->isNotEmpty()"` means "start open if the last submit failed".
 * Sheaf has no prop for it, so it becomes an `x-init` that asks the store to open
 * the modal when the expression was true at render time. One shot, not a binding,
 * because that is all Flux promised either.
 *
 * The two listeners are deliberately spelled differently — `x-on:modal-closed`
 * for the writeback, `@modal-closed` for the handler the view already had. They
 * are one directive to Alpine but two attributes to HTML, which is what lets both
 * bind to the same event; written the same way twice, the second is dropped as a
 * duplicate attribute and whichever concern lost would fail silently.
 *
 * Nothing here needs to know the modal's id. `$modal.open(modalId)` is evaluated
 * inside the component's own Alpine scope, so it reads the id the component
 * resolved for itself — which is also the only thing that works for the teams
 * variants, where the id is a bound expression like `:id="$modalName"`.
 */
final class BindModalState extends BladeSweep
{
    private const string MODAL = 'x-ui.modal';

    /** What Sheaf's modal dispatches when `isOpen` goes true to false. */
    private const string CLOSED = 'modal-closed';

    /** @var list<string> */
    private array $touched = [];

    /**
     * Modals whose `:show` refit could not safely translate, keyed by file.
     *
     * @var array<string, list<string>>
     */
    private array $skipped = [];

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'bind   modals opened from PHP onto Sheaf\'s own open state';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, self::MODAL) as $tag) {
            if ($tag->name !== self::MODAL) {
                continue;
            }

            $this->rewrite($tag, $source, $edits, $path);
        }

        if ($edits->isEmpty()) {
            return $source;
        }

        $this->touched[] = $path;

        return $edits->apply($source);
    }

    /**
     * Translate one modal's open-state attributes, if it has any.
     */
    private function rewrite(Tag $tag, string $source, Edits $edits, string $path): void
    {
        foreach ($this->closeListeners($tag) as $attribute) {
            $edits->replace($attribute->offset, strlen($attribute->name), '@'.self::CLOSED);
        }

        $model = $this->model($tag);
        $show = $tag->attribute(':show');

        if ($model instanceof Attribute && $model->value !== null && $model->value !== '') {
            $this->bind($tag, $model, $show, $source, $edits, $path);

            return;
        }

        if ($show instanceof Attribute) {
            $this->openOnRender($tag, $show, $source, $edits, $path);
        }
    }

    /**
     * Put a modal's open state under the Livewire property that used to own it.
     */
    private function bind(Tag $tag, Attribute $model, ?Attribute $show, string $source, Edits $edits, string $path): void
    {
        // An `x-effect` already on the tag is somebody's own binding, and a second
        // one would be fighting it. Left alone rather than guessed at.
        if ($tag->has('x-effect')) {
            return;
        }

        // A property and a `:show` on the same modal are two answers to one
        // question, and only the property keeps answering after the first render.
        // Translating both would have `x-init` open the modal and the effect shut
        // it again a tick later, so the `:show` is left for a person to settle.
        if ($show instanceof Attribute) {
            $this->skipped[$path] ??= [];
            $this->skipped[$path][] = 'it also binds wire:model, which governs the same state';
        }

        $property = $model->value;
        $separator = $this->separator($tag, $source);

        $edits->replace(
            $tag->nameOffset() + strlen($tag->name),
            0,
            $separator.sprintf(
                'x-effect="$wire.%s ? $modal.open(modalId) : $modal.close(modalId)"',
                $property,
            ).$separator.sprintf(
                'x-on:%s="$wire.%s = false"',
                self::CLOSED,
                $property,
            ),
        );

        // The binding is what the attribute meant; leaving it would only claim a
        // relationship Sheaf's modal does not have.
        $this->drop($model, $source, $edits);
    }

    /**
     * Open a modal on render, the way Flux's `:show` did.
     *
     * The expression is PHP and is evaluated once, server-side, so it becomes a
     * literal `true` or `false` in the emitted Alpine rather than anything the
     * page keeps watching. Parenthesised on the way in, so an expression with its
     * own ternary in it still binds the way its author wrote it.
     */
    private function openOnRender(Tag $tag, Attribute $show, string $source, Edits $edits, string $path): void
    {
        $expression = $show->value;

        if ($expression === null || $expression === '' || $tag->has('x-init')) {
            return;
        }

        // The expression has to survive being quoted into an attribute, and one
        // that already contains a double quote would close it early and produce
        // markup that is broken in a way no test of ours would catch.
        if (str_contains($expression, '"')) {
            $this->skipped[$path] ??= [];
            $this->skipped[$path][] = 'its :show expression contains a quote refit cannot safely re-quote';

            return;
        }

        $edits->replace(
            $tag->nameOffset() + strlen($tag->name),
            0,
            $this->separator($tag, $source).sprintf(
                'x-init="{{ (%s) ? \'true\' : \'false\' }} && $modal.open(modalId)"',
                $expression,
            ),
        );

        $this->drop($show, $source, $edits);
    }

    /**
     * The `wire:model` binding a modal's open state, in any of its spellings.
     */
    private function model(Tag $tag): ?Attribute
    {
        foreach ($tag->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'wire:model')) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Every listener the view has on Flux's `close` event.
     *
     * @return list<Attribute>
     */
    private function closeListeners(Tag $tag): array
    {
        $found = [];

        foreach ($tag->attributes as $attribute) {
            if (in_array($attribute->name, ['@close', 'x-on:close'], true)) {
                $found[] = $attribute;
            }
        }

        return $found;
    }

    /**
     * The whitespace to put in front of an attribute refit adds.
     *
     * Taken from whatever already separates the tag name from its first
     * attribute, so a modal written across several lines gains several lines and
     * a one-line modal stays on one.
     */
    private function separator(Tag $tag, string $source): string
    {
        $start = $tag->nameOffset() + strlen($tag->name);
        $first = $tag->attributes[0] ?? null;

        if (! $first instanceof Attribute || $first->offset <= $start) {
            return ' ';
        }

        $gap = substr($source, $start, $first->offset - $start);

        return str_contains($gap, "\n") ? $gap : ' ';
    }

    /**
     * Remove an attribute, taking the whitespace in front of it along.
     */
    private function drop(Attribute $attribute, string $source, Edits $edits): void
    {
        $start = $attribute->offset;

        while ($start > 0 && in_array($source[$start - 1], TagParser::WHITESPACE, true)) {
            $start--;
        }

        $edits->replace($start, $attribute->offset + $attribute->length - $start, '');
    }

    protected function finish(Report $report): void
    {
        ksort($this->skipped);

        foreach ($this->skipped as $path => $reasons) {
            foreach (array_unique($reasons) as $reason) {
                $report->warn(sprintf(
                    'Left a modal\'s :show alone in %s — %s. Flux opened the modal on render from it; '
                    .'Sheaf has no prop for that, so check whether the modal still needs to come up by itself.',
                    $path,
                    $reason,
                ));
            }
        }

        $this->skipped = [];

        if ($this->touched === []) {
            return;
        }

        $report->note(sprintf(
            'Rebound the modals opened from PHP in %d file(s). Sheaf\'s modal keeps its own open '
            .'state instead of taking wire:model, and dispatches modal-closed rather than close, so '
            .'both directions are now explicit Alpine on the tag, as is the :show that opened one on '
            .'render — worth a look in the diff.',
            count($this->touched),
        ));

        $this->touched = [];
    }
}
