---
title: Architecture
description: Detect, ask, plan, confirm, apply — and why the plan is a value.
order: 1
---

# Architecture

A run is five stages, and the seam between them is the point of the design:

```
ProjectDetector ──▶ Project ──▶ prompts ──▶ Plan ──▶ preview ──▶ Applier
     detect                       ask       plan     confirm      apply
```

The first thing `ask` asks is which library the project should end up on, because
that answer decides what the rest of them mean. See
[Component mapping](/docs/development/internals/component-mapping) for the
contract behind it.

Everything up to `Applier::apply()` is pure. A `Plan` is a function of the
detected project plus the user's answers and nothing else, which is what makes
`--dry-run` free, and what lets the test suite assert on plans across every
fixture without touching a filesystem.

## Project and detection

`ProjectDetector::detect()` builds an immutable `Project` by probing the
filesystem. Nothing is asked that disk can answer — refit runs after
`install:features` has chiselled the kit, so the tree varies with the answers
given at `laravel new`, and every signal is a file that either survived or did
not.

`Project` resolves paths against its own root rather than the application's,
which is what lets the entire package be pointed at a fixture directory during
tests.

`target` is the one field disk cannot answer: the library the user chose, attached
by `Project::targeting()` once that question has been asked. Carrying it on the
project rather than threading it through every signature is what keeps the `Task`
contract at the two arguments it has always had.

`blades()` and `looseComponents()` are scanned live on every call, not cached.
The plan moves and deletes files while it runs, and the reconcile pass has to see
the settled tree.

## The registry

`Refit` is a small registry of `Task` and `Library` instances, bound as a
singleton by the service provider and populated from `config('refit.tasks')` and
`config('refit.libraries')`. Each class is resolved from the container, so either
can type-hint its own dependencies, and `Refit::task()` / `Refit::library()` let a
service provider add more.

The registry is also where detection gets its list: the service provider hands
`Refit::libraries()` to `ProjectDetector`, so a third-party library is probed for
like any other.

`tasksFor()` filters to the tasks whose `appliesTo()` is true for this project
and sorts them by group order then label; `options()` renders that as the prompt
list; `resolve()` maps `--answers` keys back to instances.

Tasks are an interface on day one because they are the open-ended half of the
tool: a package can register a task refit knows nothing about.

## Plan, stages, actions

A `Plan` is a bag of `Action`s keyed by `Stage`. Stages exist so contributors do
not have to know about each other:

| Stage | For |
|---|---|
| `Dependencies` (10) | Installing what the plan is about to rewrite onto, before anything reads it. The one stage where a failure aborts the run rather than being noted — it is first, so nothing has changed yet |
| `Write` (20) | Creating or overwriting files |
| `Move` (30) | Moving, renaming and deleting files |
| `Reconcile` (40) | Whole-tree reference rewriting, once every file has stopped moving |
| `Format` (50) | Formatting and asset builds |
| `Finish` (60) | Anything that must happen after the project is otherwise final |

Tasks move and rename files freely in `Move`; the single reconciliation pass
afterwards sees the settled tree and fixes every reference in one traversal. Two
tasks that both reorganise views therefore never need to be aware of each other.

`Plan::describe()` renders the whole thing as lines — the same lines the
confirmation preview prints and the plan tests assert against. Making the preview
and the snapshot the same string is deliberate: a change to what the user is
agreeing to cannot slip past the tests.

## The rename ledger

Some moves cannot be enumerated at planning time. `MoveComponentsIntoFolders`
reads `resources/views/components` when it *runs*, because an earlier task in the
same run may have promoted a partial into it — without that, picking both
structure tasks left a components directory half namespaced.

Those actions record what they did in a `RenameLedger`, and
`ApplyLedgerRenames` reads it during `Reconcile`. The ledger is the mechanism
that keeps tasks order-independent while still allowing moves that are only known
at apply time.

A task that *can* enumerate its moves fills the same ledger while it builds the
plan — `MoveComponentsOutOfPages` does, because a route file tells it everything
up front. The ledger is read at `Reconcile` either way, so both kinds of rename
travel through one traversal rather than growing a second sweep.

## Applier and Report

`Applier` walks `Plan::actions()` in stage order and calls each one, invoking an
optional callback first so the command can echo the line. It has no error
handling of its own; actions decide what is fatal.

The convention is that a missing file is a *warning*, not an exception —
something already gone is a fact about the project, not a bug — while an
unwritable target throws. `RunProcess` is the same shape: a Pint binary that is
not there should not strand a project that has already been rewritten.

`Report` collects three things: `note()` for the record, `warn()` for anything
that needs a human, and `changed()` for paths that were written. Warnings are
printed after the run and rendered to `REFIT-NOTES.md` by `toMarkdown()`.

## Preconditions

`Support\Git` shells out for `rev-parse --is-inside-work-tree` and
`status --porcelain`. That is the entire safety model: refit rewrites in place
and the changes are one-way, so requiring a clean tree makes `git checkout .` the
undo and buys the package out of ever writing backup or rollback machinery.

The `chisel.php` check is not overridable, because forcing past it produces work
the user is about to delete.

## Service provider

`RefitServiceProvider` merges the config, binds the registry, and — only when
running in console — registers the publish tags and the command. Refit serves no
routes and publishes no views, so console is the only context worth wiring
anything up in.
