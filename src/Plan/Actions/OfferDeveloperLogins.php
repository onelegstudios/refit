<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Tasks\LoginLinks;

/**
 * Put a block of developer login links under the login form.
 *
 * Swept over the tree rather than aimed at a path, because where the login view
 * lives is not settled until the move stage is over: it ships in
 * `resources/views/pages/auth` on four of the five variations and in
 * `resources/views/livewire/auth` on the fifth, and refit's own
 * "Move the auth views out of pages" task offers to move it again. The form's own
 * `route('login.store')` is what identifies it instead — the one line every
 * variation's login view has and no other view does.
 *
 * The block goes directly under the form, which is where somebody looking for a
 * way in looks, and above whatever the kit writes below it — the "Don't have an
 * account?" footer stays the last thing on the page.
 *
 * It is a card with one heading and a row of logins under it, divided by pipes.
 * The heading carries the verb for all of them, which is what keeps each login
 * down to the account it names — "Log in as: Test User | Admin User" says in one
 * line what five "Log in as the …" links said in five, and a block this size
 * belongs in a panel of its own rather than loose under somebody's login form.
 *
 * The card is the library's own component, which is why the tag is passed in
 * rather than written here: a hand-rolled panel of utility classes would be a
 * second opinion about what a card looks like, sitting inside an application
 * that already has one. Both spellings have to be known up front, because this
 * runs in the same reconcile pass as the migration that renames Flux's tags and
 * after it — a `flux:card` written here would be the one tag left behind.
 *
 * Everything inside it is wrapped in `@env('local')`, so the links are compiled
 * into every build and rendered in exactly one. That is belt and braces on
 * purpose: spatie/laravel-login-link throws outside its own
 * `allowed_environments`, and the package is installed as a dev dependency, so
 * three separate things have to go wrong before a production page offers a way
 * past the password.
 *
 * A view that already has a login link in it is left alone, which is what makes
 * a second run harmless — refit's changes are one-way, but this one reads its
 * own result.
 *
 * @see LoginLinks
 */
final class OfferDeveloperLogins extends BladeSweep
{
    /**
     * The route the kit's login form posts to, and so what makes a view the
     * login view.
     */
    public const string MARKER = "route('login.store')";

    /**
     * The component the links are written as, both to write and to recognise.
     */
    public const string COMPONENT = 'x-login-link';

    /**
     * What the heading over the links reads, which is also what makes the links
     * themselves short enough to sit in a row.
     */
    private const string HEADING = 'Log in as';

    /**
     * The card a run that has chosen nothing writes, which is the kit's own: the
     * starter kit ships Flux, and `flux:card` is in the free edition.
     *
     * `outline` is Flux's card with no fill of its own — an edge, and whatever is
     * behind it. The other variants paint a tint mixed for the page they were
     * drawn on, so a project that later gives this page a background of its own
     * gets a card sitting on a patch of the old one. An outline has nothing to go
     * stale.
     *
     * @var array{string, string}
     */
    public const array FLUX_CARD = ['flux:card', 'variant="outline"'];

    /**
     * The heading, in the component the page's own headings are written with.
     *
     * At the size that matches the labels over the inputs above it, which is the
     * line this one belongs with: `flux:label` is `text-sm font-medium` over
     * `text-zinc-800 dark:text-white`, and a heading at any size below `lg` is
     * the same three things. Not the page's title, which is `xl` and belongs to
     * the page rather than to a panel inside it.
     *
     * `base` is Flux's own default for the prop, written out rather than left
     * unsaid — the same thing the migration does to it, and for the same reason:
     * a value in the file is a value that can be translated.
     *
     * @var array{string, string}
     */
    public const array FLUX_HEADING = ['flux:heading', 'size="base"'];

    /**
     * What the card is asked for beyond its own surface: a shape, room inside,
     * small text and centred under the form it follows.
     *
     * The shape and the room are written out rather than left to the component's
     * own sizing, because neither library keeps them on the tag. Flux derives
     * both from CSS variables in `flux.css`, Sheaf writes them as `:where()`
     * defaults in the component it installs — so a card gets its geometry from a
     * stylesheet, and a project whose build does not have that stylesheet gets a
     * square, unpadded box. Utilities land in Tailwind's own layer, above both,
     * and they are in the view refit just wrote, which is a file Tailwind
     * scans. One version of this is what every kit renders.
     *
     * Centred, like the footer the kit writes under it. The card is a block of
     * its own rather than another field in the form, and a row that wraps reads
     * better centred than ragged.
     */
    private const string CARD = 'rounded-lg p-4 text-sm text-center';

    /**
     * All the heading is asked for beyond being one: room under it. Its size,
     * weight and colour are the component's, which is the point of using it.
     */
    private const string LABEL = 'mb-2';

    /**
     * The row itself. Each login renders as a form of its own, so they are laid
     * out rather than flowed, and they wrap on a narrow screen instead of pushing
     * the panel wider than the form above it.
     *
     * `gap-x-3` rather than the `gap-x-2` this started on: a pipe is a thin mark
     * between two words, and at this text size eight pixels is not enough to read
     * as a divider.
     */
    private const string ROW = 'flex flex-wrap items-center justify-center gap-x-3 gap-y-1.5';

    /**
     * The pipes between them: faint, and hidden from a screen reader, which has
     * the forms themselves to go on and nothing to gain from the punctuation.
     */
    private const string SEPARATOR = 'text-zinc-300 dark:text-zinc-600';

    private bool $placed = false;

    /**
     * @param  list<string>  $links  one rendered `<x-login-link />` per account
     * @param  array{string, string}  $card  the panel's tag and props, as the library the run ends on spells them
     * @param  array{string, string}  $heading  its heading's, which are not the same two words in both libraries
     */
    public function __construct(
        private readonly array $links,
        private readonly array $card = self::FLUX_CARD,
        private readonly array $heading = self::FLUX_HEADING,
    ) {}

    public function describe(): string
    {
        return sprintf(
            'edit   the login view — %d developer login%s under the form, for local only',
            count($this->links),
            count($this->links) === 1 ? '' : 's',
        );
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        if ($this->links === [] || $this->placed) {
            return $source;
        }

        $marker = strpos($source, self::MARKER);

        if ($marker === false || str_contains($source, '<'.self::COMPONENT)) {
            return $source;
        }

        $closes = strpos($source, '</form>', $marker);

        if ($closes === false) {
            $report->warn(sprintf(
                'Left %s alone — it posts to the login route but has no form to put the developer logins under.',
                $path,
            ));

            return $source;
        }

        $line = strpos($source, "\n", $closes);
        $this->placed = true;

        $report->note(sprintf(
            'Added %d developer login(s) to %s. They render in the local environment only, and an account that does not exist yet is created the first time its link is used.',
            count($this->links),
            $path,
        ));

        return substr_replace(
            $source,
            "\n".$this->block($this->indent($source, $closes))."\n",
            $line === false ? strlen($source) : $line + 1,
            0,
        );
    }

    /**
     * A sweep that found no login view has nothing to say for itself here — the
     * task only plans this once it has seen one, so a tree without one means
     * something moved it out from under the plan.
     */
    protected function finish(Report $report): void
    {
        if (! $this->placed) {
            $report->warn(
                'Could not find the login view, so no developer logins were added. '
                .'Write `<x-login-link />` into it yourself — the package is installed.',
            );
        }
    }

    /**
     * The block, indented to sit where the form it follows does.
     */
    private function block(string $indent): string
    {
        $lines = [
            $indent."@env('local')",
            $indent.'    '.$this->open($this->card, self::CARD),
            $indent.'        '.$this->open($this->heading, self::LABEL).self::HEADING.$this->close($this->heading),
            $indent.'        <div class="'.self::ROW.'">',
        ];

        foreach ($this->links as $index => $link) {
            if ($index > 0) {
                $lines[] = $indent.'            <span aria-hidden="true" class="'.self::SEPARATOR.'">|</span>';
            }

            $lines[] = $indent.'            '.$link;
        }

        $lines[] = $indent.'        </div>';
        $lines[] = $indent.'    '.$this->close($this->card);
        $lines[] = $indent.'@endenv';

        return implode("\n", $lines);
    }

    /**
     * One component's opening tag: its own props, then the classes this block
     * asks of it.
     *
     * The props are written before the class rather than after so the tag reads
     * the way the kit's own do — what the component is, then what this use of it
     * looks like. A component with nothing to pass closes straight on its class.
     *
     * @param  array{string, string}  $component
     */
    private function open(array $component, string $classes): string
    {
        return rtrim('<'.$component[0].' '.$component[1]).' class="'.$classes.'">';
    }

    /**
     * @param  array{string, string}  $component
     */
    private function close(array $component): string
    {
        return '</'.$component[0].'>';
    }
}
