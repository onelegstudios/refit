<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Blade\TagRewriter;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Rewrite every Flux component tag onto its Sheaf equivalent.
 *
 * Four rewrites, in an order that matters. The dotted icon form is folded down
 * first, while the tags still say `flux:icon.*` and the suffix is still there to
 * read. Tag names go next. Attribute names and values come last, over the
 * already-renamed tree — attributes are matched by name alone, and the value
 * table looks its tag back up through the map, so neither needs the Flux name to
 * still be on the tag.
 *
 * Anything the map has no answer for is left exactly as it was and reported. A
 * half-rewritten tag would be worse than an untouched one, and the diff is where
 * the user sees the rest.
 */
final class MapComponentTags extends BladeSweep
{
    private const string FLUX_PREFIX = 'flux:';

    private const string SHEAF_ICON = 'x-ui.icon';

    private const string SHEAF_ICON_ATTRIBUTE = 'name';

    /**
     * Flux tag mapped to the files still writing it, collected as the sweep runs
     * so one untranslatable tag is one warning rather than one per view.
     *
     * @var array<string, list<string>>
     */
    private array $unmapped = [];

    public function __construct(
        private readonly TagRewriter $rewriter = new TagRewriter,
        private readonly TagParser $parser = new TagParser,
    ) {}

    public function describe(): string
    {
        return sprintf('map    Flux components to Sheaf (%d tags)', count(ComponentMap::TAGS));
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $this->collectUnmapped($source, $path);

        // `<flux:icon.home />` -> `<x-ui.icon name="home" />`. Suffixes that have
        // a component of their own — `icon.loading` — are left for the tag pass.
        $source = $this->rewriter->collapseNameSuffix(
            $source,
            self::FLUX_PREFIX.'icon.',
            self::SHEAF_ICON,
            self::SHEAF_ICON_ATTRIBUTE,
            static fn (string $suffix): ?string => ComponentMap::tag(self::FLUX_PREFIX.'icon.'.$suffix) === null
                ? $suffix
                : null,
        );

        $source = $this->rewriter->renameTags(
            $source,
            self::FLUX_PREFIX,
            static fn (string $name): ?string => ComponentMap::tag($name),
        );

        $source = $this->rewriter->renameAttributes(
            $source,
            'x-ui.',
            static function (Tag $_, Attribute $attribute): ?string {
                // Bound attributes keep their colon: `:icon-trailing` names the
                // same prop as `icon-trailing`, and only the value differs.
                $bound = str_starts_with($attribute->name, ':');
                $bare = $bound ? substr($attribute->name, 1) : $attribute->name;

                $to = ComponentMap::attribute($bare);

                return $to === null ? null : ($bound ? ':'.$to : $to);
            },
        );

        return $this->rewriteValues($source);
    }

    /**
     * Translate the attribute values Sheaf spells differently.
     *
     * The table is keyed by the Flux tag, but this runs after the rename, so the
     * lookup has to go back through the map to find which Flux tag a Sheaf tag
     * came from.
     */
    private function rewriteValues(string $source): string
    {
        $flux = array_flip(ComponentMap::TAGS);

        return $this->rewriter->rewriteAttributeValues(
            $source,
            'x-ui.',
            $this->valueAttributes(),
            static function (Tag $tag, Attribute $attribute, string $value) use ($flux): ?string {
                $origin = $flux[$tag->name] ?? null;

                return $origin === null
                    ? null
                    : ComponentMap::value($origin, $attribute->name, $value);
            },
        );
    }

    /**
     * Every attribute name the value table has an opinion about.
     *
     * @return list<string>
     */
    private function valueAttributes(): array
    {
        $names = [];

        foreach (ComponentMap::VALUES as $attributes) {
            foreach (array_keys($attributes) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Note the Flux tags in one file that this sweep is about to leave behind.
     */
    private function collectUnmapped(string $source, string $path): void
    {
        foreach ($this->parser->parse($source, self::FLUX_PREFIX) as $tag) {
            if (ComponentMap::tag($tag->name) !== null) {
                continue;
            }

            // The dotted icon form is handled by the collapse, not the table.
            if (str_starts_with($tag->name, self::FLUX_PREFIX.'icon.')) {
                continue;
            }

            $this->unmapped[$tag->name] ??= [];

            if (! in_array($path, $this->unmapped[$tag->name], true)) {
                $this->unmapped[$tag->name][] = $path;
            }
        }
    }

    /**
     * One warning per untranslatable tag, naming the files that still write it.
     *
     * Files matter here: "refit has no Sheaf equivalent for flux:profile" is only
     * actionable once you know which view to go and look at.
     */
    protected function finish(Report $report): void
    {
        ksort($this->unmapped);

        foreach ($this->unmapped as $tag => $paths) {
            $report->warn(sprintf(
                'Left <%s> alone in %s — %s',
                $tag,
                implode(', ', $paths),
                ComponentMap::whyUnmapped($tag) ?? 'refit has no Sheaf equivalent for it.',
            ));
        }

        $this->unmapped = [];
    }
}
