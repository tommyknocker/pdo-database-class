#!/bin/bash
set -e

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================="
echo "Testing PDOdb Examples"
echo "========================================="
echo ""

# Counter
TOTAL=0
PASSED=0
FAILED=0

# Find all example PHP files
for file in examples/*/*.php; do
    TOTAL=$((TOTAL + 1))
    filename=$(basename "$file")
    category=$(basename $(dirname "$file"))

    echo -n "[$category] $filename ... "

    if timeout 5 php "$file" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ PASSED${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}✗ FAILED${NC}"
        FAILED=$((FAILED + 1))

        # Show error for failed tests
        if [ "$1" == "--verbose" ] || [ "$1" == "-v" ]; then
            echo -e "${YELLOW}Error output:${NC}"
            php "$file" 2>&1 | tail -5
            echo ""
        fi
    fi
done

echo ""
echo "========================================="
echo "Results: $PASSED/$TOTAL passed"
if [ $FAILED -gt 0 ]; then
    echo -e "${RED}$FAILED failed${NC}"
    echo ""
    echo "Run with --verbose to see error details"
    exit 1
else
    echo -e "${GREEN}All examples passed!${NC}"
    exit 0
fi