# CI/CD Pipeline

The GitHub Actions workflows are intentionally small and mirror the local development commands.

## CI

`.github/workflows/ci.yml` runs on code changes to `main`, `develop` and pull requests to `main`. It uses PHP 8.2 and checks:

- Composer metadata
- PHP syntax in `src/`
- ECS code style
- PHPStan level 5
- Composer audit

## Release

`.github/workflows/release.yml` runs on `v*` tags. It repeats the quality checks and creates a GitHub release.

## Configuration

- `ecs.php` defines the Contao ECS rules and paths.
- `phpstan.neon` defines static-analysis paths, level and project-specific ignores.
- `.gitattributes` export-ignores CI/development files that should not be shipped in Composer archives.
