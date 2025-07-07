# CI/CD Pipeline Documentation

This document describes the comprehensive CI/CD pipeline for the Contao OpenAI Assistant bundle.

## Overview

The CI/CD pipeline ensures code quality, security, and reliability through automated testing and deployment processes.

## Pipeline Structure

### Jobs

1. **Code Quality** - Syntax validation, code style, and static analysis
2. **Code Formatting** - Automated formatting with validation
3. **Security Check** - Vulnerability scanning and dependency audit

### PHP Version

The pipeline uses **PHP 8.2** with these extensions:
- `mbstring` - Multibyte string handling
- `xml` - XML processing
- `ctype` - Character type checking
- `iconv` - Character encoding conversion
- `intl` - Internationalization
- `curl` - HTTP requests
- `json` - JSON processing
- `dom` - DOM manipulation
- `gd` - Image processing

## Workflow Files

### `.github/workflows/ci.yml`

**Triggers:**
- Push to `main` and `develop` branches
- Pull requests to `main` branch
- Excludes documentation changes

**Jobs:**

#### 1. Code Quality
- **PHP Version:** 8.2
- **Steps:**
  - Validate composer.json
  - Install dependencies with caching
  - Check PHP syntax
  - Run ECS code style check
  - Run PHPStan static analysis

#### 2. Code Formatting
- **PHP Version:** 8.2
- **Steps:**
  - Install dependencies
  - Run ECS with auto-fix
  - Validate no formatting changes

#### 3. Security Check
- **PHP Version:** 8.2
- **Steps:**
  - Install dependencies
  - Run composer audit

### `.github/workflows/release.yml`

**Triggers:**
- Push of version tags (`v*`)

**Steps:**
- All quality checks (same as CI)
- Automated GitHub release creation
- Professional release notes

## Quality Assurance Tools

### Code Style (ECS)
- **Tool:** `contao/easy-coding-standard`
- **Configuration:** `ecs.php`
- **Standards:** PSR-12, Clean Code, Common
- **Command:** `vendor/bin/ecs check`

### Static Analysis (PHPStan)
- **Tool:** `phpstan/phpstan`
- **Configuration:** `phpstan.neon`
- **Level:** 5 (strict)
- **Command:** `vendor/bin/phpstan analyse src/ --level=5`

### Security Scanning
- **Tool:** `composer audit`
- **Format:** JSON output
- **Fallback:** Graceful handling for older Composer versions
- **Command:** `composer audit --format=json 2>/dev/null || echo "Security check skipped"`

## Caching Strategy

### Composer Cache
- **Path:** `${{ steps.composer-cache.outputs.dir }}`
- **Key:** `${{ runner.os }}-php-8.2-composer-${{ hashFiles('**/composer.lock') }}`
- **Restore Keys:** Fallback to previous cache versions

## Error Handling

### Graceful Degradation
- Security checks skip gracefully if `composer audit` unavailable
- Clear error messages for failed formatting
- Detailed output for debugging

### Validation Steps
- Composer validation before installation
- PHP syntax checking
- Code style compliance
- Static analysis with strict rules

## Performance Optimizations

### Dependency Installation
- `--prefer-dist` - Use dist packages when possible
- `--no-progress` - Reduce output verbosity
- Caching - Reuse downloaded packages

### Parallel Execution
- Jobs run in parallel when possible
- Independent job execution
- Optimized for GitHub Actions runners

## Troubleshooting

### Common Issues

#### Dependency Conflicts
```bash
# Update lock file
composer update

# Validate composer.json
composer validate
```

#### Code Style Issues
```bash
# Auto-fix formatting
vendor/bin/ecs check --fix

# Check specific files
vendor/bin/ecs check src/Controller/
```

#### Static Analysis Errors
```bash
# Run with verbose output
vendor/bin/phpstan analyse src/ --level=5 --verbose

# Check specific file
vendor/bin/phpstan analyse src/Controller/ApiValidationController.php
```

#### Security Vulnerabilities
```bash
# Check for vulnerabilities
composer audit

# Update vulnerable packages
composer update package-name
```

### Debugging Commands

```bash
# Check PHP version and extensions
php --version
php -m

# Verify Composer installation
composer --version

# Test individual tools
vendor/bin/ecs check --help
vendor/bin/phpstan --help
```

## Local Development

### Prerequisites
- PHP 8.2+
- Composer
- Required PHP extensions

### Setup
```bash
# Install dependencies
composer install

# Run quality checks locally
composer validate
find src/ -name "*.php" -exec php -l {} \;
vendor/bin/ecs check
vendor/bin/phpstan analyse src/ --level=5
```

### Pre-commit Checklist
- [ ] PHP syntax is valid
- [ ] Code style passes ECS
- [ ] Static analysis passes PHPStan
- [ ] No security vulnerabilities
- [ ] Composer validation passes

## Release Process

### Automated Release
1. Create and push version tag
2. CI runs all quality checks
3. Release workflow creates GitHub release
4. Professional release notes generated

### Manual Release
```bash
# Create tag
git tag -a v1.0.3 -m "Release 1.0.3"
git push origin v1.0.3

# GitHub Actions will handle the rest
```

## Monitoring and Metrics

### Success Indicators
- All jobs pass (green checkmarks)
- No security vulnerabilities
- Code quality metrics maintained
- Release creation successful

### Quality Gates
- Syntax validation must pass
- Code style compliance required
- Static analysis level 5
- Security scan clean
- Composer validation successful

## Best Practices

### Code Quality
- Follow PSR-12 coding standards
- Maintain PHPStan level 5 compliance
- Regular dependency updates
- Security-first approach

### CI/CD
- Fast feedback loops
- Comprehensive testing
- Automated quality gates
- Professional release process

### Documentation
- Keep documentation updated
- Clear error messages
- Troubleshooting guides
- Development setup instructions

## Integration with Contao

### Compatibility
- **PHP Version:** 8.2+ (matches Contao 5.x)
- **Contao Version:** 5.3+
- **Symfony Version:** 6.x

### Bundle Integration
- Proper Contao bundle structure
- Service configuration
- Template integration
- Backend module integration

## Security Considerations

### Vulnerability Scanning
- Automated dependency audit
- Regular security updates
- CVE monitoring
- Secure coding practices

### Access Control
- GitHub repository permissions
- CI/CD secrets management
- Release access control
- Code review requirements

## Future Enhancements

### Planned Improvements
- Unit test integration
- Coverage reporting
- Performance benchmarking
- Automated dependency updates

### Scalability
- Parallel job execution
- Caching optimization
- Resource utilization
- Build time reduction

## Support and Maintenance

### Documentation
- [Local Testing Commands](local-testing-commands.md)
- [CI/CD Quick Reference](ci-cd-quick-reference.md)
- [Troubleshooting Guide](troubleshooting.md)

### Resources
- [Contao Documentation](https://docs.contao.org/)
- [ECS Documentation](https://github.com/symplify/easy-coding-standard)
- [PHPStan Documentation](https://phpstan.org/)
- [Composer Documentation](https://getcomposer.org/doc/)

## Conclusion

This CI/CD pipeline provides comprehensive quality assurance for the Contao OpenAI Assistant bundle, ensuring reliable, secure, and maintainable code through automated testing and deployment processes.

---

*Version: 1.0* 