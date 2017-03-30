# MigrationManager

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)

A simple, framework agnostic, MySQL non-reversible migrations library that accepts a pre-configured PDO connection
(allowing you to preset desired connection configuration such as sql_mode or timezone and your own error handling).

Why? I looked at other libraries for migrations but they all had a large number of issues around MySQL language support
and reversals, or controlling the mysql connection (eg. timezone, sql_mode) is problematic because they control creation
of the connection. While I could try to enforce not using the problematic features, it's much easier if they aren't
available in the first place.

## Versions

Use version 1 for compatibility with PHP 5.6. Version 2+ require PHP 7.1+

## Install

Via Composer

``` bash
$ composer require allenjb/migrationmanager
```

## Usage

``` php
$pdo = new \PDO(...);
$pathToMigrations = "../db/migrations/";
$migrationsTable = 'migrations';
$manager = new AllenJB\MigrationManager($pdo, $pathToMigrations, $migrationsTable);

// List migrations
print_r ($manager->executedMigrations());
print_r ($manager->migrationsToExecute());
print_r ($manager->futureMigrations());

// Perform pending migrations
$manager->executeMigrations();

// You MUST call when you've finished using the manager to release all locks
$manager->unlock();
```

## Migration Files

Filenames MUST be in the format YYYYMMDD_HHmm_ClassName.php

Time is in 24 hour format.

ClassName must be unique.

If the date is greater than today (disregarding time), then the migration will not be executed until that date. This
allows you to schedule migrations in the future (for example, you're removing a field but want to leave it in the
database for a period to guard against dataloss / code rollbacks).

``` php
<?php
declare(strict_types = 1);
// example filename: 20170330_1100_Initialize.php

use AllenJB\MigrationManager\AbstractMigration;

class Initialize extends AbstractMigration
{

    public function up() : void
    {
        // Execute your changes here, using $this->db to access the PDO connection
    }
}
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

[ico-version]: https://img.shields.io/packagist/v/AllenJB/MigrationManager.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/AllenJB/MigrationManager
[link-author]: https://github.com/AllenJB
