#!/bin/bash

# Release script for Contao OpenAI Assistant
# Usage: ./scripts/release.sh <version>
# Example: ./scripts/release.sh 1.0.2

set -e

if [ $# -eq 0 ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 1.0.2"
    exit 1
fi

VERSION=$1
TAG="v$VERSION"

echo "🚀 Preparing release $VERSION..."

# Check if we're on main branch
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    echo "❌ Error: You must be on the main branch to create a release"
    exit 1
fi

# Check if working directory is clean
if [ -n "$(git status --porcelain)" ]; then
    echo "❌ Error: Working directory is not clean. Please commit or stash changes."
    exit 1
fi

# Update CHANGELOG.md
echo "📝 Updating CHANGELOG.md..."
# Note: You'll need to manually add the changelog entry

# Run quality checks
echo "🔍 Running quality checks..."
composer validate --strict
find src/ -name "*.php" -exec php -l {} \;
vendor/bin/ecs check src
vendor/bin/phpstan analyse src/ --level=5
composer audit

echo "✅ All quality checks passed!"

# Create and push tag
echo "🏷️  Creating tag $TAG..."
git tag -a "$TAG" -m "Release $VERSION"
git push origin "$TAG"

echo "🎉 Release $VERSION has been created!"
echo "📦 The GitHub Action will automatically create a release"
echo "🔗 View at: https://github.com/juhe-it-solutions/contao-openai-assistant/releases"
echo "📋 Don't forget to update CHANGELOG.md with release notes!" 