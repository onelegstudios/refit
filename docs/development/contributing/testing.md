---
title: Testing
description: Pest, Testbench, the fixture suite, and what skips without a licence.
order: 2
---

# Testing

```bash
composer test
```

That runs the four gates CI runs, in the same order: PHPStan, Pint, type
coverage, then Pest.

| Gate | Command | Bar |
|---|---|---|
| Static analysis | `composer analyse` | PHPStan level 7 over `src` and `config` |
| Formatting | `composer lint:check` | Pint, with the repository's `pint.json` |
| Type coverage | `composer test:types` | 100% |
| Tests | `composer test:unit` | Pest 4/5, in parallel |

## Suites

| Suite | What lives there |
|---|---|
| `tests/ArchTest.php` | Pest's PHP and security presets, no `dd()`/`env()`/`exit()`, strict types everywhere |
| `tests/Unit` | Pure machinery: the tag parser, the rewriter, the guard, the grouper, the icon map |
| `tests/Feature` | Detection, plans, tasks and the command, against real starter kits |

Unit tests take inline snippets and assert on strings; nothing there touches a
filesystem. Feature tests work against fixtures, and the ones that mutate a
project work against a throwaway copy of one.

## Fixtures

The feature suite runs against real copies of every `laravel new` Livewire
variation:

```bash
composer fixtures
```

They land in `tests/fixtures/starter-kits` and are gitignored — five checkouts of
a Laravel application is not something to commit. Any test needing one skips
itself when it is absent:

> Starter kit fixture [livewire-teams] is missing — run `composer fixtures`.

A suite that has never downloaded them is green and largely hollow, so run this
before trusting a pass.

`tests/Pest.php` provides the helpers:

| Helper | What it gives you |
|---|---|
| `starterKits()` | The five variation names, for `->with()` |
| `requireFixture($kit)` | The fixture path, or a skip |
| `detectFixture($kit)` | A detected `Project` for a fixture, read-only |
| `copyFixture($kit)` | A throwaway copy in the temp directory, cleaned up at shutdown |
| `requireFluxPro($kit)` | A fixture's licensed Flux stubs, or a skip |
| `fluxScanner()` / `sheafScanner()` | An `IconScanner` reading one library's vocabulary |
| `sheafComponents()` | Every tag a Sheaf install answers to, from the recorded manifest |
| `sheafKit($kit)` | A copied fixture staged as if Sheaf were already installed |
| `migratedSheafKit($kit)` | A staged fixture refit has already moved to Sheaf, shared per process |
| `installSteps($root)` / `reconcileSteps($root)` | One stage of a Sheaf plan, as described lines |
| `fluxTagsUnder($root)` | Every `<flux:*>` tag left in a tree |

Anything that applies a plan must use `copyFixture()`. Applying against the
fixture itself corrupts it for every later test in the run.

## Testing a plan

Plans are pure, so most task behaviour can be asserted without applying anything:

```php
$project = detectFixture('livewire');
$plan = new Plan;

(new MoveToastsToTop)->contribute($plan, $project, new Report);

expect($plan->describe())->toContain('  rewrite <flux:toast.group> -> <flux:toast.group position="top end">');
```

`Plan::describe()` is the same rendering the confirmation preview uses, so a
change to what the user is agreeing to shows up as a failing assertion.

When the outcome is what ends up on disk, apply against a copy:

```php
$root = copyFixture('livewire');
$project = (new ProjectDetector)->detect($root);
$report = new Report;

(new Applier)->apply($plan, $project, $report);

expect($project->exists('resources/views/partials'))->toBeFalse();
```

## Testing the command

Point the application at a throwaway kit and drive it with `--answers`, which is
what the flag exists for:

```php
$root = copyFixture('livewire');

@unlink($root.'/chisel.php');
app()->setBasePath($root);

$this->artisan('refit', [
    '--force' => true,
    '--answers' => json_encode(['icons' => 'heroicons', 'tasks' => ['remove-flux-pro-source']]),
])->assertSuccessful();
```

`--force` stands in for a clean git tree — a fixture copy is not a repository —
and `--answers` suppresses both the confirmation and the offer to remove refit.
Omitting `library` means Flux, so a test written before libraries existed still
drives the run it always did.

### Testing a Sheaf run

Fixtures are raw checkouts with no `vendor/`, so a Sheaf target has to be staged:
`sheafKit()` adds `sheaf/cli` to the copied `composer.json`, writes a `resources/css/theme.css`, and creates the
component directories the CLI would have written. That is not a fiction — those
are exactly the two commands refit's preflight insists on, and pre-creating the
components is what keeps the test about rewriting rather than about shelling out.

A test that only reads the result should call `migratedSheafKit($kit)` instead.
It migrates each kit and icon answer once per test process and hands every
later caller the same tree, which is most of what keeps these tests fast. Take
your own copy from `sheafKit()` whenever the test writes to the tree before or
after the run, or runs refit with other answers.

The Sheaf tests are split by what they cover, so a new one goes in the file it
belongs to:

| File | Covers |
|---|---|
| `SheafInstallTest` | The dependency stage: what gets installed, and what is skipped |
| `SheafMigrationTest` | The whole tree: no Flux left, icons, theme, teardown |
| `SheafChromeTest` | The layouts refit writes: sidebar, header, user menu, team switcher |
| `SheafComponentsTest` | Components mapped across: modals, buttons, headings, badges, dropdowns |
| `SheafFormsTest` | Form controls: labels, two-factor, OTP, select, segmented groups |

The two assertions worth copying are the ones that would catch a bad mapping:
no `<flux:*>` tag survives anywhere in the tree, and every `<x-ui.*>` tag produced
names something in `sheafComponents()`.

## What skips, and why

Two things skip rather than fail:

- **Missing fixtures.** They are five Laravel applications fetched from GitHub;
  making them mandatory would fail a first `composer test` for reasons that have
  nothing to do with the change being made.
- **Missing Flux Pro.** It is a licensed package, absent from most checkouts and
  from every fork's CI run, since GitHub does not pass secrets to workflows
  triggered from a fork. The recorded names in
  `resources/flux/internal-icons.json` are what refit falls back to, and those
  are asserted without a licence in `tests/Unit/IconsTest.php`.

Neither skip hides a regression in refit's own logic. See
[The icon pipeline](/docs/development/internals/icon-pipeline) for the licensed job that
covers the rest.

Sheaf needs no such gate. Its registry is public and MIT, so
`composer sheaf:components:check` runs anywhere, and the tests read the recorded
manifest rather than the network. Lucide is public too, so `composer icons:check`
runs anywhere as well.

`composer resources:check` runs all three together, via
`bin/check-resources.php`. It is a script rather than a list of Composer scripts
because a list aborts at the first non-zero exit, which would let a drifted
Lucide bundle hide what Flux and Sheaf had to say — the one time you most want to
hear it. Every check runs, each reports as it goes, and a summary at the end says
which of them failed.

It passes `--optional` to the Flux scan, which turns "no Flux installed
anywhere" from an error into a skip (exit 2, which is how the summary tells a
skip from a clean run), so the aggregate is usable on a checkout without a
licence. Asked for on its own, `composer flux:internals:check` still fails in
that situation: nothing was verified, and you asked for it to be verified.

## CI

The matrix is PHP 8.3 / 8.4 / 8.5 against Laravel 13 and Testbench 11, each on
`prefer-lowest` and `prefer-stable`.

Three jobs sit outside the matrix, one per vendored resource. `flux-pro`
re-records the icons Flux renders internally, and needs a licence. `icons` checks
the committed Lucide bundle against the latest release, and `sheaf-components`
checks `ComponentMap` against Sheaf's registry; neither needs anything, so both
run on every push and every fork's pull request. All three also run on the weekly
schedule, which is the only thing that catches an upstream release landing
between pull requests.

Type coverage runs once, on PHP 8.5 / `prefer-stable`. It is a property of `src`
rather than of the resolved dependency versions, and it *must* run on
`prefer-stable`: `pest-plugin-type-coverage` analyses files in forked processes,
and its shared file cache is only lock-protected from v5.0.2 onwards. Older
versions let the forks interleave writes and corrupt the cache into a parse
error.
