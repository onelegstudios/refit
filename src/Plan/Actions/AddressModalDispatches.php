<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Address the modal events the kit raises itself to the modal that is listening.
 *
 * Both libraries open and close a modal with browser events called `open-modal`
 * and `close-modal`, which is what makes these survive every pass looking
 * correct. What differs is the envelope. Flux read the modal's `name` out of the
 * event; Sheaf's modal listens on the window and answers only when `detail.id`
 * matches the id it resolved for itself. An event with the wrong key, or with a
 * bare string where an object was wanted, is received and ignored — and nothing
 * anywhere says so.
 *
 * The kit raises them in two spellings.
 *
 * **From PHP**, once the work is done:
 *
 *     $this->dispatch('close-modal', name: 'invite-member');
 *
 * Livewire sends named arguments as the event's detail, so only the argument is
 * wrong. The id is already the value `name` held — `MapComponentTags` pairs
 * `<flux:modal name="…">` with `<x-ui.modal id="…">` on exactly that string — so
 * this is a rename of the key and nothing else. Left alone, the invitation sends,
 * the toast appears, the list updates, and the dialog stays open on top of it.
 *
 * **From Alpine**, on the button that opens one:
 *
 *     x-on:click.prevent="$dispatch('open-modal', 'create-team')"
 *
 * Those sit inside a `<flux:modal.trigger>` that the rename turns into
 * `<x-ui.modal.trigger>`, which opens the modal by id on its own — so the
 * dispatch is not merely mis-addressed, it is redundant, and it goes along with
 * an `x-data=""` whose only job was to give it a scope to run in. A dispatch
 * outside a trigger is the case where something really is being asked for, and
 * is rewritten to the call that does it: `$modal.open(…)`.
 *
 * `<flux:modal.close>` — the Cancel button — is the same family from the other
 * side, and belongs to `RestructureOverlays`, which is where the wrapper it lives
 * in gets rewritten.
 */
final class AddressModalDispatches implements Action
{
    /** What Sheaf's modal opens and closes from, given the right detail. */
    private const string TRIGGER = 'x-ui.modal.trigger';

    /**
     * A `dispatch()` of either modal event, up to the parameter that names it.
     *
     * `open-modal` is here for symmetry rather than for the kit: Sheaf reads
     * `detail.id` for both, and a project that opens a modal from PHP would fail
     * the same way with nothing in the log to say so.
     */
    private const string DISPATCH = '/(->dispatch\(\s*([\'"])(?:open|close)-modal\2\s*,\s*)name(\s*:)/';

    /** What Sheaf's modal compares against its own id. */
    private const string KEY = 'id';

    /**
     * An Alpine handler that is one modal dispatch and nothing else.
     *
     * Anchored, so a handler doing anything besides raising the event is left for
     * a human — there is no safe way to keep the rest and move the call.
     */
    private const string HANDLER = '/^\s*\$dispatch\(\s*([\'"])(open|close)-modal\1\s*,\s*(.+?)\s*\)\s*;?\s*$/s';

    /** Alpine's two spellings of an event handler. */
    private const string LISTENER = '/^(?:x-on:|@)(?<event>[A-Za-z0-9_-]+)(?:\.[A-Za-z0-9_-]+)*$/';

    public function __construct(
        private readonly TagParser $parser = new TagParser,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return 'blade  address the kit\'s own open-modal and close-modal events to Sheaf\'s id';
    }

    public function apply(Project $project, Report $report): void
    {
        $dispatches = 0;
        $handlers = 0;

        foreach ([...$project->blades(), ...$project->livewireClasses()] as $path) {
            $source = $project->get($path);

            if (! str_contains($source, '-modal')) {
                continue;
            }

            $rewritten = $this->readdress($source, $dispatches);
            $rewritten = $this->rewriteHandlers($rewritten, $handlers);

            if ($rewritten === $source) {
                continue;
            }

            file_put_contents($project->path($path), $rewritten);

            $report->changed($path);
        }

        $this->report($report, $dispatches, $handlers);
    }

    /**
     * Rename the argument naming the modal, wherever PHP dispatches at one.
     */
    private function readdress(string $source, int &$count): string
    {
        $rewritten = preg_replace(self::DISPATCH, '${1}'.self::KEY.'${3}', $source, -1, $replacements);

        if ($rewritten === null) {
            return $source;
        }

        $count += $replacements;

        return $rewritten;
    }

    /**
     * Drop or translate every Alpine handler that raises a modal event.
     */
    private function rewriteHandlers(string $source, int &$count): string
    {
        $triggers = $this->nesting->elements($source, [self::TRIGGER]);
        $edits = new Edits;

        foreach ($this->parser->parse($source, '') as $tag) {
            if ($tag->name === '') {
                // A closing tag, which carries nothing to rewrite.
                continue;
            }

            foreach ($tag->attributes as $attribute) {
                $raised = $this->raises($attribute);

                if ($raised === null) {
                    continue;
                }

                [$event, $call, $id] = $raised;

                if ($event === 'click' && $this->triggered($tag, $triggers)) {
                    $this->drop($tag, $attribute, $source, $edits);
                } else {
                    $this->translate($attribute, $call, $id, $edits);
                }

                $count++;
            }
        }

        return $edits->apply($source);
    }

    /**
     * What this attribute raises, if all it does is raise a modal event: the DOM
     * event it answers, which of the two it sends, and the modal it names.
     *
     * @return array{string, string, string}|null
     */
    private function raises(Attribute $attribute): ?array
    {
        if ($attribute->value === null || ! str_contains($attribute->value, '-modal')) {
            return null;
        }

        if (preg_match(self::LISTENER, $attribute->name, $listener) !== 1) {
            return null;
        }

        if (preg_match(self::HANDLER, $attribute->value, $handler) !== 1) {
            return null;
        }

        return [$listener['event'], $handler[2], $handler[3]];
    }

    /**
     * Is this tag inside a trigger that already opens the modal on a click?
     *
     * @param  list<Element>  $triggers
     */
    private function triggered(Tag $tag, array $triggers): bool
    {
        foreach ($triggers as $trigger) {
            if ($trigger->holds($tag->offset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take a redundant handler off the tag, and the empty scope it needed.
     *
     * `x-data=""` is Flux's own instruction for this button — `$dispatch` is an
     * Alpine magic and needs a component to run in. With the handler gone it
     * declares a scope for nothing, unless the tag has other Alpine of its own.
     */
    private function drop(Tag $tag, Attribute $attribute, string $source, Edits $edits): void
    {
        $this->erase($attribute, $source, $edits);

        $data = $tag->attribute('x-data');

        if (! $data instanceof Attribute || ($data->value !== null && trim($data->value) !== '')) {
            return;
        }

        foreach ($tag->attributes as $other) {
            if ($other === $attribute || $other === $data) {
                continue;
            }

            if (str_starts_with($other->name, 'x-') || str_starts_with($other->name, '@')) {
                return;
            }
        }

        $this->erase($data, $source, $edits);
    }

    /**
     * Remove an attribute along with the whitespace that separated it, so the
     * line it sat on goes with it rather than becoming a blank one.
     */
    private function erase(Attribute $attribute, string $source, Edits $edits): void
    {
        $start = $attribute->offset;

        while ($start > 0 && in_array($source[$start - 1], TagParser::WHITESPACE, true)) {
            $start--;
        }

        $edits->replace($start, $attribute->offset - $start + $attribute->length, '');
    }

    /**
     * Ask Sheaf's store to do what the event was asking Flux to do.
     */
    private function translate(Attribute $attribute, string $call, string $id, Edits $edits): void
    {
        $edits->replace(
            $attribute->valueOffset(),
            $attribute->valueLength(),
            sprintf('$modal.%s(%s)', $call, $id),
        );
    }

    private function report(Report $report, int $dispatches, int $handlers): void
    {
        if ($dispatches > 0) {
            $report->note(sprintf(
                'Re-addressed %d modal event(s) dispatched from PHP, from `name:` to `id:`. Livewire sends '
                .'those named arguments as the browser event\'s detail, and Sheaf\'s modal closes on '
                .'`detail.id` where Flux read `detail.name` — so the call succeeded and the modal stayed open.',
                $dispatches,
            ));
        }

        if ($handlers > 0) {
            $report->note(sprintf(
                'Rewrote %d Alpine handler(s) raising a modal event. Sheaf ignores a `$dispatch(\'open-modal\', '
                .'\'…\')` written the way Flux took it, so one inside an <x-ui.modal.trigger> — which opens the '
                .'modal by id itself — is dropped, and one anywhere else becomes the $modal call it meant.',
                $handlers,
            ));
        }
    }
}
