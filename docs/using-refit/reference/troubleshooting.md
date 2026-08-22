---
title: Troubleshooting
description: Warnings, REFIT-NOTES.md, and how to undo a run.
order: 3
---

# Troubleshooting

## Undoing a run

```bash
git checkout .
```

That is the whole story, and it is why refit insists on a clean working tree
before it starts. Refit ships no backup or rollback machinery of its own, because
git already is one.

If you ran with `--force` on a dirty tree, the changes are mixed in with whatever
was already uncommitted, and only you can separate them.

## REFIT-NOTES.md

When a run has anything to flag, refit writes it to `REFIT-NOTES.md` in the
project root:

```markdown
# Refit notes

## What changed

- Deleted resources/views/flux/icon/layout-grid.blade.php

## Needs a look

- No Lucide translation for "sparkles" — still Heroicons in resources/views/pages/dashboard.blade.php.
```

The file appears only when there is something under **Needs a look**; a clean run
leaves none. Delete it once you have worked through the list — it is a report,
not state refit reads back. Set `notes` to `null` in
[the config](/docs/using-refit/reference/configuration) to keep the report on screen only.

## Common messages

**"This starter kit still has chisel.php"**

Run `php artisan install:features` first. While `chisel.php` is present the file
tree is not the one you are keeping, so refit would be rewriting views you are
about to delete. This check cannot be forced.

Seeing it at all is unusual: `laravel new` runs that step during creation and the
script deletes itself afterwards, so the file survives mainly when the kit
repository was cloned directly, or when the installer stopped partway. If you do
not recognise the name, [`install:features` and
`chisel.php`](/docs/using-refit/reference/starter-kits#installfeatures-and-chiselphp)
explains what it is.

**"Your git working tree has uncommitted changes"**

Commit or stash first, so `git checkout .` can undo the run. `--force` skips the
check.

**"This is not a git repository"**

Same reasoning, one step earlier: with no repository there is no way back at all.
`git init && git add . && git commit` takes a minute and is worth it. `--force`
skips the check.

**"No Lucide translation for X"**

The name is outside refit's curated map, so that usage stays Heroicons. The map
is deliberately semantic rather than mechanical — `arrow-right-start-on-rectangle`
is Lucide's `log-out` — and guessing would be worse than reporting. Adding a
translation is a small change to refit itself; see
[The icon pipeline](/docs/development/internals/icon-pipeline).

**"No Lucide artwork bundled for X"**

The mapping exists but refit does not ship the drawing, so the rename was dropped
along with the override. Pointing a usage at a file that never gets written would
leave a blank where the icon was.

**"An @include('…') passes data — left alone"**

The argument-less form has a mechanical translation to a component tag; one that
passes data does not, because the component would need matching props. Convert it
by hand.

**"Left resources/views/… alone — '…' would have broken it"**

A rewrite would have left that file's Blade less balanced than it found it, so
that one file was skipped and everything else went ahead. Skipping beats aborting
here: a half-applied run is harder to reason about than one bad file, and
`git checkout .` is always available as the real undo. The warning names the tag
and the counts, so the file is worth a look either way.

**"Left [resources/views/partials] in place — it still holds …"**

Something the plan did not account for was still in a directory it meant to
remove. Nothing was deleted; move or delete the remaining files yourself.

**"Sheaf's select is driven by the `$rover` Alpine plugin, and @sheaf/rover is not installed"**

The `npm install @sheaf/rover` step did not run or did not work, so refit imported
the select's runtime but left the plugin it depends on unregistered. Run the
install, then add `import rover from '@sheaf/rover'` and `Alpine.plugin(rover)` to
`resources/js/app.js` — the message says both. Until then every `<x-ui.select>`
renders and never opens; nothing else in the migration is waiting on it.

**"Skipped importing Sheaf's runtimes — resources/js/app.js does not exist"**

Sheaf writes half of some components as JavaScript and imports none of it, and
refit does that importing from the Vite entrypoint the kit ships. With no
entrypoint there is nowhere to put the lines. Import
`resources/js/globals/*.js` and `resources/js/components/*.js` from whichever file
your build actually reads.

**"Formatting with Pint failed"**

The finishing Pint pass could not run — usually because the project has no
`./vendor/bin/pint`. Everything else in the run still applied. Format the diff
however your project normally does.

## Running it twice

Don't. Refit is one-time scaffolding: the changes are one-way and not idempotent,
which is exactly why the command offers to remove itself when it finishes. A
second run against an already-refitted project will find a different tree than
the one its tasks were written for.

If a run went wrong, `git checkout .` and start again from a clean tree.
