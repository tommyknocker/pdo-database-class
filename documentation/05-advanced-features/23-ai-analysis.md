# AI-Powered Database Analysis

PDOdb provides AI-powered database analysis capabilities, allowing you to get intelligent recommendations for query optimization, schema improvements, and performance tuning using various AI providers (OpenAI, Anthropic, Google, Microsoft, Ollama).

## Table of Contents

- [Overview](#overview)
- [Supported AI Providers](#supported-ai-providers)
- [Configuration](#configuration)
- [Code-Level Integration](#code-level-integration)
- [CLI Commands](#cli-commands)
- [MCP Server](#mcp-server)
- [Examples](#examples)
- [Best Practices](#best-practices)

## Overview

The AI analysis feature combines traditional database analysis (EXPLAIN plans, schema inspection) with AI-powered recommendations to provide comprehensive optimization suggestions. This helps developers:

- **Optimize SQL queries** - Get AI suggestions for improving query performance
- **Improve database schema** - Receive recommendations for indexes, constraints, and table structure
- **Understand query execution** - Combine EXPLAIN analysis with AI explanations
- **Get optimization suggestions** - Receive actionable recommendations based on your database structure

## Supported AI Providers

PDOdb supports multiple AI providers, each with their own strengths:

| Provider | Models | Best For | API Key Required |
|----------|--------|----------|------------------|
| **OpenAI** | gpt-4o-mini, gpt-4, gpt-3.5-turbo | General analysis, fast responses | Yes |
| **Anthropic** | claude-3-5-sonnet, claude-3-opus | Detailed analysis, long context | Yes |
| **Google** | gemini-2.5-flash, gemini-2.5-pro, gemini-2.0-flash-001, gemini-flash-latest, gemini-pro-latest | Multimodal analysis, large context | Yes |
| **Microsoft** | Azure OpenAI models | Enterprise environments | Yes |
| **DeepSeek** | deepseek-chat, deepseek-reasoner | Cost-effective, OpenAI-compatible | Yes |
| **Yandex** | gpt-oss-120b/latest | Russian market, Yandex Cloud | Yes (API key + folder ID) |
| **Ollama** | Any local model (llama2, deepseek-coder, etc.) | Privacy, offline use, no API costs | No |

## Configuration

### Environment Variables

Configure AI providers using environment variables:

```bash
# Default provider
export PDODB_AI_PROVIDER=openai

# OpenAI
export PDODB_AI_OPENAI_KEY=sk-...

# Anthropic
export PDODB_AI_ANTHROPIC_KEY=sk-ant-...

# Google
export PDODB_AI_GOOGLE_KEY=...
export PDODB_AI_GOOGLE_MODEL=gemini-2.5-flash  # Optional: gemini-2.5-pro, gemini-2.0-flash-001, gemini-flash-latest, gemini-pro-latest

# Microsoft Azure OpenAI
export PDODB_AI_MICROSOFT_KEY=...
export PDODB_AI_MICROSOFT_MODEL=gpt-4  # Optional: model name
# Also configure endpoint via config array (see below)

# DeepSeek
export PDODB_AI_DEEPSEEK_KEY=...
export PDODB_AI_DEEPSEEK_MODEL=deepseek-chat  # Optional: deepseek-reasoner (thinking mode)

# Yandex Cloud
export PDODB_AI_YANDEX_KEY=...
export PDODB_AI_YANDEX_FOLDER_ID=b1ge9k4rdlck8g72slht  # Required: Yandex Cloud folder ID
export PDODB_AI_YANDEX_MODEL=gpt-oss-120b/latest  # Optional: model name

# Ollama (local, no API key needed)
export PDODB_AI_OLLAMA_URL=http://localhost:11434
export PDODB_AI_OLLAMA_MODEL=llama3.2  # Optional: model name

# OpenAI
export PDODB_AI_OPENAI_MODEL=gpt-4o-mini  # Optional: gpt-4, gpt-3.5-turbo

# Anthropic
export PDODB_AI_ANTHROPIC_MODEL=claude-3-5-sonnet-20241022  # Optional: claude-3-opus
```

### Configuration Array

You can also configure AI providers in your application config:

```php
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'ai' => [
        'provider' => 'openai',
        'openai_key' => 'sk-...',
        'anthropic_key' => 'sk-ant-...',
        'google_key' => '...',
        'microsoft_key' => '...',
        'deepseek_key' => '...',
        'yandex_key' => '...',
        'yandex_folder_id' => 'b1ge9k4rdlck8g72slht',  # Optional: can also be in providers.yandex.folder_id
        'ollama_url' => 'http://localhost:11434',
        'providers' => [
            'openai' => [
                'model' => 'gpt-4o-mini',
                'temperature' => 0.7,
                'max_tokens' => 2000,
            ],
            'anthropic' => [
                'model' => 'claude-3-5-sonnet-20241022',
                'temperature' => 0.7,
            ],
            'google' => [
                'model' => 'gemini-2.5-flash',  # or gemini-2.5-pro, gemini-2.0-flash-001, gemini-flash-latest, gemini-pro-latest
                'temperature' => 0.7,
                'max_tokens' => 2000,
            ],
            'microsoft' => [
                'endpoint' => 'https://your-resource.openai.azure.com',
                'deployment' => 'gpt-4',
            ],
            'deepseek' => [
                'model' => 'deepseek-chat',  # or deepseek-reasoner (thinking mode)
                'temperature' => 0.7,
                'max_tokens' => 2000,
            ],
            'yandex' => [
                'folder_id' => 'b1ge9k4rdlck8g72slht',  # Required: Yandex Cloud folder ID
                'model' => 'gpt-oss-120b/latest',  # Optional: model name
                'temperature' => 0.7,
                'max_tokens' => 2000,
            ],
            'ollama' => [
                'model' => 'llama3.2',  # or any local model name
            ],
        ],
    ],
]);
```

### Available Google Gemini Models

Google provides multiple model variants. Recommended models:

- **gemini-2.5-flash** (default) - Stable, fast, versatile model with 1M token context
- **gemini-2.5-pro** - Best for complex tasks, 1M token context, 65K output tokens
- **gemini-2.0-flash-001** - Stable version of Gemini 2.0 Flash (January 2025)
- **gemini-flash-latest** - Always uses the latest Flash model
- **gemini-pro-latest** - Always uses the latest Pro model

For a complete list of available models, use the provided script:

```bash
php check-google-models.php
```

This script will show all models that support `generateContent` with their token limits and descriptions.

### Priority

Configuration priority (highest to lowest):
1. Environment variables
2. Config array
3. Default values

## Code-Level Integration

### Using `explainAiAdvice()`

The `explainAiAdvice()` method extends the standard `explainAdvice()` with AI-powered recommendations:

```php
use tommyknocker\pdodb\PdoDb;

$db = PdoDb::fromEnv();

// Get AI-enhanced analysis
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explainAiAdvice(
        tableName: 'users',
        provider: 'openai',
        options: [
            'temperature' => 0.5,
            'max_tokens' => 2000,
        ]
    );

// Access base analysis (traditional EXPLAIN)
$baseAnalysis = $result->baseAnalysis;
echo "Issues found: " . count($baseAnalysis->issues) . "\n";
echo "Recommendations: " . count($baseAnalysis->recommendations) . "\n";

// Access AI analysis
echo "AI Analysis:\n";
echo $result->aiAnalysis . "\n";

// Provider information
echo "Provider: {$result->provider}\n";
echo "Model: {$result->model}\n";
```

### Using `AiAnalysisService` Directly

For more control, use `AiAnalysisService` directly:

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\ai\AiAnalysisService;

$db = PdoDb::fromEnv();

$aiService = new AiAnalysisService($db);

// Analyze a SQL query
$sql = "SELECT * FROM users WHERE email = ? AND status = 'active'";
$analysis = $aiService->analyzeQuery(
    sql: $sql,
    tableName: 'users',
    provider: 'anthropic',
    options: ['max_tokens' => 3000]
);

echo $analysis;

// Analyze database schema
$schemaAnalysis = $aiService->analyzeSchema(
    tableName: 'users',
    provider: 'openai'
);

echo $schemaAnalysis;

// Get optimization suggestions based on existing analysis
$baseAnalysis = [
    'issues' => [...],
    'recommendations' => [...],
];

$suggestions = $aiService->suggestOptimizations(
    analysis: $baseAnalysis,
    context: ['table_stats' => [...]],
    provider: 'google'
);
```

## CLI Commands

### `pdodb ai analyze`

Analyze a SQL query with AI:

```bash
pdodb ai analyze "SELECT * FROM users WHERE email = 'user@example.com'" \
    --provider=openai \
    --table=users \
    --format=json
```

Options:
- `--provider=NAME` - AI provider (openai, anthropic, google, microsoft, ollama)
- `--model=NAME` - Model name (provider-specific)
- `--temperature=N` - Temperature (0.0-2.0, default: 0.7)
- `--max-tokens=N` - Maximum tokens (default: 2000)
- `--table=NAME` - Table name for context
- `--format=FORMAT` - Output format (text, json)

### `pdodb ai query`

Analyze query using `explainAiAdvice()` (includes both base and AI analysis):

```bash
pdodb ai query "SELECT * FROM orders o JOIN users u ON o.user_id = u.id" \
    --provider=anthropic \
    --table=orders
```

### `pdodb ai schema`

Analyze database schema with AI:

```bash
# Analyze specific table
pdodb ai schema --table=users --provider=openai

# Analyze all tables
pdodb ai schema --provider=ollama --format=json
```

### `pdodb ai optimize`

Get AI-powered optimization suggestions:

```bash
pdodb ai optimize --table=users --provider=openai --temperature=0.5
```

## MCP Server

PDOdb includes an MCP (Model Context Protocol) server for integration with AI agents and IDEs that support MCP.

### Starting the MCP Server

```bash
vendor/bin/pdodb-mcp
```

The server reads database configuration from environment variables (same as `PdoDb::fromEnv()`).

### Available Tools

The MCP server exposes the following tools:

1. **`get_schema`** - Get database schema information
   ```json
   {
     "name": "get_schema",
     "arguments": {
       "table": "users"  // optional, omit for all tables
     }
   }
   ```

2. **`analyze_query`** - Analyze SQL query with AI
   ```json
   {
     "name": "analyze_query",
     "arguments": {
       "sql": "SELECT * FROM users WHERE id = 1",
       "table": "users",
       "provider": "openai"
     }
   }
   ```

3. **`suggest_indexes`** - Get AI-powered index suggestions
   ```json
   {
     "name": "suggest_indexes",
     "arguments": {
       "table": "users",
       "provider": "anthropic"
     }
   }
   ```

4. **`explain_plan`** - Get AI analysis of query execution plan
   ```json
   {
     "name": "explain_plan",
     "arguments": {
       "sql": "SELECT * FROM users",
       "table": "users",
       "provider": "openai"
     }
   }
   ```

### Resources

The server also exposes database tables as resources:

- `table://users` - Schema information for the `users` table
- `table://orders` - Schema information for the `orders` table

### Prompts

The server provides prompt templates:

- `analyze_query` - Template for analyzing SQL queries
- `optimize_schema` - Template for schema optimization

## Examples

### Example 1: Optimize a Slow Query

```php
$db = PdoDb::fromEnv();

// Get AI analysis for a slow query
$result = $db->find()
    ->from('orders')
    ->join('users', 'orders.user_id = users.id')
    ->where('orders.status', 'pending')
    ->orderBy('orders.created_at', 'DESC')
    ->limit(100)
    ->explainAiAdvice(
        tableName: 'orders',
        provider: 'openai'
    );

// Check for critical issues
if ($result->baseAnalysis->hasCriticalIssues()) {
    echo "⚠️ Critical issues found!\n";
    foreach ($result->baseAnalysis->issues as $issue) {
        if ($issue->severity === 'critical') {
            echo "- {$issue->message}\n";
        }
    }
}

// Read AI recommendations
echo "\n🤖 AI Recommendations:\n";
echo $result->aiAnalysis . "\n";
```

### Example 2: Schema Optimization

```php
use tommyknocker\pdodb\ai\AiAnalysisService;

$db = PdoDb::fromEnv();
$aiService = new AiAnalysisService($db);

// Analyze entire schema
$analysis = $aiService->analyzeSchema(
    tableName: null,  // null = all tables
    provider: 'anthropic'
);

echo "Schema Analysis:\n";
echo $analysis . "\n";
```

### Example 3: Using Ollama (Local, No API Key)

```php
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'ai' => [
        'provider' => 'ollama',
        'ollama_url' => 'http://localhost:11434',
        'providers' => [
            'ollama' => [
                'model' => 'deepseek-coder:6.7b',
                'max_tokens' => 1000,
            ],
        ],
    ],
]);

$result = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->explainAiAdvice(provider: 'ollama');

echo $result->aiAnalysis;
```

### Example 4: CLI Usage

```bash
# Analyze query with OpenAI
pdodb ai analyze "SELECT * FROM users WHERE email LIKE '%@gmail.com'" \
    --provider=openai \
    --table=users \
    --format=json

# Get schema optimization suggestions
pdodb ai schema --table=orders --provider=anthropic

# Analyze with custom model and temperature
pdodb ai query "SELECT COUNT(*) FROM users" \
    --provider=openai \
    --model=gpt-4 \
    --temperature=0.3 \
    --max-tokens=1500
```

## Best Practices

### 1. Choose the Right Provider

- **OpenAI (gpt-4o-mini)**: Fast, cost-effective for general analysis
- **Anthropic (claude-3-5-sonnet)**: Best for detailed, comprehensive analysis
- **Google (gemini-pro)**: Good balance of speed and quality
- **Ollama**: Use for privacy-sensitive environments or when API costs are a concern

### 2. Provide Context

Always provide table names when analyzing queries:

```php
// Good - provides context
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explainAiAdvice(tableName: 'users');

// Less optimal - no table context
$result = $db->rawQuery('SELECT * FROM users WHERE email = ?')
    ->explainAiAdvice();
```

### 3. Combine with Base Analysis

AI analysis works best when combined with traditional EXPLAIN analysis:

```php
$result = $db->find()
    ->from('orders')
    ->explainAiAdvice(tableName: 'orders');

// Check base analysis first
if ($result->baseAnalysis->hasCriticalIssues()) {
    // Handle critical issues
}

// Then review AI suggestions
echo $result->aiAnalysis;
```

### 4. Adjust Parameters for Your Use Case

- **Temperature**: Lower (0.3-0.5) for precise, factual analysis. Higher (0.7-1.0) for creative suggestions.
- **Max Tokens**: Increase for complex schemas or detailed analysis (2000-4000).

### 5. Cache AI Responses (Optional)

For frequently analyzed queries, consider caching AI responses:

```php
// Simple caching example
$cacheKey = 'ai_analysis_' . md5($sql . $tableName);
$cached = $cache->get($cacheKey);

if ($cached === null) {
    $result = $db->find()->from('users')->explainAiAdvice();
    $cache->set($cacheKey, $result->aiAnalysis, 3600); // 1 hour
    $cached = $result->aiAnalysis;
}
```

### 6. Use MCP Server for IDE Integration

Integrate with AI-powered IDEs (Cursor, VS Code with MCP extensions) for real-time database analysis:

```json
{
  "mcpServers": {
    "pdodb": {
      "command": "vendor/bin/pdodb-mcp",
      "env": {
        "PDODB_DRIVER": "mysql",
        "PDODB_HOST": "localhost",
        "PDODB_DATABASE": "mydb",
        "PDODB_USERNAME": "user",
        "PDODB_PASSWORD": "pass"
      }
    }
  }
}
```

## Troubleshooting

### Provider Not Available

If a provider is not available, check:

1. API key is set correctly (for cloud providers)
2. Ollama server is running (for Ollama)
3. Network connectivity (for cloud providers)

```php
use tommyknocker\pdodb\ai\providers\OpenAiProvider;
use tommyknocker\pdodb\ai\AiConfig;

$config = new AiConfig();
$provider = new OpenAiProvider($config);

if (!$provider->isAvailable()) {
    echo "OpenAI provider is not available. Check PDODB_AI_OPENAI_KEY.\n";
}
```

### Model Not Found (Ollama)

If using Ollama and getting "model not found" errors:

```bash
# List available models
curl http://localhost:11434/api/tags

# Pull a model if needed
ollama pull deepseek-coder:6.7b
```

### Rate Limiting

Cloud providers may rate limit requests. Consider:

- Using Ollama for local development
- Implementing request throttling
- Caching AI responses

### Cost Management

To manage API costs:

- Use Ollama for local development
- Use smaller models (gpt-4o-mini instead of gpt-4)
- Reduce `max_tokens` for simpler queries
- Cache AI responses for repeated queries

## See Also

- [Query Analysis](08-query-analysis.md) - Traditional EXPLAIN analysis
- [CLI Tools](21-cli-tools.md) - Complete CLI documentation
- [Performance Tips](../../README.md#performance-tips) - General performance optimization

