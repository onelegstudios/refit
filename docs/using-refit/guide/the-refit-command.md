---
title: The refit command
description: The five stages of a run, the options, and the checks that stop one.
order: 1
---

# The refit command

```bash
php artisan refit
```

The command runs in five stages — **detect, ask, plan, confirm, apply**. Nothing
touches disk until you have seen the plan and agreed to it.

| Option | What it does |
|---|---|
| `--dry-run` | Print the plan and exit without changing anything |
| `--force` | Run even though the git working tree is dirty, or there is no repository |
| `--answers=` | JSON answers, for a run with no prompts |

## Detect

Refit probes the filesystem rather than asking you what you installed. Every
signal is a file that either survived `install:features` or did not:

| Signal | Read from |
|---|---|
| Component style | `app/Livewire/Settings` — only the class-component variant ships it |
| Teams | `app/Models/Team.php` |
| WorkOS AuthKit | `laravel/workos` in `composer.json` |
| Passkeys | `resources/js/passkeys.js` |
| Two-factor, registration | The kit's auth views, under either component style |
| Flux Pro | `livewire/flux-pro` in `composer.json` or `composer.lock` |

What it found is printed as the summary line at the top of the run, and it
decides which tasks you are offered.

## Preflight

Two conditions stop a run before anything is asked.

**`chisel.php` is still present.**

> This starter kit still has chisel.php — run `php artisan install:features`
> first, so refit sees the file tree you are actually keeping.

The kit ships every authentication feature at once and `install:features` carves
the unwanted ones away, deleting `chisel.php` when it is done. `laravel new` runs
that step for you, so a normally created project never has the file — if refit
finds one, the carving is still pending. Not overridable, and deliberately so:
refitting a tree that is about to be chiselled means rewriting views you are
about to delete. The longer version is in
[Supported starter kits](/docs/using-refit/reference/starter-kits#installfeatures-and-chiselphp).

**The working tree is not clean, or is not a repository at all.**

> Your git working tree has uncommitted changes. Commit or stash them first so
> `git checkout .` can undo this run, or pass `--force`.

Refit rewrites files in place and keeps no backup of its own, so git *is* the
undo. `--force` skips this check and both of its halves.

## Ask

Three questions, in order — and the order is the point, because each one narrows
the next:

1. **Library.** Keep Flux, or replace it. See
   [Libraries](/docs/using-refit/guide/libraries). A target the project is not set
   up for is still offered — installing it is the first thing the plan does.
2. **Icons.** Only the sets your chosen library can resolve are offered. See
   [Icons](/docs/using-refit/guide/icons).
3. **Tasks.** A multiselect of the structural and cleanup tasks that fit the
   detected kit *and* the chosen target. Only applicable tasks are listed, so you
   are never asked to pick something that would do nothing. See
   [Tasks](/docs/using-refit/guide/tasks).

## Plan

Your answers become a list of actions, sorted into stages that run in order:

| Stage | What lands here |
|---|---|
| `Dependencies` | Installing what the plan is about to rewrite onto, before anything reads it |
| `Write` | Creating or overwriting files |
| `Move` | Moving, renaming and deleting files |
| `Reconcile` | Whole-tree reference rewriting, once every file has stopped moving |
| `Format` | Formatting and asset builds |
| `Finish` | Anything that must happen after the project is otherwise final |

The stages are what keep two tasks from tripping over one another: files stop
moving before the reconcile pass rewrites the references to them. When the plan
is not empty, refit appends `./vendor/bin/pint --dirty` to the `Format` stage so
the files it touched come out formatted.

A plan with nothing in it ends the run there:

> Nothing to do — the choices you made leave the project as it is.

## Confirm

The plan is printed in full, one line per action, grouped by stage. Answering no
leaves the project untouched; `--dry-run` prints the same list and exits before
the question.

## Apply

Actions run in stage order, each one echoed as it goes. Afterwards refit prints
any warnings, writes them to `REFIT-NOTES.md` if there are any
([configurable](/docs/using-refit/reference/configuration)), and offers to remove itself.

A failure is reported and stepped over — a half-applied run is harder to reason
about than one skipped file, and a clean tree means `git checkout .` is always
there. The `Dependencies` stage is the exception: it installs what every later
stage rewrites your views *onto*, so a failure there stops the run instead. It is
the first stage, so nothing has been touched yet and there is nothing to unpick.

## Running without prompts

`--answers` takes the same shape the prompts produce, which is also how the test
suite drives the command:

```bash
php artisan refit --answers='{"icons":"lucide","tasks":["partials-to-components","namespace-components"]}'
```

| Key | Value |
|---|---|
| `library` | `flux` or `sheaf`. Omitted means `flux`, which is what the kit ships. An unregistered key fails the run rather than falling back |
| `icons` | An icon set your chosen library offers. Anything else falls back to that library's first choice |
| `tasks` | A list of task keys. Unknown keys are ignored — see [Tasks](/docs/using-refit/guide/tasks) for the list |

With `--answers` there is no confirmation step (passing answers *is* the
confirmation) and no offer to remove refit at the end. Combine it with
`--dry-run` to render a plan non-interactively.
