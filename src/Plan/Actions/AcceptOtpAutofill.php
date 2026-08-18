<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Let a password manager fill Sheaf's OTP the way it filled Flux's.
 *
 * This is the one place refit edits Sheaf's own source rather than the kit's, and
 * it is deliberate: `sheaf:install` copies these files into the project, so they
 * are the project's to fix, and nothing on the page can reach the behaviour from
 * outside. It is a stopgap until the fix lands upstream — a later `sheaf:install`
 * overwrites it, and every edit is anchored on the lines it is replacing, so a
 * version that has moved on is reported rather than mangled.
 *
 * Flux's `<ui-otp>` and Sheaf's `x-ui.otp` disagree on the three things that
 * decide whether autofill works, and Sheaf takes the losing side of each:
 *
 * - **Which box claims the code.** Flux puts `autocomplete="one-time-code"` on the
 *   first input and `off` on the rest. Sheaf puts it on all six, so there is no
 *   single field for a password manager to aim at.
 * - **What a multi-character value means.** Flux distributes it across the boxes.
 *   Sheaf keeps the last character and drops the rest — and since a password
 *   manager sets `.value` and dispatches `input` rather than `paste`, Sheaf's
 *   `handlePaste`, its only code that spreads a code out, never runs. A filled
 *   `123456` becomes `6`.
 * - **How the caret is held.** Flux uses `tabindex`. Sheaf disables every box
 *   ahead of the caret, and a disabled input is one an extension cannot write to
 *   at all — so a fill that goes box by box stops after the first.
 *
 * The three together are why 1Password fills one digit and then appears to lock
 * the keyboard: `handleInput` enables and focuses the next box a frame later,
 * `x-on:focus` re-selects it a frame after that, and the page goes on stealing
 * focus every frame while the extension is still trying to drive the field.
 */
final class AcceptOtpAutofill implements Action
{
    private const string DIRECTORY = SheafLibrary::COMPONENT_DIRECTORY.'/otp';

    private const string COMPONENT = self::DIRECTORY.'/index.blade.php';

    private const string BOX = self::DIRECTORY.'/input.blade.php';

    public function describe(): string
    {
        return 'patch  Sheaf\'s OTP so a password manager can fill it in one go';
    }

    /**
     * Does this project write an OTP at all?
     *
     * Read before the rename, so the tag is still Flux's. A kit built without
     * two-factor never installs the component and has nothing to patch.
     */
    public static function used(Project $project): bool
    {
        foreach ($project->blades() as $path) {
            if (str_contains($project->get($path), '<flux:otp')) {
                return true;
            }
        }

        return false;
    }

    public function apply(Project $project, Report $report): void
    {
        $missed = [];

        foreach (self::patches() as $path => $patches) {
            if (! $project->exists($path)) {
                $report->warn(sprintf(
                    'Skipped the OTP autofill patch — %s is not there to patch, so a password '
                    .'manager will fill one digit of the code and stop.',
                    $path,
                ));

                continue;
            }

            $source = $project->get($path);
            $patched = $source;

            foreach ($patches as $label => [$find, $replace]) {
                $spliced = self::splice($patched, $find, $replace);

                if ($spliced === null) {
                    $missed[] = $label;

                    continue;
                }

                $patched = $spliced;
            }

            if ($patched === $source) {
                continue;
            }

            file_put_contents($project->path($path), $patched);

            $report->changed($path);
        }

        if ($missed === []) {
            return;
        }

        $report->warn(sprintf(
            'Sheaf\'s OTP component has moved on from the source refit knows how to patch, so '
            .'autofill was left as it is for: %s. Check whether Sheaf has fixed this upstream — '
            .'https://github.com/sheafui/components.',
            implode(', ', $missed),
        ));
    }

    /**
     * Replace a run of lines, matched on what they say rather than how far in they
     * are written.
     *
     * Sheaf's source is indented inside an `x-data` attribute, four and five levels
     * deep, and an anchor that carried that indentation would be unreadable here
     * and would miss on any reformatting there. So the match is per line and
     * trimmed, and the replacement is re-indented onto whatever the matched block
     * was written at, keeping its own shape underneath.
     *
     * Null when the run is not found, which is the signal to report rather than
     * half-apply.
     */
    private static function splice(string $source, string $find, string $replace): ?string
    {
        $needle = self::lines($find);
        $lines = explode("\n", $source);
        $depth = count($needle);

        if ($depth === 0) {
            return null;
        }

        foreach (array_keys($lines) as $start) {
            if (! self::matches($lines, $needle, $start)) {
                continue;
            }

            $indent = (string) (preg_match('/^\s*/', $lines[$start], $matches) === 1 ? $matches[0] : '');

            array_splice($lines, $start, $depth, self::reindent($replace, $indent));

            return implode("\n", $lines);
        }

        return null;
    }

    /**
     * Does the needle sit at this offset, ignoring indentation?
     *
     * @param  list<string>  $lines
     * @param  list<string>  $needle
     */
    private static function matches(array $lines, array $needle, int $start): bool
    {
        foreach ($needle as $offset => $line) {
            if (! isset($lines[$start + $offset]) || trim($lines[$start + $offset]) !== $line) {
                return false;
            }
        }

        return true;
    }

    /**
     * A block as trimmed lines, without the blank ones a heredoc leaves at either
     * end.
     *
     * @return list<string>
     */
    private static function lines(string $block): array
    {
        return array_map(trim(...), explode("\n", trim($block, "\n")));
    }

    /**
     * The replacement, flattened to its own left edge and set down at the indent
     * the matched block was written at.
     *
     * @return list<string>
     */
    private static function reindent(string $replace, string $indent): array
    {
        $lines = explode("\n", trim($replace, "\n"));
        $common = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $width = strlen($line) - strlen(ltrim($line));
            $common = $common === null ? $width : min($common, $width);
        }

        return array_map(
            fn (string $line): string => trim($line) === '' ? '' : $indent.substr($line, $common ?? 0),
            $lines,
        );
    }

    /**
     * The anchored replacements, keyed by file and then by what each one is for.
     *
     * Anchors are whole statements rather than fragments, so a near miss is a miss
     * and gets reported instead of half-applying.
     *
     * @return array<string, array<string, array{string, string}>>
     */
    private static function patches(): array
    {
        return [
            self::COMPONENT => [
                'which box claims the code' => [
                    <<<'JS'
                    this._inputs.forEach((input, index) => {
                        input.setAttribute('data-order', index);
                        input.setAttribute('aria-label', `Digit ${index + 1} of ${this.length}`);
                    });
                    JS,
                    <<<'JS'
                    this._inputs.forEach((input, index) => {
                        input.setAttribute('data-order', index);
                        input.setAttribute('aria-label', `Digit ${index + 1} of ${this.length}`);
                        // Only the first box claims the code. Six of them all
                        // answering to `one-time-code` leaves a password manager
                        // with no single field to aim at.
                        input.setAttribute('autocomplete', index === 0 ? 'one-time-code' : 'off');
                    });
                    JS,
                ],
                'holding the caret' => [
                    <<<'JS'
                    this._inputs.forEach((input, index) => {
                        input.disabled = index >= enableCount;
                    });
                    JS,
                    <<<'JS'
                    this._inputs.forEach((input, index) => {
                        // Not `disabled`: a disabled input is one an extension
                        // cannot write to, which is what stopped the fill after
                        // the first digit. Tabbing is held to the boxes in play.
                        input.tabIndex = index >= enableCount ? -1 : 0;
                    });
                    JS,
                ],
                'a code filled into one box' => [
                    <<<'JS'
                    // Always keep last typed character (avoid multi-char paste in one box)
                    if (value.length > 1) {
                        value = value.slice(-1);
                        el.value = value;
                    }
                    JS,
                    <<<'JS'
                    // A password manager writes the whole code into one box and
                    // dispatches `input`, never `paste` — so this is the only place
                    // that sees it, and keeping one character of it loses the code.
                    if (value.length > 1) {
                        this.fillFrom(value, index);

                        return;
                    }
                    JS,
                ],
                'spreading a code across the boxes' => [
                    <<<'JS'
                    // Handle paste: distribute valid chars across remaining inputs
                    handlePaste(e) {
                    JS,
                    <<<'JS'
                    // Spread a multi-character value across the boxes from one of
                    // them on. Both a paste and a password manager's fill land here.
                    fillFrom(text, startIndex) {
                        const regex = new RegExp(`^${this.allowedPattern}$`);
                        const validChars = Array.from(text).filter(char => regex.test(char));

                        // Clear from the start position on, so a second fill does
                        // not leave the tail of the first behind.
                        for (let i = startIndex; i < this._inputs.length; i++) {
                            this._inputs[i].value = '';
                        }

                        validChars.slice(0, this.length - startIndex).forEach((char, offset) => {
                            this.enableAndFill(char, offset + startIndex);
                        });

                        $nextTick(() => {
                            this.$updateStateFromInputs();

                            const next = this._inputs[startIndex + validChars.length];

                            if (next) {
                                this.focusAndSelect(next);
                            } else {
                                const lastInput = this._inputs[this.length - 1];

                                if (lastInput) {
                                    requestAnimationFrame(() => {
                                        lastInput.focus();
                                        lastInput.select();
                                    });
                                }
                            }
                        });
                    },

                    // Handle paste: distribute valid chars across remaining inputs
                    handlePaste(e) {
                    JS,
                ],
                'clearing the boxes' => [
                    <<<'JS'
                    this._inputs.forEach(input => {
                        input.value = '';
                        input.disabled = true;
                    });

                    if (this._inputs[0]) this._inputs[0].disabled = false;
                    this._state = '';
                    JS,
                    <<<'JS'
                    this._inputs.forEach(input => {
                        input.value = '';
                    });

                    // Resetting the state is what puts the tab order back, now
                    // that nothing is disabled.
                    this._state = '';
                    JS,
                ],
                'clicking a box' => [
                    <<<'JS'
                    const clickedInput = e.target.closest('[data-slot=otp-input]');

                    // If clicked directly on an active input
                    if (clickedInput && !clickedInput.disabled) {
                        this.focusAndSelect(clickedInput);
                        return;
                    }

                    // Otherwise, find the best input to focus next
                    const firstEmpty = this._inputs.find(input => !input.value && !input.disabled);

                    if (firstEmpty) {
                        this.focusAndSelect(firstEmpty);
                    } else {
                        // All filled: focus last for easy editing
                        const lastInput = this._inputs[this.length - 1];
                        if (lastInput && !lastInput.disabled) {
                            this.focusAndSelect(lastInput);
                        }
                    }
                    JS,
                    <<<'JS'
                    // Clamped to the boxes in play rather than gated on `disabled`,
                    // which nothing sets any more: a click past the caret lands on
                    // the first box still waiting for a digit.
                    const clickedInput = e.target.closest('[data-slot=otp-input]');
                    const furthest = Math.min(this._state.length, this.length - 1);
                    const order = clickedInput ? parseInt(clickedInput.dataset.order) : furthest;

                    this.focusAndSelect(this._inputs[Math.min(order, furthest)]);
                    JS,
                ],
            ],
            self::BOX => [
                'the boxes that do not claim the code' => [
                    'autocomplete="one-time-code"',
                    <<<'BLADE'
                    {{-- setupInputs() gives this to the first box alone. --}}
                    autocomplete="off"
                    BLADE,
                ],
            ],
        ];
    }
}
