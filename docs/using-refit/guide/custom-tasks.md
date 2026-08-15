---
title: Writing your own task
description: The Task interface, the plan API, and the actions refit ships.
order: 5
---

# Writing your own task

A task is a class implementing `Onelegstudios\Refit\Contracts\Task`. It says when
it applies and contributes actions to the plan; refit handles the ordering, the
preview, and the apply.

```php
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Plan\{Plan, Report, Stage};
use Onelegstudios\Refit\Plan\Actions\DeleteFile;
use Onelegstudios\Refit\Project\{Feature, Project};
use Onelegstudios\Refit\Tasks\TaskGroup;

final class DropTheWelcomePage implements Task
{
    public function key(): string { return 'drop-welcome'; }
    public function group(): TaskGroup { return TaskGroup::Cleanup; }
    public function label(): string { return 'Delete the welcome page'; }
    public function hint(): string { return 'You are going to replace it anyway'; }

    public function appliesTo(Project $project): bool
    {
        return $project->exists('resources/views/welcome.blade.php');
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $plan->add(Stage::Move, new DeleteFile('resources/views/welcome.blade.php'));
    }
}
```

Register it in `config/refit.php`, or from a service provider:

```php
use Onelegstudios\Refit\Facades\Refit;

Refit::task(new DropTheWelcomePage);
```

## The interface

| Method | Purpose |
|---|---|
| `key()` | Stable identifier, used by `--answers` and in tests |
| `group()` | `TaskGroup::Structure`, `Naming` or `Cleanup` — sorts the list and prefixes the label |
| `label()` | The line shown in the multiselect |
| `hint()` | The dimmer line under it |
| `appliesTo()` | Whether this task makes sense for the detected project |
| `contribute()` | Add actions to the plan |

`appliesTo()` is the important one. A task that cannot apply is never offered, so
the user is not asked to choose between things that would silently do nothing.
Answer it from the project rather than from configuration:

```php
public function appliesTo(Project $project): bool
{
    return $project->has(Feature::Teams)
        && $project->exists('resources/views/components/team-switcher.blade.php');
}
```

## What `Project` gives you

| Member | What it is |
|---|---|
| `root` | Absolute project root |
| `componentStyle` | `ComponentStyle::SingleFile` or `ClassBased`, with a `viewDirectory()` helper |
| `features` / `has(Feature)` | `Teams`, `WorkOs`, `Passkeys`, `TwoFactor`, `Registration` |
| `libraries` / `library($key)` | The UI libraries installed here, and whether each is the paid tier |
| `target` / `targets($key)` | The library the user chose to end on |
| `chiselPending` | Whether `chisel.php` is still present |
| `path()`, `exists()`, `get()` | Project-relative path helpers |
| `blades()` | Every Blade file, as project-relative paths |
| `looseComponents()` | Top-level anonymous components, as bare names |

`blades()` and `looseComponents()` are scanned live rather than cached: the plan
moves and deletes files while it runs, so an action reading them at apply time
sees the settled tree.

`target` is the only member detection does not fill in — it is the library the
user picked, attached once that question has been asked. A task that only makes
sense for one target says so in `appliesTo()`:

```php
public function appliesTo(Project $project): bool
{
    return $project->targets('flux');
}
```

## Stages

Actions land in stages so that contributors never have to know about each other.

| Stage | For |
|---|---|
| `Dependencies` | Composer and npm changes, before anything reads `vendor/` |
| `Write` | Creating or overwriting files |
| `Move` | Moving, renaming and deleting files |
| `Reconcile` | Whole-tree reference rewriting, once every file has stopped moving |
| `Format` | Formatting and asset builds |
| `Finish` | Anything that must happen after the project is otherwise final |

Files stop moving before the reconcile pass rewrites references, which is what
keeps two tasks from tripping over one another. Put anything that reads the file
tree as a whole in `Reconcile` or later.

## The actions refit ships

| Action | Stage it usually lands in | What it does |
|---|---|---|
| `WriteFile` | `Write` | Create or overwrite a file, making directories as needed |
| `RemoveLinesContaining` | `Write` | Drop every line of a file containing a needle |
| `MoveFile` | `Move` | Move one file, reporting when the source is gone |
| `DeleteFile` | `Move` | Delete one file, reporting when it was already gone |
| `RemoveDirectoryIfEmpty` | `Move` | Remove a directory the plan has just emptied |
| `MoveComponentsIntoFolders` | `Move` | Sort loose components into subfolders, recording each move in a ledger |
| `ReplaceInFile` | `Reconcile` | Swap one literal string for another in a single named file |
| `AddAttribute` | `Reconcile` | Give a component tag an attribute it does not have yet |
| `RewriteIconNames` | `Reconcile` | Translate icon names in both the forms Flux accepts |
| `ReplaceIncludeWithComponent` | `Reconcile` | `@include('partials.head')` → `<x-head />` |
| `ApplyLedgerRenames` | `Reconcile` | Point every reference at the names a ledger recorded |
| `RunProcess` | `Format` | Run a command in the project root, reporting a failure rather than throwing |

Writing one of your own means implementing `Contracts\Action`:

```php
interface Action
{
    public function describe(): string;

    public function apply(Project $project, Report $report): void;
}
```

`describe()` has to stand on its own — it is the line the user reads in the
confirmation preview, and therefore the thing they are agreeing to.

An action that rewrites every view should extend `Plan\Actions\BladeSweep`
instead, which walks the tree for you, and guards each file: a rewrite that would
leave the Blade less balanced than it found it is skipped and reported rather
than written. See [Blade rewriting](/docs/development/internals/blade-rewriting) for how
that check works.

## Reporting

`Report` is the honest half of the tool. Use it rather than failing silently:

```php
$report->note('Deleted the welcome page.');       // for the record
$report->warn('Left X alone — it passes data.');  // needs a human
$report->changed('resources/views/x.blade.php');  // a path this action wrote
```

Warnings are printed at the end of the run and written to
[`REFIT-NOTES.md`](/docs/using-refit/reference/troubleshooting). Notes are recorded in the same
file when there is at least one warning to write it for.

## Testing a task

Plans are a pure function of the detected project plus the answers, so a task can
be asserted against a real starter kit without touching a filesystem:

```php
$project = (new ProjectDetector)->detect($fixturePath);
$plan = new Plan;

(new DropTheWelcomePage)->contribute($plan, $project, new Report);

expect($plan->describe())->toContain('  delete resources/views/welcome.blade.php');
```

`Plan::describe()` renders the plan as the same lines the confirmation preview
shows. See [Testing](/docs/development/contributing/testing) for how refit's own suite is
laid out.
