Laravel Package
---

Laravel package template.

[![Sponsor me](https://github.com/overtrue/overtrue/blob/master/sponsor-me-button-s.svg?raw=true)](https://github.com/sponsors/overtrue)

## Requirements

- PHP 8.4 or 8.5
- Laravel 13.30 or later in the 13.x series
- Composer 2

## Create your package

Use GitHub's **Use this template** button to create your own repository, then clone it. This repository is a starting point for a package, with no application features of its own.

Before installing dependencies:

1. In `composer.json`, replace `overtrue/laravel-package` with your Composer package name, and update the description, author and license as appropriate.
2. Replace `Overtrue\LaravelPackage` with your namespace in `src/`, `tests/` and the Composer autoload and discovery settings. Remember that backslashes are escaped in JSON. Keep the provider class name consistent if you rename `PackageServiceProvider`.
3. Update this README, repository links and sponsorship settings for your package.

```shell
composer install
composer test
composer check-style
composer test-smoke
```

The smoke test requires network access and SQLite support. It creates a temporary, renamed package, installs it into a fresh Laravel 13 application through a Composer path repository, and checks automatic discovery, migration publishing and database behavior. Its sample migration is created only in the temporary package.

## Package development

`src/PackageServiceProvider.php` is automatically discovered by Laravel through `extra.laravel.providers` in `composer.json`. Add your package's implementation in `src/`, and replace or extend the example tests in `tests/`.

### Migrations

The `migrations/` directory is initially empty. Add your package's migrations there when needed. The service provider registers that directory with Laravel's migrator for console commands, so application users can run:

```shell
php artisan migrate
```

Users can also publish your migrations into their application's `database/migrations` directory. Use your renamed provider in this command:

```shell
php artisan vendor:publish --provider="Overtrue\LaravelPackage\PackageServiceProvider" --tag=migrations
```

No configuration files or events are included. Add and document them if your package needs them. The SQLite migration and model under `tests/` are test fixtures, not application migrations.

### Test in an application

In a separate Laravel 13 application, point Composer at your package's local directory (replace the path and package name):

```shell
composer config repositories.local-package path ../your-package
composer require your-vendor/your-package:@dev
```

Composer will install the package and Laravel will discover its provider. Test your actual application integration before publishing a release.

## :heart: Sponsor me 

[![Sponsor me](https://github.com/overtrue/overtrue/blob/master/sponsor-me.svg?raw=true)](https://github.com/sponsors/overtrue)

如果你喜欢我的项目并想支持它，[点击这里 :heart:](https://github.com/sponsors/overtrue)

## Contributing

You can contribute in one of three ways:

1. File bug reports using the [issue tracker](https://github.com/overtrue/laravel-package/issues).
2. Answer questions or fix bugs on the [issue tracker](https://github.com/overtrue/laravel-package/issues).
3. Contribute new features or update the wiki.

_The code contribution process is not very formal. You just need to make sure that you run `composer test`, `composer check-style`, and `composer test-smoke` before submitting changes. Any new code contributions must be accompanied by unit tests where applicable._

## Project supported by JetBrains

Many thanks to Jetbrains for kindly providing a license for me to work on this and other open-source projects.

[![](https://resources.jetbrains.com/storage/products/company/brand/logos/jb_beam.svg)](https://www.jetbrains.com/?from=https://github.com/overtrue)


## License

MIT
