# Development Notes

This project is a Contao bundle. Development tooling is intentionally small:

- ECS checks code style from `ecs.php`.
- PHPStan runs at level 5 from `phpstan.neon`.
- Composer audit checks dependency advisories.
- PHPUnit is available for focused service tests.

Run the local checks from [Local testing commands](local-testing-commands.md). CI and release workflows run the same quality tools.

`composer.lock` is ignored because this repository is a reusable bundle, not a deployed Contao application.
