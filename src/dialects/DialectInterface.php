<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use tommyknocker\pdodb\dialects\roles\DialectAdminInterface;
use tommyknocker\pdodb\dialects\roles\DialectCatalogInterface;
use tommyknocker\pdodb\dialects\roles\DialectConnectionInterface;
use tommyknocker\pdodb\dialects\roles\DialectDdlInterface;
use tommyknocker\pdodb\dialects\roles\DialectDmlInterface;
use tommyknocker\pdodb\dialects\roles\DialectDumpInterface;
use tommyknocker\pdodb\dialects\roles\DialectJsonInterface;
use tommyknocker\pdodb\dialects\roles\DialectLoaderInterface;
use tommyknocker\pdodb\dialects\roles\DialectMcpInterface;
use tommyknocker\pdodb\dialects\roles\DialectMessagingInterface;
use tommyknocker\pdodb\dialects\roles\DialectModelInterface;
use tommyknocker\pdodb\dialects\roles\DialectMonitoringInterface;
use tommyknocker\pdodb\dialects\roles\DialectPlanningInterface;
use tommyknocker\pdodb\dialects\roles\DialectQuotingInterface;
use tommyknocker\pdodb\dialects\roles\DialectSqlFunctionsInterface;
use tommyknocker\pdodb\dialects\roles\DialectTransactionInterface;

/**
 * Everything a SQL dialect provides, assembled from role interfaces (see the `roles` directory).
 *
 * Code that needs one aspect of a dialect, for example the outbox SQL or the quoting rules, can depend on the
 * matching role interface; a dialect implements this interface as a whole, normally by extending DialectAbstract.
 */
interface DialectInterface extends
    DialectConnectionInterface,
    DialectQuotingInterface,
    DialectDmlInterface,
    DialectJsonInterface,
    DialectSqlFunctionsInterface,
    DialectPlanningInterface,
    DialectTransactionInterface,
    DialectCatalogInterface,
    DialectModelInterface,
    DialectMcpInterface,
    DialectLoaderInterface,
    DialectDdlInterface,
    DialectAdminInterface,
    DialectDumpInterface,
    DialectMonitoringInterface,
    DialectMessagingInterface
{
}
