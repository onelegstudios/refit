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

A task that needs to know more than whether to run implements `Asks` as well —
see [Asking a follow-up question](#asking-a-follow-up-question).

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

## Asking a follow-up question

The multiselect asks whether a task should run. Some tasks need to know more than
that before they can plan — which corner the toasts go in, what a guest gets
where the user menu was — and the project cannot answer it, because both answers
are equally true of it.

Implement `Contracts\Asks` alongside `Task`, and refit puts your questions to the
user once they have picked the task:

```php
use Onelegstudios\Refit\Contracts\{Asks, Task};
use Onelegstudios\Refit\Plan\Actions\AddAttribute;
use Onelegstudios\Refit\Questions\{Answers, Question};

final class PositionToasts implements Asks, Task
{
    public function __construct(private readonly string $corner = 'top end') {}

    // key(), group(), label(), hint() and appliesTo() as before

    public function questions(Project $project): array
    {
        return [new Question(
            key: 'corner',
            label: 'Which corner should the toasts stack in?',
            options: [
                'top end' => 'Top right, opposite the sidebar',
                'top center' => 'Top centre, like a banner',
                'top start' => 'Top left, above the sidebar',
            ],
            hint: 'They cover whatever is under them.',
        )];
    }

    public function answered(Answers $answers): Task
    {
        return new self($answers->get('corner'));
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $plan->add(Stage::Reconcile, new AddAttribute(
            'flux:toast.group',
            'position',
            $this->corner,
        ));
    }
}
```

| Method | Purpose |
|---|---|
| `questions()` | The questions to put before this task plans anything |
| `answered()` | The task to plan with, now that they have answers |

That is [`toasts-at-top`](/docs/using-refit/guide/tasks#show-toasts-at-the-top-of-the-screen)
with the name changed — the one task refit ships that asks anything.

Nothing is asked of a task the user did not pick, so nobody answers a question
about work that is not going to happen. And nothing is asked until the library
and the icon set are settled, so a question can be narrowed to the project the
task is about to rewrite — the same way `appliesTo()` narrows the task itself.

### Questions

A question is a choice between named options. That is the whole shape on purpose:
one prompt to render, one string to put in `--answers`, one value to branch on.

| Argument | What it is |
|---|---|
| `key` | Stable identifier, scoped to your task — this is what `--answers` names |
| `label` | The prompt. It has to stand on its own: several tasks can be asking in a row, and the prompt shows the question rather than the task that asked it |
| `options` | `answer value => the line shown for it` |
| `default` | What a run without prompts falls back to. `null` means the first option |
| `hint` | The dimmer line under the prompt |

A yes/no question is two options with words on them. "Move them too" and "Leave
them where they are" tell the reader more than "Yes" and "No" ever do, the same
way an action's `describe()` has to.

A question left with a single option is not asked — its one answer is taken as
given. That is what makes a question safe to narrow to what the kit in front of
you can actually take:

```php
public function questions(Project $project): array
{
    return [new Question(
        key: 'variant',
        label: 'Which shell should survive?',
        options: $this->variantsPresentIn($project),
    )];
}
```

### Answers

`Answers` is built from the questions your task declared, so every key you asked
about is present and holds one of the options you offered.

| Call | What it gives you |
|---|---|
| `get('corner')` | The option chosen, or `''` for a key nothing asked about |
| `is('corner', 'top end')` | The same comparison, read as a sentence |
| `all()` | Every answer, keyed by question |

`answered()` hands back a task rather than mutating this one, so the registered
instance stays answer-free and one run cannot leak its choices into the next.
Give the constructor a default so the task still plans when nobody has answered
anything — a test calling `contribute()` directly, for one.

Take the answer as the thing it means — a corner, a path, a family of layouts —
rather than holding the whole `Answers`, and the constructor stays worth calling
by hand. Hold the `Answers` when there are several and unpacking them is the
noise.

It returns a `Task` rather than `static` on purpose: a fork wide enough to
deserve a class of its own can be one, and the task that asked the question hands
the planning over to it.

```php
public function answered(Answers $answers): Task
{
    return $answers->is('folders', 'flat')
        ? new FlattenLayouts
        : new KeepOneLayout;
}
```

### Answering without prompts

A task reads its answers out of [`--answers`](/docs/using-refit/guide/the-refit-command#running-without-prompts)
under a key of its own, beside `library`, `icons` and `tasks`:

```bash
php artisan refit --answers='{"tasks":["toasts-at-top"],"toasts-at-top":{"corner":"top center"}}'
```

An answer that is missing, or that the question never offered, falls back to the
question's default rather than failing the run — the same way an unknown icon set
does. There is nothing to ask again: the options were narrowed to the answers
that make sense before the question was put.

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
