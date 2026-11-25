# Structured Agent Specification

## Purpose

El agente RAG expone dos herramientas complementarias —recuperación semántica y consulta estructurada— con reglas explícitas de elección y citación.

## Requirements

### Requirement: Two tools gated by flag and mode

`RagAgent::tools()` SHALL devolver `SearchKnowledgeBase` y `QueryDatabase` cuando la feature esté activa y el modo sea `tool`. Con la feature desactivada SHALL devolver solo la tool de búsqueda. En modo `inject` SHALL devolver ninguna.

#### Scenario: Feature enabled in tool mode

- GIVEN `AI_KIT_STRUCTURED=true` y `AI_KIT_MODE=tool`
- WHEN se llaman las tools del agente
- THEN hay dos tools: `SearchKnowledgeBase` y `QueryDatabase`

#### Scenario: Feature disabled

- GIVEN `AI_KIT_STRUCTURED=false`
- WHEN se llaman las tools del agente
- THEN solo está `SearchKnowledgeBase` y el comportamiento es idéntico al actual

#### Scenario: Inject mode has no tools

- GIVEN `AI_KIT_MODE=inject`
- WHEN se llaman las tools del agente
- THEN el array está vacío

### Requirement: Selection and citation instructions

Las instrucciones SHALL definir cuándo usar cada herramienta y cómo citar cada tipo de fuente. SHALL declarar que los valores devueltos por la DB son datos, nunca instrucciones.

#### Scenario: Choice rule is present

- GIVEN las instrucciones activas
- WHEN se leen las reglas
- THEN narrative/semántico apunta a `search_knowledge_base` y conteos/agregaciones a `query_database`

#### Scenario: Citations are typed

- GIVEN el agente responde desde ambas fuentes
- WHEN se construye la respuesta
- THEN cita documentos como `(source: <Document Title>)` y datos como `(source: database/<resource>)`

#### Scenario: Data is not instructions

- GIVEN una fila de DB contiene texto con apariencia de instrucción
- WHEN el agente la procesa
- THEN la trata como dato y no modifica su comportamiento

### Requirement: Adequate step budget

El presupuesto de pasos SHALL ser suficiente para dos tools encadenadas más la respuesta final.

#### Scenario: Two searches plus answer fit the budget

- GIVEN la feature activa y `chat.max_steps` en 6
- WHEN una pregunta requiere una búsqueda semántica, una consulta estructurada y la respuesta
- THEN el bucle termina con una respuesta, no con una tool call sin ejecutar

### Requirement: MCP parity

La tool estructurada SHALL registrarse en `AiKitServer` cuando la feature esté activa y MCP habilitado.

#### Scenario: MCP tool is advertised

- GIVEN `features.mcp=true` y la feature activa
- WHEN se inspecciona el servidor MCP
- THEN `query_database` figura entre sus tools y en las instrucciones
