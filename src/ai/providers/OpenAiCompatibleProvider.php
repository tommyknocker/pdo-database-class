<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\AiTransport;

/** OpenAI-compatible Chat Completions endpoints, including OpenRouter. */
class OpenAiCompatibleProvider extends OpenAiProvider
{
    public function __construct(?AiConfig $config = null, protected string $providerName = 'openai-compatible', ?AiTransport $transport = null)
    {
        parent::__construct($config, $transport);
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }
}
