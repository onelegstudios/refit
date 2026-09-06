---
title: Contributing
description: Setting up, running the suite, and getting a release out.
order: 1
---

# Contributing

Working *on* refit rather than with it.

| Page | What it covers |
|---|---|
| [Setup](/docs/development/contributing/setup) | Clone, install, and the commands you will actually use |
| [Testing](/docs/development/contributing/testing) | Pest, Testbench, fixtures, and what skips without a licence |
| [Releasing](/docs/development/contributing/releasing) | CI, the changelog, and how a version goes out |

For how the tool is built — the plan, the Blade rewriting, the icon pipeline —
see [Internals](/docs/development/internals).

## Ground rules

- Use Laravel-native package APIs and the existing service provider shape before
  adding abstractions.
- Add only the files and dependencies needed for the behaviour being
  implemented.
- Keep tests on observable behaviour through public APIs — commands, the
  registry, published resources, and the plans a project produces.
- Package names, namespaces, Composer metadata, publish tags and examples all say
  `onelegstudios/refit`.
