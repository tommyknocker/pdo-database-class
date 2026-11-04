<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use PDO;

/**
 * Per-connection memory for table metadata that an INSERT needs to report its key (primary key, auto-increment, triggers).
 *
 * Scope is the dialect instance, which belongs to one connection, and the PDO handle it holds: a new handle after a
 * reconnect starts empty. {@see forgetMetadata()} is called by the connection on DDL, GRANT/REVOKE, USE and rollback.
 * A change made by another session is not seen until one of those happens here.
 */
trait MetadataMemoTrait
{
    /** @var array<string, mixed> */
    protected array $metadataMemo = [];

    protected ?PDO $metadataMemoHandle = null;

    public function forgetMetadata(): void
    {
        $this->metadataMemo = [];
    }

    /**
     * The remembered value for a key, loading it on the first request. A null result is remembered too.
     *
     * @template T
     *
     * @param callable(): T $load
     *
     * @return T
     */
    protected function remembered(string $key, callable $load): mixed
    {
        if ($this->metadataMemoHandle !== $this->pdo) {
            $this->metadataMemo = [];
            $this->metadataMemoHandle = $this->pdo;
        }
        if (!array_key_exists($key, $this->metadataMemo)) {
            $this->metadataMemo[$key] = $load();
        }

        return $this->metadataMemo[$key];
    }
}
