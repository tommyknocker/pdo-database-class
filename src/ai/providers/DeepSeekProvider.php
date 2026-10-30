<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

/** DeepSeek uses the shared OpenAI-compatible Chat Completions transport. */
class DeepSeekProvider extends OpenAiProvider
{
    public function getProviderName(): string
    {
        return 'deepseek';
    }
}
