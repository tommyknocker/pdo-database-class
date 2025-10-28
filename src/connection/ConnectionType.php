<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

/**
 * Connection type enumeration.
 *
 * Defines whether a connection is used for read or write operations.
 */
enum ConnectionType: string
{
    case READ = 'read';
    case WRITE = 'write';
}
