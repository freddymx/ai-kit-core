# Guía de desarrollo — ai-kit-core

## 1. Objetivo

`freddymx/ai-kit-core` es el motor RAG de la suite ai-kit: cubre el ciclo completo **ingestion → chunking → embeddings → retrieval → agent** sobre documentos propios, apoyado en el `laravel/ai` SDK oficial. La promesa central es un asistente *grounded* y con citas sin exigir una vector DB externa: el driver por defecto es SQLite (extensión `sqlite-vec` si está disponible, fallback de cosine scan en PHP puro), con `pgvector` como opción de producción.

El paquete es **headless por diseño**: la misma lógica se expone en cuatro superficies — Livewire UI, Artisan CLI, HTTP API y MCP server — sin acoplarse a Filament ni a ningún panel. Todas las superficies son opcionales salvo el core; el HTTP API y MCP se activan por flags de configuración.

El flujo de trabajo típico es `composer require freddymx/ai-kit-core` → `php artisan ai-kit:install` → ingesta de documentos → preguntas con citas. El installer publica config y migraciones, configura provider/model interactivamente, scafoldea `App\Ai\SupportAgent` (extends `RagAgent`) y corre migraciones.

## 2. Relación con la suite

| Paquete | Rol | Requisitos sobre los otros |
| --- | --- | --- |
| `freddymx/ai-kit-core` (este) | Ingestion, retrieval, agents + CLI, HTTP API, MCP | base de la suite, sin dependencias de los otros dos |
| `freddymx/ai-kit-ui-livewire` | Componente chat Livewire 4 | `freddymx/ai-kit-core: ^0.4` |
| `freddymx/ai-kit-filament` | Panel admin Filament v5 | `freddymx/ai-kit-core: ^0.4` **y** `freddymx/ai-kit-ui-livewire: ^0.4` |

El punto compartido es la capa de servicios: `ChatService`, `CollectionService` y `DocumentService`. Las 4 "caras" (UI, CLI, HTTP API, MCP) llaman exactamente los mismos servicios, así que el comportamiento RAG es idéntico sin importar el entrypoint. `AiKitServiceProvider` registra los bindings (contratos `Retriever`/`VectorIndex`, `Embedder`, `Chunker`, `TextExtractor`, `ConversationStore`), los comandos y las superficies opcionales.

**Settings y tenancy (DB compartida con `tenant_id`):** `ai_kit_settings` tiene una fila por tenant (migración `000005`; `000004` queda intacta para instalaciones ya publicadas). `AiSettings` usa `BelongsToTenant`: el global scope filtra por `tenant()` y `creating` stampea `tenant_id`. Con `AI_KIT_TENANCY=true` y **sin tenant activo**, `AiSettings::current()` devuelve `null` — nunca filtra la fila ajena — y `AiProviderResolver::save()/reset()` lanzan `RuntimeException`. `AiKit::setTenant()` / `withTenant()` re-publican la config al entrar **y** al restaurar el tenant anterior (vía `resolver->applyToConfig()` en `try/catch`). Con tenancy desactivado sigue existiendo una única fila global.

Importante: el **vendor de Composer es `freddymx`** (el namespace PHP sigue siendo `SolutionForest\AiKit\`). No hay que cambiar `use` statements ni referencias a clases en config; solo el nombre del paquete en `composer.json`. Las versiones hasta 0.2 se publicaron como `solution-forest/ai-kit-core`.

## 3. Stack y requisitos

- **PHP** `^8.5`; Laravel `12|13` (`illuminate/contracts`, `database`, `support`, `http`, `bus` `^12.0|^13.0`).
- **`laravel/ai: ^1.4`** (lock actual: `v1.4.1`) — agentes, providers, embeddings, conversaciones y tool calling.
- **`laravel/prompts: ^0.3.6`** (installer y comandos interactivos); **`smalot/pdfparser: ^2.7`** (extracción PDF).
- **Dev:** `pestphp/pest: ^5.0`, `orchestra/testbench: ^10.0|^11.0`, `laravel/mcp: ^1.0` (también en `suggest`, requerido solo si `features.mcp` está activo), `laravel/pao: ^1.1` (sin uso en código).
- **DB:** 5 migraciones propias (4 de creación + `000005` que agrega `tenant_id` a `ai_kit_settings`); tablas `ai_kit_collections`, `ai_kit_documents`, `ai_kit_chunks`, `ai_kit_settings`; las conversaciones usan las tablas del SDK (`agent_conversations`, `agent_conversation_messages`).
- Tests offline: sin API keys — LLM con fakes del SDK y embeddings con un double determinístico (`tests/Support/FakeEmbedder.php`).

## 4. Estado actual

Versión publicada: **v0.4.3**. `main` tiene un commit extra sin tag (`8895735 chore: add laravel/pao to dev dependencies`), así que `git describe` da `v0.4.3-1-g8895735`. `dev-main` tiene branch alias `0.4.x-dev`.

Capacidades implementadas:

- **2 drivers de vector:** `sqlite` (sqlite-vec con fallback PHP cosine) y `pgvector` (índice HNSW/IVFFlat, operador `<=>`).
- **2 modos RAG:** `tool` (default, `SearchKnowledgeBase` tool, agentic/multi-hop) e `inject` (`ContextInjector`, classic RAG); ambos consumen el mismo contrato `Retriever`.
- **Extractores:** PDF, DOCX, XLSX, HTML, Markdown, TXT, CSV y URL (fetch + `HtmlExtractor`).
- Streaming (`RagAgent::stream()`, SSE en el API), citations por título de documento, conversation persistence con `ConsistentConversationStore`, `UsageStats` + pricing estimado, multi-tenancy (`tenant_id` nullable en cada tabla), HTTP API opcional, MCP server opcional.
- **Settings resolver con DB sobre `.env`:** `AiSettings` es la source of truth; `AiProviderResolver::applyToConfig()` publica los valores efectivos en config en cada request.

Tests: **92 passed / 319 assertions** (`vendor/bin/pest`). Hay **1 warning menor** en el output de Pest, sin detalle expuesto ni en la salida estándar ni con `--display-warnings`.

## 5. Estructura del código

```
src/
├── AiKitServiceProvider.php      # bindings, comandos, migraciones, API, MCP, publish
├── Agents/                       # RagAgent (prompt/stream, provider, instructions, tenant)
├── Chunking/                     # RecursiveTextChunker (sentence-aware)
├── Console/                      # 10 comandos ai-kit:*
├── Contracts/                    # Chunker, Embedder, Retriever, TextExtractor, VectorIndex
├── Conversations/                # ConsistentConversationStore
├── Data/                         # DTOs (resultados de chat/search)
├── Embeddings/                   # AiSdkEmbedder
├── Enums/                        # DocumentStatus (pending|processing|indexed|failed)
├── Extraction/                   # ExtractorManager + extractors por tipo
├── Facades/                      # AiKit (withTenant)
├── Http/Controllers/             # Chat, Collection, Conversation, Usage
├── Ingestion/                    # IngestionService + Jobs/ (ExtractText, ChunkDocument, EmbedChunks)
├── Mcp/ + Mcp/Tools/             # AiKitServer + search/ask/list/ingest tools
├── Models/ + Models/Concerns/    # Collection, Document, Chunk, AiSettings
├── Pipeline/                     # orquestación del pipeline de ingesta
├── Retrieval/                    # AbstractRetriever, SqliteVecRetriever, PgVectorRetriever
├── Services/                     # ChatService, CollectionService, DocumentService
├── Support/                      # AiProviderResolver, AiSettings, UsageStats, helpers
└── Tools/                        # SearchKnowledgeBase y herramientas del agente
config/ai-kit.php                 # toda la configuración con overrides env()
database/migrations/              # 4 migraciones (collections, documents, chunks, settings)
resources/samples/                # 3 docs de demo (markdown)
resources/stubs/                  # SupportAgent.php.stub
routes/api.php                    # endpoints HTTP opcionales
tests/                            # Pest.php, TestCase, Feature/, Unit/, Support/
```

Clases clave y su rol:

| Clase | Rol |
| --- | --- |
| `AiKitServiceProvider` | Registra singleton de contratos, comandos, migraciones, store de conversaciones, rutas API/MCP y publishing. |
| `RagAgent` | Agente RAG; resuelve provider/modelo vía resolver, inyecta tool o contexto según `mode`, stream y citations. |
| `AiSdkEmbedder` | Genera embeddings through el SDK; guarda el nombre del modelo en cada chunk. |
| `AiProviderResolver` | Source of truth de provider/modelo; DB → config → `.env`, expande el sentinel `local`. |
| `AiSettings` | Modelo de settings con `BelongsToTenant`: una fila por tenant si tenancy está activo, global si no; `current()` con guard sin tenant. |
| `ConsistentConversationStore` | Store del SDK que mantiene tool calls y outputs pareados para no romper conversaciones truncadas. |
| `IngestionService` | `createFromPath`/`createFromUrl`; extract → chunk → embed, sync o vía queue. |
| `ChatService` | `ask()` / stream; punto de entrada compartido por UI, CLI, API y MCP. |
| `ExtractorManager` | Dispatch por extensión/MIME a los extractores concretos. |

## 6. Configuración

| Variable | Default | Uso |
| --- | --- | --- |
| `AI_KIT_CHAT_PROVIDER` / `AI_KIT_CHAT_MODEL` | `anthropic` / `claude-sonnet-5` | Provider y modelo de chat del agente |
| `AI_KIT_EMBED_PROVIDER` / `AI_KIT_EMBED_MODEL` / `AI_KIT_EMBED_DIMENSIONS` | `openai` / `text-embedding-3-small` / `1536` | Embeddings; dimensiones deben coincidir con la columna vector |
| `AI_KIT_MODE` | `tool` | `tool` (agentic) o `inject` (classic) |
| `AI_KIT_RETRIEVER` | `sqlite` | `sqlite` o `pgvector` |
| `AI_KIT_TENANCY` | `false` | Activa scoping por `tenant_id` |
| `AI_KIT_API` | `false` | Publica el HTTP API (`api/ai-kit`, `auth:sanctum` por defecto) |
| `AI_KIT_MCP` | `false` | Registra el MCP server (stdio; `AI_KIT_MCP_WEB=true` suma HTTP) |
| `AI_KIT_SETTINGS` | `true` | Expone la página de AI settings del plugin Filament |
| `AI_KIT_APPLY_TO_SDK` | `true` | Publica `ai.default` / `ai.default_for_embeddings` globales del SDK |
| `AI_KIT_SQLITE_VEC_PATH` | _(none)_ | Path absoluto de la extensión loadable `vec1`; sin ella, cosine scan PHP |
| `AI_KIT_DB_CONNECTION` | _(null)_ | Conexión de los modelos ai-kit (null = default) |

Otras variables relevantes: `AI_KIT_COLLECTION`, `AI_KIT_AGENT`, `AI_KIT_STREAMING`, `AI_KIT_MAX_STEPS`, `AI_KIT_CHUNK_SIZE`/`AI_KIT_CHUNK_OVERLAP`, `AI_KIT_TOP_K`/`AI_KIT_MIN_SCORE`, `AI_KIT_PGVECTOR_INDEX`, `AI_KIT_QUEUE`/`AI_KIT_EMBED_BATCH`, `AI_KIT_EMBED_CACHE`/`AI_KIT_EMBED_TIMEOUT`, `AI_KIT_API_PREFIX`, `AI_KIT_MCP_HANDLE`/`AI_KIT_MCP_WEB_ROUTE`/`AI_KIT_MCP_COLLECTION`, `AI_KIT_DASHBOARD`/`AI_KIT_TOKEN_USAGE` y los defaults de UI `AI_KIT_UI_CITATIONS`/`AI_KIT_UI_HISTORY`.

### Local models

Setear `AI_KIT_CHAT_PROVIDER` y/o `AI_KIT_EMBED_PROVIDER` en el sentinel `local` enruta chat y/o embeddings a cualquier server OpenAI-compatible (Ollama, LM Studio, vLLM, llama.cpp). Resuelven de forma independiente: se puede combinar `AI_KIT_CHAT_PROVIDER=anthropic` con `AI_KIT_EMBED_PROVIDER=local`.

| Variable | Default | Uso |
| --- | --- | --- |
| `AI_KIT_LOCAL_DRIVER` | `openai-compatible` | Driver del SDK; también acepta `ollama` |
| `AI_KIT_LOCAL_URL` | `http://localhost:11434/v1` | Base URL del servidor local |
| `AI_KIT_LOCAL_KEY` | _(none)_ | API key / bearer token opcional |
| `AI_KIT_LOCAL_CHAT_MODEL` | `llama3.2` | Modelo de chat |
| `AI_KIT_LOCAL_EMBED_MODEL` | `nomic-embed-text` | Modelo de embeddings |
| `AI_KIT_LOCAL_EMBED_DIMENSIONS` | `768` | Dimensiones del vector (sincronizar con las migraciones) |

**Precedencia:** los valores guardados en `ai_kit_settings` (página Filament) ganan siempre a `config/ai-kit.php` y `.env`. En cada request, `AiProviderResolver::applyToConfig()` copia los valores efectivos a `config('ai-kit.chat.*')`, `config('ai-kit.embeddings.*')` y `config('ai.providers.local')`, de modo que el código que llama al SDK directamente (`Ai::text()`, `Ai::embeddings()`) ve lo mismo. `AiProviderResolver::reset()` borra la fila del tenant activo y vuelve a `.env`. `effective()` enmascara la key para UIs/logs; la key real se escribe en `ai.providers.local.key` porque el SDK la necesita.

Con tenancy activo, `applyToConfig()` **no publica nada** mientras no haya tenant: publicar en `boot()` (antes de resolver al tenant) dejaría los valores de un tenant en la config global. El resolver mantiene `envDefaults` como baseline global capturado una sola vez por clave — NUNCA se sobrescribe con un valor publicado — y `publishedConfig` por tenant (`tenant_id ?? '__global__'`) para detectar qué valor en `config()` es propio. Los cambios escritos por otro código se registran como baseline del tenant (`tenantBaselines`), sin envenenar el baseline global.

## 7. Flujo de desarrollo local

```bash
cd ../ai-kit-core
composer install
vendor/bin/pest
composer validate
```

Los repos hermanos viven al mismo nivel: `../ai-kit-ui-livewire` y `../ai-kit-filament`. Sus `composer.json` declaran repos `path` hacia `../ai-kit-core` (y el de Filament también hacia `../ai-kit-ui-livewire`), con `symlink: true` en Filament, así que los cambios del core se ven sin publicar.

`composer.lock` está gitignoreado en los tres paquetes: en CI se corre `composer update --prefer-dist --no-interaction --no-progress` en vez de `install`. El workflow `.github/workflows/tests.yml` se dispara con `release: [published]` y `workflow_dispatch`, sobre PHP `8.5`, con extensiones `zip, sqlite3, pdo_sqlite` (no corre en cada push).

**Cambios transversales:** publicar siempre en orden **core → ui-livewire → filament**. En 0.x, el caret `^0.3` **no incluye 0.4** — los tres paquetes deben moverse juntos y actualizar constraints a `^0.4` en el mismo release. Los tags de la suite se publican por separado en cada repo.

## 8. Comandos y flujos clave

| Comando | Propósito |
| --- | --- |
| `ai-kit:install` | Publica config/migraciones, configura providers y scafoldea el agente |
| `ai-kit:demo {--collection=demo} {--fresh}` | Siembra 3 docs de ejemplo (sync) |
| `ai-kit:ingest {path} {--collection=} {--queue}` | Ingesta archivo o directorio |
| `ai-kit:ingest-url {url} {--collection=} {--title=} {--queue}` | Fetch + ingesta de una URL |
| `ai-kit:collection {action=list\|new\|show\|delete} {name?} {--slug=} {--description=} {--json}` | Admin de knowledge bases |
| `ai-kit:document {action=list\|chunks\|reindex\|delete} {id?} {--collection=} {--json}` | Admin de documentos y chunks |
| `ai-kit:search {query} {--collection=} {--top=} {--json}` | Retrieval crudo, sin LLM |
| `ai-kit:chat {collection?} {--agent=}` | REPL de chat grounded |
| `ai-kit:conversation {action=list\|show} {id?} {--json}` | Log de conversaciones + tokens |
| `ai-kit:stats {--days=30} {--json}` | Docs/chunks/tokens/costo |

**Borrado de conversaciones:** `ConversationService` (singleton en `register()`) expone `delete($id, ?$type, $key)`, `deleteForParticipant($id, $participant)` y `deleteAllForParticipant($participant)`. Con `participantType` no null, delega la verificación de propiedad en `conversationBelongsTo()` del store del SDK y devuelve `false` sin borrar si el participante no es dueño. `delete()` envuelve el borrado en una transacción sobre la conexión del modelo.

**Ingest sync vs queued:** en CLI la ingesta corre **sync por defecto**; `--queue` la manda a la cola. A nivel de API, `IngestionService` hace default queued (chained `ExtractText → ChunkDocument → EmbedChunks` en la conexión `AI_KIT_QUEUE`) y `sync: true` ejecuta inline. En modo queued el documento pasa por `pending → processing → indexed`, o `failed` con `failure_reason` ante errores (`DocumentStatus` en `src/Enums/`).

## 9. Decisiones de diseño y gotchas

- **`Ai::build()` on-demand (`ai_kit_local`):** el sentinel `local` se expande en un objeto `Provider` concreto con la URL/key/modelos vigentes, en vez de depender de un provider estático en `ai.providers.*`. Así los overrides de DB se aplican incluso a agentes creados con `new RagAgent(...)`.
- **Resolución lazy:** los getters de `AiProviderResolver` son lazy y toleran que la tabla `ai_kit_settings` todavía no exista (los bindings del contenedor son singletons). `applyToConfig()` sí corre en `boot()`, pero dentro de `try/catch`: una DB caída o migraciones sin correr nunca impiden el boot de la app.
- **Centinela `local` registrado como `ai.providers.local`:** para que las llamadas directas al SDK resuelvan el provider, se publica bajo la clave `local` (no `ai_kit_local`): `AiManager::build()` rechaza nombres ya tomados. Internamente el provider on-demand conserva el nombre `ai_kit_local`.
- **`config:cache` excluido del apply:** `isConfigCacheCommand()` detecta `config:cache` en argv y salta el paso DB → config, porque cachear valores de DB los dejaría stale hasta el próximo rebuild.
- **Guard de tenant en settings:** con `AI_KIT_TENANCY=true` y sin `AiKit::setTenant()`, `current()` es `null`, `applyToConfig()` no escribe y `save()/reset()` tiran `RuntimeException`. Es deliberado: una query sin scope devolvería la fila de otro tenant y un `query()->delete()` borraría todas las filas. El panel Filament convierte esa excepción en `Notification::warning()`.
- **Re-apply en cada cambio de tenant:** `setTenant()` y el `finally` de `withTenant()` llaman al resolver dentro de `try/catch`. El resolver no llama a `setTenant()`, así que no hay recursión; una DB caída nunca rompe el switch.
- **Octane / workers:** `config()` es **process-global** y sobrevive entre requests. En un worker con tenants distintos hay que re-aplicar por request (llamando `AiKit::setTenant($id)` / `withTenant()`, que ya re-publican, o `resolver->reapply()`) o usar el resolver directamente en vez de leer `config('ai-kit.*')`. Si se limpia el tenant con `setTenant(null)`, `applyToConfig()` publica los valores derivados de `.env` (incluido `ai.providers.local` con `AI_KIT_LOCAL_*`) para que no quede ningún valor del tenant anterior en la config global.
- **Baseline nunca envenenado:** `envDefaults[key]` se captura una vez y jamás se sobreescribe con un valor publicado; por eso un `reset()` dentro de acme vuelve exactamente al valor de `.env`, incluso después de pasar por globex.
- **Error si falta URL:** `buildLocalProvider()` tira `RuntimeException` indicando configurar `AI_KIT_LOCAL_URL` o el campo "Local URL" de Filament; no hay default silencioso para el provider on-demand.
- **Reindexado detectable por embedding model:** cada chunk guarda `embedding_model`; cambiar `AI_KIT_EMBED_MODEL` no invalida lo ya indexado silenciosamente. `ai-kit:document reindex {id}` permite re-embedder de forma explícita.
- **SDK 1.x:** las conversaciones se scoping por `participant_type` / `participant_id` (ya no `user_id`), y `ConsistentConversationStore` se basa en la columna `steps` del SDK. Un tool call sin respuesta no se persiste; el SDK repara el historial en el siguiente run.
- **Borrado sin FK cascade:** la migración del SDK no pone `ON DELETE CASCADE` en `agent_conversation_messages.conversation_id`. `AiKitServiceProvider::boot()` registra `Conversation::deleting()` para borrar los mensajes con `ConversationMessage::query()->where('conversation_id', ...)->delete()`. Así cualquier `->delete()` del modelo (servicio, Filament, código de app) limpia los mensajes; el servicio solo borra la conversación y confía en el evento, evitando un doble borrado.
- **Propiedad en el borrado:** `conversationBelongsTo()` NO está en el contrato `ConversationStore` del SDK, sino en `Laravel\Ai\Contracts\VerifiesConversationOwnership` (implementado por `DatabaseConversationStore`). `ConversationService` comprueba `instanceof` antes de llamarlo: un store custom que solo implemente `ConversationStore` no puede acreditar propiedad y por eso no se le permite borrar.
- **Token usage compat:** se leen `input_tokens` / `output_tokens` del SDK 1.x, con fallback a `prompt_tokens` / `completion_tokens` de filas escritas por 0.x (`UsageStats`).

## 10. Verificación y publicación

Checklist antes de publicar:

1. `vendor/bin/pest` en el paquete (y en los hermanos si el cambio es transversal) — 105 passed / 384 assertions en el estado actual.
2. `composer validate` (sin errores).
3. Revisar que `features.api` / `features.mcp` siguen funcionando con los flags en `false` y `true`.
4. Tag semver en 0.x: **patch** para fixes (`0.4.3 → 0.4.4`), **minor** para features (`0.4.3 → 0.5.0`); recordar actualizar `branch-alias` y las constraints `^0.4` de los hermanos cuando corresponda.
5. `git push origin main` y `git push origin vx.y.z`. Packagist se autoactualiza por el hook de GitHub; no hace falta publicar a mano.
6. Verificar con una instalación limpia en un temp dir: `composer require freddymx/ai-kit-core:<versión>` y `php artisan ai-kit:install` en un proyecto Laravel 12/13 con PHP 8.5.

El workflow de CI corre al publicar el release, así que conviene confirmar que el tag dispara `tests` en verde.

## 11. Límites conocidos / próximos pasos

- Los formatos legacy binarios **`.doc` / `.xls` no están soportados**; solo `.docx` y `.xlsx` (extracción de XML zipped, sin dependencias extra).
- El driver `sqlite` sin extensión `sqlite-vec` hace un **exact scan en PHP**: correcto para corpora chicos/medianos y demos, pero más lento a gran escala. Para millones de chunks usar `pgvector`.
- El **costo de `pricing` es estimado** (tarifas $ por 1M tokens en `config/ai-kit.php`); los modelos sin entrada explícita caen en `default` con costo 0.
- `laravel/pao` está en `require-dev` pero **no se usa en ningún archivo de `src/`, `tests/`, `config/`, `database/` ni `routes/`**.
- La UI de Livewire y el panel Filament son paquetes separados: esta guía cubre el core y sus superficies headless, no el detalle de esos componentes.
- Esta guía refleja el estado del repo al **25/11/2026**. Después de cada release conviene revisarla, sobre todo la versión publicada y el resultado de los tests.
