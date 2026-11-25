# Cambio: Acceso determinista a base de datos vía tools (DSL)

**Estado:** Propuesto — pendiente de revisión
**Paquetes afectados:** `ai-kit-core`, `ai-kit-filament`, `ai-kit-ui-livewire`
**Relación:** complementa (no reemplaza) las colecciones RAG existentes

## ¿Por qué?

Las colecciones RAG responden bien preguntas semánticas y narrativas ("¿qué dice la política de reembolsos?"), pero son **aproximadas** y no saben hacer conteos, joins, comparaciones exactas ni agregaciones ("¿cuántos pedidos del cliente X están refunded en Q1?"). Hoy el kit solo expone `SearchKnowledgeBase`, así que ese tipo de pregunta o se alucina o se rechaza.

La salida es un mismo agente con **dos herramientas complementarias**: recuperación semántica para texto no estructurado y acceso estructurado y determinista a la base de datos relacional del host. La palabra **determinista** es el requisito rector: misma entrada → misma consulta → mismo resultado. Por eso se descarta SQL libre generado por el modelo.

## ¿Qué cambia?

1. **Core (`ai-kit-core`)**
   - Nuevo DSL tipado (`StructuredQuery`) con `resource`, `select`, `filters`, `aggregate`, `group_by`, `order_by`, `limit`.
   - Nuevo contrato `StructuredDataSource` + implementación por defecto `QueryBuilderDataSource` (SELECT-only, allowlist, tenant-scope, límites, orden estable).
   - Nueva tool del Laravel AI SDK `Tools\QueryDatabase` y su par MCP `Mcp\Tools\QueryDatabaseTool`.
   - `RagAgent::tools()` pasa a devolver ambas tools cuando la feature está activa y el modo es `tool`.
   - Config `ai-kit.structured` (feature flag desactivada por defecto) y resolución de settings con precedencia DB > config > `.env`.
   - Extensión de `ai_kit_settings` con los detalles de conexión/política + tabla nueva `ai_kit_structured_resources` para el catálogo.

2. **Filament (`ai-kit-filament`)**
   - Nueva página `DatabaseAccessSettings` bajo el grupo "AI Kit", con secciones: **Connection**, **Query policy**, **Effective configuration** y un action **Test connection**.
   - Nuevo Resource `StructuredResource` para administrar el catálogo de entidades/columnas permitidas (Repeater de columnas, operadores y agregaciones).
   - Registro en `AiKitPlugin::register()`, detrás de `config('ai-kit.structured.enabled')` y `features.settings`.
   - Nunca se muestra ni se reenvía la contraseña: se enmascara igual que `local_key` en `AiSettingsPage`.

3. **Livewire (`ai-kit-ui-livewire`)**
   - Las citas pasan a tener un discriminador `kind: document | database`, con backward compatibility (si falta `kind`, se trata como `document`).
   - `ChatService` captura los resultados de la tool `query_database` desde los `steps` de la respuesta del SDK y los fusiona en `ChatResult::$citations`.
   - El componente `Chat` y su blade renderizan un segundo tipo de chip con popup de tabla para resultados estructurados.
   - El Playground de Filament embebe este componente, así que el cambio llega al panel sin UI duplicada.

## Capacidades (deltas de spec)

### `structured-query` (nueva)
- El sistema SHALL exponer un DSL tipado para consultar entidades declaradas en un catálogo.
- El sistema SHALL rechazar toda entidad, columna, operador o agregación no incluida en el catálogo (deny by default).
- El sistema SHALL construir la consulta con query builder y bindings, nunca concatenando valores provistos por el modelo.
- El sistema SHALL garantizar resultados acotados, ordenados de forma estable y determinista.
- El sistema SHALL inyectar el scope de tenant en la capa de datos, nunca desde un parámetro del modelo.

### `structured-connection` (nueva)
- El administrador SHALL poder configurar la conexión (named connection o detalles inline) y la política (max rows, timeout, columna tenant) desde Filament.
- Los valores guardados en DB SHALL prevalecer sobre `config/ai-kit.php` y `.env`, con indicador de origen.
- El sistema SHALL ofrecer un "Test connection" que no exponga credenciales.

### `structured-agent` (nueva)
- `RagAgent` SHALL exponer `SearchKnowledgeBase` y `QueryDatabase` en modo `tool` cuando la feature esté activa.
- Las instrucciones del agente SHALL definir explícitamente cuándo usar cada herramienta y cómo citar cada tipo de fuente.

### `structured-ui` (nueva)
- El chat SHALL renderizar citas de documento y de base de datos con tratamientos visualmente distinguibles.
- SHALL conservar el comportamiento actual cuando la feature esté desactivada o cuando una cita no tenga `kind`.

## Fuera de alcance (v1)

- SQL libre escrito por el modelo.
- Operaciones de escritura (`INSERT` / `UPDATE` / `DELETE` / DDL).
- Descubrimiento automático del esquema de la DB del host.
- Funcionamiento en modo `inject` (no hay tools en ese modo; ver pregunta abierta Q3).
- Reindexación o cambio del pipeline de embeddings.

## Impacto y riesgos

| Riesgo | Mitigación |
|---|---|
| Exposición de credenciales en Filament | `encrypted` cast + enmascarado en UI, mismo patrón que `local_key` |
| Fuga cross-tenant | Predicado de tenant inyectado server-side en las filas; `tenant_id` + `BelongsToTenant` en settings y catálogo; guard de escritura y re-apply de config en el cambio de tenant |
| Prompt injection desde un valor de DB | Los datos se tratan como datos; las instrucciones lo declaran explícitamente |
| Modelo chico elige mal entre dos tools | Feature flag, instrucciones explícitas y `max_steps` ampliado |
| Citas duplicadas o inconsistentes | Unificación en `ChatService`, con `kind` como discriminador |
| Vistas UI ya publicadas por el host no reciben el markup nuevo | Notas de upgrade con `vendor:publish --tag=ai-kit-ui-views --force`; degrada a "sin chip de DB" hasta republicar |
| `0.x` trata el minor como breaking y los paquetes van en lockstep | Publicar `0.5.0` en los tres en orden core → ui → filament, actualizando `branch-alias` y constraints `^0.5` |

## Criterios de aceptación (alto nivel)

- Con la feature apagada, el comportamiento de los tres paquetes es **idéntico** al actual.
- Una misma `StructuredQuery` ejecutada dos veces devuelve el mismo orden y las mismas filas.
- Un filtro sobre columna no permitida es rechazado con error estructurado, no ejecutado.
- Un `tenant_id` distinto nunca puede filtrar filas de otro tenant, aunque el modelo lo pida.
- Con tenancy activo y sin tenant, Filament no escribe y la config no publica la conexión de otro tenant; al cambiar de tenant se re-aplica la conexión correcta (incluido el `finally` de `withTenant()`).
- La suite completa (core + filament + livewire) corre offline, sin keys ni DB externa.

## Preguntas abiertas

Ver sección "Preguntas abiertas" en `design.md`. Las recomendaciones ahí son las que esta propuesta asume por defecto.
