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
echo "Testing PDOdb Examples on All Database Dialects"
echo "================================================"
echo ""

# Check which databases are available
echo "Checking database availability..."
echo ""

MYSQL_AVAILABLE=0
MARIADB_AVAILABLE=0
PGSQL_AVAILABLE=0
MSSQL_AVAILABLE=0
SQLITE_AVAILABLE=1  # SQLite always available

# Check MySQL
if [ -f "examples/config.mysql.php" ]; then
    if php -r "
        \$config = require 'examples/config.mysql.php';
        try {
            \$dsn = \$config['driver'] . ':host=' . \$config['host'] . ';dbname=' . \$config['dbname'];
            new PDO(\$dsn, \$config['username'], \$config['password']);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        MYSQL_AVAILABLE=1
        echo -e "${GREEN}✓ MySQL available${NC}"
    else
        echo -e "${YELLOW}⊘ MySQL not available (config exists but connection failed)${NC}"
    fi
else
    echo -e "${YELLOW}⊘ MySQL config not found (examples/config.mysql.php)${NC}"
fi

# Check MariaDB
if [ -f "examples/config.mariadb.php" ]; then
    if php -r "
        \$config = require 'examples/config.mariadb.php';
        try {
            \$dsn = 'mysql:host=' . \$config['host'] . ';dbname=' . \$config['dbname'];
            if (isset(\$config['port'])) {
                \$dsn .= ';port=' . \$config['port'];
            }
            new PDO(\$dsn, \$config['username'], \$config['password']);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        MARIADB_AVAILABLE=1
        echo -e "${GREEN}✓ MariaDB available${NC}"
    else
        echo -e "${YELLOW}⊘ MariaDB not available (config exists but connection failed)${NC}"
    fi
else
    echo -e "${YELLOW}⊘ MariaDB config not found (examples/config.mariadb.php)${NC}"
fi

# Check PostgreSQL  
if [ -f "examples/config.pgsql.php" ]; then
    if php -r "
        \$config = require 'examples/config.pgsql.php';
        try {
            \$port = \$config['port'] ?? 5432;
            \$dsn = \$config['driver'] . ':host=' . \$config['host'] . ';dbname=' . \$config['dbname'] . ';port=' . \$port;
            new PDO(\$dsn, \$config['username'], \$config['password']);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        PGSQL_AVAILABLE=1
        echo -e "${GREEN}✓ PostgreSQL available${NC}"
    else
        echo -e "${YELLOW}⊘ PostgreSQL not available (config exists but connection failed)${NC}"
    fi
else
    echo -e "${YELLOW}⊘ PostgreSQL config not found (examples/config.pgsql.php)${NC}"
fi

# Check MSSQL
# First check if environment variables are set (CI), then fallback to config file
if [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
    # Use environment variables (CI)
    if php -r "
        \$host = getenv('DB_HOST') ?: 'localhost';
        \$port = getenv('DB_PORT') ?: '1433';
        \$dbname = getenv('DB_NAME') ?: 'testdb';
        \$username = getenv('DB_USER');
        \$password = getenv('DB_PASS');
        try {
            \$dsn = 'sqlsrv:Server=' . \$host . ',' . \$port . ';Database=' . \$dbname . ';TrustServerCertificate=yes';
            new PDO(\$dsn, \$username, \$password);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        MSSQL_AVAILABLE=1
        echo -e "${GREEN}✓ MSSQL available (via environment variables)${NC}"
    else
        echo -e "${YELLOW}⊘ MSSQL not available (environment variables set but connection failed)${NC}"
    fi
elif [ -f "examples/config.mssql.php" ]; then
    if php -r "
        \$config = require 'examples/config.mssql.php';
        try {
            \$port = \$config['port'] ?? 1433;
            \$dsn = 'sqlsrv:Server=' . \$config['host'] . ',' . \$port . ';Database=' . \$config['dbname'];
            if (isset(\$config['trust_server_certificate']) && \$config['trust_server_certificate']) {
                \$dsn .= ';TrustServerCertificate=yes';
            }
            if (isset(\$config['encrypt']) && \$config['encrypt']) {
                \$dsn .= ';Encrypt=yes';
            }
            new PDO(\$dsn, \$config['username'], \$config['password']);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        MSSQL_AVAILABLE=1
        echo -e "${GREEN}✓ MSSQL available${NC}"
    else
        echo -e "${YELLOW}⊘ MSSQL not available (config exists but connection failed)${NC}"
    fi
else
    echo -e "${YELLOW}⊘ MSSQL config not found (examples/config.mssql.php)${NC}"
fi

echo -e "${GREEN}✓ SQLite available (always)${NC}"
echo ""

# Counters
declare -A RESULTS
RESULTS["mysql_total"]=0
RESULTS["mysql_passed"]=0
RESULTS["mysql_failed"]=0
RESULTS["mariadb_total"]=0
RESULTS["mariadb_passed"]=0
RESULTS["mariadb_failed"]=0
RESULTS["pgsql_total"]=0
RESULTS["pgsql_passed"]=0
RESULTS["pgsql_failed"]=0
RESULTS["sqlite_total"]=0
RESULTS["sqlite_passed"]=0
RESULTS["sqlite_failed"]=0
RESULTS["mssql_total"]=0
RESULTS["mssql_passed"]=0
RESULTS["mssql_failed"]=0

echo "================================================"
echo "Running Tests"
echo "================================================"
echo ""

# Find all example PHP files
for file in examples/*/*.php; do
    filename=$(basename "$file")
    category=$(basename $(dirname "$file"))
    
    # Skip config and helper files
    if [[ "$filename" == "config"* ]] || [[ "$filename" == "helpers.php" ]] || [[ "$filename" == "README.md" ]]; then
        continue
    fi
    
    # Test on SQLite (always)
    RESULTS["sqlite_total"]=$((${RESULTS["sqlite_total"]} + 1))
    echo -n -e "${CYAN}[$category/$filename]${NC} on ${BLUE}SQLite${NC} ... "
    
    export PDODB_DRIVER="sqlite"
    if timeout 30 php "$file" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ PASSED${NC}"
        RESULTS["sqlite_passed"]=$((${RESULTS["sqlite_passed"]} + 1))
    else
        echo -e "${RED}✗ FAILED${NC}"
        RESULTS["sqlite_failed"]=$((${RESULTS["sqlite_failed"]} + 1))
        
        if [ "$1" == "--verbose" ] || [ "$1" == "-v" ]; then
            echo -e "${YELLOW}Error output:${NC}"
            php "$file" 2>&1 | tail -10
            echo ""
        fi
    fi
    
    # Test on MySQL if available
    if [ $MYSQL_AVAILABLE -eq 1 ]; then
        RESULTS["mysql_total"]=$((${RESULTS["mysql_total"]} + 1))
        echo -n -e "${CYAN}[$category/$filename]${NC} on ${BLUE}MySQL${NC} ... "
        
        export PDODB_DRIVER="mysql"
        if timeout 30 php "$file" > /dev/null 2>&1; then
            echo -e "${GREEN}✓ PASSED${NC}"
            RESULTS["mysql_passed"]=$((${RESULTS["mysql_passed"]} + 1))
        else
            echo -e "${RED}✗ FAILED${NC}"
            RESULTS["mysql_failed"]=$((${RESULTS["mysql_failed"]} + 1))
            
            if [ "$1" == "--verbose" ] || [ "$1" == "-v" ]; then
                echo -e "${YELLOW}Error output:${NC}"
                php "$file" 2>&1 | tail -10
                echo ""
            fi
        fi
    fi
    
    # Test on MariaDB if available
    if [ $MARIADB_AVAILABLE -eq 1 ]; then
        RESULTS["mariadb_total"]=$((${RESULTS["mariadb_total"]} + 1))
        echo -n -e "${CYAN}[$category/$filename]${NC} on ${BLUE}MariaDB${NC} ... "
        
        export PDODB_DRIVER="mariadb"
        if timeout 30 php "$file" > /dev/null 2>&1; then
            echo -e "${GREEN}✓ PASSED${NC}"
            RESULTS["mariadb_passed"]=$((${RESULTS["mariadb_passed"]} + 1))
        else
            echo -e "${RED}✗ FAILED${NC}"
            RESULTS["mariadb_failed"]=$((${RESULTS["mariadb_failed"]} + 1))
            
            if [ "$1" == "--verbose" ] || [ "$1" == "-v" ]; then
                echo -e "${YELLOW}Error output:${NC}"
                php "$file" 2>&1 | tail -10
                echo ""
            fi
        fi
    fi
    
    # Test on PostgreSQL if available
    if [ $PGSQL_AVAILABLE -eq 1 ]; then
        RESULTS["pgsql_total"]=$((${RESULTS["pgsql_total"]} + 1))
        echo -n -e "${CYAN}[$category/$filename]${NC} on ${BLUE}PostgreSQL${NC} ... "
        
        export PDODB_DRIVER="pgsql"
        if timeout 30 php "$file" > /dev/null 2>&1; then
            echo -e "${GREEN}✓ PASSED${NC}"
            RESULTS["pgsql_passed"]=$((${RESULTS["pgsql_passed"]} + 1))
        else
            echo -e "${RED}✗ FAILED${NC}"
            RESULTS["pgsql_failed"]=$((${RESULTS["pgsql_failed"]} + 1))
            
            if [ "$1" == "--verbose" ] || [ "$1" == "-v" ]; then
                echo -e "${YELLOW}Error output:${NC}"
                php "$file" 2>&1 | tail -10
                echo ""
            fi
        fi
    fi
    
    # Test on MSSQL if available
    if [ $MSSQL_AVAILABLE -eq 1 ]; then
        RESULTS["mssql_total"]=$((${RESULTS["mssql_total"]} + 1))
        echo -n -e "${CYAN}[$category/$filename]${NC} on ${BLUE}MSSQL${NC} ... "
        
        export PDODB_DRIVER="mssql"
        if timeout 30 php "$file" > /dev/null 2>&1; then
            echo -e "${GREEN}✓ PASSED${NC}"
            RESULTS["mssql_passed"]=$((${RESULTS["mssql_passed"]} + 1))
        else
            echo -e "${RED}✗ FAILED${NC}"
            RESULTS["mssql_failed"]=$((${RESULTS["mssql_failed"]} + 1))
            
            if [ "$1" == "--verbose" ] || [ "$1" == "-v" ]; then
                echo -e "${YELLOW}Error output:${NC}"
                php "$file" 2>&1 | tail -10
                echo ""
            fi
        fi
    fi
done

echo ""
echo "================================================"
echo "Results Summary"
echo "================================================"

TOTAL_FAILED=0

if [ ${RESULTS["sqlite_total"]} -gt 0 ]; then
    echo -e "${BLUE}SQLite:${NC} ${GREEN}${RESULTS["sqlite_passed"]}${NC}/${RESULTS["sqlite_total"]} passed"
    if [ ${RESULTS["sqlite_failed"]} -gt 0 ]; then
        echo -e "  Failed: ${RED}${RESULTS["sqlite_failed"]}${NC}"
        TOTAL_FAILED=$((TOTAL_FAILED + ${RESULTS["sqlite_failed"]}))
    fi
fi

if [ ${RESULTS["mysql_total"]} -gt 0 ]; then
    echo -e "${BLUE}MySQL:${NC} ${GREEN}${RESULTS["mysql_passed"]}${NC}/${RESULTS["mysql_total"]} passed"
    if [ ${RESULTS["mysql_failed"]} -gt 0 ]; then
        echo -e "  Failed: ${RED}${RESULTS["mysql_failed"]}${NC}"
        TOTAL_FAILED=$((TOTAL_FAILED + ${RESULTS["mysql_failed"]}))
    fi
fi

if [ ${RESULTS["mariadb_total"]} -gt 0 ]; then
    echo -e "${BLUE}MariaDB:${NC} ${GREEN}${RESULTS["mariadb_passed"]}${NC}/${RESULTS["mariadb_total"]} passed"
    if [ ${RESULTS["mariadb_failed"]} -gt 0 ]; then
        echo -e "  Failed: ${RED}${RESULTS["mariadb_failed"]}${NC}"
        TOTAL_FAILED=$((TOTAL_FAILED + ${RESULTS["mariadb_failed"]}))
    fi
fi

if [ ${RESULTS["pgsql_total"]} -gt 0 ]; then
    echo -e "${BLUE}PostgreSQL:${NC} ${GREEN}${RESULTS["pgsql_passed"]}${NC}/${RESULTS["pgsql_total"]} passed"
    if [ ${RESULTS["pgsql_failed"]} -gt 0 ]; then
        echo -e "  Failed: ${RED}${RESULTS["pgsql_failed"]}${NC}"
        TOTAL_FAILED=$((TOTAL_FAILED + ${RESULTS["pgsql_failed"]}))
    fi
fi

if [ ${RESULTS["mssql_total"]} -gt 0 ]; then
    echo -e "${BLUE}MSSQL:${NC} ${GREEN}${RESULTS["mssql_passed"]}${NC}/${RESULTS["mssql_total"]} passed"
    if [ ${RESULTS["mssql_failed"]} -gt 0 ]; then
        echo -e "  Failed: ${RED}${RESULTS["mssql_failed"]}${NC}"
        TOTAL_FAILED=$((TOTAL_FAILED + ${RESULTS["mssql_failed"]}))
    fi
fi

echo "================================================"

if [ $TOTAL_FAILED -gt 0 ]; then
    echo -e "${RED}Some tests failed!${NC}"
    echo "Run with --verbose to see error details"
    exit 1
else
    echo -e "${GREEN}All available examples passed!${NC}"
    
    # Show tip only if some databases are not configured
    MISSING_DBS=()
    if [ $MYSQL_AVAILABLE -eq 0 ]; then
        MISSING_DBS+=("MySQL (create examples/config.mysql.php)")
    fi
    if [ $MARIADB_AVAILABLE -eq 0 ]; then
        MISSING_DBS+=("MariaDB (create examples/config.mariadb.php)")
    fi
    if [ $PGSQL_AVAILABLE -eq 0 ]; then
        MISSING_DBS+=("PostgreSQL (create examples/config.pgsql.php)")
    fi
    if [ $MSSQL_AVAILABLE -eq 0 ]; then
        MISSING_DBS+=("MSSQL (create examples/config.mssql.php)")
    fi
    
    if [ ${#MISSING_DBS[@]} -gt 0 ]; then
        echo ""
        echo "💡 Tip: Test on more databases by creating config files:"
        for db in "${MISSING_DBS[@]}"; do
            echo "  • $db"
        done
        echo ""
        echo "   Config files included - just update credentials in examples/config.*.php"
    fi
    
    exit 0
fi