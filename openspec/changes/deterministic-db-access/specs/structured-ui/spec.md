# Structured UI Specification

## Purpose

El chat Livewire muestra citas de documento y de base de datos con tratamientos distinguibles, sin romper el comportamiento actual ni el streaming.

## Requirements

### Requirement: Unified citations with kind

Toda cita SHALL incluir un discriminador `kind`. Las citas de documento SHALL conservar su forma actual y las estructuradas SHALL incluir `resource`, `columns`, `rows`, `count` y `truncated`.

#### Scenario: Document citation keeps its shape

- GIVEN una cita de documento con `document_title`, `chunk_index` y `score`
- WHEN se renderiza
- THEN se muestra el chip actual y se agrega `kind: document`

#### Scenario: Structured citation renders a table popup

- GIVEN una cita con `kind: database`
- WHEN se renderiza el mensaje del asistente
- THEN aparece un chip `database/<resource>` y al abrirlo una tabla con columnas y filas

#### Scenario: Citation without kind is backward compatible

- GIVEN una cita guardada sin el campo `kind`
- WHEN se renderiza con la vista nueva
- THEN se trata como documento y no se rompe

### Requirement: Streaming carries citations

El evento `done` SHALL incluir las citas y `completeStream()` SHALL persistirlas en el mensaje.

#### Scenario: Streamed answer shows structured results

- GIVEN streaming activo (default) y una consulta estructurada ejecutada
- WHEN termina el `EventSource`
- THEN el evento `done` incluye `citations` y el mensaje muestra el chip de DB

#### Scenario: Non-streamed path also carries citations

- GIVEN `streaming=false`
- WHEN `ChatService::ask()` responde
- THEN `ChatResult::$citations` contiene ambos tipos de cita

### Requirement: Feature flag preserves current behaviour

Con la feature desactivada, el componente SHALL comportarse exactamente como hoy.

#### Scenario: Feature off renders only document chips

- GIVEN `AI_KIT_STRUCTURED=false`
- WHEN se abre el chat
- THEN no hay chips de DB y las citas existentes se renderizan sin cambios

### Requirement: Published views degrade gracefully

Las vistas publicadas SHALL seguir funcionando hasta republicarse. Las notas de upgrade MUST documentar `vendor:publish --tag=ai-kit-ui-views --force`.

#### Scenario: Host with published views upgrades

- GIVEN el host publicó `chat.blade.php` en una versión anterior
- WHEN actualiza el paquete sin republicar
- THEN el chat sigue funcionando y solo muestra citas de documento

### Requirement: Filament playground parity

El Playground de Filament SHALL heredar el render porque embebe el componente Livewire, sin UI duplicada.

#### Scenario: Playground shows structured citations

- GIVEN el Playground con una collection válida
- WHEN una respuesta usa la tool estructurada
- THEN el panel muestra el chip de DB con el mismo markup que el chat standalone
