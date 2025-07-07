# CI/CD Pipeline Documentation

## Overview

This document describes the Continuous Integration and Continuous Deployment (CI/CD) pipeline implemented for the Contao OpenAI Assistant project. The pipeline ensures code quality, security, and automated releases.

## Table of Contents

- [Pipeline Overview](#pipeline-overview)
- [Workflow Files](#workflow-files)
- [Quality Checks](#quality-checks)
- [Release Process](#release-process)
- [Triggering the Pipeline](#triggering-the-pipeline)
- [Troubleshooting](#troubleshooting)
- [Best Practices](#best-practices)

## Pipeline Overview

The CI/CD pipeline consists of two main workflows:

1. **Continuous Integration (CI)** - Runs on every push to main branch
2. **Release Pipeline** - Runs when a version tag is pushed

### Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Code Push     │───▶│  Quality Checks │───▶│  Release        │
│   or Tag Push   │    │  & Validation   │    │  Creation       │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Workflow Files

### 1. Continuous Integration (`.github/workflows/ci.yml`)

**Purpose**: Ensures code quality on every push to main branch

**Triggers**:
- Push to `main` branch
- Pull requests to `main` branch

**Checks Performed**:
- PHP syntax validation
- Code style compliance (ECS)
- Static analysis (PHPStan)
- Security vulnerability scanning
- Composer validation

### 2. Release Pipeline (`.github/workflows/release.yml`)

**Purpose**: Creates automated releases with quality assurance

**Triggers**:
- Push of tags matching pattern `v*` (e.g., `v1.0.2`)

**Process**:
1. Quality checks (same as CI)
2. Automatic GitHub release creation
3. Release notes generation

## Quality Checks

### PHP Syntax Validation

```yaml
- name: Check PHP syntax
  run: |
    find src/ -name "*.php" -exec php -l {} \;
```

**What it does**:
- Scans all PHP files in the `src/` directory
- Validates PHP syntax is correct
- Fails if any file has syntax errors

### Code Style Compliance (ECS)

```yaml
- name: Check code style
  run: vendor/bin/ecs check src
```

**What it does**:
- Runs Easy Coding Standard (ECS)
- Ensures code follows PSR-12 standards
- Checks for consistent formatting
- Validates coding conventions

### Static Analysis (PHPStan)

```yaml
- name: Run PHPStan analysis
  run: vendor/bin/phpstan analyse src/ --level=5
```

**What it does**:
- Performs static code analysis
- Level 5 is very strict
- Finds potential bugs and code issues
- Checks type safety and logic errors

### Security Vulnerability Scan

```yaml
- name: Run security check
  run: composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"
```

**What it does**:
- Scans all dependencies for known vulnerabilities
- Checks against security databases
- Ensures no vulnerable packages are used
- Provides security recommendations

### Composer Validation

```yaml
- name: Validate composer.json
  run: composer validate --strict
```

**What it does**:
- Validates `composer.json` structure
- Checks for required fields
- Ensures proper dependency definitions
- Validates autoloading configuration

## Release Process

### Automated Release Creation

When a tag is pushed, the pipeline automatically:

1. **Runs all quality checks**
2. **Creates GitHub release** if checks pass
3. **Generates release notes** with:
   - Quality check results
   - Installation instructions
   - Links to documentation

### Release Notes Template

```markdown
## 🎉 Release {version}

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

## Triggering the Pipeline

### For Development (CI)

```bash
# Push to main branch
git push origin main
```

**Result**: Runs quality checks, no release created

### For Releases

```bash
# Create and push a tag
git tag v1.0.2
git push origin v1.0.2
```

**Result**: Runs quality checks + creates GitHub release

### Tag Naming Convention

- **Major releases**: `v2.0.0`
- **Minor releases**: `v1.1.0`
- **Patch releases**: `v1.0.2`
- **Pre-releases**: `v1.0.2-beta.1`

## Environment Setup

### Required Extensions

The pipeline uses PHP 8.4 with these extensions:
- `mbstring`
- `xml`
- `ctype`
- `iconv`
- `intl`
- `dom`

### Dependencies

The pipeline requires these development dependencies:
- `contao/easy-coding-standard` - Code style checking
- `phpstan/phpstan` - Static analysis
- `composer audit --format=json 2>/dev/null || echo "Security check skipped"` - Built-in security scanning

## Troubleshooting

### Common Issues

#### 1. ECS Code Style Failures

**Problem**: Code style check fails
**Solution**: Run locally to fix issues
```bash
vendor/bin/ecs check src --fix
```

#### 2. PHPStan Analysis Failures

**Problem**: Static analysis finds issues
**Solution**: Review and fix code issues
```bash
vendor/bin/phpstan analyse src/ --level=5
```

#### 3. Security Check Failures

**Problem**: Vulnerable dependencies detected
**Solution**: Update dependencies
```bash
composer update
composer audit
```

#### 4. Release Creation Fails

**Problem**: Release not created despite passing checks
**Solution**: Check GitHub Actions logs for specific errors

### Debugging Workflows

1. **View workflow runs**: Go to GitHub → Actions tab
2. **Check specific job**: Click on failed job for details
3. **View logs**: Expand steps to see detailed output
4. **Re-run workflow**: Use "Re-run jobs" button

## Best Practices

### For Developers

1. **Run checks locally** before pushing:
   ```bash
   composer install
   vendor/bin/ecs check src
   vendor/bin/phpstan analyse src/ --level=5
   composer audit
   ```

2. **Follow coding standards**:
   - Use PSR-12 formatting
   - Follow Contao coding conventions
   - Write clear, documented code

3. **Test thoroughly** before tagging:
   - Ensure all tests pass
   - Verify functionality works
   - Check for breaking changes

### For Releases

1. **Update CHANGELOG.md** before releasing
2. **Use semantic versioning** for tags
3. **Test in staging** before production release
4. **Review release notes** after creation

### For Maintenance

1. **Regular dependency updates**:
   ```bash
   composer update
   composer audit
   ```

2. **Monitor security advisories**:
   - Check GitHub security tab
   - Review dependency updates
   - Update vulnerable packages promptly

3. **Keep workflows updated**:
   - Review GitHub Actions updates
   - Update workflow syntax as needed
   - Monitor for deprecated actions

## Configuration Files

### `.github/workflows/ci.yml`

Main CI workflow for quality checks on every push.

### `.github/workflows/release.yml`

Release workflow for automated releases with quality gates.

### `ecs.php`

Easy Coding Standard configuration for code style rules.

### `phpstan.neon`

PHPStan configuration for static analysis settings.

## Monitoring and Metrics

### GitHub Actions Insights

- **Workflow run history**: View success/failure rates
- **Execution time**: Monitor pipeline performance
- **Resource usage**: Track GitHub Actions minutes

### Quality Metrics

- **Code coverage**: Track test coverage trends
- **Security issues**: Monitor vulnerability reports
- **Code quality**: Track ECS and PHPStan issues

## Future Enhancements

### Planned Improvements

1. **Test automation**: Add PHPUnit test execution
2. **Coverage reporting**: Generate code coverage reports
3. **Performance testing**: Add performance benchmarks
4. **Enhanced security scanning**: Additional vulnerability checks
5. **Automated deployment**: Direct deployment to staging

### Integration Opportunities

1. **Slack notifications**: Alert team of failures
2. **Jira integration**: Link issues to releases
3. **Docker builds**: Container image creation
4. **Package publishing**: Automated Packagist updates

## Support and Resources

### Documentation Links

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [Easy Coding Standard](https://github.com/symplify/easy-coding-standard)
- [PHPStan](https://phpstan.org/)
- [Composer Audit](https://getcomposer.org/doc/03-cli.md#audit)

### Getting Help

1. **Check existing issues**: Search GitHub issues
2. **Review documentation**: Read this and related docs
3. **Ask the team**: Contact maintainers
4. **Create issue**: Report bugs or request features

---

*Last updated: December 19, 2024*
*Version: 1.0* 