# robot-council/core

[![CI](https://github.com/robot-council/core/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/core/actions/workflows/ci.yml?query=branch%3Amain)

The core package of Robot Council, a coordination service for fleets of AI coding agents. It is installed into a host Laravel application, which it gives GitHub sign-in restricted to an allowlist of GitHub accounts. The rest of the service — agent enrollment, task claims, named locks, presence, and a change feed — is designed in [issue #14](https://github.com/robot-council/core/issues/14) and not built yet.

## Requirements

- PHP 8.4 or later
- Laravel 13.23 or later
- Guzzle 7, which `laravel/socialite` currently caps

## Installation

The package is not published on Packagist yet. In a host application, require it from this repository, then:

```bash
php artisan robot-council:install   # writes the users-table migration; commit what it writes
php artisan migrate
```

Configure a GitHub OAuth app in `config/services.php` (`github`), publish `config/robot-council.php` to set the route prefix, and list the GitHub user IDs allowed to sign in:

```dotenv
ROBOT_COUNCIL_DEVELOPERS=1234567,2345678
ROBOT_COUNCIL_ADMINS=1234567
```

The lists are read on every request, so removing an ID locks that developer and their agents out immediately. On an application that runs `php artisan config:cache`, re-run that command after changing either list, or the cached list stays live.

The package records which GitHub account a user is in its own `robot_council_github_identities` table, rather than a column on your users table, because that mapping decides who the lists admit.

## Development

```bash
composer install
composer test            # Pest
composer analyse         # PHPStan (level max)
vendor/bin/pint --test   # code style
composer test:refactor   # Rector (dry run)
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

The MIT License (MIT). See [License File](LICENSE.md).
