<div align="center">
    <h1>Refit</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/onelegstudios/refit"><img src="https://img.shields.io/packagist/v/onelegstudios/refit.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/onelegstudios/refit"><img src="https://img.shields.io/packagist/php-v/onelegstudios/refit.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/onelegstudios/refit"><img src="https://badge.laravel.cloud/badge/onelegstudios/refit?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/onelegstudios/refit/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/onelegstudios/refit/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/onelegstudios/refit"><img src="https://img.shields.io/packagist/dt/onelegstudios/refit.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Refit the starter. One-time scaffolding for Laravel's Livewire starter kit.

One interactive command picks an icon set and a list of structural tasks, shows
you exactly what it intends to change, and applies it.

It rewrites files in place, the changes are one-way, and it offers to remove
itself when it is done.

## Installation

Install it as a dev dependency, right after `laravel new`:

```bash
composer require --dev onelegstudios/refit
```

## Usage

```bash
php artisan refit
```

The command runs in five stages — detect, ask, plan, confirm, apply. Nothing
touches disk until you have seen the plan and agreed to it, and it will not start
unless `install:features` has already run and your git working tree is clean.

It asks two questions:

- **Icons.** A fresh kit speaks Heroicons *and* Lucide at once. Refit takes you
  to one or the other, including the icons Flux renders from inside its own
  components.
- **Tasks.** Partials become components, loose components get sorted into folders,
  the layouts nothing renders go. Only tasks that fit the kit it detected are
  offered.

Anything it cannot do — an icon with no translation, an `@include` that passes
data — is reported with the file it is in, never silently guessed at.

## Documentation

Full documentation lives in [`docs/`](docs), and is served by
[Laradocs](https://laradocs.dev) at `/docs` when you run `composer serve`.

**Using refit**

- [Getting started](docs/getting-started.md)
- [The refit command](docs/using-refit/guide/the-refit-command.md) — stages, options, and the checks that stop a run
- [Icons](docs/using-refit/guide/icons.md)
- [Tasks](docs/using-refit/guide/tasks.md)
- [Configuration](docs/using-refit/reference/configuration.md)
- [Writing your own task](docs/using-refit/guide/custom-tasks.md)
- [Supported starter kits](docs/using-refit/reference/starter-kits.md)
- [Troubleshooting](docs/using-refit/reference/troubleshooting.md)

**Working on refit**

- [Setup](docs/development/contributing/setup.md)
- [Architecture](docs/development/internals/architecture.md)
- [Blade rewriting](docs/development/internals/blade-rewriting.md)
- [The icon pipeline](docs/development/internals/icon-pipeline.md)
- [Testing](docs/development/contributing/testing.md)
- [Releasing](docs/development/contributing/releasing.md)

## Testing

```bash
composer test
```

Tests that exercise a real starter kit skip themselves unless the fixtures have
been downloaded with `composer fixtures`. You do not need a Flux Pro licence to
contribute — see [Setup](docs/development/contributing/setup.md).

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Refit! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Oneleggedswede](https://github.com/onelegstudios)
- [All Contributors](../../contributors)
- Icon artwork from [Lucide](https://lucide.dev), ISC licensed — see [`resources/icons/lucide/LICENSE`](resources/icons/lucide/LICENSE)

## License

Refit is open-sourced software licensed under the [MIT license](LICENSE.md).
