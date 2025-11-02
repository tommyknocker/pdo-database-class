#!/bin/bash
set -e

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo "================================================"
echo "Complete Code Quality Check"
echo "================================================"
echo ""

# Check PHP CS Fixer
echo -e "${CYAN}1. Checking code style with PHP CS Fixer...${NC}"
if composer pdodb:cs-check > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Code style is compliant with PSR-12${NC}"
else
    echo -e "${RED}✗ Code style issues found${NC}"
    echo "Run 'composer pdodb:cs-fix' to fix them"
    exit 1
fi

# Check PHPStan
echo -e "${CYAN}2. Running static analysis with PHPStan...${NC}"
if composer pdodb:phpstan > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Static analysis passed${NC}"
else
    echo -e "${RED}✗ Static analysis issues found${NC}"
    composer pdodb:phpstan
    exit 1
fi

# Run tests
echo -e "${CYAN}3. Running unit tests...${NC}"
if composer pdodb:test > /dev/null 2>&1; then
    echo -e "${GREEN}✓ All unit tests passed${NC}"
else
    echo -e "${RED}✗ Unit tests failed${NC}"
    composer pdodb:test
    exit 1
fi

# Run example tests
echo -e "${CYAN}4. Testing examples on all databases...${NC}"
if bash scripts/test-examples.sh > /dev/null 2>&1; then
    echo -e "${GREEN}✓ All examples passed${NC}"
else
    echo -e "${RED}✗ Example tests failed${NC}"
    bash scripts/test-examples.sh
    exit 1
fi

echo ""
echo "================================================"
echo -e "${GREEN}🎉 All quality checks passed!${NC}"
echo "================================================"
echo ""
echo "Your code is ready for:"
echo "• ✅ PSR-12 compliance"
echo "• ✅ Static analysis (PHPStan level 8)"
echo "• ✅ Unit tests (432 tests)"
echo "• ✅ Example compatibility (all databases)"
echo ""
echo "Run 'composer pdodb:check-all' to repeat this check anytime."
