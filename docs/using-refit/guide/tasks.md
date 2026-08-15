---
title: Tasks
description: The structural and cleanup tasks refit offers once the icon set is chosen.
order: 4
---

# Tasks

After the icon question you pick from a list of structural tasks. Only tasks that
fit the kit refit detected are offered, so you are never asked to choose
something that would do nothing.

| Group | Task | Key |
|---|---|---|
| Structure | Use components instead of partials | `partials-to-components` |
| Structure | Move the auth views out of pages | `auth-views-out-of-pages` |
| Structure | Move the non-page components out of pages | `components-out-of-pages` |
| Structure | Group components into folders | `namespace-components` |
| Structure | Show toasts at the top of the screen | `toasts-at-top` |
| Cleanup | Delete the layouts the kit does not render | `single-layout` |
| Cleanup | Remove the Flux Pro `@source` line from `app.css` | `remove-flux-pro-source` |
| Cleanup | Remove what is left of Flux | `remove-flux` |

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

## Show toasts at the top of the screen

The kit renders a bare `<flux:toast.group>` in every layout, which leaves Flux on
its `bottom end` default — the corner a sidebar footer, a cookie bar or a mobile
keyboard tends to occupy. Moving the toasts up adds the position the group was
missing, and nothing else:

```blade
<flux:toast.group position="top end">
    <flux:toast />
</flux:toast.group>
```

A group that already carries a `position` is left as it is.

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
loses both `@source` lines to the task below, and being asked to trim one line off
a file that is about to lose two would only be confusing.

## Remove what is left of Flux

Offered only when the target is a library other than Flux. The component tags are
already gone by the time it runs — that is the migration's job — but three things
outlive them:

- the Tailwind `@source` lines in `resources/css/app.css` pointing into Flux's
  vendor stubs;
- the `@fluxAppearance` and `@fluxScripts` directives, which fatal once the
  package is gone;
- `resources/views/flux`, a directory that exists only to intercept Flux's own
  resolution. The kit puts four icon overrides and a `navlist/group` override in
  there; whatever you have added is just as dead.

The Composer package itself is not removed. Refit prints the line instead, the
same way it does for its own uninstall:

```bash
composer remove livewire/flux
```

## Adding tasks of your own

The list is not fixed — a task is a class implementing a small interface, and
refit handles the ordering, the preview and the apply. See
[Writing your own task](/docs/using-refit/guide/custom-tasks).
