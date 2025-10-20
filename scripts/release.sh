#!/bin/bash
set -e

# Parse arguments
COMMAND=$1
VERSION=$2

# Handle command variations
if [ "$COMMAND" == "delete" ] || [ "$COMMAND" == "remove" ] || [ "$COMMAND" == "rollback" ]; then
    ACTION="delete"
    if [ -z "$VERSION" ]; then
        echo "Usage: ./scripts/release.sh delete <version>"
        echo "Example: ./scripts/release.sh delete 2.5.0"
        exit 1
    fi
elif [ -n "$COMMAND" ] && [ -z "$VERSION" ]; then
    # Single argument - assume it's version for creation
    ACTION="create"
    VERSION=$COMMAND
else
    echo "Usage:"
    echo "  Create release:  ./scripts/release.sh <version>"
    echo "  Delete release:  ./scripts/release.sh delete <version>"
    echo ""
    echo "Examples:"
    echo "  ./scripts/release.sh 2.5.0"
    echo "  ./scripts/release.sh delete 2.5.0"
    exit 1
fi

TAG="v${VERSION}"

# ========================================
# DELETE RELEASE
# ========================================
if [ "$ACTION" == "delete" ]; then
    echo "========================================="
    echo "🗑️  Deleting release ${TAG}..."
    echo "========================================="
    echo ""
    
    # Confirm deletion
    echo "⚠️  This will delete:"
    echo "   - Local tag: ${TAG}"
    echo "   - Remote tag: ${TAG}"
    echo "   - You'll need to manually delete the GitHub Release"
    echo ""
    read -p "Are you sure? (yes/no): " CONFIRM
    
    if [ "$CONFIRM" != "yes" ]; then
        echo "❌ Deletion cancelled"
        exit 1
    fi
    
    echo ""
    echo "🏷️  Deleting local tag..."
    git tag -d "${TAG}" 2>/dev/null && echo "✅ Local tag deleted" || echo "  (tag doesn't exist locally)"
    
    echo ""
    echo "🏷️  Deleting remote tag..."
    git push origin ":refs/tags/${TAG}" 2>/dev/null && echo "✅ Remote tag deleted" || echo "  (tag doesn't exist on remote)"
    
    echo ""
    echo "========================================="
    echo "✅ Tag ${TAG} deleted successfully!"
    echo "========================================="
    echo ""
    echo "📦 Don't forget to manually delete the GitHub Release at:"
    echo "   https://github.com/tommyknocker/pdo-database-class/releases"
    echo ""
    exit 0
fi

# ========================================
# CREATE RELEASE
# ========================================

echo "========================================="
echo "📋 Preparing release ${TAG}..."
echo "========================================="
echo ""

# Check for uncommitted changes
echo "🔍 Checking git status..."
if [ -n "$(git status --porcelain)" ]; then
    echo "❌ You have uncommitted changes. Commit or stash them first."
    exit 1
fi
echo "✅ No uncommitted changes"

# Check current branch is master
echo ""
echo "🔍 Checking current branch..."
BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$BRANCH" != "master" ]; then
    echo "❌ You must be on master branch. Current: $BRANCH"
    exit 1
fi
echo "✅ On master branch"
echo ""

# Check for Russian characters in PHP files
echo "🔍 Checking for Russian characters in PHP files..."
RUSSIAN_FILES=$(find src tests examples -name "*.php" -type f -exec grep -l '[А-Яа-яЁё]' {} \; 2>/dev/null || true)

if [ -n "$RUSSIAN_FILES" ]; then
    echo "❌ Found Russian characters in PHP files:"
    echo "$RUSSIAN_FILES" | while read file; do
        echo "   - $file"
        grep -n '[А-Яа-яЁё]' "$file" | head -3 | sed 's/^/     Line /'
    done
    echo ""
    echo "Please remove all Russian text from code and comments."
    echo "Use English for all code, comments, and documentation."
    exit 1
fi
echo "✅ No Russian characters found in PHP files"
echo ""

# Run all tests (including all dialects)
echo "🧪 Running all tests (MySQL, PostgreSQL, SQLite)..."
ALL_TESTS=1 ./vendor/bin/phpunit

if [ $? -ne 0 ]; then
    echo "❌ Tests failed. Fix them before releasing."
    exit 1
fi
echo "✅ All tests passed!"

# Verify all examples work
echo ""
echo "📝 Verifying all examples..."
./scripts/test-examples.sh

if [ $? -ne 0 ]; then
    echo "❌ Some examples failed. Fix them before releasing."
    exit 1
fi
echo "✅ All examples work correctly!"

# Update version in composer.json (optional)
echo ""
echo "📝 Updating composer.json..."
sed -i "s/\"version\": \".*\"/\"version\": \"${VERSION}\"/" composer.json

# Commit version bump
git add composer.json
git commit -m "chore: bump version to ${VERSION}" 2>/dev/null || echo "  (version already up to date)"

# Create tag
echo ""
echo "🏷️  Creating tag ${TAG}..."
# Delete existing tag if present
git tag -d "${TAG}" 2>/dev/null || true
git push origin ":refs/tags/${TAG}" 2>/dev/null || true

git tag -a "${TAG}" -m "Release ${TAG}"

# Push to remote
echo ""
echo "📤 Pushing to origin..."
git push origin master
git push origin "${TAG}"

echo ""
echo "========================================="
echo "✅ Release ${TAG} created successfully!"
echo "========================================="
echo ""
echo "📦 Next steps:"
echo "   1. Go to: https://github.com/tommyknocker/pdo-database-class/releases/new?tag=${TAG}"
echo "   2. Set title: 'v${VERSION}'"
echo "   3. Paste the release notes from CHANGELOG.md"
echo "   4. Publish release"
echo ""
