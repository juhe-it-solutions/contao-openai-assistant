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
    exit 1
fi

# Check if dependencies are installed
if [ ! -d "vendor" ]; then
    echo -e "${YELLOW}📦 Installing dependencies...${NC}"
    composer install --prefer-dist --no-progress
fi

# Check if tag already exists
if git tag -l | grep -q "^$TAG$"; then
    echo -e "${RED}❌ Error: Tag $TAG already exists${NC}"
    exit 1
fi

# Update CHANGELOG.md
echo -e "${BLUE}📝 Updating CHANGELOG.md...${NC}"
# Note: You'll need to manually add the changelog entry

# Run quality checks (matching CI workflow)
echo -e "${BLUE}🔍 Running quality checks (matching CI workflow)...${NC}"

echo -e "${YELLOW}📦 Validating composer.json...${NC}"
if ! composer validate; then
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

# Check if CHANGELOG.md has been updated
if ! grep -q "## \[$VERSION\]" CHANGELOG.md 2>/dev/null; then
    echo -e "${YELLOW}⚠️  Warning: CHANGELOG.md doesn't contain entry for version $VERSION${NC}"
    echo -e "${YELLOW}💡 Please update CHANGELOG.md before proceeding${NC}"
    read -p "Continue anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${RED}❌ Release cancelled${NC}"
        exit 1
    fi
fi

# Create and push tag
echo -e "${BLUE}🏷️  Creating tag $TAG...${NC}"
git tag -a "$TAG" -m "Release $VERSION"
git push origin "$TAG"

echo -e "${GREEN}🎉 Release $VERSION has been created!${NC}"
echo -e "${BLUE}📦 The GitHub Action will automatically create a release${NC}"
echo -e "${BLUE}🔗 View at: https://github.com/juhe-it-solutions/contao-openai-assistant/releases${NC}"
echo -e "${YELLOW}📋 Don't forget to update CHANGELOG.md with release notes!${NC}" 