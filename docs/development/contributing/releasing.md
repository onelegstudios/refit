---
title: Releasing
description: Versioning, the changelog, and the workflows that run around a release.
order: 3
---

# Releasing

Refit follows [SemVer](https://semver.org). It has not reached v0.1.0 yet, so
changes to the core logic are still fair game — but the shipped `Task` and
`Action` contracts are what third-party tasks are written against, so treat them
as the public surface once tagged.

## Before tagging

1. `composer test` — the four gates CI runs.
2. `composer starter-kits` first if you have not, so the feature suite
   actually runs.
3. `composer icons:check` and `composer flux:check` — both report drift
   without writing, and a release is a good moment to be sure the committed
   artwork and the recorded Flux internals are current.
4. Read `git diff` on `resources/` if either check wrote anything. Bundled
   artwork changing is a real change to what refit generates.

## Cutting the release

Releases are created from GitHub. `.github/release.yml` sorts the generated notes
into categories from pull request labels:

| Label | Section |
|---|---|
| `breaking` | Breaking Changes |
| `enhancement` | Enhancements |
| `bug` | Bug Fixes |
| `documentation` | Documentation |
| `dependencies` | Dependencies |
| `maintenance` | Maintenance |
| anything else | Other Changes |

`skip-changelog` and `duplicate` are excluded. Labelling pull requests as they
merge is what makes the generated notes worth publishing.

Publishing a release fires `update-changelog.yml`, which folds the release notes
into `CHANGELOG.md` on `main` and commits them. `CHANGELOG.md` is therefore
generated for released versions — edit the `Unreleased` heading, not the
published sections.

## Workflows

| Workflow | Trigger | What it does |
|---|---|---|
| `tests.yml` | push, pull request, weekly, manual | The matrix, plus the licensed Flux internals check |
| `update-changelog.yml` | release published | Writes the notes into `CHANGELOG.md` |
| `publish-docs.yml` | release published, manual | Pushes `docs/` to the documentation site |

The weekly schedule exists for the Flux drift check, not to re-test the
dependency matrix — the matrix job skips itself on a scheduled run. Dependabot
covers Composer and GitHub Actions weekly, labelled `dependencies`.

## Publishing the documentation

`publish-docs.yml` copies `docs/` into the documentation site's repository when a
release is published. The site therefore never installs refit to read its docs —
this repository pushes, rather than the site pulling, which is what keeps a
release and its published documentation in step without anyone running
`composer update` by hand.

Each release lands in a per-major directory, which is the layout Laradocs reads
as versions:

```
docs-sources/refit/
├── v1/   ← overwritten by every v1.x release
└── v2/
```

One directory per major, so every release in a line refreshes it instead of
adding an entry to the version selector — the whole of `0.x` is `v0`. A
`_version.json` is written alongside carrying the exact tag, which is what the
site's version selector displays.

The sync is `rsync --delete`: a page removed in a release stops being served,
rather than lingering as an orphan the sidebar still links to. Pre-release tags
are refused, so `v2.0.0-beta.1` can never overwrite the v2 docs people are
reading.

### Configuring the target

Three repository settings, all optional. Without them the job skips and says so
in the run summary, so a fork never sees a failed workflow it cannot fix.

| Setting | Purpose |
|---|---|
| `vars.DOCS_SITE_REPO` | `owner/repo` of the documentation site |
| `vars.DOCS_SITE_PATH` | Docs root within that repo (default `docs-sources/refit`) |
| `secrets.DOCS_SITE_TOKEN` | A token with `contents: write` on the site repository |

`workflow_dispatch` re-publishes any stable tag — pass one, or leave it empty to
take the latest release. Useful for backfilling a version directory, or after
changing where the site keeps its docs.

## What ships

`.gitattributes` keeps development files out of the Composer dist: tests,
workbench, `bin/`, CI configuration, the agent instructions, and this
documentation. What a consumer installs is `src`, `config`, `stubs`,
`resources`, and the licence.

Refit is a dev-only package, so its own dependencies stay minimal:
`illuminate/console`, `illuminate/support`, `laravel/prompts`,
`symfony/finder` and `symfony/process`. Adding a runtime dependency to a package
that installs into someone else's application is a decision worth arguing for
first.
