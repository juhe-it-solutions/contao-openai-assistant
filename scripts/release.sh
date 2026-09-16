#!/usr/bin/env bash

# Release script for Contao OpenAI Assistant
# Usage: ./scripts/release.sh [--check] <version>
# Example: ./scripts/release.sh --check 2.2.3

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
    echo "Example: $0 --check 2.2.3"
    exit 1
fi

VERSION="$1"
TAG="v$VERSION"

if [[ ! "$VERSION" =~ ^[23]\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    fail "Only 2.x and 3.x semantic versions can be released by this script."
fi

case "$VERSION" in
    2.*)
        RELEASE_BRANCH='2.x'
        PHP_SERIES='8.2'
        CONTAO_CONSTRAINT='5.3.*'
        ;;
    3.*)
        RELEASE_BRANCH='main'
        PHP_SERIES='8.4'
        CONTAO_CONSTRAINT='^6.0'
        ;;
esac

PROJECT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$PROJECT_DIR"

echo -e "${BLUE}Preparing release $VERSION ($RELEASE_BRANCH lane)...${NC}"

CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "$RELEASE_BRANCH" ]; then
    fail "Release $VERSION must be prepared from the $RELEASE_BRANCH branch."
fi

if [ -n "$(git status --porcelain)" ]; then
    fail "Working directory is not clean. Commit or stash the changes first."
fi

echo -e "${BLUE}Checking origin/$RELEASE_BRANCH and existing tags...${NC}"
if ! git fetch --quiet origin "$RELEASE_BRANCH" --tags; then
    fail "Could not fetch origin/$RELEASE_BRANCH and tags."
fi

if [ "$(git rev-parse HEAD)" != "$(git rev-parse "origin/$RELEASE_BRANCH")" ]; then
    fail "Local $RELEASE_BRANCH must exactly match origin/$RELEASE_BRANCH before releasing."
fi

if git rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null; then
    fail "Tag $TAG already exists locally."
fi

if git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null 2>&1; then
    fail "Tag $TAG already exists on origin."
fi

echo -e "${BLUE}Checking CHANGELOG.md...${NC}"
CHANGELOG_HEADING="## [$VERSION]"
RELEASE_DATE=$(date +%Y-%m-%d)
if ! grep -Fqx "## [$VERSION] - $RELEASE_DATE" CHANGELOG.md; then
    if [ "$CHECK_ONLY" = true ] && grep -Fqx "## [Unreleased] - $VERSION" CHANGELOG.md; then
        CHANGELOG_HEADING="## [Unreleased] - $VERSION"
        echo -e "${YELLOW}Check-only mode accepts the unreleased $VERSION section; date it before tagging.${NC}"
    else
        fail "CHANGELOG.md needs the heading '## [$VERSION] - $RELEASE_DATE' before release."
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

CURRENT_PHP_SERIES=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
if [ "$CURRENT_PHP_SERIES" != "$PHP_SERIES" ]; then
    fail "The $VERSION release baseline requires PHP $PHP_SERIES; current PHP is $CURRENT_PHP_SERIES."
fi

COMPOSER_VERSION=$(composer --no-ansi --version | awk '/^Composer version / { print $3; exit }')
if ! php -r 'exit(version_compare($argv[1], "2.10.2", ">=") ? 0 : 1);' "$COMPOSER_VERSION"; then
    fail "Composer 2.10.2 or newer is required; current version is $COMPOSER_VERSION."
fi

echo -e "${BLUE}Resolving the release baseline (PHP $PHP_SERIES / Contao $CONTAO_CONSTRAINT)...${NC}"
if ! composer update --prefer-dist --no-progress --no-interaction --with "contao/core-bundle:$CONTAO_CONSTRAINT"; then
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
