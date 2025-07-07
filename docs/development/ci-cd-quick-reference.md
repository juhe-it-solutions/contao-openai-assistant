# CI/CD Quick Reference

## 🚀 Quick Commands

### Local Quality Checks
```bash
# Install dependencies
composer install

# Run all quality checks locally
vendor/bin/ecs check src
vendor/bin/phpstan analyse src/ --level=5
vendor/bin/security-checker security:check composer.lock
composer validate --strict
```

### Fix Code Style Issues
```bash
# Auto-fix ECS issues
vendor/bin/ecs check src --fix
```

### Create Release
```bash
# Create and push tag (triggers release)
git tag v1.0.2
git push origin v1.0.2
```

## 📋 Workflow Summary

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| `ci.yml` | Push to main | Quality checks |
| `release.yml` | Tag push (v*) | Release creation |

## 🔍 Quality Checks

| Check | Command | Purpose |
|-------|---------|---------|
| PHP Syntax | `php -l` | Validate syntax |
| Code Style | `ecs check src` | PSR-12 compliance |
| Static Analysis | `phpstan analyse src/` | Find bugs |
| Security | `security-checker` | Vulnerability scan |
| Composer | `composer validate` | Config validation |

## 🚨 Common Issues & Solutions

### ECS Failures
```bash
# Fix automatically
vendor/bin/ecs check src --fix

# Check specific file
vendor/bin/ecs check src/YourFile.php
```

### PHPStan Errors
```bash
# Run with specific level
vendor/bin/phpstan analyse src/ --level=3

# Generate baseline (ignore current errors)
vendor/bin/phpstan analyse src/ --generate-baseline
```

### Security Issues
```bash
# Update dependencies
composer update

# Check specific package
vendor/bin/security-checker security:check package/name
```

## 📊 Release Process

### 1. Prepare Release
```bash
# Update CHANGELOG.md
# Test locally
# Commit changes
git add .
git commit -m "Prepare release v1.0.2"
git push origin main
```

### 2. Create Release
```bash
# Create tag
git tag v1.0.2

# Push tag (triggers release workflow)
git push origin v1.0.2
```

### 3. Monitor Release
- Check GitHub Actions tab
- Verify release was created
- Review release notes

## 🛠️ Troubleshooting

### Workflow Not Triggering
- Check tag format: `v1.0.2` (not `1.0.2`)
- Ensure tag is pushed to remote
- Check GitHub Actions permissions

### Release Creation Fails
- Check all quality checks pass
- Verify GitHub token permissions
- Review workflow logs

### Local vs CI Differences
- Use same PHP version (8.4)
- Install same dependencies
- Run same commands locally

## 📈 Monitoring

### GitHub Actions
- **Success Rate**: Monitor workflow success/failure
- **Execution Time**: Track performance
- **Resource Usage**: Monitor GitHub Actions minutes

### Quality Metrics
- **ECS Issues**: Track code style compliance
- **PHPStan Errors**: Monitor static analysis
- **Security Issues**: Track vulnerability reports

## 🔧 Configuration Files

| File | Purpose |
|------|---------|
| `.github/workflows/ci.yml` | CI workflow |
| `.github/workflows/release.yml` | Release workflow |
| `ecs.php` | Code style rules |
| `phpstan.neon` | Static analysis config |

## 📚 Useful Links

- [Full CI/CD Documentation](./ci-cd-pipeline.md)
- [GitHub Actions](https://docs.github.com/en/actions)
- [ECS Documentation](https://github.com/symplify/easy-coding-standard)
- [PHPStan](https://phpstan.org/)

## 🎯 Best Practices

### Before Pushing
1. Run quality checks locally
2. Fix any issues found
3. Test functionality
4. Update documentation

### Before Releasing
1. Update CHANGELOG.md
2. Test thoroughly
3. Check for breaking changes
4. Review release notes

### Regular Maintenance
1. Update dependencies monthly
2. Monitor security advisories
3. Review workflow performance
4. Update documentation

---

*Quick reference for CI/CD pipeline - Last updated: December 19, 2024* 