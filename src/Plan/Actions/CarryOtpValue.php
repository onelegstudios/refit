<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Carry an OTP's value into the form it is posted from.
 *
 * Flux's `<flux:otp name="code">` renders a `<ui-otp>` custom element that keeps
 * a single hidden input named `code` holding the joined digits, so an ordinary
 * `<form method="POST">` submits the whole code. Sheaf's `<x-ui.otp>` has no such
 * input. Its `name` is a declared prop that `otp.input` reads back with `@aware`,
 * so the name lands on *every* digit box instead — six inputs called `code`, of
 * which PHP keeps the last. The two-factor challenge therefore posts a single
 * digit, and Fortify answers every login with "The provided two factor
 * authentication code was invalid."
 *
 * Sheaf's own answer is `wire:model`: the component entangles the property and
 * Livewire carries it, which is what the kit's two-factor *setup* modal does and
 * why that page survives the rename. The challenge page is not Livewire — it is a
 * plain POST to `two-factor.login.store`, with the digits held in Alpine through
 * `x-model` — and there Sheaf leaves nothing for the browser to submit.
 *
 * So the name comes off the tag, and the Alpine value the component already
 * writes back is posted through a hidden input of refit's own:
 *
 * ```blade
 * <x-ui.otp x-model="code" length="6" class="mx-auto" />
 * <input type="hidden" name="code" x-bind:value="code" />
 * ```
 *
 * Taking `name` off is half the fix rather than a tidy-up: left on, its six
 * namesakes outrank the hidden input and the last digit box is still what posts.
 *
 * The second half is that Sheaf's digit boxes are unconditionally `required`,
 * which Flux's were not. The challenge page keeps its recovery-code form in the
 * same `<form>`, behind `x-show`, and a required control that is `display: none`
 * is still submitted against — the browser refuses the submit outright with "an
 * invalid form control is not focusable" and the button does nothing at all. The
 * page already guards its own recovery input with `x-bind:required`, but nothing
 * outside Sheaf can reach the boxes Sheaf generates, so the OTP is wrapped in the
 * one element that disables its descendants from above:
 *
 * ```blade
 * <fieldset class="contents" x-bind:disabled="showRecoveryInput">
 * ```
 *
 * which does both jobs at once — a disabled fieldset is exempt from validation
 * *and* submits nothing, so the recovery post carries `recovery_code` alone.
 */
final class CarryOtpValue extends BladeSweep
{
    private const string OTP = 'x-ui.otp';

    private const string INDENT = '    ';

    /**
     * Elements a page hides an alternative view behind.
     *
     * Only `x-show` matters here: `x-if` renders a `<template>`, and a control
     * that is not in the document is not validated or submitted either way.
     *
     * @var list<string>
     */
    private const array WRAPPERS = ['div', 'section'];

    /**
     * OTPs that had a name to post under but no value to post, for one warning at
     * the end rather than one per file.
     *
     * @var list<string>
     */
    private array $left = [];

    public function __construct(
        private readonly TagParser $parser = new TagParser,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return 'carry  the OTP value into the form Sheaf leaves it out of';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are where the prop is declared, not a place
        // where the value has gone missing.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $tags = $this->parser->parse($source, self::OTP);

        if ($tags === []) {
            return $source;
        }

        $edits = new Edits;
        $forms = $this->nesting->elements($source, ['form']);
        $wrappers = $this->nesting->elements($source, self::WRAPPERS);

        foreach ($tags as $tag) {
            // The prefix also matches `x-ui.otp.input`, which is a digit box
            // rather than the component that generates them.
            if ($tag->name !== self::OTP) {
                continue;
            }

            // Livewire's own binding is what Sheaf is built around: it entangles
            // the property, posts through the component rather than the form, and
            // names the boxes after the binding on purpose.
            if ($this->entangled($tag)) {
                continue;
            }

            $name = $tag->attribute('name') ?? $tag->attribute(':name');

            if (! $name instanceof Attribute || ($name->value ?? '') === '') {
                continue;
            }

            // A slot means the developer wrote the digit boxes themselves, and
            // named them themselves — there is nothing here refit put wrong.
            if (! $tag->selfClosing || ! $this->inside($forms, $tag)) {
                continue;
            }

            $model = $tag->attribute('x-model');

            if (! $model instanceof Attribute || ($model->value ?? '') === '') {
                $this->left[] = sprintf('%s: name="%s"', $path, $name->value);

                continue;
            }

            $this->carry($edits, $source, $tag, $name, $model, $this->hiddenBehind($wrappers, $tag));
        }

        return $edits->apply($source);
    }

    protected function finish(Report $report): void
    {
        if ($this->left === []) {
            return;
        }

        $report->warn(sprintf(
            'These OTPs post a form but hold their value in neither wire:model nor x-model, so there '
            .'was nothing to submit under the name they were given, and Sheaf submits none of its own — %s.',
            implode(', ', $this->left),
        ));

        $this->left = [];
    }

    /**
     * Is this OTP's value Livewire's to carry?
     */
    private function entangled(Tag $tag): bool
    {
        foreach ($tag->attributes as $attribute) {
            // `wire:model.live` and `wire:model.blur` bind the same property the
            // bare spelling does; the modifier is about when, not what.
            if (str_starts_with($attribute->name, 'wire:model')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Element>  $elements
     */
    private function inside(array $elements, Tag $tag): bool
    {
        foreach ($elements as $element) {
            if ($element->holds($tag->offset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `x-show` expression of the innermost wrapper this OTP is hidden behind,
     * or null when nothing hides it.
     *
     * @param  list<Element>  $wrappers
     */
    private function hiddenBehind(array $wrappers, Tag $tag): ?string
    {
        $expression = null;
        $innermost = -1;

        foreach ($wrappers as $wrapper) {
            if (! $wrapper->holds($tag->offset) || $wrapper->contentStart() <= $innermost) {
                continue;
            }

            $show = $wrapper->open->attribute('x-show');

            if (! $show instanceof Attribute || ($show->value ?? '') === '') {
                continue;
            }

            $expression = $show->value;
            $innermost = $wrapper->contentStart();
        }

        return $expression;
    }

    /**
     * Rewrite one OTP: the name off the tag, the value into a hidden input, and
     * both inside a fieldset when the page hides them.
     */
    private function carry(Edits $edits, string $source, Tag $tag, Attribute $name, Attribute $model, ?string $shown): void
    {
        $indent = $this->indent($source, $tag->offset);
        $control = $this->without(
            substr($source, $tag->offset, $tag->length),
            $tag->offset,
            [$name],
        );

        $carrier = $this->carrier($name, $model);

        if ($shown === null) {
            $edits->replace($tag->offset, $tag->length, $control."\n".$indent.$carrier);

            return;
        }

        $inner = $indent.self::INDENT;

        $edits->replace($tag->offset, $tag->length, sprintf(
            "<fieldset class=\"contents\" x-bind:disabled=\"%s\">\n%s%s\n%s%s\n%s</fieldset>",
            $this->negate($shown),
            $inner,
            // The control keeps the shape it was written in, one level further in.
            str_replace("\n", "\n".self::INDENT, $control),
            $inner,
            $carrier,
            $indent,
        ));
    }

    /**
     * The hidden input, named exactly as the OTP was named.
     */
    private function carrier(Attribute $name, Attribute $model): string
    {
        $quote = $name->quote === '' ? '"' : $name->quote;

        return sprintf(
            '<input type="hidden" %sname=%s%s%s x-bind:value="%s" />',
            $name->isBound() ? ':' : '',
            $quote,
            $name->value,
            $quote,
            $model->value,
        );
    }

    /**
     * When the wrapper is shown, the fieldset is enabled — so the one is the
     * other's negation.
     *
     * A wrapper written as the negation of a plain name is unwrapped rather than
     * double-negated, because `!(!showRecoveryInput)` is the same condition
     * spelled to be unreadable, and this one is read by whoever maintains the page
     * next.
     */
    private function negate(string $expression): string
    {
        $expression = trim($expression);

        return preg_match('/^!\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*)$/', $expression, $matches) === 1
            ? $matches[1]
            : '!('.$expression.')';
    }
}
