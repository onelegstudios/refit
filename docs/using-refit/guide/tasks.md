---
title: Tasks
description: The structural, tooling and cleanup tasks refit offers once the icon set is chosen.
order: 4
---

# Tasks

After the icon question you pick from a list of tasks. Only tasks that fit the
kit refit detected are offered, so you are never asked to choose something that
would do nothing.

| Group | Task | Key |
|---|---|---|
| Structure | Use components instead of partials | `partials-to-components` |
| Structure | Move the auth views out of pages | `auth-views-out-of-pages` |
| Structure | Move the non-page components out of pages | `components-out-of-pages` |
| Structure | Group components into folders | `namespace-components` |
| Structure | Move the toast stack to another corner | `position-toasts` |
| Structure | Put an icon on the buttons that have none | `icon-buttons` |
| Structure | Put an icon on the settings sections | `icon-settings` |
| Structure | Flatten the layout folders | `flatten-layouts` |
| Structure | Let the app layouts render for a guest | `guests-in-app-layouts` |
| Tooling | Offer developer logins on the login page | `login-links` |
| Cleanup | Delete the layouts the kit does not render | `single-layout` |
| Cleanup | Remove the Flux Pro `@source` line from `app.css` | `remove-flux-pro-source` |
| Cleanup | Use Flux's own components instead of the kit's overrides | `remove-flux-overrides` |

The keys are what [`--answers`](/docs/using-refit/guide/the-refit-command#running-without-prompts)
takes.

## Use components instead of partials

Turns `resources/views/partials` into anonymous Blade components. The kit ships
two — `partials.head` and `partials.settings-heading` — pulled in with `@include`
42 times between them. Both are self-contained, so the move is mechanical:

```blade
@include('partials.head')   →   <x-head />
```

Once every partial has moved out, the directory goes too — unless something
unplanned is still sitting in it, which is reported and left alone.

> [!NOTE]
> An `@include` that passes data has no mechanical translation: the component
> would need matching props. Those are reported and left for you.

## Move the auth views out of pages

`resources/views/pages` is Livewire's directory. Everything else in it is a
single-file component — an `⚡` on the filename, a class in the same file, a route
pointing at it:

```php
Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
```

The auth views are none of that. They are plain Blade, rendered by Fortify:

```php
Fortify::loginView(fn () => view('pages::auth.login'));
```

The folder is the only thing they share with their neighbours, and it costs
something: logging in reads as though it were a Livewire page, and every
reference carries a namespace that means nothing here. The task moves them to
`resources/views/auth`, where a Laravel developer looks for ordinary views:

```
resources/views/
├── auth/
│   ├── confirm-password.blade.php
│   ├── forgot-password.blade.php
│   ├── login.blade.php
│   ├── register.blade.php
│   ├── reset-password.blade.php
│   ├── two-factor-challenge.blade.php
│   └── verify-email.blade.php
└── pages/
    └── settings/
```

`FortifyServiceProvider` is the only file that names them, so it is the whole
reconciliation — `view('pages::auth.login')` becomes `view('auth.login')`, and
nothing in the Blade tree points at them at all. Whichever views survived
`install:features` are the ones that move; `pages/` itself stays, with the
Livewire pages still in it.

The class-component kit keeps the same views under `resources/views/livewire`,
where the argument is the same one. That is a different pair of names, so refit
does not assume it — register the task with them to move those too:

```php
use Onelegstudios\Refit\Facades\Refit;
use Onelegstudios\Refit\Tasks\MoveAuthViewsOutOfPages;

Refit::task(new MoveAuthViewsOutOfPages('resources/views/livewire/auth', 'livewire.auth.'));
```

## Move the non-page components out of pages

The kit already draws this line — in one place. `components/⚡team-switcher.blade.php`
is a single-file Livewire component sitting where components go, rendered as
`<livewire:team-switcher />`. Its neighbours were left in `pages/`, where they are
pages by folder and components by every other measure:

```blade
<livewire:pages::settings.delete-user-modal />
```

Nothing routes to that. It is a modal the profile page opens, wearing a namespace
that says the opposite — and the cost is that `pages/` stops meaning anything, so
the one question worth asking about a Livewire component, whether it is a screen
or a piece of one, has to be answered by reading it.

A route is the only thing that makes a Livewire component a page, so a route is
what the task reads. Everything a route does *not* name moves to
`resources/views/components` under the folder it already sits in:

```
resources/views/
├── components/
│   └── settings/
│       ├── layout.blade.php
│       ├── ⚡delete-user-form.blade.php
│       ├── ⚡delete-user-modal.blade.php
│       ├── ⚡two-factor-setup-modal.blade.php
│       └── two-factor/
│           └── ⚡recovery-codes.blade.php
└── pages/
    └── settings/
        ├── ⚡appearance.blade.php
        ├── ⚡profile.blade.php
        └── ⚡security.blade.php
```

Keeping the folder is what makes the reconciliation a subtraction — every
reference loses `pages::` and nothing else, landing on the name the
class-component kit uses for the same component:

```blade
<livewire:settings.delete-user-modal />
<x-settings.layout>
```

`settings/layout.blade.php` travels with them. It is an anonymous Blade component
rather than a Livewire one, so it moves as `<x-settings.layout>` — the path the
class-component kit ships it at, exactly. A folder that held nothing but
components, like `settings/two-factor`, goes once it is empty.

In the teams kits `pages/teams` gets the same reading: `⚡index` and `⚡edit` are
routed and stay, the five modals beside them move.

> [!NOTE]
> A section no route points into is not a page directory in the first place, and
> is left alone. `pages/auth` is that case, and
> [Move the auth views out of pages](#move-the-auth-views-out-of-pages) is its
> separate argument.

Blade tags are not the only place a component is named. `Livewire::test()` names
one as a string, and the kit's own test suite does it a few dozen times, so the
task rewrites the quoted names in `app/`, `routes/` and `tests/` too — a moved
component should not leave a green suite red.

## Group components into folders

Sorts the kit's loose anonymous components by what they are for, and drops the
prefix once the folder carries it — `<x-app-logo />` becomes `<x-brand.logo />`.
With both structure tasks picked, the kit comes out like this:

```
resources/views/components/
├── auth/
│   ├── header.blade.php
│   ├── session-status.blade.php
│   ├── passkey-registration.blade.php
│   └── passkey-verify.blade.php
├── brand/
│   ├── logo.blade.php
│   └── logo-icon.blade.php
├── layout/
│   ├── head.blade.php
│   └── desktop-user-menu.blade.php
├── settings/
│   └── heading.blade.php
└── ui/
    └── placeholder-pattern.blade.php
```

Three layers decide where each file lands, most specific first:

1. **The curated map**, which carries the judgement calls for the components the
   kit actually ships — `passkey-verify` reads as part of the auth flow, so it
   goes under `auth/` rather than into a folder of its own.
2. **A shared prefix.** A leading token two or more components share becomes
   their folder, and is dropped from the filename now that the folder says it.
3. **The fallback folder**, `ui/`, for anything with nothing to group with.

Only top-level files move. Anything already in a subfolder is namespaced, and
Livewire's own `x-layouts::` and `x-pages::` views are a different mechanism
entirely.

To lay the tree out your own way, register the task with your own map from a
service provider:

```php
use Onelegstudios\Refit\Facades\Refit;
use Onelegstudios\Refit\Tasks\NamespaceComponents;

Refit::task(new NamespaceComponents(['app-logo' => 'marketing/logo'], fallback: 'shared'));
```

## Move the toast stack to another corner

The kit renders a bare `<flux:toast.group>` in every layout, which leaves Flux on
its `bottom end` default — the corner a cookie bar or a mobile keyboard tends to
occupy. Moving the stack adds the position the group was missing, and nothing
else:

```blade
<flux:toast.group position="top end">
    <flux:toast />
</flux:toast.group>
```

Which corner is a follow-up question, because the project cannot answer it: they
are all equally true of it, and your own layout is the only thing that makes one
better than another.

| Answer | Where they stack | On Flux | On Sheaf |
|---|---|---|---|
| `top-right` | Top right, opposite the sidebar — the default | `top end` | `top-right` |
| `top-center` | Top centre, like a banner | `top center` | — |
| `top-left` | Top left, over the sidebar header | `top start` | `top-left` |
| `bottom-center` | Bottom centre | `bottom center` | — |
| `bottom-left` | Bottom left, over the sidebar footer | `bottom start` | `bottom-left` |

You answer with a corner rather than an attribute value, because the two
libraries disagree on both the spelling and the range. Flux reads one of
`top`/`bottom` and one of `start`/`center`/`end`, where `start` and `end` follow
the text direction. Sheaf's toast reads four literal corners and falls back to
its own default for anything else — so the two midpoints are not offered at all
on a run heading for Sheaf.

The bottom right is the one corner nobody is offered, because it is the one every
project already has. Leaving the task unpicked is how you keep it.

Without prompts that is
`--answers='{"tasks":["position-toasts"],"position-toasts":{"corner":"bottom-left"}}'`,
whichever library you are heading for. See
[running without prompts](/docs/using-refit/guide/the-refit-command#running-without-prompts).

The attribute lands on whatever the container is called by the time the plan
runs: `<flux:toast.group>` on a project staying with Flux, `<x-ui.toast>` on one
moving to Sheaf, which the migration has renamed it to earlier in the same pass.
A container that already carries a `position` is left as it is, whichever corner
you picked here.

## Put an icon on the buttons that have none

The kit ships two buttons wearing an icon and several dozen that are words alone.
Both of the two are in the teams kits — `plus` on "New team", `user-plus` on
"Invite member" — so the starter kit has already decided that a button with an
icon is how it wants to look, and then written every other one without one. Save,
Cancel, Delete account, Log out, Reset password: all text, in a shell whose
sidebar, navbar and user menu are nothing but icon-and-label rows.

The task finishes the job. Each button's own label picks its icon:

```blade
{{-- before --}}
<flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>

{{-- after --}}
<flux:button icon="check" variant="primary" type="submit">{{ __('Save') }}</flux:button>
```

Nothing is asked, because nothing here is a choice your project cannot make for
itself — the labels are the kit's own, read back.

### Which icon a label gets

| Icon | Labels |
|---|---|
| `check` | Save, Confirm, Accept |
| `x-mark` | Cancel, Decline |
| `trash` | Delete account, Delete team, Remove member, Remove passkey, Cancel invitation |
| `envelope` | Email password reset link, Resend verification email, Send invitation |
| `user-plus` | Create account, Register, Invite member |
| `plus` | Create team, New team |
| `key` | Reset password |
| `arrow-right-end-on-rectangle` | Log in |
| `arrow-right-start-on-rectangle` | Log out, Leave team |
| `lock-closed`, `x-circle` | Enable 2FA, Disable 2FA |
| `eye`, `eye-slash` | View recovery codes, Hide recovery codes |
| `arrow-path` | Regenerate codes |
| `finger-print` | Add passkey |
| `chevron-left`, `chevron-right` | Back, Continue |

A button that removes something gets the trash whatever it is removing, because
what you are about to lose is the part worth drawing — and a pair that undoes
itself gets one drawing and its opposite, the way the kit's own recovery codes
already read.

The labels are matched on their words rather than on their spelling, so case and
the line breaks a long tag is wrapped over make no difference. Labels the table
does not have are named in [`REFIT-NOTES.md`](/docs/using-refit/reference/troubleshooting)
rather than matched on a guess — "Keep invitation" is the one in the teams kits,
there being no icon for declining to cancel something that is not the icon for
cancelling it.

To name your application's own buttons, register the task with your own table. It
replaces refit's rather than adding to it, so spread that one in to keep it, and
write the names as Heroicons spells them — the set every translation starts from:

```php
use Onelegstudios\Refit\Facades\Refit;
use Onelegstudios\Refit\Tasks\IconButtons;

Refit::task(new IconButtons([...IconButtons::ICONS, 'publish' => 'cloud-arrow-up']));
```

### What is left alone

A button that already names an icon has made the decision itself, and keeps it —
in any of its library's attributes, bound or literal, leading or trailing. An
empty button is an icon-only one the kit sized and placed on purpose, and has no
label to read in the first place.

Past that, only contents refit can read as a label count. A single `{{ __('…') }}`
or a line of plain text is a label; anything with markup, a variable or a second
node in it is not:

```blade
<flux:button variant="primary" wire:click="register">
    <span x-show="!loading">{{ $label }}</span>
    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
</flux:button>
```

There is no reading of that which is not a guess, so the passkey buttons are left
as they are.

### Which set the icons are spelled in

Whichever one [you chose a moment earlier](/docs/using-refit/guide/icons), because
the icon pass has already run by the time the buttons are swept — so the name
that lands on the tag is the final one rather than one for a later sweep to
translate:

| Icon set | Save | Cancel | Reset password |
|---|---|---|---|
| The kit's mix, or Heroicons only | `check` | `x-mark` | `key` |
| Lucide only | `check` | `x` | `key-round` |
| Phosphor, on a Sheaf run | `ps:check` | `ps:x` | `ps:key` |

A Lucide run gets the artwork with it. Flux draws Lucide through
[override files](/docs/using-refit/guide/icons#staying-on-flux), and the icon pass
generates one for every Lucide name your project already uses — which a button
icon is not, because refit is what puts it there. So the task brings an override
for each name it is the first to write, and leaves the ones the icon pass is
already writing to it.

The tag itself is whatever the button is called by the time the plan runs:
`<flux:button>` on a project staying with Flux, `<x-ui.button>` on one
[moving to Sheaf](/docs/using-refit/guide/libraries), which the migration has
renamed it to earlier in the same pass.

The sweep runs ahead of the tasks that write buttons of their own, so an answer
you gave one of those is the one that stands — the way in
[a guest gets](#what-the-way-in-shows) shows what you said it should show, icon
or no icon.

## Put an icon on the settings sections

The settings sub-navigation is the one navlist the kit writes without icons.
Every other nav row in the shell has one — Dashboard, Repository, Documentation,
the user menu's own Settings and Log out — and then the page those rows open puts
four words in a column and a heading above the form.

The task gives each section a drawing, everywhere the section is named. On its
row in the menu:

```blade
{{-- before --}}
<flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>

{{-- after --}}
<flux:navlist.item icon="user" :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
```

And over the page that row opens, which is the useful half on a narrow screen:
there the menu scrolls away above the fold and the heading is the only thing left
saying which of the four you are on. The heading is the layout's to draw and the
section's to name, so the two halves are rewritten together — the layout learns
an `icon` prop:

```blade
<flux:heading class="flex items-center gap-2">
    @isset($icon)
        <flux:icon :name="$icon" class="size-4" />
    @endisset

    {{ $heading ?? '' }}
</flux:heading>
```

and each page passes the one its own heading asks for:

```blade
<x-settings.layout icon="key" :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
```

The icon goes inside the heading rather than in a wrapper around it, because the
gap under a heading is the heading's own: Flux draws it with
`[&:has(+[data-flux-subheading])]:mb-2`, a rule that only fires while the
subheading is the heading's next sibling. A wrapper would put a `<div>` between
the two and take the gap with it, so the arrangement of the page is left exactly
as the kit wrote it and only the heading's own contents change. The svg is
`aria-hidden`, so the heading reads the same with an icon in it as without, and
the prop is guarded, so a page that passes no icon renders the heading it always
had.

And over each section written further down the same page. The security page is
where that shows: under the password form it has a two-factor block and a passkey
block, each a heading of its own, and those are given their icon on the spot
rather than through a prop. The `size-4` and the bare colour are the kit's own
and not a choice: its recovery codes panel already writes an iconed section
heading, and that is the icon it writes — so that panel needs nothing, and the
icon takes the heading's colour rather than naming one of its own.

```blade
<flux:heading class="flex items-center gap-2">
    <flux:icon name="finger-print" class="size-4" />
    {{ __('Passkeys') }}
</flux:heading>
```

Nothing is asked, because nothing here is a choice your project cannot make for
itself: the sections are the kit's own, and the icons are their labels read back.

### Which icon a section gets

| Icon | Label | Where |
|---|---|---|
| `user` | Profile | Menu row, page heading |
| `shield-check` | Security | Menu row |
| `users` | Teams | Menu row, page heading |
| `swatch` | Appearance | Menu row, page heading |
| `key` | Update password | Page heading |
| `lock-closed` | Two-factor authentication | Section heading |
| `finger-print` | Passkeys | Section heading |

Three kinds of label, because a section is named wherever it is written. Security
is the words on the menu row; Update password is the heading on the page that row
opens; Passkeys and Two-factor authentication are the sections further down the
same page. Each is answered on its own terms rather than one being made to stand
for the others, so the shield marks the section, the key marks the form at the
top of it, and the padlock and the fingerprint mark the two below.

Labels are matched on their words rather than on their spelling, so case and the
line breaks a long tag is wrapped over make no difference. A menu row or a page
heading whose label refit has never seen gets no icon and is named in
[`REFIT-NOTES.md`](/docs/using-refit/reference/troubleshooting) instead, which is
what a settings section of your own gets: the row keeps its words, the page keeps
its heading, and the note says which.

To name your application's own sections, register the task with your own table.
It replaces refit's rather than adding to it, so spread that one in to keep it,
and write the names as Heroicons spells them — the set every translation starts
from:

```php
use Onelegstudios\Refit\Facades\Refit;
use Onelegstudios\Refit\Tasks\IconSettings;

Refit::task(new IconSettings([...IconSettings::ICONS, 'billing' => 'credit-card']));
```

### What is left alone

A row that already names an icon has made the decision itself, and keeps it — in
any of its library's attributes, bound or literal. A layout call that already
passes one keeps that too, and a heading that already has an icon — inside it or
in front of it, the way the recovery codes panel does — is not given a second
one. A heading styled through a bound `:class` is left alone as well, there being
no reading of one that is not a guess.

Only the headings inside the layout count as sections, which is what makes a
label safe to match on at all. The screen-reader heading above the slot says
Teams as well, and the modal titles below it say Remove passkey and Delete
account, and none of those is a section — so a page's headings outside the slot
are left alone, as are the ones that live in components of their own: the account
deletion form, the recovery codes panel.

A section heading the table has no icon for is passed over in silence rather than
noted, because the sweep reads every heading in the slot and a note would list
your application's headings rather than its settings sections. In the teams kits
that is Team members, Pending invitations and Delete team, which stay bare. Menu
rows and page headings are the ones the notes are about.

### Which set the icons are spelled in

Whichever one [you chose a moment earlier](/docs/using-refit/guide/icons), the
same way the [button labels](#put-an-icon-on-the-buttons-that-have-none) are:

| Icon set | Profile | Appearance | Passkeys |
|---|---|---|---|
| The kit's mix, or Heroicons only | `user` | `swatch` | `finger-print` |
| Lucide only | `user` | `swatch-book` | `fingerprint-pattern` |
| Phosphor, on a Sheaf run | `ps:user` | `ps:swatches` | `ps:fingerprint` |

A Lucide run gets the artwork with it, for the reason the buttons do: a settings
icon is a name the icon pass cannot see, because refit is what puts it there. So
the task brings an override for each name it is the first to write, and leaves
the ones the icon pass is already writing — `users` in a teams kit, which the
team switcher already draws — to it.

The components are whatever they are called by the time the plan runs:
`<flux:navlist.item>` and `<flux:heading>` on a project staying with Flux,
`<x-ui.navlist.item>` and `<x-ui.heading>` on one
[moving to Sheaf](/docs/using-refit/guide/libraries). Sheaf's nav row takes
its label as an attribute rather than as contents, which the migration has
already moved it to earlier in the same pass, so the label is read from there
instead — the words are the same either way.

## Flatten the layout folders

Every view names `<x-layouts::app>` or `<x-layouts::auth>`, and neither of those
files holds a page. `layouts/app.blade.php` is five lines that hand off to a
variant in the folder beside it:

```blade
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
```

So the shell every view names lives in a file no view names, one directory down.
The task folds the variant into the layout that renders it — the wrapper's
contents standing where the variant's `{{ $slot }}` was — moves the other
variants up beside it, and the folders go:

```
resources/views/layouts/
├── app.blade.php          ← was app/sidebar.blade.php
├── app-header.blade.php   ← was app/header.blade.php
├── auth.blade.php         ← was auth/simple.blade.php
├── auth-card.blade.php
└── auth-split.blade.php
```

Which one is folded is read from the delegating layout, the same way
[Delete the layouts the kit does not render](#delete-the-layouts-the-kit-does-not-render)
reads it. Swap to `<x-layouts::app.header>` first and the header shell is the one
that moves in, with the sidebar flattened beside it.

Nothing points at a renamed file afterwards: `auth/card.blade.php` was
`<x-layouts::auth.card>` and is `<x-layouts::auth-card>`, and every reference to
it is rewritten.

That pairs with the deletion task rather than competing with it — one asks whether
the variants nothing renders are worth keeping, the other asks whether the ones
that stay need a folder. Pick both and the unrendered variants are deleted rather
than flattened, whichever order they run in.

A fold that would not survive is reported and the variant is flattened like any
other, leaving the layout rendering it under its new name. That happens when
another view renders the variant itself, when the wrapper does more than pass its
own variables through — extra attributes, a named slot, markup around the tag — or
when the variant declares `@props`, reads `$attributes`, or does not render
`{{ $slot }}` exactly once.

As with the other layout task, pass the families you want to the constructor:

```php
use Onelegstudios\Refit\Facades\Refit;
use Onelegstudios\Refit\Tasks\FlattenLayouts;

Refit::task(new FlattenLayouts(['app']));
```

## Let the app layouts render for a guest

The kit draws its application shell for one kind of visitor.
`layouts/app/sidebar.blade.php` reads `auth()->user()->name` where the profile
goes, `auth()->user()->initials()` in the mobile header, and posts to
`route('logout')` from the menu — none of it asking first:

```blade
<x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
```

So the layout every signed-in page names is a layout no public page can use.
Point a marketing page, a pricing page or a shared link at `<x-layouts::app>` and
the view fails on a null user rather than rendering without one — which is a
structural decision rather than a missing feature, and the one this task
reverses. The signed-in chrome goes behind `@auth`, and a guest gets the way in
where the user menu was:

```blade
@auth
    <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
@else
    <div class="hidden lg:flex items-center gap-2">
        <flux:button :href="route('login')" icon="arrow-right-end-on-rectangle" variant="subtle" class="flex-1">{{ __('Log in') }}</flux:button>
        <flux:button :href="route('register')" icon="user-plus" variant="primary" class="flex-1">{{ __('Register') }}</flux:button>
    </div>
@endauth
```

Both states then render the same shell, and the navigation, the brand and the
mobile toggle stay where they were for everybody.

### What counts as signed-in chrome

Nothing is listed by name. A piece of chrome needs a user when its own markup
dereferences one — or when something it renders does, which is the usual case:
`<x-desktop-user-menu />` carries no `auth()` call at all, and the component
behind it is nothing but one. So the task follows a tag to the files that decide
what it renders — an anonymous component's view, a single-file Livewire
component, a class component's view *and* its class — and reads those too. The
teams kits get `<livewire:team-switcher />` and `<livewire:create-team-modal />`
guarded for exactly that reason.

Then each find grows outwards, which is the part worth knowing about. The
smallest guard is useless — `@auth` around the avatar inside a dropdown leaves a
guest opening an empty menu — and the largest is worse, because the sidebar holds
the user menu and guarding the sidebar would take the navigation with it. So a
find grows into the element around it only while *everything* in that element is
the user's, counting whitespace and Blade comments as nothing. The kit's mobile
menu grows from the profile out to the `<flux:dropdown>` and stops at the
`<flux:header>`, which also holds the toggle a guest still needs.

### The links a guest gets

Only chrome with a log out in it is a user menu, and only a user menu is given a
stand-in — there is no guest counterpart for a team switcher, so that is guarded
with nothing in its place. Where there is one, a guest gets the way in the kit
still has to offer: a log in link, and a register link beside it where
registration survived `install:features`. The WorkOS kits hand registration to
AuthKit and have no `register` route at all, so they get the log in link alone —
naming a route that is not there would throw rather than render.

The links are written in the tag of
[whichever library the project is ending on](/docs/using-refit/guide/libraries),
so a Sheaf run gets `<x-ui.button>`, and they carry the guarded chrome's own
classes, so a menu that was hidden at a breakpoint is replaced by links that are
hidden there too.

The one class that does not come across is the `display`. A button is laid out as
a flex row, so the sidebar menu's `lg:block` would leave the button's own
`justify-center` with nothing to centre and the label would stop sitting in the
middle of it. Nothing is needed in its place either: what decides the menu's
width is the sidebar it is a flex child of, and the link is one too. So the
sidebar's `hidden lg:block` becomes `hidden lg:inline-flex` — hidden on mobile as
the desktop menu was, and filling the sidebar as it did — while a display
standing on its own, with no variant holding a `hidden` up, is simply dropped.

Register is the one to press, the way the kit's own welcome page has it, and log
in is the quieter half of the pair. The variants are the only styling refit
writes, and both are said outright rather than left to a default: writing no
variant means an outline button in Flux and the prominent one in Sheaf, so a pair
with one of them bare would read as two buttons to press there.

`variant="primary"` needs no translating — it is the one variant name Flux and
Sheaf share outright, so it survives a target's rename pass untouched. The quiet
one is not shared, so it is asked of the library the project is ending on: Flux's
`subtle`, Sheaf's `ghost`.

Where there are two of them they go in a box of their own, because the chrome
they replace was one element and its parent laid it out as one. `<flux:header>`
gives its children no room between them — it is the children that ask, `mr-2` on
the sidebar toggle and `me-1.5` on the icon navbar — so two links standing loose
in it come out touching. The box asks the parent for nothing and gives the room
itself, which is also why the guarded chrome's classes go on the box rather than
on each link.

The box says nothing about how it displays where the kit's own classes already
settle it. A bare `flex` beside the kit's `hidden` is two display utilities in
one layer, and then the stylesheet's order decides which wins rather than
anything in the file. So the sidebar's box reads `hidden lg:flex` where the menu
read `hidden lg:block`, and the header's, carrying no classes of its own, simply
says `flex`. Only a display standing on its own counts as settling it: Sheaf's
rail hides its menu with `max-md:hidden` and leaves every other size to the
element itself, so that box says `flex` too.

The links themselves get `flex-1`, an equal part of the box each. The box is as
wide as whatever it stands in, and in the sidebar that is the whole of it — so
without that the pair would sit at the width of its own labels with the rest of
the line empty beside it. A lone log in link needs none of this: no box, no
share, and no class at all where the chrome it replaces had none.

Past that the point is that a guest has somewhere to go, not that refit has
styled your chrome — reorder them, stack them, move them, which the run report
says as well.

### What the way in shows

Whether the links wear an icon is a follow-up question, because the project
cannot answer it: an icon and a label read as the kit's own menu items do, an
icon on its own fits a header that is already a row of them, and words on their
own are the plainest thing a guest can be given.

| Answer | What a link shows |
|---|---|
| `both` | An icon and a label — the default |
| `text` | A label on its own |
| `icon` | An icon on its own, with the label as its `aria-label` |

The icons are not part of the question. They are written in whichever set
[you chose a moment earlier](/docs/using-refit/guide/icons), because the icon
pass has already run by the time the chrome is guarded — so the name that lands
in the layout is the final one rather than one for a later sweep to translate:

| Icon set | Log in | Register |
|---|---|---|
| The kit's mix, or Heroicons only | `arrow-right-end-on-rectangle` | `user-plus` |
| Lucide only | `log-in` | `user-plus` |
| Phosphor, on a Sheaf run | `ps:sign-in` | `ps:user-plus` |

A Lucide run gets one extra file. Flux draws Lucide through
[override files](/docs/using-refit/guide/icons#staying-on-flux), and the icon
pass generates one for every Lucide name your project already uses — which
`log-in` is not, because refit is what puts it there. So the task brings the
artwork with it:

```
resources/views/flux/icon/log-in.blade.php   ← Lucide's log-in, for the way in
```

Without that file Flux would resolve `log-in` against its own Heroicons and leave
one icon from the set you just left in the middle of the new one. `user-plus` is
written the same way, unless the icon pass is already writing it — the teams kits
name it on their own, and refit does not plan the same file twice.

Only an icon refit can actually draw is offered. If the bundled artwork is ever
missing a name, the question is not put at all and a guest gets the way in made
of words, rather than a link pointing at an override nobody wrote.

An icon on its own still says what it is. A button with nothing in it reads as
its own route to a screen reader, so the label the icon stands in for becomes the
link's `aria-label`, translated the way the words would have been:

```blade
<flux:button :href="route('login')" icon="arrow-right-end-on-rectangle" :aria-label="__('Log in')" variant="subtle" class="flex-1" />
```

Without prompts that is
`--answers='{"tasks":["guests-in-app-layouts"],"guests-in-app-layouts":{"show":"icon"}}'`.
See [running without prompts](/docs/using-refit/guide/the-refit-command#running-without-prompts).

### What is left alone

Only the application family. The auth layouts render for a guest already, that
being their whole job, and every other view holding an `auth()->user()` sits
behind an `auth` middleware — guarding those would answer a question nobody
asked. Chrome already inside an `@auth` or `@guest` you wrote yourself is left as
it is.

The task runs after the other layout tasks and after a library has written its
own chrome, so it guards the shell in whatever shape it ended up in — folded into
`layouts/app.blade.php`, or rewritten from
[Sheaf's stubs](/docs/using-refit/guide/libraries). Pass your own family to the
constructor if your shell is called something else:

```php
use Onelegstudios\Refit\Facades\Refit;
use Onelegstudios\Refit\Tasks\OpenLayoutsToGuests;

Refit::task(new OpenLayoutsToGuests('marketing'));
```

## Offer developer logins on the login page

Typing a password into your own application a dozen times a day is a tax the kit
leaves you paying, and the usual answer is a seeder plus a password you have
memorised. [`spatie/laravel-login-link`](https://github.com/spatie/laravel-login-link)
is the better one: a link that signs you in as whoever it names. The task
installs it as a dev dependency and writes the links into the login view, under
the form:

```blade
        </form>

        @env('local')
            <flux:card variant="outline" class="rounded-lg p-4 text-sm text-center">
                <flux:heading size="base" class="mb-2">Log in as</flux:heading>
                <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1.5">
                    <x-login-link email="test@example.com" :user-attributes="['name' => 'Test User']" label="Test User" />
                    <span aria-hidden="true" class="text-zinc-300 dark:text-zinc-600">|</span>
                    <x-login-link email="admin@example.com" :user-attributes="['name' => 'Admin User']" label="Admin User" />
                </div>
            </flux:card>
        @endenv
```

A card of its own, with the verb in the heading and the accounts divided by
pipes: "Log in as: Test User | Admin User" says in one line what a column of
"Log in as the …" links says in five, and it reads as a panel belonging to the
page rather than something loose under your login form. Each login renders as its
own little form — the package posts rather than links — so the row is laid out
with flex and wraps on a narrow screen.

The rounding and the padding are written out as utilities rather than left to the
card, and `variant="outline"` gives it no fill of its own. Both are deliberate.
Each library keeps a card's geometry in a stylesheet — Flux derives it from CSS
variables in `flux.css`, Sheaf writes `:where()` defaults into the component it
installs — so a project whose build does not have that stylesheet renders a
square, unpadded box. Utilities land in Tailwind's own layer, above both, in a
file Tailwind scans. And a filled card carries a tint mixed for the page it was
drawn on, so giving this page a background later would leave the card sitting on
a patch of the old one.

The card and its heading are the ones your application already has. `flux:card`
is in Flux's free edition, so a project staying on Flux has both; a project
[heading for Sheaf](/docs/using-refit/guide/libraries) gets `<x-ui.card>` and
`<x-ui.heading>`, and the task installs the card, because the kit builds its own
cards out of divs and nothing in the migration would otherwise bring one. The
heading needs no install: the kit writes `flux:heading` above every form it
ships, so the migration has already brought Sheaf's.

The heading sits at the size of the labels over the inputs above it, which is the
line it belongs with — `flux:label` is `text-sm font-medium` over
`text-zinc-800 dark:text-white`, and a heading at any size under `lg` is the same
three things. Not the page's title, which is `xl` and belongs to the page rather
than to a panel inside it. `base` is Flux's own default for the prop, written out
rather than left unsaid. The card itself stays centred, like the footer the kit
writes under it: it is a block of its own rather than another field in the form,
and a row that wraps reads better centred than ragged.

The two libraries put those words at different points on their scales, which is
why the Sheaf spelling says `size="xs"`;
[`ComponentMap`](/docs/development/internals/component-mapping) records that
translation for the headings the kit itself wrote, and this is the same step
taken by hand. Both spellings are decided
before the plan runs rather than left to the migration: the migration renames
Flux's tags in the same pass as this, and ahead of it, so a `flux:card` written
here would be the one tag left behind on a project that has just dropped Flux.

Which accounts is a follow-up question, because the project cannot answer it. The
kit seeds exactly one user — `Test User`, at `test@example.com`, from
`DatabaseSeeder` — and everything past that is about how you work:

| Account | Name | The link it writes |
|---|---|---|
| `test` | Test User | `test@example.com`, the user the kit's seeder creates |
| `admin` | Admin User | `admin@example.com` |
| `new` | The factory's | A random address every time the page renders, so every click is an account with nothing behind it |
| `unverified` | Unverified User | No address, and `email_verified_at` held at `null` — so you land on the "verify your email" screen instead of the dashboard |
| `first` | — | No attributes at all, which is how the package means "whoever is first in the users table" |

They do not rule each other out, so the question takes
[several answers](/docs/using-refit/guide/custom-tasks#several-answers-to-one-question)
rather than one. Pick as many as you use; the links come out in the order above
whichever order you tick them in. The addresses are refit's, because a fresh kit
has no opinion about them — what comes out is ordinary Blade, so pointing a link
at `owner@acme.test` afterwards is an edit rather than a setting.

None of them has to exist first. The package creates a user it cannot find
through your user model's factory, so a link is a login the first time it is
clicked and the same login every time after — which is also why `new` is written
as a random address rather than a fixed one. A fresh account on every click costs
a row in the users table each time you use it.

The name comes from `:user-attributes`, which the package passes to the factory,
because the kit's `UserFactory` fills the name with `fake()->name()` — and an
admin called Rosalyn Bartoletti is not much of a second account to test with.
`new` is the one left to the factory, name and all: it stands in for somebody who
has just signed up, so a random name belongs with the random address, and there
is nothing to come back to anyway.

`unverified` is the same trick aimed at the other screen. The kit guards the
dashboard with `verified` and its `User` implements `MustVerifyEmail`, so an
account whose `email_verified_at` is `null` lands on the verify page — which is
the one page you otherwise have to register a throwaway address to see. It is
offered only where that contract is actually on the user model, because without
it the middleware waves everyone through and the link would be an ordinary login
wearing a name that says otherwise.

Its link carries no address on purpose. The lookup becomes
`where name = ? and email_verified_at is null`, so it comes back to the same
account while that account is unverified, and the moment you verify it — the
whole reason to be there — it stops matching and the next click gets a fresh one.
A fixed address would make that second click a unique constraint instead.

> [!NOTE]
> The package looks an account up by *every* attribute the link gives it, the name
> included, so a row at that address under a different name is not a match — and
> the package would then try to create a second one at an address your users table
> holds unique. That is why `test` is spelled exactly as `DatabaseSeeder` writes
> it. If you rename an account in the view, rename it in your seeder too.

> [!NOTE]
> Three things keep this out of production: the links are wrapped in
> `@env('local')`, the package throws outside its own `allowed_environments`, and
> it is installed under `require-dev`. The package's own config is published with
> `php artisan vendor:publish --tag="login-link-config"` if you want to widen any
> of that — a `*.test` host, or an account that is never created.

Nothing is offered on the WorkOS variations. They have no login view to put a
link in: the kit redirects to AuthKit, and the password never reaches the
application at all.

## Delete the layouts the kit does not render

Both layout families are built the same way: `layouts/auth.blade.php` and
`layouts/app.blade.php` each render exactly one of the variants sitting in the
folder beside them.

```blade
<x-layouts::app.sidebar :title="$title ?? null">
```

That leaves three auth layouts where one is used — card, simple and split — and
two application shells where one is used, sidebar and header. The unrendered app
shell is the expensive one: a whole navigation chrome with its own brand mark,
navigation and user menu, quietly drifting out of step with the shell you do use
every time you touch one of them.

Which variant survives is read out of each delegating layout rather than asked, so
the task cannot pick a different answer than your application already has. Swap
`layouts/app.blade.php` over to `<x-layouts::app.header>` before running the task
and refit follows you — it keeps whichever variant that file names.

Both families are one task because they are one decision, and every kit ships
both. The plan still lists each file it will delete, so a single answer hides
nothing from the preview. To limit it to one family, register the task yourself:

```php
use Onelegstudios\Refit\Facades\Refit;
use Onelegstudios\Refit\Tasks\KeepOneLayout;

Refit::task(new KeepOneLayout(['auth']));
```

## Remove the Flux Pro @source line from app.css

Every variant ships this on line 6 of `resources/css/app.css`:

```css
@source '../../vendor/livewire/flux-pro/stubs/**/*.blade.php';
```

It points at a directory that only exists with a Flux Pro licence. The task is
offered only when Flux Pro is absent, so buying a licence later is never quietly
broken.

It is also only offered while the project is
[staying on Flux](/docs/using-refit/guide/libraries). A project that is leaving
loses the whole stylesheet reference on its way out, and being asked to trim one
line off a file that is about to lose several would only be confusing.

## Use Flux's own components instead of the kit's overrides

Flux lets a project replace any of its views by putting a file of the same name
under `resources/views/flux`, and every kit does that for one component:

```
resources/views/flux/
├── icon/
│   ├── book-open-text.blade.php
│   ├── chevrons-up-down.blade.php
│   ├── folder-git-2.blade.php
│   └── layout-grid.blade.php
└── navlist/
    └── group.blade.php
```

`navlist/group.blade.php` is a restyled copy of Flux's navlist group. It takes
precedence over the real one, so it stops picking up Flux's changes from the day
you install. The task deletes it, along with anything else you have added under
`resources/views/flux`, and the sidebar groups go back to Flux's own markup and
styling.

The `icon/` folder is left alone, because the
[icon question](/docs/using-refit/guide/icons) already decides it. Deleting those
files here would break every usage of a name Heroicons does not have. Choosing
**Heroicons only** removes them and rewrites their usages. Once both are gone,
`resources/views/flux` is removed too.

Like the Flux Pro cleanup, the task is only offered while the project is
[staying on Flux](/docs/using-refit/guide/libraries). Leaving Flux removes the
whole directory anyway.

## What is not a task

Removing Flux is not on this list. Choosing a target other than Flux is already
the decision, so the leftovers — the `@source` lines, the Blade directives, the
`resources/views/flux` overrides — come out as part of that run rather than as
something to tick separately. See
[Leaving a library](/docs/using-refit/guide/libraries).

## Adding tasks of your own

The list is not fixed — a task is a class implementing a small interface, and
refit handles the ordering, the preview and the apply. See
[Writing your own task](/docs/using-refit/guide/custom-tasks).
