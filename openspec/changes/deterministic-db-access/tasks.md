# Tasks: Acceso determinista a base de datos vía tools

**Change:** `deterministic-db-access` · **Specs:** `structured-query`, `structured-connection`, `structured-agent`, `structured-ui`
**Regla de release:** los tres paquetes se publican juntos en `0.5.0` (en 0.x el minor es breaking), en orden core → ui-livewire → filament, actualizando `branch-alias` y constraints `^0.5`.

## Phase 1: Core — foundation determinista

- [ ] 1.1 Crear `src/Data/StructuredQuery.php`, `StructuredFilter.php`, `StructuredAggregate.php`, `StructuredOrder.php`, `StructuredResult.php`, `ResourceDescriptor.php`, `ColumnDescriptor.php` como DTOs readonly (spec: structured-query).
- [ ] 1.2 Crear `src/Contracts/StructuredDataSource.php` con `resources()` y `query(StructuredQuery, ?string $tenantId)`.
- [ ] 1.3 Crear `src/Structured/QueryBuilderDataSource.php`: validación contra descriptor, bindings, `groupBy`/agregación, tiebreaker de PK, `max_rows`, `truncated`, `fingerprint`, `sensitive` excluidas.
- [ ] 1.4 Implementar el predicado de tenant server-side y el fail-closed sin tenant (spec: structured-query).
- [ ] 1.5 Agregar bloque `structured` a `config/ai-kit.php` con `AI_KIT_STRUCTURED*` y flag default `false`.
- [ ] 1.6 Crear migración `0001_01_01_000006_add_structured_access_to_ai_kit_settings_table.php` (`000005` ya existe con `tenant_id`).
- [ ] 1.7 Crear migración `0001_01_01_000007_create_ai_kit_structured_resources_table.php` con `tenant_id`, `unique(['tenant_id','slug'])` y columnas JSON del catálogo.
- [ ] 1.8 Crear modelo `src/Models/StructuredResource.php` usando `BelongsToTenant`.
- [ ] 1.9 Crear `src/Support/StructuredSettingsResolver.php`: `effective()`, `sources`, `save()`, `reset()`, `applyToConfig()`, `reapply()`, `assertWritableContext()` y patrón `envDefaults`/`publishedConfig`/`tenantBaselines` por tenant.
- [ ] 1.10 Extender `AiKit::reapplyProviderConfig()` (`src/AiKit.php:66`) para re-aplicar también el resolver estructurado dentro del mismo `try/catch`; si el modo es `custom`, hacer `DB::purge('ai_kit_structured')`.
- [ ] 1.11 Registrar bindings en `AiKitServiceProvider::register()` y `applyToConfig()` en `boot()` con el guard `isConfigCacheCommand()`.
- [ ] 1.12 Definir la conexión named de ejemplo en la documentación con usuario `GRANT SELECT` (spec: structured-connection).

## Phase 2: Core — tools, agente y MCP

- [ ] 2.1 Crear `src/Tools/QueryDatabase.php` implementando `Laravel\Ai\Contracts\Tool`, con `schema()` de un parámetro `query` JSON y `handle()` tipado.
- [ ] 2.2 Hacer que `description()` inyecte el catálogo activo (`resources()`) y la regla de citas `database/<resource>`.
- [ ] 2.3 Modificar `RagAgent::tools()` para agregar `QueryDatabase` solo con feature activa y modo `tool` (spec: structured-agent).
- [ ] 2.4 Reescribir la plantilla `agent.instructions` en `config/ai-kit.php`: regla de elección, citas por tipo y "datos no son instrucciones".
- [ ] 2.5 Ajustar `chat.max_steps` default a 6–8 cuando la feature esté activa.
- [ ] 2.6 Crear `src/Mcp/Tools/QueryDatabaseTool.php` y registrarlo en `AiKitServer::$tools` + `$instructions` (spec: structured-agent).
- [ ] 2.7 Agregar `ChatService::structuredCitations(object $response)` leyendo `steps` del SDK y fusionándolas en `ChatResult::$citations`.
- [ ] 2.8 Agregar `kind: document` a `RetrievedChunk::toCitation()` y crear `Data\StructuredCitation`.

## Phase 3: Filament — configuración y catálogo

- [ ] 3.1 Crear `Pages\DatabaseAccessSettings` con `form(Schema)`, `$data`, `mount()`, `save()`, `reset(...$properties)` y `originsHtml()` (Filament v5, API `Schemas\Schema`).
- [ ] 3.2 Sección **Connection**: Toggle, `Select` named **primero**, tabla de detalles solo lectura, y sección **Advanced** colapsada con `custom` + `dehydratedWhenHidden(false)` (spec: structured-connection).
- [ ] 3.3 Action **Test connection** con contrato de `LocalModelDiscovery`: captura `Throwable`, `Notification`, nunca imprime secretos.
- [ ] 3.4 Sección **Query policy**: `max_rows`, `query_timeout`, `tenant_column`; password con `->password()->revealable()` y manejo de vacío/mask.
- [ ] 3.5 Warning de tenant en `mount()`, `save()` y `reset()`, con `isMissingTenantError()` que solo convierte el `RuntimeException` del guard.
- [ ] 3.6 Crear `Resources/StructuredResource` + páginas List/Create/Edit con Repeater de columnas, filtros y agregaciones; slug único por tenant.
- [ ] 3.7 Registrar page y resource en `AiKitPlugin::register()` detrás de `structured.enabled` y `features.settings`.
- [ ] 3.8 Crear la vista `resources/views/filament/pages/database-access-settings-page.blade.php`.

## Phase 4: Livewire — citas estructuradas y cabecera

- [ ] 4.1 Renderizar chip `kind: database` con popup de tabla en `chat.blade.php`, reutilizando prefijo `ck-` y sin tocar selectores pinneados (spec: structured-ui).
- [ ] 4.2 Extender `done` en `StreamController` con `citations` y pasarlas a `completeStream(index, content, conversationId, citations)` desde el JS `@script`.
- [ ] 4.3 Agregar prop opcional `showStructuredResults` (default `true`) en `Chat.php` sin cambiar las props existentes.
- [ ] 4.4 Verificar que el Playground de Filament hereda el render sin cambios propios.
- [ ] 4.5 Documentar `vendor:publish --tag=ai-kit-ui-views --force` en las notas de upgrade (spec: structured-ui).

## Phase 5: Verificación y documentación

- [ ] 5.1 Tests core del resolver: determinismo, deny-by-default, `max_rows`, bindings, `sensitive`, tenant A/B (baselines: 92 passed / 319 assertions).
- [ ] 5.2 Tests core de settings por tenant: sin tenant no escribe/publica, switch re-aplica, catálogo por tenant, `config:cache`.
- [ ] 5.3 Tests core de tool/agente/MCP: dos tools, feature off, modo inject, instrucciones, MCP parity.
- [ ] 5.4 Tests Filament: save/reset, password mask, named vs custom, test connection, warning de tenant, CRUD del catálogo (baseline: 26 passed / 159 assertions).
- [ ] 5.5 Tests Livewire: chips de ambos tipos, backward compat, streaming con citas, feature off (baseline: 12 passed / 37 assertions).
- [ ] 5.6 Correr `vendor/bin/pest` en los tres paquetes localmente (CI corre solo al publicar release).
- [ ] 5.7 Actualizar los tres `README.md` y los tres `DEVELOPMENT.md` con la nueva feature, flags y rutas de upgrade.
- [ ] 5.8 Actualizar `branch-alias` a `0.5.x-dev` y las constraints `^0.5` en los tres `composer.json`.
- [ ] 5.9 Publicar en orden core → ui-livewire → filament y confirmar que el CI dispara en verde.
