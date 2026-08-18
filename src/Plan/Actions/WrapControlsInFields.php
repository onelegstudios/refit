<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Give the controls Sheaf will not label or error a field to do both in.
 *
 * Flux's input draws its own label and its own validation message:
 * `<flux:input name="email" :label="__('Email address')" />` is the label, the
 * control, the spacing and the error in one tag. Sheaf splits those apart —
 * `x-ui.field` is the wrapper, `x-ui.label` the text, `x-ui.error` the message,
 * `x-ui.input` only ever the control — so the rename leaves a `label` on a
 * component that has no prop for it and an error the page has stopped rendering
 * at all. Which is worse than it sounds, because nothing complains: `input` and
 * `otp` pass the label through to the wrapper div, where it becomes an HTML
 * attribute nobody renders, and `select` declares the prop and then never writes
 * it out. Both simply stop appearing. In the plainest kit that is twenty controls
 * across eleven files — every field of login, register, both password pages, the
 * profile and security pages — arriving as unlabelled boxes that reject you
 * without saying why.
 *
 * So the label comes off the tag and both are written around it, in the field
 * Sheaf expects to find all three in:
 *
 * ```blade
 * <x-ui.field>
 *     <x-ui.label :text="__('Email address')" />
 *     <x-ui.input name="email" type="email" required />
 *     <x-ui.error name="email" />
 * </x-ui.field>
 * ```
 *
 * The label's value moves verbatim, quotes and all, so `__()` stays a call and a
 * `{{ }}` stays an echo. Flux's `label:sr-only` — how the two-factor pages hide
 * the label on their code field — becomes `class="sr-only"` on the label, because
 * hiding it from sight while leaving it to a screen reader was the point.
 *
 * The error is keyed the way Flux keyed it: `name` when the control has one, the
 * Livewire property otherwise, and no error at all when it has neither, because a
 * message no bag can be asked for is worse than none. The kit's one unlabelled
 * input — the recovery code, which this sweep never touches — writes its own
 * `@error` block already, so nothing is doubled up.
 *
 * A control already inside a field gets the label alone: a project that has
 * written its own field has said how it wants errors shown, and a second wrapper
 * would only double the spacing.
 */
final class WrapControlsInFields extends BladeSweep
{
    /**
     * The Sheaf controls that render no label of their own.
     *
     * Hand-kept, like {@see ComponentMap}, because the manifest records tag names
     * rather than props. The rest of the kit's controls are absent deliberately:
     * `checkbox` renders its label through `checkbox.label`, and `radio.item` and
     * the nav items are {@see PromoteContentsToLabel}'s business.
     *
     * @var list<string>
     */
    private const array TARGETS = ['x-ui.input', 'x-ui.otp', 'x-ui.select'];

    private const string FIELD = 'x-ui.field';

    private const string LABEL = 'x-ui.label';

    private const string ERROR = 'x-ui.error';

    /** Flux's modifier for a label only a screen reader gets to read. */
    private const string SCREEN_READER = 'label:sr-only';

    private const string INDENT = '    ';

    /**
     * Controls whose label could not be moved, for one warning at the end rather
     * than one per file.
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
        return 'wrap   the labelled controls in the field Sheaf labels and errors in';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are where these props are declared, not a place
        // where one is missing.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $edits = new Edits;
        $fields = $this->nesting->elements($source, [self::FIELD]);

        foreach (self::TARGETS as $target) {
            foreach ($this->parser->parse($source, $target) as $tag) {
                if ($tag->name !== $target) {
                    continue;
                }

                $label = $tag->attribute('label') ?? $tag->attribute(':label');

                if (! $label instanceof Attribute || $label->value === null) {
                    continue;
                }

                $end = $this->ends($source, $tag);

                if ($end === null) {
                    $this->left[] = sprintf('%s: <%s>', $path, $tag->name);

                    continue;
                }

                $this->lift($edits, $source, $tag, $label, $end, $this->isInField($fields, $tag));
            }
        }

        return $edits->apply($source);
    }

    protected function finish(Report $report): void
    {
        if ($this->left === []) {
            return;
        }

        $report->warn(sprintf(
            'Could not find where these controls end, so their labels were left on the tag, '
            .'where Sheaf will not render them — %s.',
            implode(', ', $this->left),
        ));

        $this->left = [];
    }

    /**
     * Where the control's markup stops.
     *
     * The kit self-closes its inputs, but a select holds its options, so the
     * field has to go around the closing tag as well. An opening tag with no
     * closing tag describes no span at all, and is left alone.
     */
    private function ends(string $source, Tag $tag): ?int
    {
        if ($tag->selfClosing) {
            return $tag->offset + $tag->length;
        }

        foreach ($this->nesting->elements($source, [$tag->name]) as $element) {
            if ($element->open->offset !== $tag->offset) {
                continue;
            }

            $closes = strpos($source, '>', $element->closes);

            return $closes === false ? null : $closes + 1;
        }

        return null;
    }

    /**
     * @param  list<Element>  $fields
     */
    private function isInField(array $fields, Tag $tag): bool
    {
        foreach ($fields as $field) {
            if ($field->holds($tag->offset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move one control's label out of its tag and into a label of its own.
     */
    private function lift(Edits $edits, string $source, Tag $tag, Attribute $label, int $end, bool $inField): void
    {
        $indent = $this->indent($source, $tag->offset);
        $hidden = $tag->attribute(self::SCREEN_READER);

        $moving = [$label];

        if ($hidden instanceof Attribute) {
            $moving[] = $hidden;
        }

        $control = $this->without(
            substr($source, $tag->offset, $end - $tag->offset),
            $tag->offset,
            $moving,
        );

        $labelled = $this->label($label, $hidden instanceof Attribute);

        if ($inField) {
            $edits->replace($tag->offset, $end - $tag->offset, $labelled."\n".$indent.$control);

            return;
        }

        $inner = $indent.self::INDENT;
        $error = $this->error($tag);

        $edits->replace($tag->offset, $end - $tag->offset, sprintf(
            "<%s>\n%s%s\n%s%s\n%s%s</%s>",
            self::FIELD,
            $inner,
            $labelled,
            $inner,
            // The control keeps the shape it was written in, one level further in.
            str_replace("\n", "\n".self::INDENT, $control),
            $error === null ? '' : $inner.$error."\n",
            $indent,
            self::FIELD,
        ));
    }

    /**
     * The label tag, spelling the value exactly as the control spelled it.
     */
    private function label(Attribute $label, bool $hidden): string
    {
        $quote = $label->quote === '' ? '"' : $label->quote;

        return sprintf(
            '<%s %s%stext=%s%s%s />',
            self::LABEL,
            $hidden ? 'class="sr-only" ' : '',
            $label->isBound() ? ':' : '',
            $quote,
            $label->value,
            $quote,
        );
    }

    /**
     * The message Sheaf will render for this control, if it can be asked for one.
     *
     * Flux read the error off the control's `name`, falling back to whatever the
     * Livewire binding names — and a Livewire property path is the key its
     * messages come back under, so the two agree. A control bound only to Alpine
     * has no key on either side, and gets no error rather than an empty one.
     */
    private function error(Tag $tag): ?string
    {
        $name = $tag->attribute('name') ?? $tag->attribute(':name');

        if ($name instanceof Attribute && $name->value !== null && $name->value !== '') {
            $quote = $name->quote === '' ? '"' : $name->quote;

            return sprintf(
                '<%s %sname=%s%s%s />',
                self::ERROR,
                $name->isBound() ? ':' : '',
                $quote,
                $name->value,
                $quote,
            );
        }

        foreach ($tag->attributes as $attribute) {
            // `wire:model.live` and `wire:model.blur` bind the same property the
            // bare spelling does; the modifier is about when, not what.
            if (! str_starts_with($attribute->name, 'wire:model')) {
                continue;
            }

            if ($attribute->value !== null && $attribute->value !== '') {
                return sprintf('<%s name="%s" />', self::ERROR, $attribute->value);
            }
        }

        return null;
    }
}
