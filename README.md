# This is my package robot-council

[![Latest Version on Packagist](https://img.shields.io/packagist/v/joshdaugherty/robot-council.svg?style=flat-square)](https://packagist.org/packages/joshdaugherty/robot-council)
[![GitHub Tests Action Status](https://github.com/joshdaugherty/robot-council/actions/workflows/run-tests.yml/badge.svg)](https://github.com/joshdaugherty/robot-council/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/joshdaugherty/robot-council/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/joshdaugherty/robot-council/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/joshdaugherty/robot-council.svg?style=flat-square)](https://packagist.org/packages/joshdaugherty/robot-council)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require joshdaugherty/robot-council
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="robot-council-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="robot-council-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="robot-council-views"
```

## Usage

```php
$robotCouncil = new JoshDaugherty\RobotCouncil();
echo $robotCouncil->echoPhrase('Hello, JoshDaugherty!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [joshdaugherty](https://github.com/joshdaugherty)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
