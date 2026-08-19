<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Libraries\Flux\Teardown;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Put the theme on the document before the browser paints it, and keep it there.
 *
 * The kit's layouts all ship `<html class="dark">`, and the real answer arrives
 * from JavaScript. Flux made that work with `@fluxAppearance`: an inline script
 * in the head, synchronous, so the class was already right by the time anything
 * was drawn. {@see Teardown} strips that directive — it fatals once the package
 * is gone — and Sheaf has nothing that takes its place.
 *
 * Sheaf's own runtime cannot: `resources/js/globals/theme.js` registers the
 * `$theme` magic inside an `alpine:init` listener, and it reaches the page
 * through `@vite`, which Vite emits as `type="module"` — deferred by spec. So it
 * runs after the document has been parsed and painted. The page comes up dark
 * from the hardcoded class, Alpine boots, `.dark` comes off, and the whole thing
 * snaps to light in front of the user on every single load.
 *
 * The directive emitted two things, and both went with it. The other is a single
 * CSS rule — `:root.dark { color-scheme: dark }` — which is what tells the browser
 * that its *own* defaults are dark: unstyled text, scrollbars, and native form
 * chrome. Sheaf reads `prefers-color-scheme` to decide the theme and never
 * declares `color-scheme` itself, so without it every piece of text carrying no
 * colour class of its own stays black on the dark background. Most of the kit is
 * safe because Sheaf's components colour themselves; what is left is the plain
 * markup between them, and in the kit that is the recovery-code toggle at the foot
 * of the two-factor challenge.
 *
 * So this writes that script back, reading exactly what `theme.js` reads: the
 * `theme` key, defaulting to `system`, resolved against the same media query,
 * applied to the same element. Anything else and the two would disagree for the
 * one frame that matters.
 *
 * The theme is then resolved three times in total, which is what Sheaf's own dark
 * mode guide prescribes, and each one answers a different failure:
 *
 * 1. **Inline in the head**, before the first paint. Without it the hardcoded
 *    class is what the reader sees first.
 * 2. **On `livewire:navigated`.** `wire:navigate` copies the incoming document's
 *    `<html>` attributes straight onto the live one, so `class="dark"` lands on
 *    the page again on every navigation — and Livewire merges only the head
 *    children the page does not already have, so a script identical on every page
 *    is never re-run to undo it. Alpine does not boot a second time either.
 *    Without this the reader's choice survives until they click a link.
 * 3. **At the end of the body**, once the deferred modules have run. Livewire
 *    components update independently of one another, and each update is a chance
 *    for a component that renders its own colours to come back in the state the
 *    server assumed rather than the one the reader chose.
 *
 * Anchored on markup rather than on paths, because both files move:
 * `PromotePartialsToComponents` turns `partials/head.blade.php` into a component,
 * and the layouts holding `</body>` are rewritten from stubs. This runs in the
 * reconcile stage over the settled tree, so it finds them wherever that left them.
 */
final class ApplyThemeBeforePaint extends BladeSweep
{
    /**
     * The line the head script goes in front of.
     *
     * Before rather than after, so no assumption is made about where the
     * directive's arguments end — `@vite([config('assets')])` closes a
     * parenthesis halfway through — and so the theme lands as early in the head
     * as it can.
     */
    private const string ANCHOR = '/^[ \t]*@vite\b/m';

    /**
     * What tells the document's head from a component that wants a script.
     *
     * `@vite` on its own is not enough: the kit calls it inside
     * `passkey-registration` and `passkey-verify` to pull in `passkeys.js`, and a
     * theme script in either is one that runs long after the paint it exists to
     * beat. A stylesheet is the honest signal, because a stylesheet has to be in
     * the head — and a file writing the head element itself counts too, for the
     * project that imports its CSS from JavaScript and never names a `.css` here.
     */
    private const string STYLESHEET = '/@vite\([^)]*\.css/';

    private const string HEAD = '<head';

    /** Where the closing call goes, and what has to be true for it to mean anything. */
    private const string BODY = '/^[ \t]*<\/body>/m';

    /**
     * A document that reaches the head this writes to.
     *
     * The kit's `welcome.blade.php` closes a body too, and has no asset tags, no
     * theme and no `.dark` anywhere — it answers the OS through
     * `prefers-color-scheme` alone. Writing a call into it would be noise at best.
     */
    private const string REACHES_HEAD = '/@include\([\'"][^\'"]*head[\'"]\)|<x-[a-z0-9.:-]*head\b/i';

    /**
     * Named so a script later in the document can call it, and reachable through
     * `window` so that call can be optional — a layout refit did not put a head
     * script into is a layout where this is simply absent.
     */
    private const string FUNCTION = 'applyStoredTheme';

    /** Enough of each script to recognise it, so a second run adds no second one. */
    private const string MARKER = "localStorage.getItem('theme')";

    private const string BODY_MARKER = 'window.'.self::FUNCTION.'?.()';

    private const string SCRIPT = <<<'BLADE'
        {{-- What Flux's appearance directive declared, and nothing in a Sheaf
             project does.
             `color-scheme` is the browser's own light/dark switch: without it the
             UA keeps its light defaults in dark mode, so scrollbars and native form
             chrome stay light and any text with no colour class of its own renders
             black on the dark background. --}}
        <style>
            :root.dark {
                color-scheme: dark;
            }
        </style>

        {{-- Sheaf resolves the theme from Alpine, which runs after the document has
             been painted — so the class it puts on <html> arrives a frame late and
             the page snaps from the hardcoded dark to light in front of the reader.
             This is the pre-paint half, reading the same `theme` key, the same
             `system` default and the same media query as resources/js/globals/theme.js.
             Change one and change both. --}}
        <script>
            window.applyStoredTheme = () => {
                const stored = localStorage.getItem('theme') ?? 'system';
                const dark = stored === 'dark'
                    || (stored === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

                document.documentElement.classList.toggle('dark', dark);
            };

            window.applyStoredTheme();

            {{-- wire:navigate copies the incoming document's <html> attributes onto
                 the live one — the kit's hardcoded `class="dark"` with them — and
                 merges only the head children the page does not already have, so
                 this script is never re-run. Alpine does not boot a second time
                 either, so Sheaf's runtime stays quiet. Without this listener every
                 navigation reverts the reader's choice. --}}
            document.addEventListener('livewire:navigated', window.applyStoredTheme);
        </script>


        BLADE;

    private const string BODY_SCRIPT = <<<'BLADE'
        {{-- Once more now the deferred modules have run. Livewire components update
             independently of one another, and each update is a chance for one that
             renders its own colours to come back in the state the server assumed
             rather than the one the reader chose. Sheaf's dark mode guide asks for
             this call for the same reason. --}}
        <script>window.applyStoredTheme?.()</script>
        BLADE;

    private bool $written = false;

    public function describe(): string
    {
        return 'theme  resolve light/dark in the head, before the first paint';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return $this->closeBody($this->openHead($source));
    }

    /**
     * The inline script, as early in the head as the asset tags allow.
     */
    private function openHead(string $source): string
    {
        if (str_contains($source, self::MARKER)) {
            $this->written = true;

            return $source;
        }

        if (preg_match(self::STYLESHEET, $source) !== 1 && ! str_contains($source, self::HEAD)) {
            return $source;
        }

        if (preg_match(self::ANCHOR, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        $this->written = true;

        return $this->insert($source, self::SCRIPT, $matches);
    }

    /**
     * The closing call, in the documents that have the head script to call.
     */
    private function closeBody(string $source): string
    {
        if (str_contains($source, self::BODY_MARKER)) {
            return $source;
        }

        if (! str_contains($source, self::MARKER) && preg_match(self::REACHES_HEAD, $source) !== 1) {
            return $source;
        }

        if (preg_match(self::BODY, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        return $this->insert($source, self::BODY_SCRIPT."\n", $matches);
    }

    /**
     * A head that never turned up is worth saying out loud.
     *
     * Every kit layout pulls in a partial that calls `@vite`, so this only fires
     * for a project that has moved its asset tags somewhere refit cannot follow
     * — and there the flash is real and the fix is two lines a person can paste.
     */
    protected function finish(Report $report): void
    {
        if ($this->written) {
            $this->written = false;

            return;
        }

        $report->warn(
            'Found no @vite call to put the theme script in front of, so the first paint of every page '
            .'will use the `dark` class the kit hardcodes and then correct itself once Alpine has booted. '
            .'Paste a script into your <head> that reads localStorage `theme` and toggles `.dark` on '
            .'<html>, the way resources/js/globals/theme.js does.',
        );
    }

    /**
     * Put a block on the anchor's line, at the anchor's indentation.
     *
     * @param  array<int, array{0: string, 1: int}>  $matches
     */
    private function insert(string $source, string $block, array $matches): string
    {
        $line = $matches[0][0];
        $indent = substr($line, 0, strlen($line) - strlen(ltrim($line)));

        $indented = implode("\n", array_map(
            static fn (string $text): string => $text === '' ? '' : $indent.$text,
            explode("\n", $block),
        ));

        return substr_replace($source, $indented, (int) $matches[0][1], 0);
    }
}
