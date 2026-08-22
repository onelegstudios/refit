<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Address the modal events the kit raises from PHP to the modal that is
 * listening.
 *
 * Both libraries close a modal by dispatching a browser event called
 * `close-modal`, and the kit dispatches it from the component that just finished
 * its work:
 *
 *     $this->dispatch('close-modal', name: 'invite-member');
 *
 * The event name survives the migration untouched, which is exactly what makes
 * this one hard to see. What does not survive is the envelope: Livewire turns
 * named arguments into the event's `detail`, Flux read the modal's `name` out of
 * it, and Sheaf's modal compares `detail.id` against its own id — so `{name:
 * 'invite-member'}` arrives at a listener reading a key that is not there, and
 * every modal is left open behind the thing it just did. Sending the invitation
 * works, the toast appears, the list updates, and the dialog stays where it is.
 *
 * The rename is only the parameter: the event is already the one Sheaf listens
 * for, and the id is already the value Flux's `name` held — `MapComponentTags`
 * pairs `<flux:modal name="…">` with `<x-ui.modal id="…">` on exactly that
 * string.
 *
 * `<flux:modal.close>` — the Cancel button beside it — is the same event from the
 * other side, and belongs to `RestructureOverlays`, which is where the wrapper it
 * lives in gets rewritten.
 */
final class AddressModalDispatches implements Action
{
    /**
     * A `dispatch()` of either modal event, up to the parameter that names it.
     *
     * `open-modal` is here for symmetry rather than for the kit: Sheaf reads
     * `detail.id` for both, and a project that opens a modal from PHP would fail
     * the same way with nothing in the log to say so.
     */
    private const string PATTERN = '/(->dispatch\(\s*([\'"])(?:open|close)-modal\2\s*,\s*)name(\s*:)/';

    /** What Sheaf's modal compares against its own id. */
    private const string KEY = 'id';

    public function describe(): string
    {
        return 'blade  address dispatch(\'close-modal\', name: ...) to Sheaf\'s id instead';
    }

    public function apply(Project $project, Report $report): void
    {
        $touched = 0;

        foreach ([...$project->blades(), ...$project->livewireClasses()] as $path) {
            $source = $project->get($path);

            if (! str_contains($source, '-modal')) {
                continue;
            }

            $rewritten = preg_replace(self::PATTERN, '${1}'.self::KEY.'${3}', $source);

            if ($rewritten === null || $rewritten === $source) {
                continue;
            }

            file_put_contents($project->path($path), $rewritten);

            $report->changed($path);
            $touched++;
        }

        if ($touched === 0) {
            return;
        }

        $report->note(sprintf(
            'Re-addressed the modal events %d file(s) dispatch from PHP, from `name:` to `id:`. Livewire '
            .'sends those named arguments as the browser event\'s detail, and Sheaf\'s modal closes on '
            .'`detail.id` where Flux read `detail.name` — so the call succeeded and the modal stayed open.',
            $touched,
        ));
    }
}
