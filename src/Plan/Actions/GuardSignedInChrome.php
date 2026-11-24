<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Put the parts of an application layout that need a signed-in user behind
 * `@auth`, so the shell renders for a guest as well.
 *
 * The kit's application shell dereferences the user without asking whether there
 * is one — `auth()->user()->name`, `auth()->user()->initials()`, a log out form —
 * so the layout every signed-in page names is a layout no public page can use.
 * Reach for it from a marketing page, a pricing page or a shared link and the
 * view fails on a null user rather than rendering without one.
 *
 * What has to be guarded is read out of the markup rather than listed here. A
 * piece of chrome needs a user when its own source dereferences one, or when
 * something it renders does: `<x-desktop-user-menu />` carries no `auth()` call
 * of its own, and the component behind it is nothing but one. So the sweep
 * follows a tag to the files that decide what it renders — an anonymous
 * component's view, a single-file Livewire component, a class component's view
 * and its class — and reads those too.
 *
 * A find is an element that asks for the user *itself* rather than on a
 * descendant's behalf, which is the same reading run backwards: blank out the
 * children that need a user, and see whether what is left still does. The
 * sidebar holds the user menu and so reads as needing one, and this is what
 * stops it from being treated as a piece of signed-in chrome.
 *
 * Then it grows each find outwards, which is the whole difficulty. The smallest
 * guard is useless: `@auth` around the avatar inside a dropdown leaves the
 * dropdown itself, and a guest gets an empty menu. The largest is worse: the
 * sidebar holds the user menu, so guarding the sidebar hides the navigation with
 * it. So a find grows into its parent only while *everything* in that parent
 * depends on the user, counting whitespace and comments as nothing. The kit's
 * mobile menu grows from the profile to the `<flux:dropdown>` around it and stops
 * at the `<flux:header>`, which also holds the sidebar toggle a guest still
 * needs. The sidebar's `<x-desktop-user-menu />` never grows at all.
 *
 * Where the guarded piece is the user menu — the one piece of chrome with a log
 * out in it — a log in link takes its place in the `@else`, written with the tag
 * of whichever library the project is ending on, and wearing the icon and the
 * label it was given. It is deliberately plain beyond that: the point is that a
 * guest has somewhere to go, not that refit has styled it.
 * Everything else, a team switcher among them, is guarded with nothing in its
 * place, because a guest has no counterpart for it.
 *
 * Only the application layouts are swept. Every other view holding
 * `auth()->user()` is behind an `auth` middleware already, and guarding those
 * would be answering a question nobody asked.
 */
final class GuardSignedInChrome extends BladeSweep
{
    /** Where a layout family's files live, flattened out of their folder or not. */
    private const string LAYOUTS = 'resources/views/layouts/';

    private const string ANONYMOUS = 'x-';

    private const string LIVEWIRE = 'livewire:';

    /** The installer's own prefix on a single-file Livewire component. */
    private const string SINGLE_FILE = '⚡';

    /**
     * The tag families worth following. Flux is here whichever library the
     * project ends on, because Flux is what the kit wrote the chrome in.
     *
     * @var list<string>
     */
    private const array TAGS = ['flux:', self::ANONYMOUS, self::LIVEWIRE];

    /**
     * Reading the signed-in user. Not `auth()->check()`, which is already the
     * question this sweep exists to ask.
     *
     * @var list<string>
     */
    private const array USER = ['auth()->user(', 'Auth::user(', 'auth()->id(', 'Auth::id('];

    /**
     * The one thing only a signed-in user can do, and so the tell for a piece of
     * chrome a guest wants a log in link instead of.
     *
     * @var list<string>
     */
    private const array SIGN_OUT = ["route('logout')", 'route("logout")'];

    /**
     * The `display` utilities, which do not come across onto the link.
     *
     * A guarded menu's class list decides where the link shows, so it comes with
     * it — but a display the kit set on the element being replaced is not the
     * link's to wear. A button is laid out as a flex row: `block` on it leaves
     * `items-center` and `justify-center` with nothing to centre, so the label
     * stops sitting in the middle of it and an icon stops sitting beside the
     * label. Nothing is needed in its place, because what decided the menu's
     * width is the sidebar it is a flex child of, and the link is one too.
     *
     * `hidden` is a display too, and deliberately not here: {@see self::HIDDEN}.
     *
     * @var list<string>
     */
    private const array DISPLAY = [
        'block',
        'flex',
        'grid',
        'flow-root',
        'inline',
        'inline-block',
        'inline-flex',
        'inline-grid',
        'contents',
    ];

    /** How a button is laid out, which is what a variant is left holding. */
    private const string BUTTON = 'inline-flex';

    /** How a box holding more than one way in is laid out. */
    private const string ROW = 'flex';

    /** What that box gives the ways in: a line of their own, and room between. */
    private const string SPACING = 'items-center gap-2';

    /**
     * What each way in takes of the box they share: an equal part of it.
     *
     * The box is as wide as whatever it stands in, and in the sidebar that is
     * the whole of it — so without this the two sit at the width of their own
     * labels with the rest of the line empty beside them.
     */
    private const string SHARE = 'flex-1';

    /**
     * Not a display this sweep writes, and the reason the others are rewritten
     * rather than dropped: `hidden` is what the kit hides chrome at a breakpoint
     * with, and it is the one class the carry-over exists for.
     */
    private const string HIDDEN = 'hidden';

    /**
     * The button variant for the one of the two ways in to press.
     *
     * The one variant name Flux and Sheaf share outright, so it survives a
     * target's rename pass untouched — {@see ComponentMap::VALUES} says why the
     * others do not. The quiet one beside it is not shared and so is asked of
     * the library instead: {@see Vocabulary::$quietVariant}.
     */
    private const string PROMINENT = 'primary';

    /**
     * The attribute a leading icon goes in.
     *
     * The one icon attribute Flux and Sheaf spell the same way, which is why it
     * is written here rather than asked of the library. They disagree about the
     * trailing one — `icon-trailing` against `iconAfter` — and nothing here
     * writes one: a way in reads icon first, the way the kit's own menu items
     * and sidebar items do.
     */
    private const string ICON = 'icon';

    /**
     * @var list<string>
     */
    private array $guarded = [];

    /**
     * @param  string  $family  the layout family to sweep — the delegating layout's filename, and the folder of variants beside it
     * @param  Vocabulary  $vocabulary  how the library this project is ending on spells its tags
     * @param  array<string, string>  $icons  route name mapped to the icon the chosen set spells it with, empty for a way in that wears none
     * @param  bool  $labels  whether a way in says what it is in words as well
     */
    public function __construct(
        private readonly string $family,
        private readonly Vocabulary $vocabulary,
        private readonly bool $registration = false,
        private readonly array $icons = [],
        private readonly bool $labels = true,
        private readonly TagParser $parser = new TagParser,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return sprintf('guard  the %s layout chrome that needs a signed-in user', $this->family);
    }

    /**
     * The icons the ways in will wear, for a plan that has to bring the artwork
     * for one of them.
     *
     * Public for the same reason {@see regions()} is: the task that built this
     * sweep has to answer a question about what it is going to write before a
     * line of it has been written.
     *
     * @return list<string>
     */
    public function icons(): array
    {
        return array_values($this->icons);
    }

    /**
     * The spans this sweep would put behind `@auth`, in source order.
     *
     * Public because the task has to answer `appliesTo()` with it: a project
     * whose shell is guarded already has nothing to be offered.
     *
     * @return list<array{start: int, end: int, class: string|null, signsOut: bool}>
     */
    public function regions(string $source, string $path, Project $project): array
    {
        if (! $this->isLayout($path)) {
            return [];
        }

        $nodes = $this->nodes($source);

        if ($nodes === []) {
            return [];
        }

        /** @var array<string, string> $seen */
        $seen = [];

        /** @var array<int, bool> $needs */
        $needs = [];

        /** @var array<int, bool> $signsOut */
        $signsOut = [];

        foreach ($nodes as $node) {
            $reach = $this->reach($this->span($source, $node), $project, $seen);

            $needs[$node->open->offset] = $this->mentions($reach, self::USER);
            $signsOut[$node->open->offset] = $this->mentions($reach, self::SIGN_OUT);
        }

        $grown = [];

        foreach ($nodes as $node) {
            if ($needs[$node->open->offset] !== true) {
                continue;
            }

            // An element that needs a user only because something inside it does
            // is not a find of its own. Leaving it out is what stops the sidebar
            // — which holds the user menu, and the navigation beside it — from
            // being read as a piece of signed-in chrome.
            $alone = $this->withoutDependents(
                $this->span($source, $node),
                $node->open->offset,
                $node,
                $nodes,
                $needs,
                $source,
            );

            if (! $this->mentions($this->reach($alone, $project, $seen), self::USER)) {
                continue;
            }

            $grown[] = $this->grow($node, $nodes, $needs, $source);
        }

        $regions = [];

        foreach ($this->outermost($grown) as $region) {
            if ($this->guarded($source, $region->open->offset)) {
                continue;
            }

            $class = $region->open->attribute('class');

            $regions[] = [
                'start' => $region->open->offset,
                'end' => $this->ends($source, $region),
                // A bound class is an expression rather than a list of
                // utilities, and carrying it onto a link is not its meaning.
                'class' => $class instanceof Attribute && ! $class->isBound() ? $class->value : null,
                'signsOut' => $signsOut[$region->open->offset] ?? false,
            ];
        }

        return $regions;
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $regions = $this->regions($source, $path, $project);

        if ($regions === []) {
            return $source;
        }

        $edits = new Edits;

        foreach ($regions as $region) {
            $this->guard($edits, $source, $region);
        }

        $this->guarded[] = $path;

        return $edits->apply($source);
    }

    protected function finish(Report $report): void
    {
        if ($this->guarded === []) {
            return;
        }

        $report->note(sprintf(
            'Guests can render %s now. Where a user menu was guarded, %s stands in its place — move it '
            .'or dress it to match the rest of your chrome.',
            implode(', ', $this->guarded),
            $this->registration ? 'a pair of log in and register links' : 'a log in link',
        ));
    }

    /**
     * Is this one of the layouts the family is built from?
     *
     * Read from the path as it stands when the sweep runs, so the folded
     * `layouts/app.blade.php` and the `layouts/app-header.blade.php` beside it
     * are the same family as the `layouts/app/sidebar.blade.php` they were.
     */
    private function isLayout(string $path): bool
    {
        if (! str_starts_with($path, self::LAYOUTS)) {
            return false;
        }

        $name = substr($path, strlen(self::LAYOUTS), -strlen('.blade.php'));

        return $name === $this->family
            || str_starts_with($name, $this->family.'/')
            || str_starts_with($name, $this->family.'-');
    }

    /**
     * Every component tag in the source, paired with its closing tag where it has
     * one, in source order.
     *
     * A self-closing tag is a node as much as a pair is — `<x-desktop-user-menu />`
     * is the entire user menu as far as the layout can tell — and stands as an
     * element enclosing nothing.
     *
     * Plain HTML is deliberately absent. It is never a candidate for a guard, and
     * leaving it out of the tree is what makes the growth test above honest: a
     * `<div>` of markup a guest needs reads as leftover text rather than as a
     * child that was checked.
     *
     * @return list<Element>
     */
    private function nodes(string $source): array
    {
        $prefixes = $this->prefixes();
        $nodes = $this->nesting->elements($source, $prefixes);

        foreach ($prefixes as $prefix) {
            foreach ($this->parser->parse($source, $prefix) as $tag) {
                if ($tag->selfClosing) {
                    $nodes[] = new Element($tag, $tag->offset + $tag->length);
                }
            }
        }

        usort($nodes, static fn (Element $a, Element $b): int => $a->open->offset <=> $b->open->offset);

        return $nodes;
    }

    /**
     * The tag families to parse, including the target's own where it is not one
     * of them already — Sheaf's `x-ui.` is covered by `x-`, and parsing both
     * would find every tag twice.
     *
     * @return list<string>
     */
    private function prefixes(): array
    {
        foreach (self::TAGS as $known) {
            if (str_starts_with($this->vocabulary->prefix, $known)) {
                return self::TAGS;
            }
        }

        return [...self::TAGS, $this->vocabulary->prefix];
    }

    /**
     * The largest piece of chrome around a find that exists only for the
     * signed-in user.
     *
     * @param  list<Element>  $nodes
     * @param  array<int, bool>  $needs
     */
    private function grow(Element $node, array $nodes, array $needs, string $source): Element
    {
        while (true) {
            $parent = $this->parent($node, $nodes);

            if (! $parent instanceof Element || ! $this->onlyFor($parent, $nodes, $needs, $source)) {
                return $node;
            }

            $node = $parent;
        }
    }

    /**
     * The innermost node this one is written inside.
     *
     * @param  list<Element>  $nodes
     */
    private function parent(Element $node, array $nodes): ?Element
    {
        $parent = null;

        foreach ($nodes as $candidate) {
            if (! $candidate->encloses($node)) {
                continue;
            }

            if (! $parent instanceof Element || $candidate->open->offset > $parent->open->offset) {
                $parent = $candidate;
            }
        }

        return $parent;
    }

    /**
     * Is there nothing in this element but chrome for the signed-in user?
     *
     * Asked by blanking the children that need a user and seeing what is left.
     * Whitespace and Blade comments do not count; anything else does, which is
     * what stops a guard from swallowing the navigation beside a user menu.
     *
     * @param  list<Element>  $nodes
     * @param  array<int, bool>  $needs
     */
    private function onlyFor(Element $parent, array $nodes, array $needs, string $source): bool
    {
        if ($parent->open->selfClosing) {
            return false;
        }

        $base = $parent->contentStart();

        $left = $this->withoutDependents(
            substr($source, $base, $parent->closes - $base),
            $base,
            $parent,
            $nodes,
            $needs,
            $source,
        );

        return trim((string) preg_replace('/\{\{--.*?--\}\}/s', '', $left)) === '';
    }

    /**
     * A piece of source with the children that need a user taken out of it.
     *
     * Two questions are asked by subtraction, and this is both of them: whether
     * an element holds nothing but signed-in chrome, and whether it asks for the
     * user itself rather than on a descendant's behalf.
     *
     * @param  int  $base  where the text starts in the file, since child offsets are absolute
     * @param  list<Element>  $nodes
     * @param  array<int, bool>  $needs
     */
    private function withoutDependents(string $text, int $base, Element $parent, array $nodes, array $needs, string $source): string
    {
        // Back to front, so each removal leaves the offsets of the ones still to
        // come where they were read.
        foreach (array_reverse($this->children($parent, $nodes)) as $child) {
            if (($needs[$child->open->offset] ?? false) !== true) {
                continue;
            }

            $text = substr_replace(
                $text,
                '',
                $child->open->offset - $base,
                $this->ends($source, $child) - $child->open->offset,
            );
        }

        return $text;
    }

    /**
     * The nodes written directly in this element, rather than inside each other.
     *
     * @param  list<Element>  $nodes
     * @return list<Element>
     */
    private function children(Element $parent, array $nodes): array
    {
        $inside = array_values(array_filter(
            $nodes,
            static fn (Element $node): bool => $parent->encloses($node),
        ));

        return array_values(array_filter(
            $inside,
            static function (Element $node) use ($inside): bool {
                foreach ($inside as $other) {
                    if ($other->encloses($node)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * One guard per piece of chrome: a region another region already covers is
     * dropped, and a region two finds grew into is kept once.
     *
     * @param  list<Element>  $regions
     * @return list<Element>
     */
    private function outermost(array $regions): array
    {
        $kept = [];

        foreach ($regions as $region) {
            foreach ($regions as $other) {
                if ($other->encloses($region)) {
                    continue 2;
                }
            }

            $kept[$region->open->offset] = $region;
        }

        ksort($kept);

        return array_values($kept);
    }

    /**
     * Is this offset already inside a guard somebody wrote by hand?
     *
     * Only Blade's own `@auth` and `@guest` are counted. An `@if` holding the
     * same question is not, because deciding which `@endif` closed it means
     * tracking every other `@if` in the file.
     */
    private function guarded(string $source, int $offset): bool
    {
        $before = substr($source, 0, $offset);

        $opens = (int) preg_match_all('/@(?:auth|guest)\b/', $before);
        $closes = (int) preg_match_all('/@end(?:auth|guest)\b/', $before);

        return $opens > $closes;
    }

    /**
     * Write one guard, with the region's own lines moved in a level.
     *
     * @param  array{start: int, end: int, class: string|null, signsOut: bool}  $region
     */
    private function guard(Edits $edits, string $source, array $region): void
    {
        $length = $region['end'] - $region['start'];
        $body = substr($source, $region['start'], $length);

        // A region that does not start its own line is left where it is: moving
        // markup that shares a line with something else is not this sweep's to do.
        $indent = $this->indent($source, $region['start']);
        $step = $indent === '' ? '' : '    ';
        $inner = $indent.$step;

        $blade = '@auth'."\n".$inner.$this->shift($body, $step);

        if ($region['signsOut']) {
            $blade .= "\n".$indent.'@else'."\n".$inner
                .implode("\n".$inner, $this->waysIn($region['class'], $step));
        }

        $edits->replace($region['start'], $length, $blade."\n".$indent.'@endauth');
    }

    /**
     * A region's lines, each moved in by the level the guard added. The first
     * carries no indentation of its own — the guard is standing where it was.
     */
    private function shift(string $body, string $step): string
    {
        if ($step === '') {
            return $body;
        }

        $lines = explode("\n", $body);

        foreach ($lines as $number => $line) {
            if ($number > 0 && trim($line) !== '') {
                $lines[$number] = $step.$line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Somewhere for a guest to go, in the tag of the library this project is
     * ending on: a log in link, and a register link beside it where the kit
     * still has registration to offer.
     *
     * Where there are two, they go in a box of their own, because the chrome
     * they replace was one element and its parent laid it out as one. The kit's
     * `<flux:header>` gives its children no room between them — it is the
     * children that ask, `mr-2` on the sidebar toggle and `me-1.5` on the icon
     * navbar — so two links standing loose in it come out touching. The box
     * asks nothing of the parent and gives the room itself.
     *
     * The guarded region's own classes go to the element that stands where the
     * chrome stood, the box when there is one, so a menu that was hidden at a
     * breakpoint is replaced by a way in that is hidden there too.
     *
     * Registration is asked of the project rather than written behind a
     * `Route::has('register')` the way the kit's welcome page asks at render
     * time, because `install:features` has already run by the time refit does:
     * what the kit kept is a fact here, not a question.
     *
     * @return list<string>
     */
    private function waysIn(?string $class, string $step): array
    {
        if (! $this->registration) {
            // A button is laid out as a flex row already, so nothing says so
            // here: the only display written is the one a variant is holding up.
            return [$this->link('login', 'Log in', $this->attribute(
                $class === null ? [] : $this->classes($class, self::BUTTON),
            ))];
        }

        $carried = $class === null ? [] : $this->classes($class, self::ROW);

        // Register is the one to press, the way the kit's own welcome page has it,
        // and log in is the quieter half of the pair. Both are said outright:
        // writing no variant on one of them means an outline button in Flux and
        // the prominent one in Sheaf, which would make the two read alike there.
        return [
            sprintf('<div%s>', $this->attribute(array_merge(
                // A box is a plain element and has to be told how to lay out,
                // unless the kit's own classes already settle it.
                $this->settled($carried) ? [] : [self::ROW],
                $carried,
                [self::SPACING],
            ))),
            $step.$this->link('login', 'Log in', $this->attribute([self::SHARE]), $this->vocabulary->quietVariant),
            $step.$this->link('register', 'Register', $this->attribute([self::SHARE]), self::PROMINENT),
            '</div>',
        ];
    }

    /**
     * One way in, named as the kit's own welcome page names it.
     *
     * What it shows is the task's to answer, and the icon it shows with it is
     * spelled in whichever set the run is heading for — so the name arrives
     * ready to write rather than being worked out here.
     *
     * A way in with nothing but an icon in it still has to say what it is, or a
     * screen reader is left reading the route: the label the icon stands in for
     * becomes its `aria-label`, translated the same way the words would have
     * been.
     */
    private function link(string $route, string $label, string $attribute, ?string $variant = null): string
    {
        $icon = $this->icons[$route] ?? null;
        $variant = $variant === null ? '' : sprintf(' variant="%s"', $variant);

        if ($icon !== null && ! $this->labels) {
            return sprintf(
                '<%1$sbutton :href="route(\'%2$s\')" %3$s="%4$s" :aria-label="__(\'%5$s\')"%6$s%7$s />',
                $this->vocabulary->prefix,
                $route,
                self::ICON,
                $icon,
                $label,
                $variant,
                $attribute,
            );
        }

        return sprintf(
            '<%1$sbutton :href="route(\'%2$s\')"%3$s%4$s%5$s>{{ __(\'%6$s\') }}</%1$sbutton>',
            $this->vocabulary->prefix,
            $route,
            $icon === null ? '' : sprintf(' %s="%s"', self::ICON, $icon),
            $variant,
            $attribute,
            $label,
        );
    }

    /**
     * A `class` attribute, or nothing at all where there is nothing to say — so
     * a way in standing where unclassed chrome stood carries no attribute.
     *
     * @param  list<string>  $classes
     */
    private function attribute(array $classes): string
    {
        return $classes === [] ? '' : sprintf(' class="%s"', implode(' ', $classes));
    }

    /**
     * A class list as the element standing in can wear it: every class the kit
     * wrote, less the display it set.
     *
     * @return list<string>
     */
    private function classes(string $class, string $display): array
    {
        $tokens = preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $carried = [];

        foreach ($tokens as $token) {
            [$variant, $utility] = $this->split($token);

            if (! in_array($utility, self::DISPLAY, true)) {
                $carried[] = $token;

                continue;
            }

            // A display behind a variant is still holding something up — the
            // `lg:block` beside a `hidden` is what lifts it from `lg` — so the
            // variant keeps the display the element standing in already has.
            // One standing on its own has nothing left to say and goes.
            if ($variant !== '') {
                $carried[] = $variant.$display;
            }
        }

        return $carried;
    }

    /**
     * Whether a class list already says how the element displays.
     *
     * Only a display standing on its own counts. A bare `flex` beside the kit's
     * bare `hidden` is two display utilities in one layer, and then the
     * stylesheet's order decides which wins rather than anything written here —
     * so where the kit wrote `hidden lg:block` the box says nothing and lets
     * `hidden lg:flex` stand alone.
     *
     * One behind a variant settles nothing. Sheaf's rail hides its menu with
     * `max-md:hidden` and leaves the rest of the sizes to the element's own
     * display, so a box reading that as settled comes out with no display at
     * all — and then its `gap-2` has no axis to space and the links inside it
     * no line to share. A variant beside a bare `flex` is the case Tailwind is
     * built for: the variant is the later rule and wins where it applies.
     *
     * @param  list<string>  $tokens
     */
    private function settled(array $tokens): bool
    {
        foreach ($tokens as $token) {
            [$variant, $utility] = $this->split($token);

            if ($variant !== '') {
                continue;
            }

            if ($utility === self::HIDDEN || in_array($utility, self::DISPLAY, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A class split into the variant the kit wrote it behind and the utility
     * itself. Everything up to the last colon is the variant — a breakpoint,
     * `dark:`, `max-lg:` — and it stays, whatever happens to the utility.
     *
     * @return array{string, string}
     */
    private function split(string $token): array
    {
        $cut = strrpos($token, ':');

        return $cut === false
            ? ['', $token]
            : [substr($token, 0, $cut + 1), substr($token, $cut + 1)];
    }

    /**
     * A node's source, opening tag to closing tag.
     */
    private function span(string $source, Element $node): string
    {
        return substr($source, $node->open->offset, $this->ends($source, $node) - $node->open->offset);
    }

    /**
     * The offset just past a node, which {@see Element} carries only as the
     * `<` of the closing tag.
     */
    private function ends(string $source, Element $node): int
    {
        if ($node->open->selfClosing) {
            return $node->closes;
        }

        $end = strpos($source, '>', $node->closes);

        return $end === false ? $node->closes : $end + 1;
    }

    /**
     * A span's own source, plus the source of everything it renders.
     *
     * @param  array<string, string>  $seen  resolved files, which is both the memo and the cycle guard
     */
    private function reach(string $source, Project $project, array &$seen): string
    {
        $text = $source;

        foreach ($this->prefixes() as $prefix) {
            foreach ($this->parser->parse($source, $prefix) as $tag) {
                foreach ($this->sources($tag->name, $project) as $file) {
                    if (! array_key_exists($file, $seen)) {
                        // Seeded empty ahead of the recursion, so a component
                        // that renders itself is read once rather than forever.
                        $seen[$file] = '';
                        $seen[$file] = $this->reach($project->get($file), $project, $seen);
                    }

                    $text .= $seen[$file];
                }
            }
        }

        return $text;
    }

    /**
     * Every file that decides what one tag renders, as project-relative paths.
     *
     * @return list<string>
     */
    private function sources(string $name, Project $project): array
    {
        if (str_starts_with($name, self::LIVEWIRE)) {
            return $this->livewire(substr($name, strlen(self::LIVEWIRE)), $project);
        }

        if (! str_starts_with($name, self::ANONYMOUS)) {
            return [];
        }

        $component = substr($name, strlen(self::ANONYMOUS));

        // Blade's own slot syntax is not a component, and a namespaced view such
        // as `x-layouts::app` is resolved from somewhere the application's
        // component directory does not describe.
        if ($component === '' || str_contains($component, '::') || str_starts_with($component, 'slot')) {
            return [];
        }

        return $this->existing($project, [
            'resources/views/components/'.str_replace('.', '/', $component).'.blade.php',
        ]);
    }

    /**
     * The files behind a Livewire tag, in either of the styles the kit ships.
     *
     * A single-file component keeps its class inside the view; a class component
     * keeps them apart, and the user may well be read in the class rather than
     * in the Blade — so both are read.
     *
     * @return list<string>
     */
    private function livewire(string $component, Project $project): array
    {
        $component = str_replace(['::', '.'], '/', $component);
        $cut = strrpos($component, '/');
        $folder = $cut === false ? '' : substr($component, 0, $cut + 1);
        $file = substr($component, strlen($folder));

        $found = $this->existing($project, [
            'resources/views/components/'.$folder.self::SINGLE_FILE.$file.'.blade.php',
            // A view namespace reads as the folder it was registered from, which
            // is how `<livewire:pages::settings.profile />` is found.
            'resources/views/'.$folder.self::SINGLE_FILE.$file.'.blade.php',
            'resources/views/livewire/'.$component.'.blade.php',
            'app/Livewire/'.$this->studly($component).'.php',
        ]);

        return $found === [] ? $this->byFilename($file, $project) : $found;
    }

    /**
     * A single-file component found by its filename, wherever it sits.
     *
     * The `⚡` is the installer's, not a name anybody writes, so matching on it
     * cannot pick up an ordinary view — which makes it the one safe way to follow
     * a component one of refit's own tasks has already moved.
     *
     * @return list<string>
     */
    private function byFilename(string $file, Project $project): array
    {
        $name = self::SINGLE_FILE.$file.'.blade.php';

        return array_values(array_filter(
            $project->blades(),
            static fn (string $path): bool => basename($path) === $name,
        ));
    }

    /**
     * A component name as Livewire would have named the class for it.
     */
    private function studly(string $component): string
    {
        return implode('/', array_map(
            static fn (string $part): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $part))),
            explode('/', $component),
        ));
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function existing(Project $project, array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (string $path): bool => $project->exists($path),
        ));
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentions(string $source, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }
}
