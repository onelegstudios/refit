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
question is different for each library, and two of the cleanup tasks only make
sense for one of them.

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
| Dependencies | `composer require sheaf/cli`, then `php artisan sheaf:init`, then `php artisan sheaf:install <component>` — each one skipped if it has already been done |
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
plan is refit closing a gap rather than refit being greedy.

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

### The parts that move rather than rename

Most of a Flux view is a rename away from a Sheaf one. Six things are not, and
they are worth knowing about because they are where a diff will look surprising.

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

**Dropdown triggers.** Flux takes a dropdown's trigger as its first child; Sheaf
takes it as `<x-slot:button>`. Refit wraps it.

**Modal close buttons.** `<flux:modal.close>` is a wrapper meaning "clicking my
child closes the modal". Sheaf's modal listens for a `close-modal` event, so the
wrapper becomes an element that dispatches it.

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

## Running without prompts

```bash
php artisan refit --answers='{"library":"sheaf","icons":"heroicons","tasks":["remove-flux"]}'
```

An unknown library key fails the run and lists the registered ones rather than
falling back to a default you did not ask for.

## Adding your own

A library is a class implementing `Onelegstudios\Refit\Contracts\Library`,
registered the same way tasks are — in `config/refit.php`, or from a service
provider with `Refit::library(new YourLibrary)`. See
[Component mapping](/docs/development/internals/component-mapping) for what the
contract asks of you.
