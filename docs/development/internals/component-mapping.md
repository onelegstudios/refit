---
title: Component mapping
description: The Library contract, the vocabulary, and how a target translates itself from Flux.
order: 4
---

# Component mapping

Refit shipped its first version with no library abstraction at all, deliberately.
With Flux as the only target a `Library` would have been an identity adapter —
mapping `flux:button` to `flux:button` — and an interface with one trivial
implementation proves nothing about whether the contract is right.

Sheaf is the second real case, and it disagrees with Flux almost everywhere the
contract has to make a choice. That disagreement is what the shape below is built
from.

| | Flux | Sheaf |
|---|---|---|
| Distribution | vendor package | CLI copies source into the application |
| Tag prefix | `flux:` | `x-ui.` |
| Icons | resolved by bare name, overridden at `resources/views/flux/icon/{name}.blade.php` | an `<x-ui.icon>` component in the application, reading an icon package |
| Internal icons | rendered from vendor code, so they need a recorded manifest | rendered from application code, so the scanner already sees them |
| Trailing icon | `icon-trailing` | `iconAfter` |
| Paid tier | `livewire/flux-pro` in `composer.lock` | an account the CLI logs in to |

## The source is always Flux

Refit only supports the Livewire starter kit, and that kit ships Flux — the
downloader excludes the blank variant for exactly this reason. So a `Library` is
always the **target**, and each target owns its own translation *from* Flux.

That is what keeps a third library to one class. A contract where any library
could be either end would need a translation for every pair; this one needs one
per target.

Flux implements the contract as the identity case: it detects, it supplies a
vocabulary, and its `planMigration()` is empty because a Flux kit is already on
Flux.

## The contract

```php
interface Library
{
    public function key(): string;
    public function label(): string;
    public function hint(): string;

    public function vocabulary(): Vocabulary;
    public function detect(string $root): ?LibraryInstall;

    /** @return list<string> blocking problems, empty to go */
    public function preflight(Project $project): array;

    /** @return list<IconStrategy> */
    public function iconStrategies(): array;

    public function planIcons(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void;
    public function planMigration(Plan $plan, Project $project, Report $report): void;
    public function planTeardown(Plan $plan, Project $project, Report $report): void;
}
```

`preflight()` runs *after* the target is chosen rather than filtering the menu
before it. A library the project is not set up for is still offered, and then
tells the user what to run — which is more use than the option quietly missing.

## The two directions

`planMigration()` is asked of the target. `planTeardown()` is asked of every
library the project has installed and is not ending on, after the migration has
run — because a `@source` line is only dead once the tags that needed it have
moved.

Splitting them that way is what stops the second and third target from carrying a
copy of how to uninstall Flux. Nothing in Flux's teardown is about where the
project is going: the stylesheet lines, the `@fluxAppearance` and `@fluxScripts`
directives, and the `resources/views/flux` override directory are Flux's own
footprint, and `Libraries\Flux\Teardown` is the only place that knows they exist.
`SheafLibrary::planTeardown()` is empty for a reason worth stating — Sheaf's
components are copied into the application and belong to the user from that
moment, so there is nothing refit may take back.

The command resolves it off detection rather than off a constant:

```php
foreach ($project->libraries as $install) {
    if ($install->key !== $target->key()) {
        $refit->resolveLibrary($install->key)?->planTeardown($plan, $project, $report);
    }
}
```

In practice that is always Flux, because Flux is what the kit ships. But nothing
in `RefitCommand` names it, so a project that has already been through refit once
is torn down from whatever it actually has.

## Vocabulary

Everything the Blade rewriters need in order to work on a library without knowing
which one they are looking at: the tag prefix, the generic icon tag and the
attribute it names an icon through, the attributes that carry an icon name on any
component, and — for libraries that have them — the dotted tag form and the
variant attributes.

`RewriteIconNames`, `DropSolidIconVariant`, `SwitchIconSet` and `IconScanner`
all take one. None of them contains the string `flux:` any more.

Nullable fields are how a library says it does not have something. Sheaf's
`dottedIconTag` is null because `<x-ui.icon.home />` is not a thing, and its
`variantAttribute` is null because its icon component supports Heroicons' solid,
mini and micro weights natively — so nothing needs to strip `variant="solid"` the
way the Lucide path does.

## Where the target lives

The chosen library rides on `Project`, attached by `Project::targeting()` once the
question has been answered. Detection fills in everything else; the target is the
one field disk cannot answer.

This deliberately leaves the `Task` contract at the two arguments it has always
had. A task that cares reads `$project->targets('sheaf')`, so every shipped task,
the [custom task example](/docs/using-refit/guide/custom-tasks), and any
third-party task keep working unchanged.

## ComponentMap

Sheaf's translation table, and the same discipline as
[`IconMap`](/docs/development/internals/icon-pipeline): curated, commented where a
call was close, and reported rather than guessed at when there is no answer.

Seven kinds of entry:

- **`TAGS`** — `flux:callout` to `x-ui.alerts`, `flux:menu.item` to
  `x-ui.dropdown.item`, `flux:main` to `x-ui.layout.main`.
- **`ATTRIBUTES`** — `icon-trailing` to `iconAfter`, `viewable` to `revealable`,
  matched by name alone so the pass can run after the rename. A prop belongs to
  the one component that declares it, so matching by name across every Sheaf tag
  costs nothing as long as no two collide.
- **`TAG_ATTRIBUTES`** — the renames where two do collide, so they are keyed by the
  tag that owns them. `name` is the word both libraries spend everywhere — the icon
  on `<x-ui.icon>`, the field on `<x-ui.input>` — and only on a modal does it mean
  identity, where Flux's `name` is Sheaf's `id`. `heading` is the same shape:
  `<x-ui.navlist.group>` wants it renamed to `label`, and `<x-ui.alerts>`, the
  other tag Flux hands a `heading` to, wants nothing of the sort. `current` is
  here for the narrower reason that a nav item is the only thing that has one:
  Flux's `current` is Sheaf's `active`, and keying it by the Sheaf tag also
  collects `flux:sidebar.item`, which renames into `x-ui.navlist.item` and
  carries most of the kit's ten. Keyed by the *Sheaf* name, because this pass
  runs after the tag rename over a tree that already says `x-ui.`.
- **`VALUES`** — keyed by the *Flux* tag, so the pass looks a Sheaf tag back up
  through the map. Only the variants the kit actually writes are listed; Sheaf
  passes an unknown variant through to classes rather than throwing, so guessing
  would be worse than doing nothing. A heading's `level` is the exception, and
  lists all six because Sheaf *discards* a level it does not recognise. A
  heading's `size` looks like a second exception and is not one: `base` is listed
  because refit writes that value into the tree itself, before this pass runs. A
  badge's `variant` is the case where the table has nothing to say at all — the
  value that matters is the one neither library writes down, so it is added after
  this pass rather than translated by it.
- **`BOUND_VALUES`** — why a bound value is worth a word, for the few where it is.
  `VALUES` reads literals, so `:level="$depth"` goes untranslated however complete
  the table is, and a warning is all refit has left.
- **`UNMAPPED`** — a tag with no counterpart, and the sentence explaining why.
- **`SUPPORTING`** — the components refit writes itself, which no Flux tag becomes:
  `field`, `label` and `error`. They are in the map because the install list is
  built from it, not because anything renames into them.

### The manifest keeps it honest

`resources/sheaf/components.json` records every component Sheaf ships and the tags
each answers to, recorded by `composer sheaf:components` from the public
`sheafui/components` registry. Each component's `config.yml` lists where its files
install, and the install path *is* the Blade component name — so the registry is
the authority on tag names rather than a reading of the docs.

`composer sheaf:components:check` fails when a mapping points at a component Sheaf
no longer ships, which is the same job `flux:internals:check` does for icons. It
checks `ComponentMap::SUPPORTING` as well — `field`, `label` and `error`, the three
components refit writes for itself rather than renaming anything into, which no
entry in `TAGS` would otherwise vouch for. Only
names are recorded; none of Sheaf's source is copied into this repository, because
its CLI is what puts components in a project and refit runs that CLI.

Unlike the Flux manifest this needs no licence and no sidecar install —
`sheafui/components` is public and MIT.

## The order the passes run in

Inside `Stage::Reconcile`, and it matters:

1. **`RestructureOverlays`**, **`RestructureCallouts`**, **`JoinDropdownPlacement`**
   and the heading-size **`AddAttribute`** — first, while the markup still says
   `flux:`, because all four read Flux's own arrangement: where a dropdown keeps
   its trigger, what a modal close button wraps, which of a callout's two lines it
   wrote as an attribute, which two attributes a placement was spread across, and
   which headings named no size at all. Each ends by putting Flux's own longhand
   in the file, which the rename then knows how to translate.
2. **`MapComponentTags`** — the dotted icon form is folded into an attribute while
   the suffix is still there to read, then tag names, then attributes and values.
3. **The badge-variant `AddAttribute`** — the same move as the heading's, from the
   other side of the rename. Flux has no word for the variant its badge defaults
   to, so there is no Flux longhand to write out and let the rename translate;
   the value written is Sheaf's own, onto a tag that already says `x-ui.`.
4. **`MergeBrandVariants`**, **`RestructureBrandLogo`**, **`RestoreButtonRow`**,
   **`PlaceDropdownChildren`**, **`PreserveTextAlignment`**,
   **`MuteSecondaryText`**, **`PromoteContentsToLabel`**,
   **`WrapControlsInFields`**, **`ShapeSegmentedGroups`**,
   **`FollowSidebarCollapse`** and **`RaiseSidebarDropdowns`** — after the
   rename, because all eleven read the tags the rename produced.
   `MergeBrandVariants` comes before `RestructureBrandLogo` so there is one brand
   to shape rather than two. `MuteSecondaryText` follows `PreserveTextAlignment`
   because both append to the same `class` attribute on the same tags; either
   order works, and each tolerates the other having created the attribute first.
5. **`RebindAppearanceToTheme`**, **`ApplyThemeBeforePaint`** and
   **`ScopeCollapseToSidebar`** — anywhere, in practice. None of the three reads a
   tag: one rewrites Alpine expressions, one writes a script into the head, and
   the third re-keys a class inside components Sheaf's own CLI wrote, which never
   said `flux:` to begin with. All three have to be in the reconcile stage rather
   than the write stage, though, because the files they edit are still moving
   until then.
6. **The icon sweeps** — last, so they run against the tags the migration
   produced. This is why `planMigration()` is called before `planIcons()` in
   `RefitCommand::build()`.

Every one of these is a [`BladeSweep`](/docs/development/internals/blade-rewriting),
so each is one traversal, and each file is checked by `BladeGuard` individually.

### Light and dark is two halves, and only one is a rewrite

`RebindAppearanceToTheme` moves the bindings; `ApplyThemeBeforePaint` deals with
*when* the theme lands. The kit's layouts all hardcode `<html class="dark">` and
let JavaScript correct it, which Flux made safe with `@fluxAppearance` — an
inline, synchronous script in the head. Flux's teardown strips it, and Sheaf
offers no substitute: `theme.js` registers `$theme` inside `alpine:init` and is
loaded by `@vite` as `type="module"`, deferred by spec. So the correction lands
after the paint, and every page visibly snaps from dark to light.

The script written back reads what `theme.js` reads — the `theme` key, the
`system` default, `prefers-color-scheme`, `.dark` on `documentElement`. Anything
else and the two disagree for exactly the one frame the script exists for.

It also listens for `livewire:navigated`, and that half is not optional. Livewire's
`replaceHtmlAttributes()` copies the incoming document's `<html>` attributes onto
the live element, so the kit's hardcoded `class="dark"` is reapplied on every
`wire:navigate`. Its `mergeNewHead()` only adds head children the page does not
already have, and this script is byte-identical on every page, so it is never
re-run to correct that. `alpine:init` does not fire twice either, so Sheaf's
runtime never gets a second look. The symptom without the listener is a preference
that works until you click a link and then reverts — and with a dark OS it hides
completely behind `dark` and `system`, because those resolve to the colour the
hardcoded class was asserting anyway.

Finding the head is the fiddly part, and `@vite` alone does not do it: the kit
calls `@vite('resources/js/passkeys.js')` inside `passkey-registration` and
`passkey-verify`, and a theme script in either is a script in the body of a page
whose head already has one. The discriminator is a stylesheet — CSS has to be in
the head — with `<head` itself as a fallback for a project that imports its CSS
from JavaScript and never names a `.css` in Blade.

### Two components becoming one leaves a choice behind

Flux ships two brands — `flux:brand` and `flux:sidebar.brand` — and the kit's logo
component chooses between them on a `sidebar` prop, writing the whole brand out
once per arm. Both names map to `x-ui.brand`, so after `MapComponentTags` the two
arms are byte-identical and the prop cannot change what renders.

`MergeBrandVariants` drops the conditional, un-indents the surviving arm into its
place, and takes the prop out of `@props` once nothing in the file still reads it.
It is anchored on the shape rather than the path, because `NamespaceComponents`
turns `app-logo.blade.php` into `brand/logo.blade.php` in the move stage, and it
refuses anything that is not the case it was written for: arms that differ, a
conditional with another one nested inside it, or a duplicate with no brand in it.

The call sites go with it. Refit's own layout stubs stop passing `:sidebar`; a
call site refit did not write that still passes it lands the value in
`$attributes`, where Sheaf's brand merges it onto its `<a>` as a stray
`sidebar="1"`. That is inert, and the alternative — leaving a prop declared so
nothing can read it — hides the call site rather than fixing it.

### A slot is not only a slot

The kit hands its logo to the brand as a slot carrying classes, and Flux renders
that slot as `<div {{ $logo->attributes->class(...) }}>` — which is what turns
those classes into the accent tile the mark sits on. Sheaf's brand renders
`{{ $logo }}` and nothing else, so the attributes go nowhere. The mark is
`text-white dark:text-black`, so losing the tile loses the logo: white on a white
sidebar, black on a black one.

`RestructureBrandLogo` takes the classes off the slot and puts them on an element
inside it — the same element Flux was rendering, written where Sheaf will keep it.
The classes themselves are copied verbatim, so a project that restyled its tile
keeps the tile it wrote.

### A slot is not always read

The same shape, one component along. Flux's nav items take their text as slot
content; Sheaf's `navlist.item`, `navbar.item` and `radio.item` render
`{{ $label }}` into a span and never reference `$slot` at all. A rename therefore
produces a valid, clickable, invisible item — which is what the settings
sub-navigation and the appearance page's segmented control were reduced to.

`PromoteContentsToLabel` moves the contents onto the tag and closes it behind
them. A lone `{{ ... }}` becomes `:label="..."` so `__()` survives as a call
rather than being flattened; text with nothing Blade about it becomes a literal
`label`. Two echoes, markup, or a value carrying a double quote — which the tag
parser cannot read back — are all left as they were and collected into one
warning, because Sheaf's item has an icon, a label and a badge and nowhere to put
anything else.

A dropdown group is a target for the opposite reason. It *does* render its slot —
it is `display: contents`, and handing its children to the panel's grid is the
whole job — but only `label` is drawn as the muted heading. So Flux's
`menu.heading`, which carries that word as contents, arrives in a
`grid grid-cols-[auto_1fr_auto]` as a bare text node, and the panel takes it for a
child of its own: unstyled, in the first of the three columns, with the first item
of the list beside it in the other two rather than under it. The team switcher's
"Teams" is the kit's one of these. Contents richer than a label are left alone
there in silence rather than reported — `flux:menu.radio.group` renames to the
same tag, and Sheaf passes those children through exactly as Flux did.

`select.option` is the last component taking a `label`, and it renders its slot as
a slot, so it is not a target either.

### A label is not always a prop

One tag further along, the same word fails in the opposite direction. Flux's input
is the label, the control, the spacing and the validation message in a single tag;
Sheaf splits those into four components — `field` around, `label` above, `error`
below, `input` for the control alone — so a `label` handed to the control has
nowhere to land and the message stops being rendered by anything. Nothing throws,
which is the problem: `input` and `otp` have no such prop, so the label falls
through to the attribute bag and renders as `label="…"` on the wrapper div, and
`select` declares the prop and then never writes it out. Both are simply gone, in
twenty controls across eleven files of the plainest kit — which are also the kit's
whole authentication flow, rejecting people without saying why.

`WrapControlsInFields` lifts the attribute out of the tag and writes both around
the control, inside an `x-ui.field`:

```blade
<x-ui.field>
    <x-ui.label :text="__('Email address')" />
    <x-ui.input name="email" type="email" required />
    <x-ui.error name="email" />
</x-ui.field>
```

The label's value is spliced across as written — quote character, `{{ }}`, `__()`
and all — so nothing is re-rendered and nothing is read as an expression. `:label`
becomes `:text` and a literal stays literal. Flux's `label:sr-only` becomes
`class="sr-only"` on the label, because hiding the words while leaving them to a
screen reader was the point.

The error is keyed the way Flux keyed it: the control's `name`, or the property a
`wire:model` binds when there is no name — a Livewire property path is the key its
messages come back under, so the two agree. A control bound only to Alpine has no
key on either side and gets no error, because an `x-ui.error` with no name renders
nothing on every page rather than something on the one that failed. The kit's only
unlabelled input is the recovery code, which this sweep never reaches and which
writes its own `@error` block, so nothing is rendered twice.

A control already inside a field gets the label alone — a project that wrote its
own field has said how it wants errors shown — and a control whose closing tag
cannot be found is no span to wrap, so it keeps its label and is reported.

The target list is hand-kept, like the map itself: the manifest records tag names,
not props, so which components render a label is a reading of Sheaf's source.
`checkbox` is deliberately absent — it renders its label through `checkbox.label`
— and so are the items above, which are `PromoteContentsToLabel`'s.

### A value is not always posted

The same tag again, and the same silence, but this time it costs the login. Flux's
`<flux:otp name="code">` renders a `<ui-otp>` custom element that keeps one hidden
input holding the joined digits, so an ordinary `<form method="POST">` submits the
whole code. Sheaf's `x-ui.otp` has no such input. Its `name` is a declared prop
that `otp.input` reads back with `@aware`, so the name lands on *every* digit box
instead — six inputs called `code`, of which PHP keeps the last.

Sheaf's own answer is `wire:model`: the component entangles the property and
Livewire carries it, which is what the kit's two-factor setup modal does and why
that page survives the rename untouched. The two-factor challenge is not Livewire.
It posts a plain form to `two-factor.login.store` with the digits held in Alpine
through `x-model`, so after a rename alone it posts a single digit and Fortify
answers every attempt with "The provided two factor authentication code was
invalid."

`CarryOtpValue` takes the name off the tag and posts the Alpine value the
component already writes back:

```blade
<x-ui.otp x-model="code" length="6" class="mx-auto" />
<input type="hidden" name="code" x-bind:value="code" />
```

Taking `name` off is half the fix rather than a tidy-up: left in place, its six
namesakes outrank the hidden input and the last digit box is still what posts.

The other half is that Sheaf's digit boxes are unconditionally `required`, which
Flux's were not. The challenge page keeps its recovery-code field in the same
`<form>` and swaps between the two with `x-show`, and a required control that is
`display: none` is still validated — the browser refuses the submit outright and
the button does nothing at all, which is the whole recovery path gone. The page
already guards its own recovery input with `x-bind:required`, but nothing outside
Sheaf can reach the boxes Sheaf generates, so the OTP is wrapped in the one
element that disables its descendants from above:

```blade
<fieldset class="contents" x-bind:disabled="showRecoveryInput">
```

which does both jobs at once — a disabled fieldset is exempt from validation *and*
submits nothing, so the recovery post carries `recovery_code` alone. The condition
is the negation of the `x-show` the OTP is hidden behind, unwrapped rather than
double-negated where that reads as a plain name.

The sweep runs after `WrapControlsInFields`, which reads the same `name` to key the
error it writes. An OTP bound with `wire:model`, written with its own digit boxes
in a slot, or outside a form is left alone; one that posts a form but holds its
value in neither `wire:model` nor `x-model` has nothing to carry, and is reported
rather than quietened.

### The browser has a theme of its own

`@fluxAppearance` emitted two things, and the teardown takes both. The script half
is above; the other is one CSS rule:

```css
:root.dark { color-scheme: dark; }
```

`color-scheme` is what tells the browser that its *own* defaults are dark —
unstyled text, scrollbars, native form chrome. Sheaf reads `prefers-color-scheme`
to decide which theme to apply and never declares `color-scheme` itself, so
without this rule the UA keeps its light defaults while the page renders dark.

Most of the kit survives that, because Sheaf's components colour themselves. What
does not is the plain markup between them, and there is one piece of it that
matters: the "or you can — login using a recovery code" toggle under the two-factor
challenge form, which carries an underline, an opacity and no colour at all. It
comes out black on the layout's near-black gradient — measured at 1.06:1 contrast,
against 19.8:1 with the rule in place.

`ApplyThemeBeforePaint` writes it into the head alongside the script, since the two
were one directive and are restored together.

### A centred control needs a width to be centred at

`mx-auto` centres a block only if that block has width to give back, and under
Flux the kit's OTP had it: `<ui-otp>` carried `w-fit`. Sheaf's otp is an ordinary
block, and the `x-ui.field` this rewrite puts around it is `w-full` — so the auto
margins collapse to nothing and the six boxes sit hard against the left edge of a
row that is still, itself, centred. Measured on the two-factor challenge, 48px off.

Left alone the markup would have centred anyway: Sheaf's otp root is
`display: contents`, so without a field between them the wrapper inside it becomes
the flex item the page was already centring. The field is what breaks it, so
`WrapControlsInFields` is what pays for it, adding `w-fit` as it wraps.

The width goes on the control rather than on the field because the field already
declares `w-full`, and two width utilities on one element is a coin toss decided by
Tailwind's ordering rather than by ours. Only the OTP is touched, and only when it
already asks to be centred and names no width of its own — `w-fit` on an input or a
select would shrink a control meant to fill its field, and Flux centred neither.

### A box a password manager cannot fill

One of the four places refit edits Sheaf's own source rather than the kit's — the
[collapse that the whole page answers](#a-collapse-the-whole-page-answers-for), the
[icon class that is escaped twice](#a-class-escaped-twice-is-not-a-class) and the
[colour that wins a tie it should lose](#a-colour-that-wins-a-tie-it-should-lose)
are the others — and, with it, a stopgap rather than a translation.

Flux's `<ui-otp>` and Sheaf's `x-ui.otp` disagree on the three things that decide
whether a password manager can fill a code, and Sheaf takes the losing side of
each:

| | Flux | Sheaf |
| --- | --- | --- |
| Which box claims the code | `one-time-code` on the first input, `off` on the rest | `one-time-code` on all six |
| A multi-character value | distributed across the boxes | truncated to its last character |
| How the caret is held | `tabindex` | every box ahead of it is `disabled` |

A password manager sets `.value` and dispatches `input`, never `paste` — so
Sheaf's `handlePaste`, the only code it has that spreads a code out, never runs.
A filled `123456` reaches `handleInput`, which keeps the `6` and drops the rest;
and a fill that goes box by box instead cannot write to boxes two through six at
all, because a disabled input is one an extension cannot touch. Either way one
digit lands. What follows looks like the keyboard locking up: `handleInput`
enables and focuses the next box a frame later, `x-on:focus` re-selects it a frame
after that, and the page goes on stealing focus every frame while the extension is
still trying to drive the field.

None of that is reachable from the page, so `AcceptOtpAutofill` patches the
component `sheaf:install` copied into the project — giving `one-time-code` to the
first box from `setupInputs()`, spreading a multi-character value through a new
`fillFrom()` that `handlePaste` shares, and holding the caret with `tabIndex`
rather than `disabled`. The three places that read the disabled flag back —
`clear()`, `handleClick()` and the availability pass itself — change with it.

Every edit is anchored on the lines it replaces, matched per line and trimmed so
Sheaf's indentation inside the `x-data` attribute is not what decides whether it
lands, and re-indented onto wherever it was found. A block refit no longer
recognises is named in a warning rather than guessed at, and the rest still apply:
a partial patch beats no patch and beats a wrong one.

It is planned only for a project that writes an OTP, read before the rename while
the tag is still `<flux:otp`. A later `sheaf:install otp` overwrites it, which is
the point at which to check whether
[sheafui/components](https://github.com/sheafui/components) has fixed this
upstream and this action can go.

### A button is a row, minus one box

Both libraries build a button as a flex row, and the difference between them is a
single wrapper. Flux's children are the flex items. Sheaf's button wraps the whole
slot in a `<span data-text>` first, so that span is the only flex item there is and
its contents are back in ordinary block flow — which matters because Tailwind's
preflight sets `svg { display: block }`.

The team switcher's trigger is where the kit shows it. An icon, the team name and
a chevron come out as three stacked lines, and the chevron's `ms-auto` — a margin
against free space a block box does not have — leaves it under the name instead of
opposite it.

`RestoreButtonRow` puts `[&>[data-text]]:contents` on the button, which takes the
wrapper out of the layout and makes the children flex items of the button again,
laid out by its own `items-center`, `gap-x-2` and `justify-*` exactly as Flux laid
them out. One utility on a tag the view already writes, rather than a wrapper
element refit would have to invent and the reader would have to maintain.

The second utility pays for the first. Sheaf dims a loading button's contents with
`[&>[data-loading=true]:first-child~*]:opacity-0`, which names that same span — and
opacity on a box that has stopped generating one does nothing, so the label would
sit at full strength under the spinner. Restating it one level down, over the
children that now generate the boxes, keeps the loading state the component ships.

Only buttons whose slot holds markup are touched: text alone lays out the same
either way, and neither a bound `:class`, nor Sheaf's own button, nor a button
already carrying the class is rewritten.

### A collapse is written in classes, not in tags

Both libraries narrow the desktop sidebar to a column of icons, and both let a view
style itself against that. Flux stamps `data-flux-sidebar-collapsed-desktop` on an
ancestor, and the kit reads it with an `in-*` variant. Sheaf stamps `data-collapsed`
on the layout.

Nothing in the rename can see the difference, because it is spelled in class names
rather than in tags — so the team switcher arrives with four rules keyed on an
attribute nothing sets any more, and every one of them is the wrong way round. The
`users` glyph the kit put there for exactly this moment stays `hidden`, the team
name stays and truncates to nothing, the chevron keeps an `ms-auto` with no room to
use it, and the trigger stays full-width and left-aligned: 64px of column holding a
name it cannot show.

`FollowSidebarCollapse` re-keys the variant onto
`[[data-collapsed]_[data-slot=sidebar]_&]:`, which is most of the fix, because the
kit had already decided what a collapsed row should look like. The selector names
the sidebar as well as the collapse for the same reason the user menu's stub does:
`data-collapsed` sits on the layout, the header is under it too, and a row that
also appears in a header should keep its name at every width.

The rest is the box. A trigger that centres itself when collapsed is being emptied
down to one glyph, so it takes the square a collapsed navlist item stands in —
`w-auto h-9 p-2!`, which is 20px of glyph inside 8px of padding, the same 36px the
icons above it hover — instead of running the width of the row. The variant outranks
the button's own `w-full` and `ps-*` on specificity, and Sheaf's `h-10` comes from
inside a `:where()` and carries none at all. A glyph the collapse *reveals* grows
from the kit's `size-4` to the navlist's `size-5` with it; one the collapse hides
keeps whatever size the row drew it at.

`not-in-data-flux-sidebar-collapsed-desktop:` is left alone — it is a different rule
with a different answer, and swapping the middle out of it would leave neither. It
only reaches the kit's header layout, which refit replaces wholesale.

The switcher's place in the sidebar is the other half of this. Flux padded its
sidebar; Sheaf's has no padding of its own and a navlist supplies it, with the
`px-2` every other row here already has and the `items-center` that holds a
collapsed row in the column. So the stub hangs the switcher inside an
`<x-ui.navlist>` rather than off the sidebar directly.

### A collapse the whole page answers for

The rules Sheaf's own components are written with have the same subject problem,
from the other direction. Sheaf spells "when the sidebar is collapsed" as
`[:has([data-collapsed]_&)_&]:`, which Tailwind compiles to

```css
:has([data-collapsed] .the-class) .the-class { … }
```

Neither half of that asks about the element being styled. Nothing stands in front
of the `:has()`, so any ancestor may answer it and `<html>` does; and the class
inside it is the utility rather than this element, so a single navlist item under
the collapsed layout answers on behalf of the whole document. Tailwind emits one
class per utility, so the rule then reaches every element on the page that shares
it.

The settings pages are where that shows. Their sub-navigation is a navlist too —
out in the main column, three rows that are a label and nothing else. Collapse the
sidebar and the label span's own `[:has([data-collapsed]_&)_&]:hidden` matches out
there as well: three rows of nothing, and a settings menu that comes back only
when the sidebar is opened again.

`ScopeCollapseToSidebar` re-keys the variant onto the same
`[[data-collapsed]_[data-slot=sidebar]_&]:` the kit's own rules were pointed at,
which asks about the element instead of about the page: it is inside a sidebar,
and the layout above it is collapsed. Inside the sidebar every rule keeps meaning
what it meant; outside it, none of them mean anything at all.

Sheaf's opposite spelling, `[:not(:has([data-collapsed]_&))_&]:`, is left as it
is. It reads as the other half of the same question and does not behave like one:
a descendant combinator needs only one ancestor to match, and the sidebar holds no
`[data-collapsed]` of its own, so the rule is on wherever it is written — inside a
collapsed sidebar included. That is already what the page outside the sidebar
wants, and re-keying it would change how a collapsed sidebar looks rather than
what it manages to show.

Like the OTP patch, this edits files `sheaf:install` copied into the project,
which is what makes them the project's, and a later install overwrites it. It
matches on the variant rather than on a line, so a Sheaf that has fixed this
upstream, or spelled it some other way, has nothing here to change.

### A class escaped twice is not a class

The third read of Sheaf's own source, and one of two whose damage the icon choice
decides.

Sheaf's `navlist.item` and `navbar.item` size their icon with a variant written to
lose — `[:where(&)]:size-5` compiles to a zero-specificity rule, so a caller's own
`icon:class="size-4"` beats it without needing `!`. Both add it the same way, and
it is the way that breaks it:

```blade
:attributes="$iconAttributes->class('[:where(&)]:size-5')"
```

`class()` is `merge()`, and `merge()` HTML-escapes what it is handed, so the bag
carries `[:where(&amp;)]:size-5` from there on. That is correct exactly once, and
this class is written into the DOM twice: `x-ui.icon` takes the bag in through
`:attributes` and hands it straight back out through `<x-dynamic-component>`, which
is a component tag, so the compiler escapes the value again on its way to the icon
set's `<svg {{ $attributes }}>`. The browser undoes one of the two, and the class
lands as `[:where(&amp;)]:size-5` — a name Tailwind wrote no rule for.

Which icon set is in the tree is what decides whether that is visible.
`wireui/heroicons` draws every glyph with `width="24" height="24"` on the `<svg>`
itself, so the dead class costs nothing and the icon is 24px because the artwork
said so. `wireui/phosphoricons` ships a `viewBox` and no dimensions, leaving the
size entirely to a class that is no longer there — and an `<svg>` with auto width
inside the item's own flex row lays out at zero. So the same components that were
fine on Heroicons render a Phosphor sidebar as labels with nothing in front of
them, gutters and all.

`merge()` takes an `$escape` argument, so `SizeNavItemIcons` adds the same class
without the escape that is one too many:

```blade
:attributes="$iconAttributes->merge(['class' => '[:where(&)]:size-5'], escape: false)"
```

The `:where()` survives intact, which is the point of it — swapping in a plain
`size-5` would fix the escaping and take `icon:class` down with it. Like the other
two, this matches on the call rather than on a line, so a Sheaf that has fixed it
upstream, or sized its icons some other way, has nothing here to change.

### A colour that wins a tie it should lose

The fourth read of Sheaf's own source, in the component the last one hands its
class to.

Flux's icon had no colour of its own. It drew in `currentColor`, and the view
decided — a class on the tag, or the colour of the text around it. Sheaf's
`x-ui.icon` colours itself, and does it at full specificity:

```blade
{{ $attributes->class(['text-neutral-700 dark:text-neutral-300']) }}
```

`class()` is `merge()`, and `merge()` puts its default in *front* of what the
caller passed — but the order of names inside a `class` attribute decides nothing.
What decides is the order Tailwind emits the rules in, and inside one variant it
sorts them by name. `.dark\:text-accent-foreground` is written out before
`.dark\:text-neutral-300`, so of two classes tied on specificity the component's
is the one that wins.

The two-factor setup modal is where the kit shows it. Its QR glyph sits on a disc
that is light in both appearances — `bg-stone-100 dark:bg-stone-200` — and asks to
stay dark on it:

```blade
<x-ui.icon name="ps:qr-code" class="relative z-20 dark:text-accent-foreground"/>
```

Sheaf answers `neutral-300`, and the icon goes white on white. Phosphor is where
anyone notices: `wireui/phosphoricons` draws a filled QR block, which disappears
completely, where `wireui/heroicons` draws the same colour as 1.5px strokes and
merely looks faint. The tie is not confined to that page — the team invitation
alert's `text-blue-600` and the setup key's copied `text-green-500` both lose to
`text-neutral-700` the same way.

So `YieldIconColour` writes the default to lose on purpose, with the variant the
nav items already use for their size and the `escape` argument they already need:

```blade
{{ $attributes->merge(['class' => '[:where(&)]:text-neutral-700 dark:[:where(&)]:text-neutral-300'], escape: false) }}
```

A zero-specificity rule loses to any class the view writes, whatever the order,
and still beats plain inheritance — so an icon that asks for no colour is drawn in
exactly the neutral pair it was, and no caller has to shout over the component
with `!`. Like the other three, it matches on the call rather than on a line.

### A menu is a grid

Flux renders a menu as a stack of blocks, so the kit puts what it likes in one: a
plain div of avatar, name and email at the top, a `<form>` around the log out
item, a modal trigger around "New team". Sheaf renders the panel as
`grid grid-cols-[auto_1fr_auto]`, where every part it ships declares its place —
an item is `col-span-2`, a separator `col-span-full`, a group `contents`.

Anything else lands in the first column. The log out row shows it best: the item
inside the form still asks for `col-span-2`, but the form is the actual grid
child, so the span applies to nothing and the row renders half the width of the
one above it.

`PlaceDropdownChildren` places each direct child of a menu — `contents` for a
wrapper that holds a menu part, `col-span-full` for anything else, and a
spanning div around a component, whose outermost element Sheaf renders rather
than the view. Children that already say where they sit are left alone.

Finding the direct children means knowing what encloses what, which is what
`Nesting` answers — opening tags paired with their closing tags, per name, on a
stack, alongside the rest of the
[Blade rewriting](/docs/development/internals/blade-rewriting) tools.
`PreserveTextAlignment` uses the same pairing to find the wrapper an element
inherited its alignment from.

### A placement is one word, not two

Flux takes a menu's placement as two attributes and joins them itself, with a
space, on the way to its custom element:

```blade
@props([
    'position' => 'bottom',
    'align' => 'start',
])

<ui-dropdown position="{{ $position }} {{ $align }}" {{ $attributes }} data-flux-dropdown>
```

Sheaf takes one value and hands it straight to Alpine Anchor as a modifier:

```blade
x-anchor.{{ $position }}.offset.{{ $offset }}="$refs.button;"
```

So a rename alone leaves `position="bottom"` behind as a placement that is valid
and centred, and `align` — a prop Sheaf never declares — falls out of
`{{ $attributes }}` onto the panel wrapper as a stray, long-deprecated HTML
`align`. Every dropdown in the kit writes both attributes, so every dropdown
opened centred on its trigger. Most of them sit in chrome the stubs replace
wholesale, which hid it; the member-role picker in the team members table is
where it shows, a right-aligned trigger with a panel hanging under its middle.

`JoinDropdownPlacement` merges the pair: it reads both, writes
`position="{position}-{align}"`, and takes the `align` off. This is an action
rather than a `VALUES` entry because `VALUES` rewrites a value in place and
cannot consume a second attribute.

It runs before the rename, which is also what makes the defaults Flux's: a tag
writing only one of the two is still a Flux tag at that point, so the missing
half is `position="bottom"` or `align="start"` rather than anything Sheaf would
have fallen back to. A tag that writes neither is left as it is.

`align="center"` merges to the bare direction rather than to a `-center` suffix.
Sheaf's own default is `bottom-center`, which is not an Alpine Anchor placement
at all and resolves to plain `bottom` — so the centring this fixes is an accident
of that fallback, and the placements worth writing are the four directions plus
`-start` and `-end`.

A bound `:position` or `:align` holds an expression rather than a placement, so
there is nothing to join. Those are left alone and reported, the way an unmapped
tag is.

### Alignment is not only a rename

Flux's heading and text inherit their alignment, so the kit centres an auth
header by putting `text-center` on the wrapping div. Sheaf's components declare
`text-align` themselves — `text-start` in the heading's own class list, and a
`[:where(&)]:text-start` default in its text — and a declared value beats an
inherited one however faint it is. A pure rename therefore leaves every auth page
left-aligned.

`PreserveTextAlignment` restates the alignment on the tag: it reads the nearest
enclosing `div` that sets one, and adds `text-center!` when that differs from what
Sheaf already does. The `!` is load-bearing — Tailwind emits `.text-start` after
`.text-center`, so a plain `text-center` on the tag is a specificity tie the
heading's own class wins.

A tag that already says something about alignment, or whose classes are bound, is
left alone, and so is anything under Sheaf's own component directory.

### Neither is contrast

Flux draws `text` and `subheading` from the same declaration —
`[:where(&)]:text-zinc-500 [:where(&)]:dark:text-white/70` — so both are muted,
and the kit uses `subheading` for the sentence under every settings heading and
`text` for the description under every auth title. Sheaf has no `subheading` at
all, and its `text` is the other end of the scale: `text-neutral-950
dark:text-neutral-50`, written bare rather than inside `:where()`.

So the mapping is right about the component and wrong about the contrast. Both
Flux tags become `<x-ui.text>` — `x-ui.heading` would be worse, since Flux styles
its subheading as muted text rather than as a heading — and `MuteSecondaryText`
restates the contrast afterwards, in the vocabulary the rest of the
after-the-rename group reads.

Sheaf mutes text with opacity rather than with a second component, so that is what
gets restated: `opacity-75` for the ordinary secondary line, `opacity-50` where
Flux said `variant="subtle"`. Opacity composes with whatever colour the component
declares instead of competing with it on specificity, which is why it is the right
tool here and a `text-neutral-500` class would not be.

Three of Flux's props go the same way, because `x-ui.text` declares none at all —
`text.blade.php` is one `<div>` with `{{ $attributes->class(...) }}` — so each
lands on the rendered element as a stray HTML attribute that styles nothing:

| Flux | Sheaf |
| --- | --- |
| `variant="subtle"` | `opacity-50` |
| `color="red"` | `text-red-600! dark:text-red-400!` |
| `size="lg"` | `text-base`, alongside the opacity — a large subheading is still a muted one |

Note the asymmetry in `!`. Sheaf writes its size inside `[:where(&)]:` but its
colour bare, so a plain `text-base` beats `[:where(&)]:text-sm` on specificity
while a plain `text-red-600` only ties with `text-neutral-950` and would be
decided by whichever utility Tailwind emits last.

The kit's own `text-zinc-500 dark:text-zinc-400` pairs come off in the same pass,
for that same reason: against Flux's specificity-0 default they always won,
against Sheaf's bare colour they are a coin flip. Removing them leaves the kit
with one muting mechanism rather than two.

A tag whose classes are bound is left alone, as is one that has already had its
say about contrast — by carrying an `opacity-`, or by flagging a colour of its own
with `!`. That second guard is what keeps the kit's four `!text-green-600` success
messages from being dimmed. Sheaf's own component directory is skipped, as it is
for alignment.

### A level is a name, not a number

Flux numbers a heading's level, casts it to an integer and switches on it. Sheaf
names them, matching `level` against `h1` through `h6` and falling back to `h2`
for anything else — so `level="1"` is not passed through, it is thrown away, and
the heading renders as an `<h2>` like every other one on the page.

The kit writes the attribute five times as `level="1"`, once per variant, in
`partials/settings-heading.blade.php`, and three times as `level="3"` in the
two-factor recovery codes panel. Untranslated, the settings pages have no `<h1>`
at all and the recovery-codes heading sits at the same level as the section it
belongs under. Both files are rewritten in place rather than replaced from a stub,
so the fix has to live in the rewrite.

`VALUES` is the right shape for it — keyed by tag, attribute and value — and this
is the one entry that lists more than the kit writes. Everywhere else the argument
runs the other way: Sheaf sends an unknown variant through to classes, so a gap in
the table shows up on the page. Here the fallback is silent and `h2` is a
plausible tag to find, so a missing level is invisible short of a screen reader or
an outline check. That asymmetry is the whole reason all six are listed.

What the table cannot reach is a bound level. `:level="$depth"` carries a PHP
expression, the value pass reads literals, and the fallback stays as quiet as
before — so `BOUND_VALUES` gives the sweep a sentence to say about it, in the same
spirit as `UNMAPPED`. The kit writes no bound levels, so an ordinary run produces
no such warning.

### A size is a word both libraries own

Flux's heading has three outcomes: `xl` is `text-2xl`, `lg` is `text-base`, and
everything else — including the `base` its prop defaults to — is `text-sm`.
Sheaf's has eight, running `xs` through `4xl`, and defaults to `base`. The two
scales share their vocabulary and disagree about where each word sits on it, so
the rename carries every heading in the kit one or two steps up: `size="lg"`
goes from 16px to 20px, and a heading with no `size` at all goes from 14px to
16px.

`VALUES` handles the written half. `lg` becomes `sm`, which is Sheaf's word for
the size Flux was drawing, and `xl` is listed as itself — the same argument
`flux:button`'s `danger => danger` makes, that a no-op entry records a match
checked rather than a match missed.

The unwritten half is the one worth the paragraph. 56 of the kit's headings name
no size, so there is no value in the file for a value table to translate, and
the fix has to *add* an attribute rather than rewrite one. `AddAttribute` already
does exactly that, and it is planned ahead of `MapComponentTags`, writing
`size="base"` — Flux's own prop default, spelled in Flux's own vocabulary, onto a
tag that still says `flux:`. The rename then translates it to `xs` through the
same column as everything else.

Writing Flux's longhand out and letting the rename read it is the pattern
`RestructureCallouts` established, and it is why the action reads the Flux tag
rather than the Sheaf one. Keying it off `x-ui.heading` after the rename would
work too, but it would have to write `xs` — Sheaf's word — from an action that
knows nothing else about Sheaf's scale, and it would leave a project that had
written `size="base"` out by hand untranslated, because `AddAttribute` skips a
tag that already carries the attribute.

A bound `:size` is skipped for the same reason, and is left out of
`BOUND_VALUES` deliberately: Sheaf falls back to `text-base` for a size word it
does not know, so an untranslated one is visibly the wrong size on the page. That
is the ordinary variant argument, and only `level` escapes it.

### A default is a decision neither library wrote down

Flux's badge declares `variant` as `null` and Sheaf's declares it as `'solid'`,
and those two words are the whole finding. Flux's null takes a branch that paints
a translucent tint — `bg-zinc-400/15` behind `text-zinc-700` — and Sheaf's solid
takes one that paints `text-white` on `bg-neutral-900`. Every badge in the kit
names no variant, so every badge in the kit inverts on a rename.

The colour is the part worth being careful about, because it is what the defect
looks like from the outside. Four of the kit's badges say `color="zinc"`, and it
is tempting to read those as a colour that failed to translate. They are not:
neither library has a grey in its colour list. Flux's seventeen colours run `red`
through `rose` and so do Sheaf's, and `zinc` — like `neutral`, `gray`, `slate`
and `stone` — misses both and lands in each library's `default` arm. In Flux that
arm *is* the grey pill, so `color="zinc"` restates the default it already had. In
Sheaf it is the neutral chip, which under `variant="outline"` is the grey pill
again.

That is why the fix leaves `color` alone and adds `variant`, and why it covers
all seven of the kit's badges rather than the four that name a colour. The three
that name none had the identical defect with nothing in the markup to hint at it.

`outline` is the nearest tint Sheaf has, and it is not exact: alongside Flux's
translucent wash it draws a 1px `border-neutral-900` in light and `border-white`
in dark, which Flux does not. The alternative was to strip the variant question
and restate Flux's classes by hand — `bg-zinc-400/15 text-zinc-700` and their
dark counterparts — and that trades a visible border for an invisible fight over
precedence: Sheaf's badge merges with `$attributes->class(...)`, which
concatenates rather than resolves, so two competing `bg-*` utilities are settled
by Tailwind's source order and not by the attribute. `YieldIconColour` exists
because of exactly that, one component over. A border refit can describe beats a
tie it has to win.

The mechanism is the heading's, run from the other end. `AddAttribute` skips a
tag that already carries the attribute, so it writes the tint only where nothing
was said — and after `MapComponentTags` rather than before it, because Flux has
no word for the variant it defaults to. The heading could write `size="base"` in
Flux's own vocabulary and let `VALUES` translate it; a badge has nothing to
write, so the value it adds is Sheaf's `outline` and the tag it adds it to is
already `x-ui.badge`. A project that wrote `variant="solid"` out by hand still
gets Sheaf's solid, through `VALUES` and the skip.

### An overlay has to clear what it opens over

Sheaf's sidebar clips and Sheaf's sidebar covers, and a dropdown anchored inside
it runs into one or the other whichever way you place it.

Clipping first: the sidebar is `scrollable` by default, and CSS computes an
`overflow-x` of `visible` to `auto` whenever the other axis is not visible, so the
sidebar clips both ways at its 256px. A panel has a floor of `min-w-56` and grows
to its contents — the team switcher measures 265px — and the overflow takes the
difference off the right-hand edge.

`portal` is Sheaf's answer, and by itself it is a worse bug. Teleported to the
body the panel is no longer a descendant of the sidebar, so it stops winning on
position and starts losing on z-index: the panel is `z-50` and the sidebar carries
an inline `style="z-index:99"`, which beats every class including Sheaf's own
`md:[&_[data-slot=sidebar]]:z-auto`. The sidebar paints over the entire menu, and
a trigger whose menu is invisible is a trigger that looks broken.

So the two go together — `portal` on the dropdown, `z-[100]!` on the panel — and
refit writes them in two places for one reason each. The user menu is a stub refit
authors, so the pair is simply in the stub. The team switcher is a kit file refit
only rewrites, so `RaiseSidebarDropdowns` puts them there, reading the component
list off `LayoutStubs::sidebarComponents()` — the same class that decided to put
it in the sidebar in the first place. It runs after `MapComponentTags`, because
`x-ui.dropdown` does not exist until then.

Dropdowns a project writes for itself are not touched. The kit's other one is a
per-member role menu on the teams edit page, which sits in main and has neither
problem.

### A shared event is not a shared envelope

Flux and Sheaf both close a modal with a browser event called `close-modal`, which
is why this one survives every pass looking correct. What differs is what the
event has to carry. Flux read the modal's `name` out of the detail; Sheaf's modal
listens on the window and closes only when `detail.id` matches the id it resolved
for itself. An event with the wrong key — or with no detail at all — is received
and ignored, and nothing anywhere says so.

The kit raises those events from three places, and none of them survives the
rename working:

| Where | The kit's version | What refit writes |
|---|---|---|
| `<flux:modal.close>` around a Cancel button | wrapper meaning "my child closes the modal" | `<div class="contents" x-on:click="$data.close()">` |
| `$this->dispatch('close-modal', name: …)` after the work is done | Livewire event, `detail.name` | the same call with `id:` |
| `x-on:click="$dispatch('open-modal', '…')"` on the button that opens one | Alpine event, a bare string for a detail | nothing — the trigger around it already opens the modal |

`RestructureOverlays` does the first, because the wrapper is already its business.
`AddressModalDispatches` does the other two, over blades and `app/Livewire` alike,
matching on the event name rather than the argument — the toast beside it takes a
payload of its own that must not be touched.

The Cancel button takes the call rather than the event because it does not have to
know the id: it sits inside the modal's own Alpine scope, so `$data.close()`
reaches the instance directly. That is the same reasoning `BindModalState` uses for
`$modal.open(modalId)`, and it is what makes both work for the teams variants,
where the id is a bound expression rather than a literal.

The third is the only one that is deleted rather than translated, and only because
something else is already doing its job: `<flux:modal.trigger>` becomes
`<x-ui.modal.trigger>`, which opens the modal by id on a click of its own. The
`x-data=""` beside it goes too — that was Flux's instruction for this button, since
`$dispatch` is an Alpine magic and needs a component to run in, and with the
handler gone it declares a scope for nothing. A tag using that scope for anything
else keeps it.

A dispatch with no trigger around it is not redundant, it is broken, so that one is
rewritten to `$modal.open(…)` rather than removed. Neither branch touches a handler
that does more than raise the event: there is no safe way to keep the rest and move
the call, so it is left for a human.

### A shared word is not a shared meaning

`variant="segmented"` is the same attribute with the same value in both
libraries, which is exactly what makes it easy to miss: `MapComponentTags` has
nothing to do, and the tag comes out looking finished.

Flux reads "segmented" as a complete description of the control. Sheaf reads it as
one class list — `bg-neutral-200 rounded-box w-fit p-1`, the pill — and takes the
rest from two props that default the other way. `direction` is `vertical`, which
adds `space-y-2`; `indicator` is `true`, which draws the radio dot in every
segment. Renamed and no more, the appearance page renders three dotted rows
stacked down a narrow grey column.

`ShapeSegmentedGroups` writes `direction="horizontal"` and `:indicator="false"`
onto a segmented group that has not named them. Both spellings count as naming
one, and a bound `:variant` is not a value the sweep can read, so it is not a
group the sweep touches.

Worth knowing when reading Sheaf's radio: the item takes `variant` and
`indicator` through `@aware`, which reads the parent's *attributes* and not its
prop defaults. Saying these explicitly on the group is therefore the only way the
item hears them at all — which is also why the group's computed `name` default
never reaches the item, and every input renders `name=""`.

### Some of the kit is not a component at all

Light and dark is the one feature the kit takes from its library in JavaScript
rather than in markup, so no amount of tag mapping reaches it. Flux registers a
`$flux` Alpine magic and the kit binds to it directly — `x-model="$flux.appearance"`
on the segmented control, and `$flux.dark` in the expression that inverts the
two-factor QR code. Rename the tags and those bindings survive, aimed at a magic
that Flux's teardown is about to take away.

`sheaf:init --with-dark-mode` writes the same feature into
`resources/js/globals/theme.js` as a `$theme` magic, and the two line up property
for property:

| Flux | Sheaf | |
|---|---|---|
| `$flux.appearance` | `$theme.storedTheme` | the `light \| dark \| system` preference |
| `$flux.dark` | `$theme.isResolvedToDark` | what that preference currently resolves to |
| `$flux.appearance = …` | `$theme.setTheme(…)` | the only write that persists and repaints |

`RebindAppearanceToTheme` substitutes the two reads. The write is the part that is
not a substitution: assigning to `$theme.storedTheme` moves the reactive value and
neither writes localStorage nor puts `.dark` on the document, so the control would
slide and the page would stay the colour it was.

The one write the sweep does translate is the kit's own, because `x-model` makes
its shape knowable. The model keeps reading `storedTheme`, so the right segment
starts selected, and a `change` listener alongside it puts the click through
`setTheme()`. The getter/setter pair that would be neater has nowhere to live:
Sheaf's radio group declares an `x-data` of its own, and a second one passed in is
a duplicate attribute the HTML parser drops.

Every other `$flux` — a hand-written assignment, an Alpine `$flux.toast()`, a
modal — is left verbatim and reported. Each has its own Sheaf answer, and none of
them is this one. The PHP `Flux::toast()` is not one of these: it is not `$flux`
magic at all, and `RewriteToastCalls` rewrites it.

### A component can be half JavaScript, and the install only wires up one file

Sheaf writes a component's runtime into `resources/js` in two shapes. A file in
`globals/` registers an Alpine magic on `alpine:init` — `$theme` from `theme.js`,
`$modal` from `modals.js`. A file in `components/` registers the `Alpine.data()`
that the component's own markup names in its `x-data` — `selectComponent` from
`select.js`. Only `sheaf:init` writes an import, and only for its own `theme.js`.
Everything a later `sheaf:install` drops in is written to disk and left out of the
module graph.

Nothing about that is visible from the outside. The components render, Vite is
happy, and the failure waits for the browser to evaluate an expression naming
something undefined — where it throws inside Alpine's handler and stops. For a
global that expression is a click: `$modal.open(...)` on "Enable 2FA" and "Delete
account". For a component runtime it is the `x-data` itself, so the failure is on
the way in: `selectComponent is not defined` before the page has finished
painting, and every `<x-ui.select>` is then a box that will not open. The teams
kit puts one of those in the invite modal, which is how a team member gets
invited to no role at all.

`WireSheafRuntimes` imports both directories rather than a list of files, for the
reason the component graph is resolved by name rather than by the CLI's config: a
runtime that arrives tomorrow should arrive working.

The select needs one thing more. Its runtime drives the option list through
`$rover`, an Alpine plugin published as `@sheaf/rover` and installed by nothing —
the component's manifest declares no external dependency, and only Sheaf's
documentation for the primitive mentions it. Importing the runtime without
registering the plugin trades `selectComponent is not defined` for `$rover is not
defined`, so the action writes `Alpine.plugin(rover)` alongside the imports, and
`SheafLibrary` plans the `npm install` that makes it resolvable.

Three things about where those lines go:

- **Ahead of whatever else the entrypoint does.** A magic has to be registered
  before the markup reading it is evaluated, and an entrypoint that starts
  Livewire itself does that on its last line — after which registering a plugin is
  too late.
- **The primitive first.** Sheaf asks for it to be imported before the components
  that depend on it. ES imports are hoisted, so `Alpine.plugin(rover)` sitting
  among them still runs after every module has evaluated and before Alpine starts:
  the kit's `@vite` tag is deferred, and Livewire's own script — which defines
  `window.Alpine` and starts Alpine on `DOMContentLoaded` — is injected before
  `</body>` and runs first.
- **Only when the package is there.** An import Vite cannot resolve fails the
  whole build, which is a worse outcome than the select alone not opening. The
  action reads `package.json` and, when the primitive is missing, writes the
  imports it can and warns with the command to run.

## Adding a library

1. Implement `Library`. Put anything specific to it under `src/Libraries/<Name>/`.
2. Register it in `config/refit.php` under `libraries`, or from a service provider
   with `Refit::library(new YourLibrary)`.
3. Detection picks it up automatically — the service provider hands the registry's
   libraries to `ProjectDetector`.

If it needs a translation table, follow `ComponentMap`: a public constant, a
comment wherever the call was close, and an `UNMAPPED` entry rather than a guess.
