<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\config\ConfigOptimizerStrategy;
use tommyknocker\pdodb\dialects\roles\DialectMonitoringInterface;
use tommyknocker\pdodb\PdoDb;

/**
 * Default implementation of the server monitoring part of the dialect ({@see DialectMonitoringInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait MonitoringDefaults
{
    /**
     * {@inheritDoc}
     */
    public function getActiveConnections(PdoDb $db): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [
            'connections' => [],
            'summary' => [
                'current' => 0,
                'max' => 0,
                'usage_percent' => 0,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getSlowQueries(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getServerMetrics(PdoDb $db): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function killQuery(PdoDb $db, int|string $processId): bool
    {
        // Default implementation returns false
        // Dialects should override this method
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function configOptimizerStrategy(): ?ConfigOptimizerStrategy
    {
        return null;
    }
}
