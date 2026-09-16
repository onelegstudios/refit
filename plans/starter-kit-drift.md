# Starter kit drift — how do we hear about it when the kit's chrome moves?

The question: `LayoutStubs` writes three layout files from stubs that were
hand-authored against one revision of the Livewire starter kit. Nothing tells us
when that revision stops being current, so the stubs can go stale silently.

Written 2026-08-17, from the `replace-flux` branch. Deliberately **not** built
there — it is a CI and tooling concern, not part of replacing Flux. Nothing here
blocks a release; the cost of the gap is a stale stub found by a user rather than
by a build.

---

## Answer in one line

A fourth `:check` script of the shape the repo already uses three times, checking
a committed hash of the four kit files the stubs mirror — alert only, never
auto-fixed.

## Why the gap exists

Refit mirrors kit markup in two directions, and only one of them is safe.

Most of the migration **rewrites what it finds**, so a kit that changes its nav
markup gets its new markup rewritten. Those passes need no knowledge of any
particular kit revision.

The chrome is the exception. Sheaf's sidebar is a grid area of an `<x-ui.layout>`
and no rename produces that nesting, so `LayoutStubs` writes those files from
stubs instead — and a stub is a **copy**, frozen at the moment it was written. If
the kit adds a nav item, renames a route, or restructures its header, the stub
keeps handing every project the old shape. Nothing in the suite notices, because
the suite asserts the stubs render what the stubs say.

The four files in that mirrored set, all present in all five variants:

| File | Why it is in the set |
| --- | --- |
| `resources/views/layouts/app/sidebar.blade.php` | replaced by a stub |
| `resources/views/layouts/app/header.blade.php` | replaced by a stub |
| `resources/views/components/desktop-user-menu.blade.php` | replaced by a stub |
| `resources/views/layouts/app.blade.php` | `LayoutStubs::unwrapMain()` reads it for `<flux:main>` |

Four files × five variants = 20 hashes.

## The thing that makes this worse than it looks

**CI never runs `composer fixtures`.** There is no such step in
`.github/workflows/tests.yml`, so every fixture-backed test skips there: 33
explicit `is_dir(fixturePath(…))` guards in `SheafMigrationTest` alone, plus six
more files that skip through `requireFixture()` — `TagParserTest`,
`FluxTeardownTest`, `ProjectDetectorTest`, `RefitCommandTest`, `IconPlannerTest`
and `TasksTest`.

So the stubs are only ever exercised on a maintainer's machine, against whatever
fixtures that machine last downloaded. A drift check would be the only automated
signal that the kit has moved at all.

Worth deciding separately: whether CI should download fixtures and run that suite.
That is a bigger change — five kit downloads per job across a 6-cell matrix — and
it is not what this plan proposes.

## The check does not need a kit download

Verified 2026-08-17 rather than assumed. `bin/download-starter-kits.php` runs the
kit's `post-create-project-cmd` scripts, but the only one that qualifies
emoji-prefixes Livewire blade files and rewrites `chisel-paths.php` — it does not
touch the layouts. Fetching `sidebar.blade.php` and `header.blade.php` raw from
GitHub at the commit recorded in `manifest.json` and diffing against the fixtures
gave **byte-identical** results for both.

So the check is ~20 small `raw.githubusercontent.com` GETs. No zip, no
`laravel new`, no vendor install, no secrets. That is what lets it sit beside the
`sheaf-components` job, which already runs everywhere including fork pull
requests.

## Why the recorded hashes cannot be auto-updated

The recorded file **is** the alarm. A bot that re-records on drift silences the
one signal the whole thing exists to produce.

The stubs cannot be auto-updated either. A kit change usually needs a judgement
call about how Sheaf should express it — which is what most of the comments
inside the stubs are. Those comments are the reasoning behind a hand-made
translation, not decoration.

So: **the job fails with a diff of what moved.** A person updates the stubs, then
re-records — exactly the `composer icons` / `composer icons:check` relationship
that already exists for artwork.

## The build

- [ ] `bin/scan-starter-kit-chrome.php`, modelled on
      `bin/scan-sheaf-components.php` — same `--check` flag, same exit codes, same
      output style. Reads the branch list from `VARIANTS` in
      `bin/download-starter-kits.php` rather than restating it
- [ ] Record to `resources/starter-kits/chrome.json`: per variant, the resolved
      commit and a sha256 per file. Committed, unlike
      `tests/fixtures/starter-kits/manifest.json`, which is gitignored along with
      the fixtures — which is exactly why there is no baseline today
- [ ] `composer kits:chrome` and `composer kits:chrome:check` in `composer.json`,
      next to the other three pairs
- [ ] A `starter-kit-chrome` job in `tests.yml` alongside `sheaf-components`:
      public, no secrets, `GITHUB_TOKEN` only for the API rate limit
- [ ] Let it run on the existing weekly `schedule` cron. That cron already exists
      for Flux drift and carries the comment "Catches the one kind of drift no
      pull request can" — this is the same kind
- [ ] Failure output must name the variant and file and show the diff, not just
      report a hash mismatch. A hash tells you nothing about what to do
- [ ] Document it in `docs/development/internals/` beside the other checks, and
      add it to the release checklist in `plans/go-live.md` step 4

## Decisions already made

- **Hash the files, not the branch HEAD.** Watching the branch is one API call
  instead of twenty, but every commit to the repo trips it and almost none of
  them touch the chrome. A check that cries wolf gets ignored, and this one only
  fires a few times a year if it is precise.
- **Exact hashes, not a semantic fingerprint.** Comparing extracted tags or links
  would be quieter, but it would miss structural change — which is the thing most
  likely to need a stub rewrite. Same call `IconMap` and `ComponentMap` already
  make: record exactly, report rather than guess.
- **Only the mirrored set.** Kit files refit rewrites in place are not in scope;
  drift there is handled by the migration itself, which reads whatever it finds.
- **Alert only.** No auto-PR, for either the hashes or the stubs.
