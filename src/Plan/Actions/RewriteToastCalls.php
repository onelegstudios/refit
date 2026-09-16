<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use FilesystemIterator;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Point the kit's toasts at the component that is now listening for them.
 *
 * The rename gets the container right on its own — `<flux:toast.group>` becomes
 * `<x-ui.toast>`, and the layout stubs render one — but a toast has two halves,
 * and the other half is PHP. The kit raises its toasts through Flux's facade:
 *
 *     Flux::toast(variant: 'success', text: __('Profile updated.'));
 *
 * which dispatches a Livewire event only Flux's own component answers. Sheaf's
 * toast listens for a browser `notify` event instead, so without this the call
 * still runs, still succeeds, and nothing appears — the worst shape a broken
 * migration can take, because there is nothing in the log to notice.
 *
 * The rewrite is the same event the kit already dispatches elsewhere:
 *
 *     $this->dispatch('notify', type: 'success', content: __('Profile updated.'));
 *
 * `$this` is the reason this sweep reads what it reads. Volt keeps a component's
 * PHP inside the view, and `app/Livewire` holds the rest; both are places where
 * `$this` is the component. A `Flux::toast()` anywhere else — a controller, an
 * action class — is reported rather than rewritten, because guessing at a
 * dispatcher that is not there would trade a silent failure for a fatal one.
 *
 * Conservative about the call itself for the same reason. Flux takes a `heading`
 * that Sheaf's toast has no room for, and a `link` and `position` it does not
 * take at all; those are dropped and named in the report rather than folded into
 * the message. Anything this cannot read with confidence — two positional
 * arguments, where Flux's own order is `heading` then `text` — is left exactly as
 * it was, and reported, which leaves the user one grep and a decision rather than
 * a rewrite that says the wrong thing.
 */
final class RewriteToastCalls implements Action
{
    /** The Sheaf toast's event name, dispatched from the component. */
    private const string EVENT = 'notify';

    /**
     * Flux's variants against Sheaf's types.
     *
     * Three of the four line up by name. Flux has no `info` — that is Sheaf's
     * default, and an unadorned `Flux::toast()` lands there without asking.
     *
     * @var array<string, string>
     */
    private const array VARIANTS = [
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'error',
    ];

    /**
     * Flux's positional parameter order, read off `Flux::toast()` itself.
     *
     * Worth recording because Flux's own documentation lists these in a different
     * order — heading before text — while the signature is
     * `toast($text, $heading, $duration, $variant, $position, $link)`. A
     * positional call is rare, but reading it against the documented order would
     * quietly swap the message for the heading, and the heading is the half this
     * drops.
     *
     * @var list<string>
     */
    private const array ORDER = ['text', 'heading', 'duration', 'variant', 'position', 'link'];

    /**
     * Flux arguments Sheaf's toast has a home for, and what it calls them.
     *
     * @var array<string, string>
     */
    private const array ARGUMENTS = [
        'text' => 'content',
        'variant' => 'type',
        'duration' => 'duration',
    ];

    /**
     * Flux arguments with nowhere to go, and what the report should say.
     *
     * @var array<string, string>
     */
    private const array DROPPED = [
        'heading' => 'Sheaf\'s toast shows a single line, so the heading is dropped',
        'link' => 'Sheaf\'s toast is not clickable, so the link is dropped',
        'position' => 'Sheaf positions every toast on <x-ui.toast> rather than per call',
    ];

    /**
     * Files that named something this could not carry across.
     *
     * @var list<string>
     */
    private array $lossy = [];

    /**
     * Files with a call this refused to rewrite.
     *
     * @var list<string>
     */
    private array $skipped = [];

    /**
     * Files holding a Flux::toast() outside a Livewire component.
     *
     * @var list<string>
     */
    private array $unreachable = [];

    /**
     * Does this project raise a Flux toast anywhere at all?
     *
     * Read at plan time so a project that never toasts does not get a line in
     * the preview promising a rewrite with nothing to rewrite. Looks wider than
     * the sweep does — a `Flux::toast()` in a controller is still a reason to run,
     * because running is what reports it.
     */
    public static function used(Project $project): bool
    {
        foreach ($project->blades() as $path) {
            if (str_contains($project->get($path), 'Flux::toast')) {
                return true;
            }
        }

        $root = $project->path('app');

        if (! is_dir($root)) {
            return false;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) @file_get_contents($file->getPathname()), 'Flux::toast')) {
                return true;
            }
        }

        return false;
    }

    public function describe(): string
    {
        return sprintf(
            'blade  rewrite Flux::toast(...) -> $this->dispatch(\'%s\', ...) wherever it is raised',
            self::EVENT,
        );
    }

    public function apply(Project $project, Report $report): void
    {
        foreach ($this->sources($project) as $path) {
            $source = $project->get($path);

            if (! str_contains($source, 'Flux::toast')) {
                continue;
            }

            $rewritten = $this->rewrite($source, $path);
            $rewritten = $this->dropImport($rewritten);

            if ($rewritten === $source) {
                continue;
            }

            file_put_contents($project->path($path), $rewritten);

            $report->changed($path);
        }

        $this->report($project, $report);
    }

    /**
     * Every file where a `$this->dispatch()` is a sentence that makes sense.
     *
     * @return list<string>
     */
    private function sources(Project $project): array
    {
        return [...$project->blades(), ...$project->livewireClasses()];
    }

    /**
     * Replace each `Flux::toast(...)` this can read, leave the rest alone.
     */
    private function rewrite(string $source, string $path): string
    {
        $offset = 0;

        while (($start = strpos($source, 'Flux::toast', $offset)) !== false) {
            $open = strpos($source, '(', $start);

            if ($open === false) {
                break;
            }

            $close = $this->closingParenthesis($source, $open);

            if ($close === null) {
                break;
            }

            $call = $this->call(substr($source, $open + 1, $close - $open - 1), $path);

            if ($call === null) {
                // Left as it was, and already noted. Step past this call so the
                // scan does not stall on it.
                $offset = $close + 1;

                continue;
            }

            $source = substr($source, 0, $start).$call.substr($source, $close + 1);
            $offset = $start + strlen($call);
        }

        return $source;
    }

    /**
     * The Sheaf dispatch for one Flux argument list, or null to leave it alone.
     */
    private function call(string $arguments, string $path): ?string
    {
        $parts = $this->split($arguments);

        // Sheaf's toast has nothing to show without a `content`, so a call with no
        // arguments has nowhere to land. Flux's `$text` has no default either, so
        // this was already failing before refit saw it — which makes reporting it
        // more use than turning it into an empty toast.
        if ($parts === []) {
            $this->skipped[] = $path;

            return null;
        }

        $named = [];
        $positional = [];

        foreach ($parts as $part) {
            // A named argument is `name:` at the very start, and PHP does not
            // allow a space before the colon. `::` is a static call, not a name,
            // and the lookahead keeps `Flux::classes(...)` out of this branch.
            if (preg_match('/^([A-Za-z_]\w*)\s*:(?!:)\s*(.*)$/s', $part, $matches) === 1) {
                $named[$matches[1]] = trim($matches[2]);

                continue;
            }

            $positional[] = $part;
        }

        // PHP requires positional arguments to come first, so the nth of them is
        // Flux's nth parameter and nothing has to be inferred from the value.
        foreach ($positional as $index => $value) {
            $name = self::ORDER[$index] ?? null;

            // More arguments than Flux takes, or a name the call has already
            // given — either way this is not the function this thinks it is.
            if ($name === null || isset($named[$name])) {
                $this->skipped[] = $path;

                return null;
            }

            $named[$name] = $value;
        }

        $rewritten = [];

        foreach ($named as $name => $value) {
            if (isset(self::DROPPED[$name])) {
                $this->lossy[] = $path;

                continue;
            }

            if (! isset(self::ARGUMENTS[$name])) {
                // An argument Flux has grown that this table has not. Refusing is
                // the honest answer: the call may well be doing something the
                // rewrite would quietly lose.
                $this->skipped[] = $path;

                return null;
            }

            if ($name === 'variant') {
                $value = $this->variant($value, $path);

                if ($value === null) {
                    return null;
                }
            }

            $rewritten[] = sprintf('%s: %s', self::ARGUMENTS[$name], $value);
        }

        return sprintf(
            '$this->dispatch(\'%s\'%s)',
            self::EVENT,
            $rewritten === [] ? '' : ', '.implode(', ', $rewritten),
        );
    }

    /**
     * Translate a Flux variant to a Sheaf type, keeping the original quoting.
     *
     * Only a plain string literal is translated. A variable or a match expression
     * could hold `danger`, and rewriting the name it is compared against is not
     * something a text rewrite can do, so the whole call is refused instead.
     */
    private function variant(string $value, string $path): ?string
    {
        if (preg_match('/^([\'"])([a-z]+)\1$/', $value, $matches) !== 1) {
            $this->skipped[] = $path;

            return null;
        }

        $type = self::VARIANTS[$matches[2]] ?? null;

        if ($type === null) {
            $this->skipped[] = $path;

            return null;
        }

        return $matches[1].$type.$matches[1];
    }

    /**
     * Drop `use Flux\Flux;` once the file has stopped naming the class.
     *
     * Cosmetic while `livewire/flux` is still installed, and load-bearing the
     * moment the user runs the `composer remove` the report tells them to.
     */
    private function dropImport(string $source): string
    {
        if (preg_match('/\bFlux::/', $source) === 1) {
            return $source;
        }

        return (string) preg_replace('/^[ \t]*use\s+Flux\\\\Flux\s*;[ \t]*\R?/m', '', $source);
    }

    /**
     * Where the matching `)` for the `(` at `$open` is, or null if unbalanced.
     *
     * Hand-scanned rather than matched, because the kit's own calls nest both
     * parentheses and brackets — `__('You left ":name"', ['name' => $team->name])`
     * — and a quoted `)` inside a translation key is a matter of time.
     */
    private function closingParenthesis(string $source, int $open): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $character = $source[$i];

            if ($character === '\'' || $character === '"') {
                $i = $this->endOfString($source, $i);

                if ($i === null) {
                    return null;
                }

                continue;
            }

            if ($character === '(' || $character === '[') {
                $depth++;

                continue;
            }

            if ($character === ')' || $character === ']') {
                $depth--;

                if ($depth === 0) {
                    return $character === ')' ? $i : null;
                }
            }
        }

        return null;
    }

    /**
     * The index of the quote closing the string opened at `$start`.
     */
    private function endOfString(string $source, int $start): ?int
    {
        $quote = $source[$start];
        $length = strlen($source);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }

            if ($source[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Split an argument list on its top-level commas.
     *
     * @return list<string>
     */
    private function split(string $arguments): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($arguments);

        for ($i = 0; $i < $length; $i++) {
            $character = $arguments[$i];

            if ($character === '\'' || $character === '"') {
                $end = $this->endOfString($arguments, $i);

                if ($end === null) {
                    $current .= substr($arguments, $i);

                    break;
                }

                $current .= substr($arguments, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($character === '(' || $character === '[') {
                $depth++;
            }

            if ($character === ')' || $character === ']') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Everything the sweep could not do, once, rather than once per file.
     */
    private function report(Project $project, Report $report): void
    {
        foreach ($this->stragglers($project) as $path) {
            $this->unreachable[] = $path;
        }

        if ($this->lossy !== []) {
            $report->note(sprintf(
                'Rewrote a toast that named a heading, link or position in %s. %s.',
                $this->join($this->lossy),
                implode('; ', self::DROPPED),
            ));
        }

        if ($this->skipped !== []) {
            $report->warn(sprintf(
                'Left a Flux::toast() alone in %s — refit could not read its arguments with confidence. '
                .'Rewrite it by hand as $this->dispatch(\'%s\', type: ..., content: ...).',
                $this->join($this->skipped),
                self::EVENT,
            ));
        }

        if ($this->unreachable !== []) {
            $report->warn(sprintf(
                'Found a Flux::toast() outside a Livewire component in %s. There is no $this to dispatch from there — '
                .'raise it with session()->flash(\'%s\', [\'type\' => ..., \'content\' => ...]) instead.',
                $this->join($this->unreachable),
                self::EVENT,
            ));
        }
    }

    /**
     * `Flux::toast()` calls in application PHP this sweep never looks at.
     *
     * Read at report time rather than rewritten: a controller has no `$this`
     * that dispatches, so the honest thing is to say where it is.
     *
     * @return list<string>
     */
    private function stragglers(Project $project): array
    {
        $root = $project->path('app');

        if (! is_dir($root)) {
            return [];
        }

        $paths = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));

            if (str_starts_with($path, 'app/Livewire/')) {
                continue;
            }

            if (str_contains($project->get($path), 'Flux::toast')) {
                $paths[] = $path;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param  list<string>  $paths
     */
    private function join(array $paths): string
    {
        return implode(', ', array_values(array_unique($paths)));
    }
}
