# CI/CD Quick Reference

Quick reference for the Contao OpenAI Assistant CI/CD pipeline.

## Workflow Overview

| Job | Command | Purpose |
|-----|---------|---------|
| Code Quality | `composer validate && vendor/bin/ecs check && vendor/bin/phpstan analyse src/` | Syntax, style, and static analysis |
| Code Formatting | `vendor/bin/ecs check --fix` | Auto-fix formatting issues |
| Security | `composer audit` | Vulnerability scan |

## Local Testing Commands

### Quick Validation
```bash
composer validate && composer install --prefer-dist --no-progress && find src/ -name "*.php" -exec php -l {} \; && vendor/bin/ecs check && vendor/bin/phpstan analyse src/ --level=5
```

### Individual Tests
```bash
# Composer validation
composer validate

# PHP syntax check
find src/ -name "*.php" -exec php -l {} \;

# Code style check
vendor/bin/ecs check

# Static analysis
vendor/bin/phpstan analyse src/ --level=5

# Security audit
composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"
```

## CI/CD Pipeline

### Triggers
- **Push to main/develop** → Runs CI
- **Pull request to main** → Runs CI
- **Tag push (v*)** → Creates release

### Jobs
1. **Code Quality** - PHP 8.2, syntax, style, static analysis
2. **Code Formatting** - PHP 8.2, auto-fix with validation
3. **Security Check** - PHP 8.2, vulnerability scanning

### PHP Requirements
- **Version:** 8.2+
- **Extensions:** mbstring, xml, ctype, iconv, intl, curl, json, dom, gd

## Quality Tools

| Tool | Purpose | Command |
|------|---------|---------|
| ECS | Code style | `vendor/bin/ecs check` |
| PHPStan | Static analysis | `vendor/bin/phpstan analyse src/ --level=5` |
| Composer Audit | Security | `composer audit --format=json 2>/dev/null \|\| echo "Security check skipped"` |

## Release Process

### Create Release
```bash
# Create and push tag
git tag -a v1.0.3 -m "Release 1.0.3"
git push origin v1.0.3

# GitHub Actions will:
# 1. Run all quality checks
# 2. Create GitHub release
# 3. Generate release notes
```

### Release Notes Template
```markdown
## 🎉 Release v1.0.3

### ✅ Quality Checks Passed
- PHP syntax validation
- Code style compliance (ECS)
- Static analysis (PHPStan Level 5)
- Security vulnerability scan
- Composer validation

### 📦 Installation
```bash
composer require juhe-it-solutions/contao-openai-assistant
```

### 🔗 Links
- [Documentation](https://github.com/juhe-it-solutions/contao-openai-assistant/tree/main/docs)
- [Security Policy](https://github.com/juhe-it-solutions/contao-openai-assistant/blob/main/SECURITY.md)
- [Changelog](https://github.com/juhe-it-solutions/contao-openai-assistant/blob/main/CHANGELOG.md)
```

## Troubleshooting

### Common Issues

#### Dependency Conflicts
```bash
composer update
composer validate
```

#### Code Style Issues
```bash
vendor/bin/ecs check --fix
```

#### Static Analysis Errors
```bash
vendor/bin/phpstan analyse src/ --level=5 --verbose
```

#### Security Vulnerabilities
```bash
composer audit
composer update package-name
```

### Debugging
```bash
# Check PHP version
php --version

# Check extensions
php -m

# Verify Composer
composer --version

# Test tools
vendor/bin/ecs check --help
vendor/bin/phpstan --help
```

## Configuration Files

| File | Purpose |
|------|---------|
| `.github/workflows/ci.yml` | Main CI workflow |
| `.github/workflows/release.yml` | Release workflow |
| `ecs.php` | Code style configuration |
| `phpstan.neon` | Static analysis configuration |
| `composer.json` | Dependencies and metadata |

## Best Practices

### Development
- Run tests locally before pushing
- Follow PSR-12 coding standards
- Keep dependencies updated
- Monitor security advisories

### Releases
- Use semantic versioning
- Update CHANGELOG.md
- Test thoroughly before tagging
- Review release notes

### Maintenance
- Regular dependency updates
- Monitor CI/CD performance
- Keep documentation current
- Security-first approach

## Resources

- [Local Testing Commands](local-testing-commands.md)
- [CI/CD Pipeline Documentation](ci-cd-pipeline.md)
- [Troubleshooting Guide](troubleshooting.md)
- [Contao Documentation](https://docs.contao.org/)
- [ECS Documentation](https://github.com/symplify/easy-coding-standard)
- [PHPStan Documentation](https://phpstan.org/)

---

*Version: 1.0* 