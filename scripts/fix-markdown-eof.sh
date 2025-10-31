#!/bin/bash
# Fix markdown files to have exactly one empty line at the end
# Removes all trailing empty lines and adds one

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

find "$PROJECT_ROOT/documentation" -name "*.md" -type f | while read -r file; do
    # Remove all trailing empty lines and add one
    perl -i -pe 'BEGIN{undef $/} s/\n*$/\n/' "$file"
done

echo "Fixed markdown EOF in documentation files"

