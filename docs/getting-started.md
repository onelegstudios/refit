---
title: Getting started
description: Install refit and make your first run.
order: 2
---

# Getting started

## Install

Install refit as a dev dependency, right after `laravel new`:

```bash
composer require --dev onelegstudios/refit
```

It registers a single command and nothing else — no routes, no published views,
no runtime behaviour.

## Before you run it

Refit refuses to start unless two things are true:

1. **`install:features` has already run.** The Livewire kit ships every
   authentication feature and carves the unwanted ones away afterwards, using a
   `chisel.php` in the project root that deletes itself once it has run.
   `laravel new` does this for you before handing back the shell, so you have
   probably never seen the file — but if it is still there the file tree is not
   the one you are keeping, and refit would be rewriting views you are about to
   delete. See
   [`install:features` and `chisel.php`](/docs/using-refit/reference/starter-kits#installfeatures-and-chiselphp).
2. **You are in a git repository with a clean working tree.** The changes are
   one-way, and a clean tree means `git checkout .` is the undo. That is why
   refit ships no backup or rollback of its own.

```bash
php artisan install:features
git add . && git commit -m "Fresh starter kit"
```

Both checks can be waived with `--force`, but the second one is the only safety
net refit has. See [The refit command](/docs/using-refit/guide/the-refit-command#preflight)
for the exact wording of each failure.

## Run it

```bash
php artisan refit
```

Refit summarises the kit it found, asks two questions, prints the plan, and waits
for a yes:

```
  Starter kit ......... single-file components, Two-factor authentication, Registration
  Views ............... 63 Blade files
  Flux ................ free tier

 ┌ The kit ships Heroicons with a few Lucide icons vendored in. What would you like? ┐
 │ › Lucide only                                                                     │
 └───────────────────────────────────────────────────────────────────────────────────┘

Refit will make 34 change(s):

  Write
    write  resources/views/flux/icon/check.blade.php
    …
  Move
    move   resources/views/partials/head.blade.php -> resources/views/components/head.blade.php
  Reconcile
    icons  Heroicons to Lucide (18 names)

 ┌ Apply these changes? ┐
 │ ● Yes / ○ No         │
 └──────────────────────┘
```

Prefer to look before you leap:

```bash
php artisan refit --dry-run
```

## After it runs

1. **Read the diff.** `git diff` is the review, and it is the reason the clean
   tree was required.
2. **Check `REFIT-NOTES.md`** if refit wrote one. It only appears when something
   needs a human — see [Troubleshooting](/docs/using-refit/reference/troubleshooting).
3. **Say yes when refit offers to remove itself.** It deletes the published
   config on its way out and prints the removal command:

   ```bash
   composer remove --dev onelegstudios/refit
   ```

## Next steps

- [Icons](/docs/using-refit/guide/icons) — what each of the three answers does.
- [Tasks](/docs/using-refit/guide/tasks) — the structural changes on offer.
- [Configuration](/docs/using-refit/reference/configuration) — change the component map, the
  notes file, or the list of tasks.
