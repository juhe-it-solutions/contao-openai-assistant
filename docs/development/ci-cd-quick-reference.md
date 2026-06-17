# CI/CD Quick Reference

CI runs on pushes to `main` and `develop`, and on pull requests to `main`. Documentation-only changes are ignored.

The jobs are:

- Code quality: `composer validate`, PHP syntax check, ECS and PHPStan.
- Code formatting: `vendor/bin/ecs check --fix` followed by a clean-worktree check.
- Security: `composer audit`.

Release runs on `v*` tags and repeats the quality checks before creating a GitHub release.

Configuration files `ecs.php` and `phpstan.neon` should stay in the repository because CI depends on them. They are excluded from Composer release archives through `.gitattributes`.
