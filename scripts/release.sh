#!/usr/bin/env bash

# Validate or trigger a Contao OpenAI Assistant 3.x release.
# Usage: ./scripts/release.sh [--check] <version>

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

fail() {
    echo -e "${RED}Error: $1${NC}" >&2
    exit 1
}

CHECK_ONLY=false
if [ "${1:-}" = "--check" ]; then
    CHECK_ONLY=true
    shift
fi

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 [--check] <version>"
    echo "Example: $0 --check 3.0.0"
    exit 1
fi

VERSION="$1"
TAG="v$VERSION"

if [[ ! "$VERSION" =~ ^3\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    fail "Version must be a 3.x semantic version without the leading v."
fi

PROJECT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$PROJECT_DIR"

echo -e "${BLUE}Preparing release $VERSION...${NC}"

CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    fail "You must be on the main branch to prepare a 3.x release."
fi

if [ -n "$(git status --porcelain)" ]; then
    fail "Working directory is not clean. Commit or stash the changes first."
fi

echo -e "${BLUE}Checking origin/main and existing tags...${NC}"
if ! git fetch --quiet origin main --tags; then
    fail "Could not fetch origin/main and tags."
fi

if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    fail "Local main must exactly match origin/main before releasing."
fi

if git rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null; then
    fail "Tag $TAG already exists locally."
fi

if git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null 2>&1; then
    fail "Tag $TAG already exists on origin."
fi

echo -e "${BLUE}Checking CHANGELOG.md...${NC}"
CHANGELOG_HEADING="## [$VERSION]"
if ! grep -Fqx "## [$VERSION] - $(date +%Y-%m-%d)" CHANGELOG.md; then
    if [ "$CHECK_ONLY" = true ] && grep -Fqx "## [Unreleased] - $VERSION" CHANGELOG.md; then
        CHANGELOG_HEADING="## [Unreleased] - $VERSION"
        echo -e "${YELLOW}Check-only mode accepts the unreleased $VERSION section; date it before tagging.${NC}"
    else
        fail "CHANGELOG.md needs the heading '## [$VERSION] - $(date +%Y-%m-%d)' before release."
    fi
fi

CHANGELOG_SECTION=$(awk -v heading="$CHANGELOG_HEADING" '
    $0 == heading {
        found = 1
        next
    }
    found && /^## \[/ { exit }
    found { print }
' CHANGELOG.md)

if [ -z "${CHANGELOG_SECTION//[[:space:]]/}" ]; then
    fail "CHANGELOG.md needs non-empty release notes for $VERSION."
fi

echo -e "${BLUE}Verifying the exported release package...${NC}"
if ! bash scripts/check-release-archive.sh HEAD; then
    fail "Release archive contains an unexpected, missing, or potentially sensitive file."
fi

PHP_SERIES=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
if [ "$PHP_SERIES" != "8.4" ]; then
    fail "The 3.x release baseline requires PHP 8.4; current PHP is $PHP_SERIES."
fi

COMPOSER_VERSION=$(composer --no-ansi --version | awk '/^Composer version / { print $3; exit }')
if ! php -r 'exit(version_compare($argv[1], "2.10.2", ">=") ? 0 : 1);' "$COMPOSER_VERSION"; then
    fail "Composer 2.10.2 or newer is required; current version is $COMPOSER_VERSION."
fi

echo -e "${BLUE}Resolving the release baseline (PHP 8.4 / Contao 6.0)...${NC}"
if ! composer update --prefer-dist --no-progress --no-interaction --with 'contao/core-bundle:^6.0'; then
    fail "Dependency resolution failed."
fi

echo -e "${BLUE}Running release checks...${NC}"

if ! composer validate; then
    fail "Composer validation failed."
fi

if ! find src/ tests/ contao/ -name '*.php' -print0 | xargs -0 -r -n1 php -l >/dev/null; then
    fail "PHP syntax check failed."
fi

if ! vendor/bin/ecs check; then
    fail "Code style check failed. Run vendor/bin/ecs check --fix."
fi

if ! php -d memory_limit=1G vendor/bin/phpstan analyse src/ --level=5; then
    fail "Static analysis failed."
fi

if ! vendor/bin/phpunit; then
    fail "PHPUnit failed."
fi

if ! composer audit --abandoned=report; then
    fail "Security audit failed. No release tag was created."
fi

echo -e "${GREEN}All release checks passed.${NC}"

if [ "$CHECK_ONLY" = true ]; then
    echo -e "${GREEN}Check-only mode completed; no tag was created or pushed.${NC}"
    exit 0
fi

echo -e "${YELLOW}Creating and pushing $TAG. This is the release boundary.${NC}"
git tag -a "$TAG" -m "Release $VERSION"

if ! git push origin "$TAG"; then
    fail "Could not push $TAG. The local tag remains; inspect the remote before retrying."
fi

echo -e "${GREEN}Release $VERSION has been triggered.${NC}"
