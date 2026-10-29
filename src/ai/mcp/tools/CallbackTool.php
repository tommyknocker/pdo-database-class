<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use Closure;

/** Adapter for deterministic tools; independent of AI provider configuration. */
class CallbackTool implements McpToolInterface
{
    /**
     * @param array<string, mixed> $schema
     * @param Closure(array<string, mixed>): array<string, mixed> $callback
     */
    public function __construct(
        protected string $name,
        protected string $description,
        protected array $schema,
        protected Closure $callback
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return $this->schema;
    }

    public function execute(array $arguments): string|array
    {
        return ($this->callback)($arguments);
    }
}
