# CI/CD Pipeline

The GitHub Actions workflows are intentionally small and mirror the local development commands.

## CI

`.github/workflows/ci.yml` runs on changes to `main` and `develop`, and on pull requests to `main`. PHP 8.4 and 8.5 are blocking compatibility targets for Contao 6. The workflow checks:

- Composer metadata
- PHP syntax in `src/`, `tests/`, and `contao/`
- ECS code style
- PHPStan level 5
- PHPUnit
- Composer audit

## Release

`.github/workflows/release.yml` runs on `v*` tags. It resolves the PHP 8.4 and Contao 6 baseline, repeats the quality and archive checks, and creates or updates the GitHub release. Release links point to the tag, not a moving branch.

Run `scripts/release.sh --check 3.0.0` from a clean, up-to-date `main` checkout to execute the release gates without creating or pushing a tag. Omitting `--check` crosses the release boundary and creates the tag.

## Configuration

- `ecs.php` defines the Contao ECS rules and paths.
- `phpstan.neon` defines static-analysis paths, level and project-specific ignores.
- `.gitattributes` export-ignores CI/development files that should not be shipped in Composer archives.
