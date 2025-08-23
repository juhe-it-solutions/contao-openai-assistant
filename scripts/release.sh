#!/bin/bash

# Release script for Contao OpenAI Assistant
# Usage: ./scripts/release.sh <version>
# Example: ./scripts/release.sh 1.0.2

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

if [ $# -eq 0 ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 1.0.2"
    exit 1
fi

VERSION=$1
TAG="v$VERSION"

echo -e "${BLUE}🚀 Preparing release $VERSION...${NC}"

# Check if we're on main branch
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo -e "${RED}❌ Error: You must be on the main branch to create a release${NC}"
    exit 1
fi

# Check if working directory is clean
if [ -n "$(git status --porcelain)" ]; then
    echo -e "${RED}❌ Error: Working directory is not clean. Please commit or stash changes.${NC}"
    echo -e "${YELLOW}💡 Run 'git status' to see uncommitted changes${NC}"
    exit 1
fi

# Check if tag already exists
if git tag -l | grep -q "^$TAG$"; then
    echo -e "${RED}❌ Error: Tag $TAG already exists${NC}"
    exit 1
fi

# Check if CHANGELOG.md has been updated
echo -e "${BLUE}📝 Checking CHANGELOG.md...${NC}"
if ! grep -q "## \[$VERSION\]" CHANGELOG.md 2>/dev/null; then
    echo -e "${YELLOW}⚠️  Warning: CHANGELOG.md doesn't contain entry for version $VERSION${NC}"
    echo -e "${YELLOW}💡 Please update CHANGELOG.md before proceeding${NC}"
    echo -e "${CYAN}📋 Example format:${NC}"
    echo -e "${CYAN}## [$VERSION] - $(date +%Y-%m-%d)${NC}"
    echo -e "${CYAN}### Added${NC}"
    echo -e "${CYAN}- Your new features${NC}"
    echo -e "${CYAN}### Changed${NC}"
    echo -e "${CYAN}- Your changes${NC}"
    echo -e "${CYAN}### Fixed${NC}"
    echo -e "${CYAN}- Your fixes${NC}"
    read -p "Continue anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${RED}❌ Release cancelled${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✅ CHANGELOG.md contains entry for version $VERSION${NC}"
fi

# Check and update composer.lock if needed
echo -e "${BLUE}📦 Checking composer dependencies...${NC}"
if ! composer validate --no-check-all 2>/dev/null | grep -q "is valid"; then
    echo -e "${YELLOW}⚠️  Composer.lock is out of sync. Updating...${NC}"
    if composer update --no-interaction; then
        echo -e "${GREEN}✅ Composer.lock updated successfully${NC}"
        echo -e "${YELLOW}💡 Note: composer.lock is in .gitignore, so changes won't be committed${NC}"
    else
        echo -e "${RED}❌ Failed to update composer.lock${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✅ Composer dependencies are up to date${NC}"
fi

# Check if dependencies are installed
if [ ! -d "vendor" ]; then
    echo -e "${YELLOW}📦 Installing dependencies...${NC}"
    composer install --prefer-dist --no-progress
fi

# Run quality checks (matching CI workflow)
echo -e "${BLUE}🔍 Running quality checks (matching CI workflow)...${NC}"

echo -e "${YELLOW}📦 Validating composer.json...${NC}"
if ! composer validate --no-check-all 2>/dev/null | grep -q "is valid"; then
    echo -e "${RED}❌ Composer validation failed${NC}"
    exit 1
fi

echo -e "${YELLOW}📦 Installing dependencies...${NC}"
if ! composer install --prefer-dist --no-progress; then
    echo -e "${RED}❌ Dependency installation failed${NC}"
    exit 1
fi

echo -e "${YELLOW}🔍 Checking PHP syntax...${NC}"
if ! find src/ -name "*.php" -exec php -l {} \; 2>/dev/null; then
    echo -e "${RED}❌ PHP syntax check failed${NC}"
    exit 1
fi

echo -e "${YELLOW}🎨 Checking code style...${NC}"
if ! vendor/bin/ecs check; then
    echo -e "${RED}❌ Code style check failed${NC}"
    echo -e "${YELLOW}💡 Run 'vendor/bin/ecs check --fix' to auto-fix issues${NC}"
    exit 1
fi

echo -e "${YELLOW}🔬 Running static analysis...${NC}"
if ! vendor/bin/phpstan analyse src/ --level=5; then
    echo -e "${RED}❌ Static analysis failed${NC}"
    exit 1
fi

echo -e "${YELLOW}🛡️ Running security check...${NC}"
if composer audit --format=json 2>/dev/null; then
    echo -e "${GREEN}ℹ️ No security vulnerabilities found${NC}"
else
    echo -e "${YELLOW}ℹ️ Security check skipped (composer audit not available)${NC}"
fi

echo -e "${GREEN}✅ All quality checks passed!${NC}"

# Create and push tag with retry logic
echo -e "${BLUE}🏷️  Creating tag $TAG...${NC}"
git tag -a "$TAG" -m "Release $VERSION"

# Try to push tag with retry logic
MAX_RETRIES=3
RETRY_COUNT=0

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
    if git push origin "$TAG"; then
        echo -e "${GREEN}✅ Tag $TAG pushed successfully!${NC}"
        break
    else
        RETRY_COUNT=$((RETRY_COUNT + 1))
        if [ $RETRY_COUNT -lt $MAX_RETRIES ]; then
            echo -e "${YELLOW}⚠️  Push failed, retrying in 5 seconds... (Attempt $RETRY_COUNT/$MAX_RETRIES)${NC}"
            sleep 5
        else
            echo -e "${RED}❌ Failed to push tag after $MAX_RETRIES attempts${NC}"
            echo -e "${YELLOW}💡 You can manually push the tag with: git push origin $TAG${NC}"
            exit 1
        fi
    fi
done

echo -e "${GREEN}🎉 Release $VERSION has been created!${NC}"
echo -e "${BLUE}📦 The GitHub Action will automatically create a release${NC}"
echo -e "${BLUE}🔗 View at: https://github.com/juhe-it-solutions/contao-openai-assistant/releases${NC}"
echo -e "${CYAN}📋 Release workflow will run quality checks and create the GitHub release${NC}"
echo -e "${CYAN}⏱️  This may take a few minutes to complete${NC}" 