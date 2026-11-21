# Go-live plan — refit repository

What is left in *this* repo before the docs can be published. The site-side work
is in [docs-site.md](docs-site.md); the two meet at step 5.

Written 2026-08-14. State at that point: `docs/` holds 17 pages, the workbench
serves them at `/docs`, `publish-docs.yml` is written but has never run, and no
release has been tagged.

---

## 1. Decide the published URL — blocks step 2

Four shapes were on the table:

- `onelegstudios.com/refit/docs`
- `onelegstudios.com/open-source/refit/docs`
- `open-source.onelegstudios.com/refit/docs`
- `refit.onelegstudios.com/docs`

All four work — `route.prefix` is passed straight to a Laravel route group, and
`route.domain` handles the subdomain forms.

Remember the version segment: with versioning on, a page lives at
`{prefix}/{version}/{slug}`, e.g. `/open-source/refit/docs/v0/guide/icons`.

- [ ] Pick one and write it here: `________________________`

## 2. Fix the cross-links to match

Every internal link in `docs/` is site-absolute against the `/docs` prefix —
`[Icons](/docs/using-refit/guide/icons)`. There are 53 of them across the tree,
pointing at 19 distinct targets (README's links are relative `.md` paths and are
fine as they are; they serve GitHub, not the site).

All of them break the moment the prefix or the version segment changes. The
page structure is settled now, so this rewrite only has to happen once.

Three ways to fix, in order of preference:

1. **Relative links** — `[Icons](../guide/icons)` from a sibling section.
   Prefix- and version-independent, because the browser resolves them against
   the current URL. Costs: depth has to be right per page, and the tree is three
   levels deep in places, so the depths vary.
2. **Absolute with the final prefix** — simplest to read, but hardcodes both the
   prefix and the version, so every link needs rewriting again at v1.
3. **A Laradocs variable** — `Laradocs::share('base', '/open-source/refit/docs')`
   and `[Icons]({{ base }}/using-refit/guide/icons)`. Prefix-independent, but the
   markdown stops being readable outside the site, and the value lives in the
   *site's* provider — a coupling in the wrong direction for a package repo.

- [ ] Choose an approach
- [ ] Rewrite the 53 links
- [ ] Re-run the link check (the loop used when the docs were written: extract
      every `(/docs…)` or relative target and assert a file exists for it)
- [ ] **Verify against a versioned layout**, not just the flat workbench — this
      is the step that actually proves the choice. See step 3.

> Relative-link resolution under a version segment is reasoned, not yet
> observed. Do not skip the verification.

## 3. Rehearse the published shape in the workbench

Today the workbench points Laradocs at `docs/` with the default `/docs` prefix
and no versioning — which is *not* the shape the site will serve.

- [ ] In `WorkbenchServiceProvider`, mirror the site: set `route.prefix` to the
      chosen path, `versions.enabled` to true, and point `docs.path` at a
      directory holding a `v0/` subdirectory rather than at `docs/` itself
      (symlink `v0 → ../docs`, or rsync into `build/docs-preview/v0`, so there
      is still one copy of the markdown to edit)
- [ ] `composer serve`, then click through every section, the version selector,
      and the search palette
- [ ] Confirm no link 404s and the sidebar highlights correctly
- [ ] Decide whether this rehearsal config stays (useful) or reverts to the
      simple flat setup once verified

## 4. Get the repo release-ready

- [ ] `CHANGELOG.md` still has placeholders — `202x-xx-xx` and a `v0.1.0`
      compare link with an empty base. Fix both before the first tag
- [ ] `composer starter-kits` then `composer test` — the feature suite is
      hollow without fixtures on disk
- [ ] `composer icons:check` and `composer flux:check` — both report
      drift without writing
- [ ] Review `git diff` on `resources/` if either check wrote anything
- [ ] Tag and publish `v0.1.0` from GitHub (the publish workflow fires on
      `released`, so a draft or pre-release does nothing)

## 5. Turn on publishing — needs the site repo to exist

- [ ] Create the token with `contents: write` on the site repository
- [ ] Set `secrets.DOCS_SITE_TOKEN`, `vars.DOCS_SITE_REPO`, and
      `vars.DOCS_SITE_PATH` if it differs from `docs-sources/refit`
- [ ] Run `publish-docs.yml` via `workflow_dispatch` **before** relying on a
      release to fire it — the workflow has never executed
- [ ] Confirm the commit lands in the site repo at `docs-sources/refit/v0/`,
      with `_version.json` carrying the exact tag
- [ ] Confirm the site rebuilds and serves the new pages

## Decisions already made

- Docs are delivered by **push from this repo**, not by the site requiring the
  package. Composer installs one version at a time, so composer-require cannot
  serve v1 and v2 side by side.
- **One directory per major.** All of `0.x` is `v0`; pre-release tags are
  refused so a beta cannot overwrite a stable line.
- The version selector shows the **exact tag** of the latest release in each
  line (`v0.2.1`), refreshed on every publish.
- `docs/` and `plans/` are `export-ignore`d, so the Composer dist stays lean.
  The publisher reads the git repo, not the dist, so this costs nothing.
