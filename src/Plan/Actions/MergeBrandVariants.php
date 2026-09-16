<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Take out the branch that chose between two brands, now that there is one.
 *
 * Flux ships two brand components — `flux:brand` for a header and
 * `flux:sidebar.brand` for a sidebar — so the kit's logo component takes a
 * `sidebar` prop and writes the whole brand out twice to pick between them. Its
 * `@if($sidebar)` arm renders `<flux:sidebar.brand>`, its `@else` arm renders
 * `<flux:brand>`, and the two are otherwise the same markup.
 *
 * Sheaf ships one brand, and {@see MapComponentTags} sends both names to it. So
 * the two arms come out of the rename byte for byte identical, and the prop that
 * chose between them has nothing left to choose. Left alone it is a conditional
 * whose answer cannot matter, wrapped around a duplicate of the markup underneath
 * it — and every later edit to the logo has to be made twice or the two arms
 * drift apart.
 *
 * The branch goes, the surviving arm is un-indented into its place, and the prop
 * goes with it. Refit's own layout stubs stop passing `:sidebar` in the same
 * change; a call site refit did not write that still passes it lands the value in
 * `$attributes` instead, where Sheaf's brand merges it onto its `<a>` as a stray
 * `sidebar="1"`. That is inert, and the alternative — keeping a prop declared so
 * that nothing can read it — hides the same call site rather than fixing it.
 *
 * Anchored on the shape rather than on the path, because the logo component moves:
 * `NamespaceComponents` turns `app-logo.blade.php` into `brand/logo.blade.php`.
 * This runs in the reconcile stage over the settled tree, so it finds it wherever
 * that left it.
 */
final class MergeBrandVariants extends BladeSweep
{
    /** What both arms have to render for this to be the brand's own branch. */
    private const string BRAND = 'x-ui.brand';

    /**
     * A line that is not itself a conditional directive.
     *
     * The leading whitespace is part of the look-ahead, since a nested `@if` is
     * indented under the one it sits in and would otherwise slip past.
     */
    private const string PLAIN = '(?![ \t]*(?:@if\b|@elseif\b|@else\b|@endif\b|@unless\b|@endunless\b))';

    /**
     * An `@if`/`@else` on a bare variable, and nothing nested inside either arm.
     *
     * The nesting is excluded rather than parsed: a conditional with another one
     * inside it cannot be paired off by a regex, and a logo that has grown one is
     * a logo where the two arms have already stopped being duplicates.
     */
    private const string BRANCH = '/^([ \t]*)@if\s*\(\s*\$([A-Za-z_]\w*)\s*\)\R'
        .'((?:'.self::PLAIN.'.*\R)*)'
        .'[ \t]*@else\R'
        .'((?:'.self::PLAIN.'.*\R)*)'
        .'[ \t]*@endif[ \t]*\R?/m';

    public function describe(): string
    {
        return 'merge  the two brands the logo chose between into the one Sheaf ships';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        if (! str_contains($source, '<'.self::BRAND)) {
            return $source;
        }

        if (preg_match(self::BRANCH, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $source;
        }

        [$whole, $offset] = $matches[0];
        $indent = $matches[1][0];
        $prop = $matches[2][0];
        $chosen = $matches[3][0];
        $other = $matches[4][0];

        // Identical arms are the whole justification: they are what says the
        // condition cannot change what renders. Anything else is a logo somebody
        // has since made a real decision in, and it stays as it is.
        if (trim($chosen) !== trim($other) || ! str_contains($chosen, '<'.self::BRAND)) {
            return $source;
        }

        $source = substr_replace($source, $this->dedent($chosen, $indent), $offset, strlen($whole));

        // Only once nothing reads it any more. A logo that spends the prop
        // somewhere else as well keeps it, and keeps taking it.
        if (preg_match('/\$'.$prop.'\b/', $source) === 1) {
            return $source;
        }

        return $this->unprop($source, $prop);
    }

    /**
     * The surviving arm, moved left to sit where the `@if` sat.
     *
     * The shift is the arm's own common indent measured against the directive's,
     * so a component written at any depth comes out at the depth it was at.
     */
    private function dedent(string $arm, string $indent): string
    {
        $lines = preg_split('/\R/', rtrim($arm, "\r\n")) ?: [];

        $common = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $lead = strlen($line) - strlen(ltrim($line, " \t"));
            $common = $common === null ? $lead : min($common, $lead);
        }

        $strip = max(0, ($common ?? 0) - strlen($indent));

        $lines = array_map(
            static fn (string $line): string => trim($line) === '' ? '' : substr($line, $strip),
            $lines,
        );

        return implode("\n", $lines)."\n";
    }

    /**
     * The file with one entry taken out of its `@props`, and the directive itself
     * taken out if that was the last entry in it.
     */
    private function unprop(string $source, string $prop): string
    {
        $open = strpos($source, '@props(');

        if ($open === false) {
            return $source;
        }

        $close = $this->balanced($source, $open + strlen('@props('));

        if ($close === null) {
            return $source;
        }

        $inner = substr($source, $open, $close - $open + 1);

        $entry = '/[ \t]*([\'"])'.preg_quote($prop, '/').'\1\s*=>[^,\]]*,?[ \t]*\R?/';

        if (preg_match($entry, $inner) !== 1) {
            return $source;
        }

        $inner = preg_replace($entry, '', $inner, 1) ?? $inner;

        // Nothing but the brackets left, so the directive is declaring an empty
        // set of props — which is the same as not declaring any.
        if (preg_match('/@props\(\s*\[\s*\]\s*\)/', $inner) === 1) {
            return preg_replace(
                '/^[ \t]*'.preg_quote(substr($source, $open, $close - $open + 1), '/').'[ \t]*\R\R?/m',
                '',
                $source,
                1,
            ) ?? $source;
        }

        return substr_replace($source, $inner, $open, $close - $open + 1);
    }

    /**
     * Where the parenthesis opened at `$from` closes, ignoring nothing — the
     * directive's argument is an array literal, so brackets and commas inside it
     * are counted by the same walk that finds the end of it.
     */
    private function balanced(string $source, int $from): ?int
    {
        $depth = 1;

        for ($i = $from, $length = strlen($source); $i < $length; $i++) {
            $depth += match ($source[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
