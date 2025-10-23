<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;

/**
 * Resource exhaustion exceptions.
 *
 * Thrown when database resources are exhausted,
 * such as too many connections, memory limits, etc.
 */
class ResourceException extends DatabaseException
{
    protected ?string $resourceType = null;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = '',
        int|string $code = 0,
        ?PDOException $previous = null,
        string $driver = 'unknown',
        ?string $query = null,
        array $context = [],
        ?string $resourceType = null
    ) {
        parent::__construct($message, $code, $previous, $driver, $query, $context);

        $this->resourceType = $resourceType;
    }

    public function getCategory(): string
    {
        return 'resource';
    }

    public function isRetryable(): bool
    {
        // Resource errors may be retryable after a delay
        return true;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function getDescription(): string
    {
        $description = parent::getDescription();

        if ($this->resourceType) {
            $description .= " (Resource: {$this->resourceType})";
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['resource_type'] = $this->resourceType;

        return $data;
    }
}
