# Local Testing Commands

Install development dependencies first:

```bash
composer install
```

Run the usual local checks:

```bash
composer validate
vendor/bin/ecs check
vendor/bin/phpstan analyse src/ --level=5
composer audit
```

Useful focused commands:

```bash
vendor/bin/ecs check --fix
php -d memory_limit=1G vendor/bin/phpstan analyse src/ --level=5
vendor/bin/phpunit
```

The repository does not track `composer.lock`; do not treat a missing lock file as an error for normal bundle development.
