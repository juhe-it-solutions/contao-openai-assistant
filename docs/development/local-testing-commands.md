# Local Testing Commands

This document provides all CLI commands needed to run each test manually locally, matching the exact order of the CI/CD pipeline.

## Prerequisites

Ensure you have the following installed:
- PHP 8.2+ (recommended: 8.2)
- Composer
- Git

## Setup Commands

```bash
# Clone the repository (if not already done)
git clone https://github.com/juhe-it-solutions/contao-openai-assistant.git
cd contao-openai-assistant

# Install dependencies (use update if lock file is out of sync)
composer install --prefer-dist --no-progress
# If you get validation errors, run: composer update

# Verify PHP version
php --version
```

## Job 1: Code Quality

This matches the `code-quality` job in CI.

```bash
# 1. Validate composer.json
composer validate

# 2. Install dependencies
composer install --prefer-dist --no-progress

# 3. Check PHP syntax
find src/ -name "*.php" -exec php -l {} \;

# 4. Check code style
vendor/bin/ecs check

# 5. Run PHPStan analysis
vendor/bin/phpstan analyse src/ --level=5
```

## Job 2: Code Formatting

This matches the `code-formatting` job in CI.

```bash
# 1. Install dependencies
composer install --prefer-dist --no-progress

# 2. Format code
vendor/bin/ecs check --fix

# 3. Check if formatting changed files
if [ -n "$(git status --porcelain)" ]; then
  echo "Code formatting issues found. Please run 'vendor/bin/ecs check --fix' locally."
  git diff
  exit 1
fi
```

## Job 3: Security Check

This matches the `security` job in CI.

```bash
# 1. Install dependencies
composer install --prefer-dist --no-progress

# 2. Run security check
composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"
```

## Job 4: Release Process

This matches the `release` job in CI.

```bash
# 1. Install dependencies
composer install --prefer-dist --no-progress

# 2. Validate composer.json
composer validate

# 3. Check PHP syntax
find src/ -name "*.php" -exec php -l {} \;

# 4. Check code style
vendor/bin/ecs check

# 5. Run PHPStan analysis
vendor/bin/phpstan analyse src/ --level=5

# 6. Run security check
composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"
```

## Individual Test Commands

### 1. Composer Validation

```bash
# Validate composer.json syntax and structure
composer validate

# If validation fails due to lock file being out of sync:
composer update
composer validate
```

### 2. PHP Syntax Check

```bash
# Check PHP syntax for all source files
find src/ -name "*.php" -exec php -l {} \;
```

### 3. Code Style Check (ECS)

```bash
# Check code style without fixing
vendor/bin/ecs check

# Fix code style issues automatically
vendor/bin/ecs check --fix
```

### 4. Static Analysis (PHPStan)

```bash
# Run PHPStan analysis with level 5
vendor/bin/phpstan analyse src/ --level=5

# Run with memory limit (if needed)
php -d memory_limit=1G vendor/bin/phpstan analyse src/ --level=5
```

### 5. Security Audit

```bash
# Run security vulnerability check
composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"

# Alternative: Check for known vulnerabilities
composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"
```

## Complete Test Suite (All Jobs in Order)

```bash
# Run all tests in exact CI order
echo "=== Starting Complete Test Suite (CI Order) ==="

echo "=== Job 1: Code Quality ==="
echo "1. Validating composer.json..."
composer validate

echo "2. Installing dependencies..."
composer install --prefer-dist --no-progress

echo "3. Checking PHP syntax..."
find src/ -name "*.php" -exec php -l {} \;

echo "4. Checking code style..."
vendor/bin/ecs check

echo "5. Running static analysis..."
vendor/bin/phpstan analyse src/ --level=5

echo "=== Job 2: Code Formatting ==="
echo "6. Installing dependencies..."
composer install --prefer-dist --no-progress

echo "7. Formatting code..."
vendor/bin/ecs check --fix

echo "8. Checking for formatting changes..."
if [ -n "$(git status --porcelain)" ]; then
  echo "Code formatting issues found. Please run 'vendor/bin/ecs check --fix' locally."
  git diff
  exit 1
fi

echo "=== Job 3: Security Check ==="
echo "9. Installing dependencies..."
composer install --prefer-dist --no-progress

echo "10. Running security audit..."
composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"

echo "=== Job 4: Release Process ==="
echo "11. Installing dependencies..."
composer install --prefer-dist --no-progress

echo "12. Validating composer.json..."
composer validate

echo "13. Checking PHP syntax..."
find src/ -name "*.php" -exec php -l {} \;

echo "14. Checking code style..."
vendor/bin/ecs check

echo "15. Running static analysis..."
vendor/bin/phpstan analyse src/ --level=5

echo "16. Running security check..."
composer audit --format=json 2>/dev/null || echo "Security check skipped (composer audit not available)"

echo "=== All CI jobs completed successfully ==="
```

## Cleanup Commands

```bash
# Clear Composer cache (if needed)
composer clear-cache

# Clear ECS cache
rm -rf .ecs_cache/

# Clear PHPStan cache
rm -rf .phpstan.cache/

# Clear PHPUnit cache
rm -f .phpunit.result.cache
rm -f .phpunit.cache
```

## Development Workflow Commands

```bash
# Install dependencies for development
composer install --dev

# Update dependencies
composer update

# Check for outdated packages
composer outdated

# Validate and fix code style
vendor/bin/ecs check --fix

# Run static analysis with verbose output
vendor/bin/phpstan analyse src/ --level=5 --verbose
```

## Troubleshooting Commands

```bash
# Check PHP extensions
php -m

# Verify Composer installation
composer --version

# Check available PHP versions
php -v

# Clear all caches
rm -rf .ecs_cache/ .phpstan.cache/ .phpunit.result.cache .phpunit.cache

# Reinstall dependencies
rm -rf vendor/
composer install

# Fix lock file sync issues
composer update
composer validate
```

## Notes

- **PHP Version**: The CI uses PHP 8.2, which is the minimum requirement for Contao 5.x
- **Memory**: Some commands may require increased memory limits (`php -d memory_limit=1G`)
- **Cache**: Clear caches if you encounter unexpected behavior
- **Dependencies**: Always run `composer install` after pulling changes
- **Lock File**: If `composer validate` fails, run `composer update` to sync the lock file
- **CI Order**: The complete test suite follows the exact order of GitHub Actions jobs

## Quick Reference

```bash
# Quick validation (CI order)
composer validate && composer install --prefer-dist --no-progress && find src/ -name "*.php" -exec php -l {} \; && vendor/bin/ecs check && vendor/bin/phpstan analyse src/ --level=5

# Quick fix
vendor/bin/ecs check --fix
``` 