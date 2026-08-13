#!/usr/bin/env bash

# Release script for Contao OpenAI Assistant
# Usage: ./scripts/release.sh <version>
# Example: ./scripts/release.sh 2.2.0

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

fail() {
    echo -e "${RED}❌ $1${NC}" >&2
    exit 1
}

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 2.2.0"
    exit 1
fi

VERSION="$1"
TAG="v$VERSION"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    fail "Version must be a semantic version without the leading v (for example 2.2.0)."
fi

PROJECT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$PROJECT_DIR"

echo -e "${BLUE}🚀 Preparing release $VERSION...${NC}"

CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    fail "You must be on the main branch to create a release."
fi

if [ -n "$(git status --porcelain)" ]; then
    fail "Working directory is not clean. Commit or stash the changes first."
fi

echo -e "${BLUE}🔄 Checking origin/main and existing tags...${NC}"
if ! git fetch --quiet origin main --tags; then
    fail "Could not fetch origin/main and tags."
fi

if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    fail "Local main must exactly match origin/main before releasing."
fi

if git rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null; then
    fail "Tag $TAG already exists."
fi

echo -e "${BLUE}📝 Checking CHANGELOG.md...${NC}"
CHANGELOG_SECTION=$(awk -v heading="## [$VERSION]" '
    index($0, heading) == 1 && (length($0) == length(heading) || substr($0, length(heading) + 1, 3) == " - ") {
        found = 1
        next
    }
    found && /^## \[/ { exit }
    found { print }
' CHANGELOG.md)

if [ -z "${CHANGELOG_SECTION//[[:space:]]/}" ]; then
    fail "CHANGELOG.md needs a non-empty [$VERSION] section before release."
fi
echo -e "${GREEN}✅ CHANGELOG.md contains release notes for $VERSION${NC}"

echo -e "${BLUE}📦 Verifying the exported release package...${NC}"
if ! bash scripts/check-release-archive.sh HEAD; then
    fail "Release archive contains an unexpected, missing or potentially sensitive file."
fi

PHP_SERIES=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
if [ "$PHP_SERIES" != "8.2" ]; then
    fail "The main release baseline requires PHP 8.2; current PHP is $PHP_SERIES."
fi

# Composer 2.10.2 fixes the 2026 package-name and bin-path validation advisories.
# Refuse to resolve release dependencies with an older, vulnerable executable.
COMPOSER_VERSION=$(composer --no-ansi --version | awk '/^Composer version / { print $3; exit }')
if ! php -r 'exit(version_compare($argv[1], "2.10.2", ">=") ? 0 : 1);' "$COMPOSER_VERSION"; then
    fail "Composer 2.10.2 or newer is required for the release checks; current version is $COMPOSER_VERSION."
fi

# This intentionally performs a full update. The bundle does not track composer.lock,
# so composer install would otherwise reuse an arbitrary ignored local lock or resolve an
# unsupported Contao line. This matches the GitHub release workflow's 5.3 LTS baseline.
echo -e "${BLUE}📦 Resolving the release dependency baseline (PHP 8.2 / Contao 5.3)...${NC}"
if ! composer update --prefer-dist --no-progress --no-interaction --with 'contao/core-bundle:5.3.*'; then
    fail "Dependency resolution failed."
fi

echo -e "${BLUE}🔍 Running release checks...${NC}"

echo -e "${YELLOW}📦 Validating Composer metadata...${NC}"
if ! composer validate; then
    fail "Composer validation failed."
fi

echo -e "${YELLOW}🔍 Checking PHP syntax...${NC}"
if ! find src/ tests/ contao/ -name '*.php' -print0 | xargs -0 -r -n1 php -l >/dev/null; then
    fail "PHP syntax check failed."
fi

echo -e "${YELLOW}🎨 Checking code style...${NC}"
if ! vendor/bin/ecs check; then
    fail "Code style check failed. Run vendor/bin/ecs check --fix."
fi

echo -e "${YELLOW}🔬 Running static analysis...${NC}"
if ! vendor/bin/phpstan analyse src/ --level=5; then
    fail "Static analysis failed."
fi

echo -e "${YELLOW}🧪 Running PHPUnit...${NC}"
if ! vendor/bin/phpunit; then
    fail "PHPUnit failed."
fi

echo -e "${YELLOW}🛡️ Running security audit...${NC}"
if ! composer audit --abandoned=report; then
    fail "Security audit failed. No release tag was created."
fi

echo -e "${GREEN}✅ All release checks passed.${NC}"

echo -e "${BLUE}🏷️ Creating tag $TAG...${NC}"
git tag -a "$TAG" -m "Release $VERSION"

MAX_RETRIES=3
RETRY_COUNT=0

while [ "$RETRY_COUNT" -lt "$MAX_RETRIES" ]; do
    if git push origin "$TAG"; then
        echo -e "${GREEN}✅ Tag $TAG pushed successfully.${NC}"
        break
    fi

    RETRY_COUNT=$((RETRY_COUNT + 1))
    if [ "$RETRY_COUNT" -ge "$MAX_RETRIES" ]; then
        fail "Could not push $TAG after $MAX_RETRIES attempts. The local tag remains; check the remote before retrying manually."
    fi

    echo -e "${YELLOW}⚠️ Push failed; retrying in 5 seconds ($RETRY_COUNT/$MAX_RETRIES)...${NC}"
    sleep 5
done

echo -e "${GREEN}🎉 Release $VERSION has been triggered.${NC}"
echo -e "${BLUE}The GitHub release workflow will repeat the checks and create the release.${NC}"
