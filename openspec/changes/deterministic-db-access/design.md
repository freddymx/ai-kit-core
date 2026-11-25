# Diseño técnico: Acceso determinista a DB vía DSL

## 1. Contexto y restricciones

El kit ya tiene las costuras necesarias; este cambio **no introduce un subsistema paralelo**, agrega un segundo caso de uso a la arquitectura existente "un core, cuatro caras":

- `Tools\SearchKnowledgeBase` (`src/Tools/SearchKnowledgeBase.php`) implementa `Laravel\Ai\Contracts\Tool` con `description() / schema() / handle()` y devuelve JSON con metadatos de cita.
- `RagAgent::tools()` (`src/Agents/RagAgent.php:146`) ya devuelve un array de tools condicionado por `ai-kit.mode`.
- `Mcp\AiKitServer::$tools` (`src/Mcp/AiKitServer.php:37`) es la lista de tools MCP.
- `AiKitServiceProvider::makeVectorIndex()` (`:70`) muestra el patrón de resolución de conexión desde config.
- `AiSettings` + `AiProviderResolver` muestran el patrón DB > config > `.env` con `effective() / save() / reset() / applyToConfig()` y enmascarado de secretos.
- `AiKitPlugin::register()` (`ai-kit-filament`) registra páginas según flags de config.
- `ChatService::ask()` (`src/Services/ChatService.php:41`) calcula las citas con una retrieval **separada** (`citationsFor()`), no desde los resultados de las tools. Este es el punto de integración no obvio para Livewire.
- `StreamController` emite `done` con `{content, conversationId}` únicamente (`:60`). El canal de streaming también hay que extenderlo.

### 1.1 Implicaciones de los `DEVELOPMENT.md` de la suite

Los tres `DEVELOPMENT.md` aportan restricciones que este diseño respeta:

| Hallazgo | Implicación para este cambio |
|---|---|
| Dependencia en cascada `core ← ui-livewire ← filament` (Filament también requiere UI) | Los tres paquetes se mueven en ese orden. El Playground de Filament **embebe** `<livewire:ai-kit.chat>`, así que el render de resultados estructurados llega al panel sin UI duplicada. |
| En `0.x`, `^0.4` = `>=0.4.0 <0.5.0`; el minor es breaking y los paquetes van en lockstep | Feature nueva ⇒ `0.5.0` en los tres, con `branch-alias` y constraints `^0.5` actualizados en el mismo release. No se publica solo el core. |
| `composer.lock` gitignoreado; path repos con `symlink: true` | Los cambios cross-package se verifican localmente sin publicar. CI corre solo en `release: published` / `workflow_dispatch`, no en cada push. |
| Baselines de tests: core `92/319`, filament `24/141`, ui `11/32` | Referencia para detectar regresiones; hay que correr los tres en local antes de taggear. |
| Filament v5 usa `Filament\Schemas\Schema` + `Components\{Form, Actions, Section, Html}` | La página nueva usa esa API; la API de `Forms\Form` de v3/v4 rompe el page. |
| En Filament v5 los campos ocultos **no se deshidratan** | Los campos `custom` van con `dehydratedWhenHidden(false)`; al pasar de `custom` a un nombre de conexión, los valores ocultos no deben sobrescribir lo guardado. Mismo patrón que los pickers `*_manual`. |
| `features.settings=false` oculta la página pero **no desactiva el resolver** | Igual semántica para `structured.enabled`: el flag controla UI y tools, pero `StructuredSettingsResolver::applyToConfig()` sigue leyendo `ai_kit_settings`. |
| `LocalModelDiscovery` nunca lanza y devuelve `{models, error}` con fallback manual + `Notification::warning()` | **Test connection** copia ese contrato: nunca lanza, notifica resultado y jamás imprime credenciales. |
| Las vistas UI se publican con `vendor:publish --tag=ai-kit-ui-views` y Composer **no las actualiza** | El markup de citas estructuradas no llega a quien ya publicó; documentar `--force`. Hasta republicar, el feature degrada limpiamente a "sin chip de DB". |
| CSS inline con `@once`, prefijo `ck-` y `text-align:left!important` en hijos markdown | Los estilos nuevos van en el mismo bloque con prefijo `ck-`; `table/th/td` ya están en la regla existente. |
| **Fix de tenancy ya aplicado**: `ai_kit_settings` tiene `tenant_id` (migración `000005`), `AiSettings` usa `BelongsToTenant`, `AiProviderResolver` tiene guard de escritura y `applyToConfig()` no publica sin tenant, y `AiKit::setTenant()/withTenant()` re-aplica config | Q7 queda **resuelto**: conexión y catálogo son **por tenant**, no globales. Además, mis migraciones previstas pasan a `000006`/`000007` porque `000005` ya está ocupada. |
| **Q1 resuelto** | Named connection como principal/default; `custom` en Advanced colapsado. Ver §4.4 para el rationale completo. |

**No negociables:**

1. Determinista por construcción: misma entrada → mismo SQL → mismas filas en el mismo orden.
2. Deny by default: catálogo explícito de entidades, columnas, operadores y agregaciones.
3. SELECT-only en dos capas (resolver + permisos de DB).
4. Tenant inyectado server-side.
5. Credenciales cifradas en reposo y enmascaradas en UI.
6. Feature desactivada por defecto: con el flag en `false`, los tres paquetes se comportan **exactamente** como hoy.

---

## 2. Visión general

```
                      config/ai-kit.php  ←  ai_kit_settings (DB gana)
                              │
                              ▼
              StructuredSettingsResolver  (effective/save/reset/applyToConfig)
                              │
        ┌─────────────────────┼──────────────────────┐
        ▼                     ▼                      ▼
 ai_kit_structured_    StructuredDataSource    RagAgent::tools()
 resources (catálogo)   (QueryBuilderDataSource)   [SearchKB, QueryDB]
        │                     │                      │
        │                     ▼                      ├──▶ MCP: QueryDatabaseTool
        └──▶ Filament ──▶ resolución determinista ───┘
                              │
                              ▼
                    ChatResult.citations[]
                    ├─ kind: document  (existente)
                    └─ kind: database  (nuevo)
                              │
                              ▼
                    Livewire Chat (chips + popup de tabla)
```

---

## 3. Capa core (`ai-kit-core`)

### 3.1 Contratos y DTOs

Nuevos archivos bajo `src/Contracts/` y `src/Data/`, siguiendo el estilo readonly del kit:

```php
// src/Contracts/StructuredDataSource.php
interface StructuredDataSource
{
    /** Catálogo activo, para construir la descripción de la tool. */
    public function resources(): array;

    public function query(StructuredQuery $query, ?string $tenantId = null): StructuredResult;
}
```

```php
// src/Data/StructuredQuery.php
final readonly class StructuredQuery
{
    public function __construct(
        public string $resource,
        /** @var list<string> */
        public array $select = [],
        /** @var list<StructuredFilter> */
        public array $filters = [],
        public ?StructuredAggregate $aggregate = null,
        /** @var list<string> */
        public array $groupBy = [],
        /** @var list<StructuredOrder> */
        public array $orderBy = [],
        public int $limit = 20,
    ) {}
}

// src/Data/StructuredFilter.php  → field, op, value
// src/Data/StructuredAggregate.php → fn (count|sum|avg|min|max), field
// src/Data/StructuredOrder.php → field, dir (asc|desc)
// src/Data/StructuredResult.php → resource, columns, rows, count, truncated, fingerprint
```

`ResourceDescriptor` / `ColumnDescriptor` viven en `src/Data/` y son la proyección tipada de una fila de `ai_kit_structured_resources`:

- `ResourceDescriptor`: `slug`, `label`, `table`, `description`, `primaryKey`, `tenantColumn`, `active`, `columns`, `allowedFilters`, `allowedAggregations`.
- `ColumnDescriptor`: `name`, `label`, `type`, `selectable`, `filterable`, `sensitive`.

### 3.2 El DSL

La tool expone un único parámetro `query` (string JSON), con este shape:

```json
{
  "resource": "order",
  "select": ["id", "status", "total", "created_at"],
  "filters": [
    { "field": "status",     "op": "=",  "value": "refunded" },
    { "field": "created_at", "op": ">=", "value": "2026-01-01" }
  ],
  "aggregate": { "fn": "sum", "field": "total" },
  "group_by": ["status"],
  "order_by": [{ "field": "created_at", "dir": "desc" }],
  "limit": 20
}
```

Operadores permitidos: `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`, `is_null`, `like` (escapado). Cualquier otro se rechaza. No existe `raw`, ni subqueries, ni joins arbitrarios: si hace falta un join, se modela como una **view** en la DB y se registra como resource.

Errores de validación — estructurados para que el modelo pueda autocorregirse, nunca una excepción cruda:

```json
{
  "error": "validation_failed",
  "violations": [
    { "path": "filters[0].field", "reason": "column_not_allowed", "allowed": ["status","total","created_at"] }
  ]
}
```

### 3.3 Algoritmo del resolver (`QueryBuilderDataSource::query`)

1. Resolver el `ResourceDescriptor` por `slug`; si no existe o `active === false` → `resource_not_found`.
2. Validar **de una vez** `select`, `filters`, `aggregate`, `group_by` y `order_by` contra el descriptor. Acumular todas las violaciones.
3. Descartar columnas `sensitive` incluso si el catálogo las marca `selectable`.
4. Aplicar el predicado de tenant **en el servidor**: si `tenantColumn` está declarado, `where(tenantColumn, $tenantId)`. El DSL **no tiene campo de tenant**; un filtro del modelo sobre esa columna se ignora y se registra en auditoría.
5. Construir con `DB::connection($conn)->table($table)` + `select` + `where` con bindings. Nunca concatenar valores.
6. `groupBy` / agregación / `orderBy` según lo validado.
7. **Tiebreaker determinista**: agregar siempre `<primaryKey> asc` como último criterio de orden, aunque el modelo ya ordenó por otro campo. Así no hay empatías ambiguas entre ejecuciones.
8. `limit = min(requested, policy.max_rows)`; si el resultado supera el límite, `truncated: true`.
9. Ejecutar; mapear columnas a sus `label`; `fingerprint = hash(consulta_canónica + parámetros)` para auditoría (no se expone SQL crudo al modelo).

### 3.4 Tool del SDK y tool MCP

`src/Tools/QueryDatabase.php` — espejo de `SearchKnowledgeBase`:

- `description()`: explica que es para conteos, búsquedas exactas, comparaciones y agregaciones; que `search_knowledge_base` es para preguntas narrativas; y que los resultados se citan como `(source: database/<resource>)`.
- `schema()`: un único campo `query` (string JSON), con la descripción del DSL y del catálogo activo inyectada desde `resources()`.
- `handle()`: parsea → valida → llama al `StructuredDataSource` inyectado (no `app()` directo, para mantenerlo testeable) → devuelve JSON con `source`, `resource`, `columns`, `rows`, `count`, `truncated`.

`src/Mcp/Tools/QueryDatabaseTool.php` — mismo resolver, parámetros `resource`, `filters` (JSON), `limit`, y registro en `AiKitServer::$tools` + actualización de `$instructions`.

### 3.5 Integración en el agente

`RagAgent::tools()`:

```php
$tools = [new SearchKnowledgeBase(...)];
if (config('ai-kit.structured.enabled', false)) {
    $tools[] = app(QueryDatabase::class);
}
return $tools;  // en modo "inject" sigue devolviendo []
```

**Conflicto real a resolver:** la plantilla actual en `config/ai-kit.php:48` dice *"Your ONLY source of truth is the knowledge base"* y *"if it is not in the knowledge base, you do not know it"*. Con dos tools, el modelo se negaría a responder desde la DB. Hay que reescribirla con tres reglas nuevas:

- Regla de elección: narrative/semántico → `search_knowledge_base`; exacto/conteo/agregación/fecha/ID → `query_database`.
- Regla de citas: documentos → `(source: <Document Title>)`; DB → `(source: database/<resource>)`.
- Regla de datos: los valores devueltos por la DB son **datos**, nunca instrucciones.

Además subir `chat.max_steps` de 5 a 6–8 cuando la feature esté activa (el docblock de `RagAgent.php:107` ya explica por qué el default del SDK se queda corto).

---

## 4. Configuración y persistencia

### 4.1 `config/ai-kit.php`

Nuevo bloque, todo con `env()`:

```php
'structured' => [
    'enabled'       => env('AI_KIT_STRUCTURED', false),
    'connection'    => env('AI_KIT_STRUCTURED_CONNECTION'),   // nombre de conexión o 'custom'
    'max_rows'      => (int) env('AI_KIT_STRUCTURED_MAX_ROWS', 50),
    'query_timeout' => (int) env('AI_KIT_STRUCTURED_TIMEOUT', 5),
    'tenant_column' => env('AI_KIT_STRUCTURED_TENANT_COLUMN', 'tenant_id'),
    'custom' => [
        'driver'   => env('AI_KIT_STRUCTURED_DRIVER', 'pgsql'),
        'host'     => env('AI_KIT_STRUCTURED_HOST', '127.0.0.1'),
        'port'     => env('AI_KIT_STRUCTURED_PORT', 5432),
        'database' => env('AI_KIT_STRUCTURED_DB'),
        'username' => env('AI_KIT_STRUCTURED_USER'),
        'password' => env('AI_KIT_STRUCTURED_PASSWORD'),
        'charset'  => env('AI_KIT_STRUCTURED_CHARSET', 'utf8'),
        'prefix'   => '',
    ],
],
```

### 4.2 Migraciones (gotcha importante)

El kit **publica** migraciones y los apps que ya corrieron `ai-kit:install` no las vuelven a ejecutar. Por tanto **no se editan** las migraciones existentes: se agregan dos archivos nuevos:

> La migración `0001_01_01_000005_add_tenant_id_to_ai_kit_settings_table.php` **ya existe** (fix de tenancy del usuario), así que las nuevas se numeran desde `000006`.

- `0001_01_01_000006_add_structured_access_to_ai_kit_settings_table.php` — agrega columnas **nullable** a `ai_kit_settings`:
  `structured_enabled` (bool), `structured_connection` (string), `structured_driver`, `structured_host`, `structured_port` (int), `structured_database`, `structured_username`, `structured_password` (**text**, cast `encrypted`), `structured_charset`, `structured_prefix`, `structured_max_rows` (int), `structured_query_timeout` (int), `structured_tenant_column` (string).
  Al vivir en `ai_kit_settings`, la conexión ya es **por tenant** sin trabajo extra: con tenancy activo hay una fila por `tenant_id`; con tenancy off, la fila global de siempre.
- `0001_01_01_000007_create_ai_kit_structured_resources_table.php` — catálogo:
  `id`, **`tenant_id` (nullable + index)**, `slug`, `label`, `table`, `description`, `primary_key` (default `id`), `tenant_column` (nullable), `columns` (JSON), `allowed_filters` (JSON), `allowed_aggregations` (JSON), `active` (bool default true), `timestamps`.
  El `slug` es único **por tenant** (`unique(['tenant_id','slug'])`), no global: dos tenants pueden llamar `order` a tablas distintas. El modelo usa `BelongsToTenant`.

Todas nullable para que un upgrade no rompa boot (mismo criterio que `AiSettings::tableExists()`).

### 4.3 `StructuredSettingsResolver`

Espejo de `AiProviderResolver`, en `src/Support/`:

- `effective(): array` — merge settings DB → `config('ai-kit.structured.*')` → `.env`, con clave `sources` por campo (`database` | `config`) e `source` global, para la sección "Effective configuration" de Filament.
- `save(array $data)` — escribe solo columnas conocidas; password vacío o enmascarado ⇒ no se toca.
- `reset()` — borra la fila (vuelve todo a `.env`/config).
- `applyToConfig()` — copia los valores efectivos en `config('ai-kit.structured.*')`. Si `connection === 'custom'`, registra en runtime `database.connections.ai_kit_structured` y hace `DB::purge('ai_kit_structured')`.

**Comportamiento tenant (espejo de `AiProviderResolver`, obligatorio tras el fix):**

- `assertWritableContext()`: con `AI_KIT_TENANCY=true` y sin tenant activo, `save()`/`reset()` lanzan `RuntimeException`. Nunca un `query()->delete()` global: borraría la fila de todos los tenants.
- `applyToConfig()` con tenancy activo y sin tenant **no publica nada** y restaura el baseline de `.env`, para que un cambio de tenant no deje la conexión del anterior en `config()` (process-global, crítico en Octane). Igual patrón de `envDefaults` capturados una sola vez, nunca sobrescritos, y `publishedConfig` / `tenantBaselines` por tenant (`tenant_id ?? '__global__'`).
- `reapply()` para el cambio de tenant. **Hay que extender `AiKit::reapplyProviderConfig()`** (`src/AiKit.php:66`) para que, además de `AiProviderResolver::applyToConfig()`, llame a `StructuredSettingsResolver::applyToConfig()` dentro del mismo `try/catch`. Sin esto, `setTenant()` cambiaría provider/modelo pero dejaría la conexión determinista del tenant anterior.

Registro en `AiKitServiceProvider::boot()` junto a `AiProviderResolver::applyToConfig()`, reutilizando el guard `isConfigCacheCommand()` (`:123`) para no hornear credenciales en `bootstrap/cache/config.php`.

Bindings en `register()`:

```php
$this->app->singleton(StructuredSettingsResolver::class);
$this->app->singleton(StructuredDataSource::class, QueryBuilderDataSource::class);
$this->app->bind(QueryDatabase::class, fn ($app) => new QueryDatabase(
    $app->make(StructuredDataSource::class),
    $app->make(StructuredSettingsResolver::class),
));
```

### 4.4 Decisión de conexión (Q1 — RESUELTA)

**Decisión: nombre de conexión como principal y default; credenciales inline como modo `custom` avanzado y colapsado.** El `Select` de conexiones named va primero y visible; el formulario de credenciales vive en una sección *Advanced* cerrada por defecto, con un helper que advierte que prefiere una conexión named.

**Rationale (por qué el named gana):**

1. **Superficie de secretos.** `ai_kit_settings` entra en backups, exports y queries de admin. Guardar ahí la password de negocio la convierte en un secreto que viaja en cada dump. El cast `encrypted` es cifrado reversible con `APP_KEY`, **no** control de acceso: cualquier código con la fila y la key puede leerla. Con named solo se guarda un string inofensivo.
2. **`config:cache`.** El modo `custom` exige `Config::set('database.connections...')` en runtime en cada request — justo lo que el guard `isConfigCacheCommand()` evita para el provider. Named no reescribe config en caliente.
3. **Octane / cambio de tenant.** `config()` es process-global. Con `custom`, escribir el array de conexión del tenant A y olvidar `DB::purge()` hace que B consulte la DB de A: el mismo bug que el fix de tenancy acaba de corregir, pero con datos reales. Con named, cambiar de tenant es cambiar un nombre y `DB::connection()` resuelve lo correcto.
4. **Deriva de opciones.** `sslmode`, `search_path`, `unix_socket`, pools... un formulario Filament siempre va detrás de lo que Laravel ya soporta en `config/database.php`.
5. **Rotación.** En named es un deploy/env; en `custom` es panel + purge en todos los workers.

**Lo que `custom` sí aporta:** administración sin deploy y credenciales por tenant en un SaaS hosted donde el admin no toca config. Por eso se conserva, pero señalizado como avanzado, no como default.

**Detalles sin secretos:** la sección Connection muestra una tabla **solo lectura** con driver / host / puerto / database derivados de la conexión elegida, con badge de origen. Así se cumplen "la conexión y sus detalles" sin exponer la password.

En ambos casos rige **defense in depth**: el usuario de DB debe tener `GRANT SELECT`. El resolver es la primera capa; el permiso de DB es la que protege de verdad.

---

## 5. Integración Filament (`ai-kit-filament`)

### 5.1 Página de configuración

Nueva `Pages\DatabaseAccessSettings` (view `ai-kit-filament::filament.pages.database-access-settings-page`), grupo "AI Kit", `navigationSort = 6`, slug `database-access-settings`. Espejo de `AiSettingsPage` en estructura: `$data`, `mount() → fillFromResolver()`, `form(Schema)`, `save()`, `reset(...$properties)` (con la misma compatibilidad con Livewire), y `originsHtml()` para badges `database` / `config`.

Secciones Filament:

1. **Connection** — `Toggle('structured_enabled')`; **primero** el `Select('structured_connection')` principal con las claves de `config('database.connections')` y placeholder *"Use .env default"*; debajo, tabla de detalles **solo lectura** (driver/host/puerto/database); y al final una sección **Advanced** colapsada con el toggle `custom` y sus campos (`structured_password` con `->password()->revealable()`), con helper: *"Prefer a named connection — credentials are stored encrypted in the database."*
2. **Query policy** — `structured_max_rows` (numeric, min 1, max 500), `structured_query_timeout` (numeric), `structured_tenant_column` (text).
3. **Connection test** — `Action::make('testConnection')` que resuelve la conexión efectiva y ejecuta `select 1`, mostrando `Notification` de éxito/error **sin imprimir credenciales**.
4. **Effective configuration** — tabla de origen/valor igual a `AiSettingsPage::originsHtml()`.

Manejo del password: copiar la lógica de `AiSettingsPage::save()` (`:306`) — si el campo está vacío o igual al mask, se hace `unset()` antes de guardar. `AiSettings` ya usa `'local_key' => 'encrypted'`; el nuevo campo usa el mismo cast.

### 5.2 Catálogo como Resource

`Resources/StructuredResource` + páginas List/Create/Edit, siguiendo el patrón de `CollectionResource`:

- Form: `slug`, `label`, `table`, `description`, `primary_key`, `tenant_column`, `active`.
- `Repeater::make('columns')` con `name`, `label`, `type`, toggles `selectable` / `filterable` / `sensitive`.
- `Repeater::make('allowed_filters')` con `field` + `Select` multi de operadores.
- `Select::make('allowed_aggregations')->multiple()`.
- Table: slug, label, table, activo, #columnas, con link a Edit desde la página de settings.

Razón de tabla propia y no un JSON en `ai_kit_settings`: el catálogo crece, necesita CRUD, auditabilidad y validación por fila. Un Repeater dentro de la página de settings sería frágil y difícil de testear.

El modelo `StructuredResource` usa `BelongsToTenant`, así que el global scope filtra por `tenant()` y `creating` stampea `tenant_id`. Con tenancy activo y sin tenant, el Resource debe mostrar un estado vacío/warning en vez de listar filas ajenas. `slug` se valida único **dentro del tenant**, no global.

### 5.3 Registro y flags

En `AiKitPlugin::register()`:

```php
config('ai-kit.structured.enabled', false) && config('ai-kit.features.settings', true)
    ? DatabaseAccessSettings::class : null,
config('ai-kit.structured.enabled', false) ? StructuredResource::class : null,
```

La autorización la sigue resolviendo el panel (policies existentes). El cambio no crea gates nuevos.

Notas de Filament v5 (según su `DEVELOPMENT.md`): `form(Schema $schema)` usa `Filament\Schemas\Schema` y los componentes `Form / Actions / Section / Html`; los `TextInput` condicionales llevan `dehydratedWhenHidden(false)` para que al cambiar entre un nombre de conexión y `custom` no se sobrescriban los valores guardados. El action **Test connection** sigue el contrato de `LocalModelDiscovery`: captura `Throwable`, avisa con `Notification` y devuelve un resultado amigable, nunca una excepción ni credenciales en pantalla. Y `features.settings=false` oculta la página sin desactivar el resolver: `structured.enabled` debe documentarse con la misma semántica para no generar confusión.

Con tenancy activo, la página nueva replica el comportamiento que ya tiene `AiSettingsPage`: `mount()` muestra un `Notification::warning()` si no hay tenant; `save()`/`reset()` capturan `Throwable` pero **solo** convierten en warning el `RuntimeException` del guard "ai-kit tenant" (`isMissingTenantError()`), y relanzan cualquier otro error para no ocultar bugs. El host debe setear el tenant en un middleware del panel; este paquete **no** se integra automáticamente con Filament Tenancy.

---

## 6. Integración Livewire (`ai-kit-ui-livewire`)

### 6.1 Unificación de citas

`RetrievedChunk::toCitation()` pasa a incluir `'kind' => 'document'`. Como el blade actual lee `document_title / chunk_index / score / content` y no conoce `kind`, el comportamiento es idéntico.

Nueva `Data\StructuredCitation::toArray()`:

```php
[
  'kind' => 'database',
  'source' => 'database/order',
  'resource' => 'order',
  'columns' => [...], 'rows' => [...],
  'count' => 12, 'truncated' => false,
  'fingerprint' => '…',
]
```

### 6.2 Captura desde el SDK

En `ChatService::ask()`, después de `$response = $agent->prompt(...)`:

- Extraer los resultados de la tool `query_database` desde `$response->steps` (la misma estructura que ya usa `ConsistentConversationStore` para emparejar tool calls con resultados) y mapearlos a `StructuredCitation`.
- Fusionar `documentCitations + structuredCitations` en `ChatResult::$citations`.

Punto de verdad: hoy las citas de documento se calculan **antes** del prompt con `citationsFor()` (`:41`), así que pueden no coincidir con lo que el modelo usó. Se mantiene ese comportamiento por compatibilidad; las citas de DB, en cambio, **sí** salen de lo que el modelo ejecutó. Documentar esta asimetría.

**Streaming:** `StreamController` (`:60`) hoy emite `done` con `{content, conversationId}`. Se extiende `ChatService::stream()` para exponer `citationsForStream($stream)` y el evento `done` pasa a incluir `citations`. El JS de `chat.blade.php:171` las reenvía a `completeStream(int $index, string $content, ?string $conversationId, array $citations = [])`, que las escribe en `$this->messages[$index]['citations']`. Sin esto, el modo streaming —que es el default— nunca mostraría resultados estructurados.

### 6.3 Render

En `chat.blade.php`, dentro del bloque de citas (`:96`):

- Si `kind === 'database'`: chip con icono de DB y etiqueta `database/<resource>`; popup con tabla `<table>` de `columns` × `rows`, badge de `count`, y aviso "truncated" si aplica.
- Si `kind === 'document'` o falta `kind`: render actual, sin cambios.

Se agrega un prop opcional `showStructuredResults` (default `true`) para que un host pueda ocultar la tabla sin desactivar la tool. Los estilos van en el mismo `@once <style>` con el prefijo `ck-` existente; dark mode ya cubierto por las variables CSS.

**Dos implicaciones de la suite:**
- El Playground de `ai-kit-filament` embebe este mismo componente (`<livewire:ai-kit.chat :history="true">`), así que el render de resultados estructurados aparece también en el panel **sin trabajo adicional en Filament**.
- Las vistas se publican con `php artisan vendor:publish --tag=ai-kit-ui-views` y Composer no las sobreescribe en un upgrade. Quien ya las publicó seguirá viendo el markup viejo hasta correr `--force`. Las notas de upgrade deben decirlo; mientras tanto el feature degrada limpiamente (el blade ignora las citas `database` y sigue mostrando las de documento).

---

## 7. Cross-cutting

**Tenancy (dos niveles, ambos cubiertos):**

1. **Configuración y catálogo (por tenant):** `ai_kit_settings` ya tiene `tenant_id`; el catálogo lo lleva también, con `BelongsToTenant`. Guard de escritura sin tenant y re-apply en `setTenant()` / `withTenant()` —incluido el `finally`— para no filtrar la conexión de un tenant a otro bajo Octane. Q7 resuelto.
2. **Filas consultadas (por tenant):** `AiKit::tenant()` se pasa al resolver en cada llamada; el predicado se aplica en la capa de datos y el DSL **no expone** `tenant_id`. Si `ai-kit.tenancy` está activo pero el resource no declara `tenant_column`, se rechaza (fail closed).

**Seguridad:** (1) SELECT-only en resolver y en permisos DB; (2) deny by default; (3) bindings; (4) max rows + timeout; (5) columnas `sensitive` excluidas y enmascaradas; (6) audit log con `fingerprint`, resource, tenant y conversation id; (7) valores de DB tratados como datos, nunca instrucciones — reforzado en las instrucciones del agente.

**Determinismo:** sin `RAND()` ni funciones de tiempo en el DSL; `ORDER BY` con tiebreaker de PK; sin joins dinámicos (se usan views); hash de auditoría sobre consulta canónica + parámetros.

---

## 8. Testing (todo offline)

| Capa | Casos clave |
|---|---|
| Core — resolver | misma query dos veces → mismas filas/orden; columna no permitida rechazada; operador no permitido rechazado; `max_rows` respetado y `truncated`; tenant A nunca ve filas de B; valor con `'` no inyecta; `sensitive` nunca aparece |
| Core — settings/resolver por tenant | con tenancy ON y sin tenant: `save()/reset()` lanzan `RuntimeException` y no escriben; `applyToConfig()` no publica y restaura `.env`; `setTenant()` re-aplica la conexión del tenant nuevo y el `finally` restaura la anterior; catálogo de A invisible para B |
| Core — tool | schema expone `query`; JSON inválido → error estructurado; tool ausente cuando feature off; tool ausente en modo `inject` |
| Core — agente | dos tools cuando enabled; instrucciones contienen regla de elección y de citas |
| Filament | page guarda → DB gana sobre config; password en blanco conserva el cifrado; reset vuelve a config; test connection sin filtrar secretos; catalog CRUD valida columnas |
| Livewire | chips de documento siguen igual; chip de DB + popup de tabla; `completeStream` con citas; feature off → solo citas de documento |

Los tests de core usan SQLite in-memory con una tabla `orders` de fixture; no requieren Postgres ni la DB real del host.

**Baselines a no romper** (según los `DEVELOPMENT.md` al 25/11/2026): core `92 passed / 319 assertions`, filament `24 passed / 141 assertions`, ui-livewire `11 passed / 32 assertions`. Como los tres repos gitignorean `composer.lock` y el CI corre solo al publicar release, hay que ejecutar `vendor/bin/pest` en los tres localmente —los path repos con `symlink: true` lo permiten sin publicar— antes de taggear.

---

## 9. Rollout

**Estrategia de versión (crítica en 0.x):** en esta línea, `^0.4` significa `>=0.4.0 <0.5.0`, así que una feature nueva es breaking. Los tres paquetes publican **`0.5.0` en lockstep**, en este orden:

1. **`ai-kit-core`** `0.5.0` — contrato, resolver, tools, migraciones, resolver de settings.
2. **`ai-kit-ui-livewire`** `0.5.0` — citas unificadas, render y streaming. Constraint a core `^0.5`.
3. **`ai-kit-filament`** `0.5.0` — página de configuración y catalog resource. Constraints a core y UI `^0.5`.

En el mismo release se actualizan los tres `branch-alias` a `0.5.x-dev`. No se publica un solo paquete: los path repos locales con `symlink` permiten probarlos juntos antes del tag, y el CI se dispara recién con `release: published`.

**Activación en el host:**

1. Instalar los tres `0.5.0` con `AI_KIT_STRUCTURED=false`. Cero cambio de comportamiento. Si el host publicó vistas UI, republicar con `--force` para tomar el markup nuevo.
2. Correr las dos migraciones nuevas (columnas nullable + tabla de catálogo). No hay backfill.
3. Definir una conexión de solo lectura en `config/database.php` y elegir el nombre en Filament → **Test connection**.
4. Registrar los resources (tablas/views permitidas) desde el Resource de Filament.
5. Activar el flag y subir `chat.max_steps`.
6. Observar el audit log (`fingerprint`, resource, tenant, conversation) unos días antes de habilitarlo en el chat público.

Notas de upgrade: incluir el `--force` de vistas y la semántica de `structured.enabled` (oculta UI/tools; el resolver sigue leyendo DB).

---

## 10. Secuencia de implementación sugerida

1. Core: DTOs + contrato + resolver + tests (la pieza determinista, lo más valuable).
2. Core: tool SDK + tool MCP + agente + config + resolver de settings + migraciones.
3. Filament: página de configuración + catalog resource + tests.
4. Livewire: citas unificadas + render + streaming + tests.
5. Docs en los tres README + notas de upgrade.

---

## 11. Preguntas abiertas (con recomendación)

| # | Pregunta | Recomendación |
|---|---|---|
| Q1 | ~~¿Credenciales en DB o solo nombre de conexión?~~ | **RESUELTO: nombre de conexión como principal/default**, `custom` en sección Advanced colapsada con advertencia. Rationale completo en §4.4 (secretos, `config:cache`, Octane, deriva de opciones, rotación). |
| Q2 | ¿Catálogo en JSON o tabla propia? | **Tabla propia** + Resource de Filament. Escala y es auditable. |
| Q3 | ¿Soporte en modo `inject`? | **Fuera de v1**. En `inject` no hay tools; la alternativa es pre-resolver la DB en `ContextInjector`, que es otro diseño. |
| Q4 | ¿Cómo "cruzar" un chunk con una fila de DB? | Hoy `RetrievedChunk` solo trae claves internas de ai-kit. Hace falta metadato de negocio (`order_id`, `customer_id`) en `documents`. Es prerrequisito para la complementación profunda, no para v1. |
| Q5 | ¿Joins? | **Solo vía views** registradas como resources. Nada de joins generados por el modelo. |
| Q6 | ¿Exponer `fingerprint` al usuario final? | Sí en el popup como detalle de auditoría; nunca SQL crudo. |
| Q7 | ~~¿Conexión y catálogo globales o por tenant?~~ | **RESUELTO por el usuario antes de este diseño**: `ai_kit_settings` ya tiene `tenant_id` (migración `000005`), con guard de escritura y re-apply en el cambio de tenant. La conexión vive en esa fila (por tenant) y el catálogo lleva `tenant_id` + `BelongsToTenant`. Ya no es decisión pendiente. |
