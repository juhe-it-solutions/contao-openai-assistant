# Troubleshooting Guide

Common issues and solutions for the Contao OpenAI Assistant CI/CD pipeline.

## Quick Diagnosis

### Check Environment
```bash
# PHP version and extensions
php --version
php -m

# Composer version
composer --version

# Available tools
vendor/bin/ecs --version
vendor/bin/phpstan --version
```

### Run Basic Tests
```bash
# Quick validation
composer validate
find src/ -name "*.php" -exec php -l {} \;
vendor/bin/ecs check
vendor/bin/phpstan analyse src/ --level=5
```

## Common Issues

### 1. Composer Validation Failures

#### Problem
```bash
composer validate
# Error: The lock file is not up to date with the latest changes in composer.json
```

#### Solution
```bash
# Update lock file
composer update

# Validate again
composer validate
```

#### Prevention
- Always run `composer update` after changing `composer.json`
- Commit both `composer.json` and `composer.lock`
- Use `composer install` for CI, `composer update` for development

### 2. PHP Syntax Errors

#### Problem
```bash
find src/ -name "*.php" -exec php -l {} \;
# Parse error: syntax error, unexpected '}' in /path/to/file.php on line 123
```

#### Solution
1. **Check the specific file mentioned**
2. **Look for common syntax issues:**
   - Missing semicolons
   - Unclosed brackets/braces
   - Invalid PHP 8.2 syntax
   - Missing type hints

#### Example Fix
```php
// Before (error)
public function processData($data) {
    return $data;
}

// After (fixed)
public function processData(array $data): array {
    return $data;
}
```

### 3. ECS Code Style Issues

#### Problem
```bash
vendor/bin/ecs check
# Found 15 errors in 3 files
```

#### Solution
```bash
# Auto-fix most issues
vendor/bin/ecs check --fix

# Check remaining issues
vendor/bin/ecs check
```

#### Common ECS Issues
- **Missing type hints**: Add return types and parameter types
- **Spacing issues**: Fix indentation and spacing
- **Import ordering**: Organize use statements
- **Line length**: Break long lines

#### Manual Fix Example
```php
// Before
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

// After (alphabetical order)
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
```

### 4. PHPStan Static Analysis Errors

#### Problem
```bash
vendor/bin/phpstan analyse src/ --level=5
# 10/10 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
# 
#  ------ -------------------------------------------------------------------------
#  Line   src/Controller/ApiController.php
#  ------ -------------------------------------------------------------------------
#  45     Call to an undefined method getRequest() on an object of type
#         Symfony\Component\HttpFoundation\Request
```

#### Solution
1. **Check the specific error**
2. **Add proper type hints**
3. **Use correct method names**
4. **Handle nullable types**

#### Example Fix
```php
// Before (PHPStan error)
public function handleRequest(Request $request): Response {
    $data = $request->getRequest(); // Wrong method
    return new Response($data);
}

// After (fixed)
public function handleRequest(Request $request): Response {
    $data = $request->getContent(); // Correct method
    return new Response($data);
}
```

### 5. Security Check Failures

#### Problem
```bash
composer audit
# Found 1 security vulnerability in 1 package
```

#### Solution
```bash
# Check specific vulnerabilities
composer audit --format=json

# Update vulnerable package
composer update package-name

# Or update all packages
composer update
```

#### Common Security Issues
- **Outdated packages**: Update to latest versions
- **Known CVEs**: Check security advisories
- **Transitive dependencies**: Update parent packages

### 6. Missing PHP Extensions

#### Problem
```bash
composer install
# The requested PHP extension gd is missing from your system
```

#### Solution
```bash
# Install required extensions (Ubuntu/Debian)
sudo apt-get update
sudo apt-get install php8.2-gd php8.2-xml php8.2-mbstring php8.2-curl

# Or for other systems, install the specific extension
```

#### Required Extensions
- `mbstring` - Multibyte string handling
- `xml` - XML processing
- `ctype` - Character type checking
- `iconv` - Character encoding conversion
- `intl` - Internationalization
- `curl` - HTTP requests
- `json` - JSON processing
- `dom` - DOM manipulation
- `gd` - Image processing

### 7. CI/CD Workflow Failures

#### Problem
GitHub Actions workflow fails with unclear error messages.

#### Solution
1. **Check workflow logs** in GitHub Actions tab
2. **Identify the failing job**
3. **Look for specific error messages**
4. **Run the same commands locally**

#### Common CI Issues
- **Cache problems**: Clear GitHub Actions cache
- **Version mismatches**: Ensure same PHP version locally
- **Permission issues**: Check repository permissions
- **Timeout issues**: Optimize workflow performance

### 8. Release Creation Failures

#### Problem
Release workflow runs but no GitHub release is created.

#### Solution
1. **Check all quality checks pass**
2. **Verify tag format**: `v1.0.3` (not `1.0.3`)
3. **Check GitHub token permissions**
4. **Review workflow logs for specific errors**

#### Debugging Steps
```bash
# Check tag format
git tag -l

# Verify tag push
git push origin v1.0.3

# Check GitHub Actions logs
# Go to Actions tab → Release workflow → View logs
```

### 9. Dependency Conflicts

#### Problem
```bash
composer install
# Your requirements could not be resolved to an installable set of packages
```

#### Solution
```bash
# Update all dependencies
composer update --with-all-dependencies

# Or resolve conflicts manually
composer why package-name
composer update package-name
```

#### Common Conflicts
- **PHP version requirements**: Ensure compatible PHP version
- **Symfony version conflicts**: Update to compatible versions
- **Transitive dependencies**: Update parent packages

### 10. Performance Issues

#### Problem
CI/CD pipeline takes too long to run.

#### Solution
1. **Optimize caching**:
   ```yaml
   - uses: actions/cache@v3
     with:
       path: ~/.composer/cache
       key: ${{ runner.os }}-php-8.2-composer-${{ hashFiles('**/composer.lock') }}
   ```

2. **Parallel execution**: Run independent jobs in parallel
3. **Reduce dependencies**: Remove unused packages
4. **Optimize commands**: Use `--no-progress` flags

## Debugging Commands

### Environment Check
```bash
# System information
php --version
php -m
composer --version
git --version

# Available tools
ls -la vendor/bin/
```

### Tool Testing
```bash
# Test ECS
vendor/bin/ecs check --help

# Test PHPStan
vendor/bin/phpstan --help

# Test Composer
composer --help
```

### Cache Clearing
```bash
# Clear all caches
rm -rf .ecs_cache/ .phpstan.cache/ .phpunit.result.cache .phpunit.cache
composer clear-cache

# Reinstall dependencies
rm -rf vendor/
composer install
```

## Prevention Strategies

### Before Pushing
1. **Run local tests**:
   ```bash
   composer validate
   find src/ -name "*.php" -exec php -l {} \;
   vendor/bin/ecs check
   vendor/bin/phpstan analyse src/ --level=5
   ```

2. **Check for security issues**:
   ```bash
   composer audit
   ```

3. **Validate composer.json**:
   ```bash
   composer validate
   ```

### Regular Maintenance
1. **Update dependencies monthly**:
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

## Getting Help

### Self-Service
1. **Check this troubleshooting guide**
2. **Review CI/CD documentation**
3. **Search GitHub issues**
4. **Run debugging commands**

### External Resources
- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [ECS Documentation](https://github.com/symplify/easy-coding-standard)
- [PHPStan Documentation](https://phpstan.org/)
- [Composer Documentation](https://getcomposer.org/doc/)

### Creating Issues
When creating a GitHub issue, include:
1. **Error message** (exact text)
2. **Environment details** (PHP version, OS)
3. **Steps to reproduce**
4. **Expected vs actual behavior**
5. **Relevant logs**

---

*Version: 1.0* 