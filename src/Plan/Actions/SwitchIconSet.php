<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagRewriter;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Move every icon name onto another set, and mark it as belonging to that set.
 *
 * Sheaf's icon component picks its provider from the name: a bare name is a
 * Heroicon, `ps:` is Phosphor, `bk:` is the Blade Icons ecosystem. So the prefix
 * is half the job — the other half is the name itself, which the two sets rarely
 * spell the same. `finger-print` prefixed but not translated is
 * `phosphor.icons::regular.finger-print`, a component that does not exist, and
 * the page 500s rather than losing an icon.
 *
 * A name the table has no entry for is left exactly as it was, so it keeps being
 * drawn by the set it already names, and is reported once with the files it was
 * left in. Mixed sets on one page look inconsistent; a missing component takes
 * the page down.
 *
 * Unlike its sibling {@see RewriteIconNames} this runs against a tree refit has
 * not seen yet — Sheaf's own components arrive during the same run, in an
 * earlier stage — so the untranslated names can only be collected while the
 * sweep walks, not counted up while the plan is built.
 */
final class SwitchIconSet extends BladeSweep
{
    /**
     * Names with no entry in the table, mapped to the files they were left in.
     *
     * @var array<string, list<string>>
     */
    private array $untranslated = [];

    /**
     * @param  string  $prefix  What the target set's names are marked with, `ps:`.
     * @param  array<string, string>  $map  Current name mapped to the same icon in the target set.
     * @param  string  $set  The target set's name, for the plan preview and the report.
     */
    public function __construct(
        private readonly string $prefix,
        private readonly array $map,
        private readonly string $set,
        private readonly Vocabulary $vocabulary,
        private readonly TagRewriter $rewriter = new TagRewriter,
    ) {}

    public function describe(): string
    {
        return sprintf(
            'icons  rename every name to %s and prefix it with "%s" (%d name%s)',
            $this->set,
            $this->prefix,
            count($this->map),
            count($this->map) === 1 ? '' : 's',
        );
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return $this->rewriter->rewriteAttributeValues(
            $source,
            $this->vocabulary->prefix,
            $this->vocabulary->candidateAttributes(),
            function (Tag $tag, Attribute $attribute, string $value) use ($path): ?string {
                if (! $this->vocabulary->namesAnIcon($tag->name, $attribute->name)) {
                    return null;
                }

                if ($value === '') {
                    return null;
                }

                // A name a component takes from its caller, `name="{{ $icon }}"`.
                // The caller's own attribute is the one carrying a name, and this
                // sweep rewrites that; gluing a prefix onto the interpolation
                // would prefix it a second time.
                if (str_contains($value, '{')) {
                    return null;
                }

                // Already spoken for by a set, this one included, which is what
                // makes a second pass over the same tree change nothing.
                if (str_contains($value, ':')) {
                    return null;
                }

                $translated = $this->map[$value] ?? null;

                if ($translated === null) {
                    $this->untranslated[$value][] = $path;
                    $this->untranslated[$value] = array_values(array_unique($this->untranslated[$value]));

                    return null;
                }

                return $this->prefix.$translated;
            },
        );
    }

    protected function finish(Report $report): void
    {
        ksort($this->untranslated);

        foreach ($this->untranslated as $name => $paths) {
            $report->warn(sprintf(
                'No %s translation for "%s" — still Heroicons in %s.',
                $this->set,
                $name,
                implode(', ', $paths),
            ));
        }
    }
}
