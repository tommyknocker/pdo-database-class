#!/bin/bash
set -e

VERSION=$1

if [ -z "$VERSION" ]; then
    echo "Usage: ./scripts/release.sh <version>"
    echo "Example: ./scripts/release.sh 2.5.0"
    exit 1
fi

TAG="v${VERSION}"

echo "📋 Preparing release ${TAG}..."

# Проверка что нет uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
    echo "❌ You have uncommitted changes. Commit or stash them first."
    exit 1
fi

# Проверка что на master
BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$BRANCH" != "master" ]; then
    echo "❌ You must be on master branch. Current: $BRANCH"
    exit 1
fi

# Запуск тестов
echo "🧪 Running tests..."
./vendor/bin/phpunit

# Обновление версии в composer.json (опционально)
echo "📝 Updating composer.json..."
sed -i "s/\"version\": \".*\"/\"version\": \"${VERSION}\"/" composer.json

# Коммит
git add composer.json
git commit -m "chore: bump version to ${VERSION}"

# Создание тега
echo "🏷️  Creating tag ${TAG}..."
git tag -a "${TAG}" -m "Release ${TAG}"

# Push
echo "📤 Pushing to origin..."
git push origin master
git push origin "${TAG}"

echo "✅ Release ${TAG} created successfully!"
echo "📦 Don't forget to create GitHub Release at:"
echo "   https://github.com/tommyknocker/pdo-database-class/releases/new?tag=${TAG}"
