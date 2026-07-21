# ai-kit-core

**The engine of [ai-kit](https://github.com/solutionforest/ai-kit)** — document ingestion, chunking, extraction, vector retrieval, and RAG agents for Laravel, built on the official [Laravel AI SDK](https://laravel.com/docs/ai).

`composer require` → `php artisan ai-kit:install` → a grounded, cited RAG assistant over your own documents — with **no external vector database required**.

- **Two vector drivers** behind one contract: **SQLite** (zero-infra, default) and **pgvector** (production).
- **Two RAG modes** via one config flag: **tool** (agentic) and **inject** (classic).
- **Ingest** PDF, Word (.docx), Excel (.xlsx), HTML, Markdown, plain text, **and URLs**.
- **Provider-agnostic** — OpenAI, Anthropic, OpenRouter, Gemini, Groq, Mistral, … (anything the AI SDK supports).
- **Fully testable offline** — no API keys needed in CI.

## Requirements

- PHP **8.3+**
- Laravel **12 or 13**
- `laravel/ai` **^0.9**

## Install

```bash
composer require solutionforest/ai-kit-core
php artisan ai-kit:install
```

The installer:
1. Publishes `config/ai-kit.php` + migrations (including the AI SDK's conversation tables).
2. Interactively asks for your **AI provider config** (chat + embeddings provider/model/key) and writes it to `.env`.
3. Scaffolds `App\Ai\SupportAgent` (extends `RagAgent`).
4. Runs migrations.
5. Optionally seeds a demo knowledge base.

Then set your keys (the installer adds the scaffold; fill in the value):

```dotenv
AI_KIT_CHAT_PROVIDER=openai
AI_KIT_CHAT_MODEL=gpt-5
AI_KIT_EMBED_PROVIDER=openai
AI_KIT_EMBED_MODEL=text-embedding-3-small
OPENAI_API_KEY=sk-...
```

## Quick start

```php
use SolutionForest\AiKit\Models\Collection;
use SolutionForest\AiKit\Ingestion\IngestionService;
use SolutionForest\AiKit\Agents\RagAgent;

// 1. Create a knowledge base and ingest documents.
$kb = Collection::create(['name' => 'Handbook']);
app(IngestionService::class)->createFromPath($kb, storage_path('handbook.pdf'), sync: true);
app(IngestionService::class)->createFromUrl($kb, 'https://example.com/faq', sync: true);

// 2. Ask a question — grounded, cited, provider from config.
$response = (new RagAgent('handbook'))->prompt('What is the refund window?');
echo $response->text;
```

Or from the CLI:

```bash
php artisan ai-kit:ingest path/to/docs --collection=handbook   # file or directory
php artisan ai-kit:ingest-url https://example.com/faq --collection=handbook
php artisan ai-kit:demo                                         # seed 3 sample docs
```

## How ingestion works

```
createFromPath / createFromUrl
        │
        ▼
  ExtractText ──▶ ChunkDocument ──▶ EmbedChunks ──▶ vector index
 (pdf/docx/xlsx/    (recursive,      (AI SDK          (sqlite-vec
  html/md/txt)      sentence-aware)   embeddings)      or pgvector)
```

- **Synchronous** (`sync: true`) — runs inline; used by `ai-kit:demo` and CLI. No queue worker needed.
- **Queued** (default) — dispatches a chained `ExtractText → ChunkDocument → EmbedChunks` batch; document status moves `pending → processing → indexed` (or `failed`).

Every chunk stores its raw embedding **and** the embedding model name, so re-indexing with a different model is detectable and you can switch drivers without re-embedding blindly.

### Supported document types

| Type | Extractor | Notes |
|---|---|---|
| `.txt` `.md` `.csv` | `PlainTextExtractor` | |
| `.html` | `HtmlExtractor` | strips script/style/boilerplate |
| `.pdf` | `PdfExtractor` | via `smalot/pdfparser` |
| `.docx` | `WordExtractor` | zero-dependency (zipped XML) |
| `.xlsx` | `ExcelExtractor` | zero-dependency; extracts text cells |
| URL | fetch + `HtmlExtractor` | `createFromUrl` / `ai-kit:ingest-url` |

Legacy binary `.doc` / `.xls` are **not** supported.

## Two RAG modes

`config('ai-kit.mode')`:

- **`tool`** (default) — the agent gets a `SearchKnowledgeBase` tool and decides when/what to search (agentic RAG; supports multi-hop). Requires a model with reliable tool-calling.
- **`inject`** — no tool; `ContextInjector` retrieves and prepends context to the prompt before the model runs (classic RAG). Grounding never depends on the model choosing to search — safer with small models.

Both consume the same `Retriever` contract.

## Two vector drivers

`config('ai-kit.retrieval.driver')`:

- **`sqlite`** (default) — uses the sqlite-vec (`vec1`) extension when present, otherwise an **exact pure-PHP cosine scan** (correct everywhere, zero setup — ideal for demos and small/medium corpora). Optional extension path: `AI_KIT_SQLITE_VEC_PATH`.
- **`pgvector`** — a `vector` column indexed with HNSW/IVFFlat, searched with the `<=>` cosine operator. Scales to millions of chunks.

## Building agents

`RagAgent` reads config and wires the configured chat provider/model into every `prompt()` / `stream()`:

```php
use SolutionForest\AiKit\Agents\RagAgent;

(new RagAgent(collection: 'handbook'))
    ->forTenant('acme')            // optional tenant scope
    ->prompt('...');               // uses ai-kit.chat.provider + model
```

Customise instructions by extending it (the installer scaffolds this):

```php
namespace App\Ai;

use SolutionForest\AiKit\Agents\RagAgent;

final class SupportAgent extends RagAgent
{
    public function instructions(): \Stringable|string
    {
        return 'You are our support assistant. Always search first; answer only from the knowledge base; cite sources.';
    }
}
```

Point `AI_KIT_AGENT=App\Ai\SupportAgent` at it. The default instructions are strict-grounded (search-first, refuse-if-unsupported, cite by document title).

## Conversation persistence & token usage

When a prompt runs **for an authenticated participant** (`->forUser($user)` / `->continue($id, $user)`), the AI SDK persists the exchange to `agent_conversations` / `agent_conversation_messages`, **including per-message token usage**. Aggregate it with `UsageStats`:

```php
use SolutionForest\AiKit\Support\UsageStats;

app(UsageStats::class)->totals(30);            // ['prompt'=>, 'completion'=>, 'total'=>, 'cost'=>, 'messages'=>]
app(UsageStats::class)->daily(14);             // per-day token series
app(UsageStats::class)->conversationTokens($id);
```

Cost is estimated from `config('ai-kit.pricing')` ($ per 1M tokens, per model) using the active chat model's rate.

## Multi-tenancy

Every table has a nullable `tenant_id`. Scope models + retrieval:

```php
use SolutionForest\AiKit\Facades\AiKit;

AiKit::withTenant('acme', fn () => (new RagAgent('handbook'))->prompt('...'));
```

Enable with `AI_KIT_TENANCY=true`.

## Contracts (swap any layer)

| Contract | Default | Bind your own via the container |
|---|---|---|
| `Retriever` / `VectorIndex` | `SqliteVecRetriever` / `PgVectorRetriever` | custom store |
| `Embedder` | `AiSdkEmbedder` | e.g. a local embedder |
| `Chunker` | `RecursiveTextChunker` | custom splitting |
| `TextExtractor` | `ExtractorManager` | add extractors |

## Configuration reference

`config/ai-kit.php`: `mode`, `default_collection`, `agent` (class + instructions), `chat` (provider/model/fallback/streaming), `embeddings` (provider/model/dimensions/cache/timeout), `chunking` (size/overlap), `retrieval` (driver/top_k/min_score/sqlite/pgvector), `ingestion` (queue/embed_batch), `tenancy`, `database` (connection/prefix), `features` (dashboard/token_usage), `pricing`. Every value has an `env()` override.

## Commands

| Command | Purpose |
|---|---|
| `ai-kit:install` | Publish config/migrations, configure AI providers, scaffold agent |
| `ai-kit:demo` | Seed a demo collection + 3 sample docs (sync) |
| `ai-kit:ingest {path} --collection=` | Ingest a file or directory |
| `ai-kit:ingest-url {url} --collection=` | Fetch + ingest a web page |

## Headless surfaces (CLI · HTTP API · MCP)

Everything the Filament panel does is reachable without Filament. All four
surfaces call the same service layer (`ChatService`, `CollectionService`,
`DocumentService`), so behaviour is identical everywhere.

### CLI

| Command | Purpose |
|---|---|
| `ai-kit:collection {list\|new\|show\|delete}` | Manage knowledge bases (`--json`) |
| `ai-kit:document {list\|chunks\|reindex\|delete}` | Manage documents (`--collection`, `--json`) |
| `ai-kit:search {query} --collection=` | Raw retrieval, no LLM (`--json`) |
| `ai-kit:chat {collection}` | Interactive grounded chat REPL |
| `ai-kit:conversation {list\|show}` | Conversation log + token usage (`--json`) |
| `ai-kit:stats` | Documents/chunks/tokens/cost (`--json`) |

### HTTP API (optional)

Enable with `AI_KIT_API=true`. Locked behind auth by default
(`config('ai-kit.api.middleware') = ['api','auth:sanctum']`); set your own guard,
or `['api']` for public. Prefix: `AI_KIT_API_PREFIX` (default `api/ai-kit`).

```
POST  /chat                          { message, collection?, conversation_id? } → answer + citations + usage
POST  /chat/stream                   SSE (token-by-token)
POST  /search                        { query, collection?, top? } → chunks
GET   /collections                   list
POST  /collections                   { name, slug?, description? }
GET   /collections/{slug}            + stats
GET   /collections/{slug}/documents  list
POST  /collections/{slug}/documents  { url | text | file, title?, sync? } → ingest
GET   /conversations                 list (+ token totals)
GET   /conversations/{id}            thread + per-message tokens
GET   /usage?days=30                 totals + daily series
```

### MCP server (optional)

Expose ai-kit to MCP clients (Claude Desktop, IDE agents). Requires
`composer require laravel/mcp`, then `AI_KIT_MCP=true`. Tools: `search_knowledge_base`,
`ask_knowledge_base`, `list_collections`, `ingest_url`. Serve over stdio:

```bash
php artisan mcp:start ai-kit
```

Set `AI_KIT_MCP_WEB=true` to also register an HTTP transport route
(`config('ai-kit.mcp.web_route')`, guarded by `mcp.web_middleware`).

### Examples

**HTTP API** (Bearer auth by default):

```bash
# Ask a question
curl -X POST https://your-app.test/api/ai-kit/chat \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"message":"What is the refund window?","collection":"handbook"}'

# Raw search
curl -X POST https://your-app.test/api/ai-kit/search \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"query":"refunds","collection":"handbook","top":5}'

# Ingest a URL
curl -X POST https://your-app.test/api/ai-kit/collections/handbook/documents \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"url":"https://example.com/faq"}'
```

**MCP** — point a client (e.g. Claude Desktop) at the stdio server:

```json
{
  "mcpServers": {
    "ai-kit": {
      "command": "php",
      "args": ["artisan", "mcp:start", "ai-kit"],
      "cwd": "/path/to/your/laravel/app"
    }
  }
}
```

**Programmatic** (any surface, same result):

```php
use SolutionForest\AiKit\Services\ChatService;

$result = app(ChatService::class)->ask('What is the refund window?', 'handbook');
$result->answer;      // string
$result->citations;   // [{document_title, chunk_index, score, content}, ...]
$result->usage;       // {in, out, total}
```

## Testing

The suite runs **offline with no API keys** — LLM calls use the AI SDK's fakes, embeddings use a deterministic in-memory double.

```bash
composer install && vendor/bin/pest
```

## License

MIT © [SolutionForest](https://www.solutionforest.com)
