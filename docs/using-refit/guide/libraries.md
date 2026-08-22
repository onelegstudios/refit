---
title: Libraries
description: Keeping Flux, or replacing it with Sheaf UI.
order: 2
---

# Libraries

The first thing refit asks is where you want to end up:

```
 Which component library should this project end up on?
 ❯ Flux — keep the library the kit ships
   Sheaf UI — replace Flux entirely
```

It is asked first because it decides what everything after it means. The icon
question is different for each library, and one of the cleanup tasks only makes
sense if you are staying put.

The starter kit ships Flux, so Flux is always the *source*. A library is somewhere
a project can end up, and each one carries its own translation from Flux — which
is why adding a third costs one class rather than a new pair for every library
already registered.

## Flux

Keeping Flux changes no components at all. The run is exactly what refit did
before libraries existed: the [icon question](/docs/using-refit/guide/icons), and
whichever [tasks](/docs/using-refit/guide/tasks) you pick.

This is the default, and `--answers` treats a missing `library` key as Flux, so a
payload written before this feature existed still means what it always did.

## Sheaf UI

Choosing Sheaf rewrites every view onto [Sheaf's components](https://sheafui.dev)
and takes Flux out. When it finishes there is no `<flux:*>` tag anywhere in
`resources/views`, no `resources/views/flux` directory, and nothing left but the
Composer package — which refit tells you to remove rather than removing for you.

### You do not need to install it first

Sheaf is a copy-paste library: its CLI writes component source into your
application, and that code is then yours. Refit runs that CLI rather than
reimplementing it — including the parts that put it there in the first place.

| Stage | What happens |
|---|---|
| Dependencies | `composer require sheaf/cli`, then `php artisan sheaf:init`, then `php artisan sheaf:install <component>`, then `npm install @sheaf/rover` — each one skipped if it has already been done |
| Write | The chrome files, rewritten from refit's Sheaf stubs, and `app.css`'s imports put back in order |
| Reconcile | The overlays are reshaped, then every remaining tag and attribute is mapped |
| Format | `pint --dirty`, as always |

All of it shows up in the plan you confirm, so nothing is installed behind your
back. If you would rather do it yourself, run those commands first and refit will
find them done and leave them alone — the same way it leaves alone any component
already sitting in `resources/views/components/ui`, so anything you have
customised stays customised.

The plan asks for more components than your views mention, by design. Sheaf's CLI
resolves the dependencies a component's config declares, and some of those configs
declare less than the component actually renders — the dropdown writes `<x-ui.kbd>`
without naming `kbd` anywhere. Refit records what each component's source really
reaches for and installs the whole graph by name, so `sheaf:install kbd` in the
plan is refit closing a gap rather than refit being greedy. `field`, `label` and
`error` are there for the same reason from the other end: no Flux tag becomes any
of them, but refit writes all three itself, around every control it
[labels](#the-parts-that-move-rather-than-rename).

Two of those components are half Blade and half JavaScript, and the CLI installs
only the Blade half in any meaningful sense. The other half lands in
`resources/js` — a magic in `globals/`, an `Alpine.data()` in `components/` — and
nothing imports it, so the module never reaches the bundle and what it registers
never exists. Refit imports both directories from your entrypoint, whatever they
happen to hold, because a file in either is by definition something the page wants
loaded once.

`@sheaf/rover` is the npm half of the same gap. Sheaf's select runtime drives its
option list through a `$rover` plugin published separately, and the component's
manifest declares no dependency on it — so `sheaf:install select` succeeds, the
component is complete on disk, and the control cannot open. Refit installs the
package and registers the plugin in your entrypoint, ahead of the runtime that
reads it. If the install fails you keep a working migration and a note saying what
to run: the select is the only thing waiting on it.

`sheaf:init` is a one-time setup, and refit passes the flags your answers imply:
`--with-dark-mode`, because every layout the kit ships puts `class="dark"` on the
`<html>` element, and `--with-phosphor` when you asked for
[Phosphor icons](/docs/using-refit/guide/icons).

It leaves one thing behind that refit puts right. `sheaf:init` prepends its
`@import './theme.css';` to the *top* of `resources/css/app.css`, above
`@import 'tailwindcss'`, and from there it moves the point at which Tailwind can
open `@layer theme` — the `:root` block holding every theme variable is emitted
ahead of the layer, unlayered. Unlayered declarations beat layered ones whatever
the order, so every `.dark { --color-* }` override written into `@layer theme`
quietly stops applying: the kit's `--color-accent`, `--color-accent-content` and
`--color-accent-foreground`, and Sheaf's own `--color-primary` trio with them. The
logo is where you see it — the kit's mark is `dark:text-black` on a tile that is
only white in dark mode because of one of those overrides, so it goes black on a
tile that stayed dark. Refit moves the import below Tailwind's, which is the whole
fix, and skips the step when your stylesheet already has them in that order.

### If a dependency step fails

The run stops, and nothing is rewritten.

This stage exists to install what every later step rewrites your views *onto*, so
carrying on after it failed would point an entire application at components that
are not there. Being offline, or a Pro component wanting `php artisan sheaf:login`
first, both land here. Fix it and run refit again — the plan is rebuilt from
scratch each time, so the steps that did work are simply skipped.

`npm install @sheaf/rover` is the one step that does not stop the run. Nothing is
rewritten *onto* it, so a failure there is a warning in the notes and a select
that will not open, rather than a migration you have to start over.

### The parts that move rather than rename

Most of a Flux view is a rename away from a Sheaf one. Several things are not,
and they are worth knowing about because they are where a diff will look
surprising.

**The chrome.** Flux's sidebar is a root element. Sheaf's is a grid area of an
`<x-ui.layout>`, and the variant decides where its siblings go: the sidebar layout
puts the mobile header inside the main, the header layout puts the header
alongside it, in the row the grid keeps for one. No sequence of renames produces
either nesting, so `layouts/app/sidebar.blade.php`, `layouts/app/header.blade.php`
and `components/desktop-user-menu.blade.php` are written from stubs instead. The
stubs are built entirely from components Sheaf ships. **Anything you had
customised in those three files is in your git history rather than in the new
ones** — which is the main reason refit insists on a clean tree.

Because the stubs render the main themselves, refit also drops the `<flux:main>`
the kit wraps the slot in inside `layouts/app.blade.php`. Two of them would put a
full-height scrolling box inside a full-height scrolling box.

The one the stubs render is not a bare `<x-ui.layout.main>`, and the extra classes
on it are load-bearing. Sheaf's main is a plain block, so a page that sizes itself
against it has nothing to measure — the kit's dashboard fills the screen, and
without a column to grow into it collapses to the height of its own borders. Sheaf
also pads the main's children itself, by 2, from a selector on the main that a
plain utility loses to, so the `p-6 lg:p-8` the kit's pages were written against
comes back marked important on a child of its own. In the header layout the main
is sized to its grid row as well: Sheaf asks for a screen of height whichever
variant it lands in, and there the grid has already spent a header on the row
above, so a screen is a header too many and the layout clips the difference off
the bottom of every page.

The sidebar the stubs render is bare on purpose. Sheaf's sidebar paints its own
surface in two places — the panel, and the sticky brand row above it — and only
the panel takes attributes, so restating the kit's `bg-zinc-50 dark:bg-zinc-900`
tints one of the two and leaves the row white behind the logo. The chrome is
Sheaf's white-on-border sidebar instead. Flux's `sticky` and `collapsible="mobile"`
go with it: Sheaf spells them `sticky-header` and `collapsable`, and left in place
they were rendering as literal attributes on the div rather than reaching a prop.

The user menu at the foot of that sidebar is dressed as a nav row rather than as a
button — a ghost button is taller, squarer, darker and heavier than a Sheaf nav
item, and hovers neutral where every Sheaf nav hovers on the primary — and it
follows the nav one step further when the sidebar collapses to its 64px of icons.
The name and the chevron go, and the avatar keeps the same 36px square the icons
above it stand in. The rules name the sidebar rather than the collapse alone,
because Sheaf stamps the collapse on the layout and the header sits under that too:
the same component is the header's menu, and there it keeps its name at every
width.

**Dropdown triggers.** Flux takes a dropdown's trigger as its first child; Sheaf
takes it as `<x-slot:button>`. Refit wraps it.

**Tooltip triggers.** The same story with a different slot. Flux hangs a tooltip on
its child and takes the text as a `content` attribute; Sheaf reads `{{ $trigger }}`
and a `<x-ui.tooltip.content>` child. So refit wraps the child in
`<x-slot:trigger>` and turns the attribute into that content child. Left alone the
team pages die on `Undefined variable $trigger` before they draw a row. `position`
becomes `placement` while it is there, or the bubble points up wherever the kit
asked for down.

**Modal close buttons.** `<flux:modal.close>` is a wrapper meaning "clicking my
child closes the modal". Refit turns it into a wrapper that calls `$data.close()`,
the call Sheaf documents for exactly this button. Both libraries also close a
modal with a `close-modal` event, and that resemblance is a trap: Sheaf's modal
listens on the window and closes only when the event carries its own id, so an
event dispatched without one is heard and ignored. The kit's Cancel buttons are
the ones that go quiet.

The kit closes its modals from PHP as well, once the work is done —
`$this->dispatch('close-modal', name: 'invite-member')`. The event name is already
the one Sheaf listens for; the argument is not. Livewire sends named arguments as
the browser event's detail, and Sheaf reads `detail.id` where Flux read
`detail.name`, so refit re-addresses those calls to `id:`. Left alone the
invitation sends, the toast appears, the list updates and the dialog stays open on
top of it.

The same mismatch reaches the buttons that *open* a modal, and there the fix is a
deletion. `<flux:modal.trigger>` becomes `<x-ui.modal.trigger>`, which opens the
modal by id on its own, so the `x-on:click="$dispatch('open-modal', '…')"` the kit
puts on the button inside it — and the `x-data=""` that was only there to give that
magic a scope — are both taken off. A dispatch with no trigger around it is doing
real work, and becomes `$modal.open(…)` instead.

**Button contents.** Both libraries build a button as a flex row, and the
difference is one box: Flux's children are the flex items, while Sheaf wraps the
whole slot in a plain span first. Anything richer than a word then falls back into
block flow — and Tailwind renders an svg as a block — so the team switcher's icon,
team name and chevron come out as three stacked lines, with the chevron's `ms-auto`
pushing against no free space. Refit takes that wrapper out of the layout, and the
contents lay out in the button's own row again.

**Dropdown menu contents.** Sheaf renders a menu panel as a three-column grid,
and only the parts it ships declare a place in it. Refit places everything else:
`contents` on a wrapper such as the log out form, `col-span-full` on a block such
as the profile header, and a spanning div around a component such as a modal
trigger. Left alone, those rows render in the first column at a fraction of the
panel's width.

**The logo tile.** The kit gives its logo slot the classes that draw the accent
tile behind the mark, and Flux's brand renders them onto a wrapper. Sheaf's brand
renders the slot bare, so refit moves those classes onto an element inside the
slot. Left alone the mark keeps its `text-white dark:text-black` with nothing
behind it, which reads as no logo at all.

**Text alignment.** The kit centres its auth headings by putting `text-center` on
a wrapping div. Sheaf's heading and text declare `text-start` themselves, so refit
restates the alignment on the tag as `text-center!`.

**Item labels.** Flux reads a nav item's text out of its slot; Sheaf's
`navlist.item`, `navbar.item` and `radio.item` render `{{ $label }}` and never
touch the slot. So refit moves the contents onto the tag — a single Blade echo
becomes `:label`, keeping `__()` where the kit put it, and plain text becomes a
literal `label` — and closes the tag behind them. Left alone the settings
sub-navigation is three links with a href, a hover state and no text at all, and
the appearance page's segmented control loses its three words. A slot holding more
than a label is left alone and reported instead: Sheaf's item draws an icon, a
label and a badge, and has nowhere to put markup.

A menu heading takes the same move for a different reason. Sheaf's dropdown group
does render its slot, but only `label` is drawn as the heading — so the word lands
in the panel's grid as a child of its own: unstyled, in the first of three columns,
with the first item of the list beside it rather than under it. The team switcher's
"Teams" is the one the kit writes. A group holding items rather than a heading —
which is what an appearance menu is — is left exactly as it was.

**Form labels and errors.** Flux's input is the label, the control, the spacing
and the validation message in one tag. Sheaf splits those apart — `x-ui.field` is
the wrapper, `x-ui.label` the text, `x-ui.error` the message, `x-ui.input` only
the control — and nothing complains when a label arrives on a control with no prop
for it: `input` and `otp` pass it through to the wrapper div as an inert HTML
attribute, and `select` declares the prop and never renders it. So refit lifts the
label out of the tag and writes both around the control, in a field:

```blade
<x-ui.field>
    <x-ui.label :text="__('Email address')" />
    <x-ui.input name="email" type="email" required />
    <x-ui.error name="email" />
</x-ui.field>
```

The label's value moves verbatim, so `__()` stays a call. `label:sr-only`, which
the two-factor pages use to label their code field without showing the words,
becomes `class="sr-only"` on the label. The error is keyed the way Flux keyed it —
the control's `name`, or the Livewire property it binds — and is left out entirely
when the control has neither, since a message no bag can be asked for is worse
than none.

Left alone the plainest kit comes out with twenty unlabelled boxes across eleven
files — every field of login, register, both password pages and the profile and
security pages — rejecting you without saying why, because those pages had no
`@error` block of their own to fall back on. A control you have already put in
your own field gets the label alone, and the checkbox is not part of this at all:
Sheaf's renders the label it is handed.

**Password reveal.** Flux's `viewable` is Sheaf's `revealable`. It is only a
rename, but without it the attribute lands on the wrapper div and all ten password
fields in the kit lose their eye.

**Dropdowns in the sidebar.** Sheaf's sidebar is `scrollable` by default, and an
`overflow-y` of `auto` makes the `overflow-x: visible` beside it compute to `auto`
too — so the sidebar clips on both axes at its 256px, and a menu panel anchored
inside it comes out with its right-hand edge sliced off. Sheaf's answer is
`portal`, which teleports the panel to the body; on its own that trades the bug
for a worse one, because at the body the panel stops outranking the sidebar by
position and starts losing to the inline `z-index:99` the sidebar carries, so the
whole menu paints behind it and the trigger reads as dead. Refit writes both — the
`portal` and a `z-[100]!` on the panel — into the user menu it renders from a
stub, and into the team switcher it puts in the sidebar beside it.

**Segmented controls.** Both libraries spell one `variant="segmented"`, so the
attribute survives the rename looking like the whole answer. Flux reads it as a
complete description — a row of flush segments, no radio dots — while Sheaf reads
it as the pill background alone and leaves the rest to `direction`, which defaults
to `vertical`, and `indicator`, which defaults to `true`. Refit says both out
loud, so the appearance control renders as a row rather than a narrow grey column
of three dotted rows. A group that names either prop itself is left alone.

**Toasts.** A toast has two halves, and only one of them is markup. The rename
gets the container right on its own — `<flux:toast.group>` is Sheaf's bare
`<x-ui.toast>`, and the chrome stubs render one — but the kit raises its toasts
from PHP, through Flux's facade:

```php
Flux::toast(variant: 'success', text: __('Profile updated.'));
```

That dispatches a Livewire event only Flux's own component answers. Sheaf's toast
listens for a browser `notify` event instead, so left alone the call still runs,
still succeeds, and nothing appears — a form that saves in silence, with nothing
in the log to notice. Refit rewrites each one as the event Sheaf is listening for:

```php
$this->dispatch('notify', type: 'success', content: __('Profile updated.'));
```

`variant` becomes `type`, `text` becomes `content`, and `duration` carries across
unchanged because both libraries count in milliseconds. Flux's `danger` is Sheaf's
`error`; the other two variants share a name. A toast with no variant is left
without a `type`, which is Sheaf's `info` — Flux has no such variant, so nothing
is being invented there. The `use Flux\Flux;` import goes once the file has
stopped naming the class, which matters the moment you run the `composer remove`
below.

Where a call lives is half of what refit decides, because `$this->dispatch()` is a
promise about the class it lands in. Volt keeps a component's PHP inside the view
and `app/Livewire` holds the rest, so both are rewritten — this is the only thing
in refit that reads application PHP outside `resources/views`. A `Flux::toast()`
anywhere else, in a controller or an action class, has no `$this` to dispatch
from; refit names the file in `REFIT-NOTES.md` and leaves it, and the fix there is
Sheaf's flashed form:

```php
session()->flash('notify', ['type' => 'success', 'content' => 'Signed out.']);
```

Three things Sheaf's toast has no room for are dropped and named in the notes: a
`heading`, because Sheaf shows a single line; a `link`, because Sheaf's toast is
not clickable; and a `position`, because Sheaf positions every toast once, on
`<x-ui.toast>`. And a call refit cannot read with confidence is left exactly as it
was rather than rewritten into something that says the wrong thing — a variant
held in a variable, or more arguments than `Flux::toast()` has parameters.

Positional calls are read against the signature, `toast($text, $heading,
$duration, $variant, $position, $link)`, which is worth naming because Flux's own
documentation lists those six in a different order — heading before text. The
signature is the one refit follows, so `Flux::toast('Saved.')` becomes a message
rather than a heading Sheaf would then drop.

**Light and dark.** The kit switches appearance through Flux's `$flux` Alpine
magic, not through a component, so the rename leaves it pointing at something the
package took with it. Sheaf writes the same feature as a `$theme` magic in
`resources/js/globals/theme.js`, so refit moves the bindings across:
`$flux.appearance` reads as `$theme.storedTheme`, `$flux.dark` as
`$theme.isResolvedToDark`, and the appearance page's segmented control gets a
`change` listener calling `$theme.setTheme()` — the only call that persists the
choice and repaints the page. Left alone, the three buttons render, highlight, and
change nothing.

Anything else that names `$flux` — your own `$flux.appearance = …`, an Alpine
`$flux.toast()`, a modal — is left verbatim and named in `REFIT-NOTES.md`, because
each has its own Sheaf answer and refit will not guess which. The PHP
`Flux::toast()` is the exception, and is rewritten; see **Toasts** above.

That is only half the feature, and the other half is why refit writes a small
script into your head. Every layout the kit ships hardcodes `<html class="dark">`
and lets JavaScript correct it. Flux corrected it in time, with `@fluxAppearance`
— an inline script in the head, synchronous, so the class was already right
before anything was drawn. Its teardown takes that directive away, and Sheaf has
nothing that stands in: `theme.js` registers `$theme` inside an `alpine:init`
listener and arrives through `@vite`, which Vite emits as a deferred module. It
runs *after* the first paint, so the page comes up dark and snaps to light on
every load.

So refit puts the pre-paint half back, above your `@vite` call, reading the same
`theme` key with the same `system` default and the same media query as Sheaf's
own runtime. If you change one, change both. It goes into whichever file writes
your head, found when the tree has settled — so promoting `partials/head` to a
component moves it along with everything else.

It resolves the theme twice, and the second time is the one that matters most.
`wire:navigate` copies the incoming document's `<html>` attributes onto the live
one, so `class="dark"` is written back onto the page every time the reader clicks
a link — and Livewire merges only the head children the page does not already
have, so a script identical on every page is never re-run to undo it. Alpine does
not boot a second time either, which leaves Sheaf's runtime out of it too. The
script therefore listens for `livewire:navigated` as well; without that, choosing
light survives exactly until you navigate.

> Running `npm run dev` shows a second, unrelated flicker: the Vite dev server
> injects CSS through JavaScript rather than as a blocking stylesheet, so every
> load flashes unstyled first. That one is a dev-server property, not something a
> migration can fix — `npm run build` is where you check the real thing.

### What Sheaf has no answer for

Refit never guesses. A tag with no Sheaf equivalent is left exactly as it was and
named in [`REFIT-NOTES.md`](/docs/using-refit/reference/troubleshooting) along with
the files it is still in.

In practice that list is empty for a stock kit, because every one of them lives in
a chrome file that gets rewritten from a stub. They surface if you have moved that
markup somewhere unusual:

| Tag | Why |
|---|---|
| `flux:profile`, `flux:sidebar.profile` | Sheaf ships no profile component — the sidebar footer is ordinary markup |
| `flux:sidebar.header` | Sheaf's sidebar takes its header through `<x-slot:brand>` |
| `flux:sidebar.collapse` | Sheaf uses `<x-ui.sidebar.toggle>` instead |
| `flux:spacer` | Inside a sidebar this is `<x-ui.sidebar.push>`; elsewhere it has no counterpart |

### Afterwards

```bash
composer remove livewire/flux
npm run build
```

The build is not optional. The migration writes utilities the old stylesheet has
never seen — `text-center!` on the auth headings among them — so a stale build
renders the new markup with half its classes missing.

Then walk the app. Login, register, the two-factor setup modal, settings, and the
sidebar both collapsed and on mobile — that is where the mapping is most likely to
show a seam.

## Leaving a library

Choosing a target other than Flux is choosing to remove Flux. It is not a separate
question and there is no task to tick — the component tags are gone by the time
the migration finishes, and what outlives them comes out in the same run:

- the Tailwind `@source` lines in `resources/css/app.css` pointing into Flux's
  vendor stubs, and the `flux.css` import above them;
- the `@fluxAppearance` and `@fluxScripts` directives, which fatal once the
  package is gone;
- `resources/views/flux`, a directory that exists only to intercept Flux's own
  resolution. The kit puts four icon overrides and a `navlist/group` override in
  there; whatever you have added is just as dead.

All of it is listed in the plan you confirm, like everything else.

The Composer package itself is not removed. Refit prints the line instead, the
same way it does for its own uninstall:

```bash
composer remove livewire/flux
```

This is the library's own business rather than the target's — Sheaf does not know
what a `@fluxAppearance` is, and neither will the library after it. A `Library`
says how to take *itself* out, and refit calls that on whatever the project is
installed with and not ending on.

## Running without prompts

```bash
php artisan refit --answers='{"library":"sheaf","icons":"heroicons"}'
```

An unknown library key fails the run and lists the registered ones rather than
falling back to a default you did not ask for.

## Adding your own

A library is a class implementing `Onelegstudios\Refit\Contracts\Library`,
registered the same way tasks are — in `config/refit.php`, or from a service
provider with `Refit::library(new YourLibrary)`. See
[Component mapping](/docs/development/internals/component-mapping) for what the
contract asks of you.
