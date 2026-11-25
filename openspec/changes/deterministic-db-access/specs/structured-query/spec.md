# Structured Query Specification

## Purpose

Consultas estructuradas y deterministas sobre la base de datos relacional del host, complementarias a la recuperación vectorial. Toda consulta queda confinada al catálogo declarado.

## Requirements

### Requirement: Typed query DSL

El sistema SHALL aceptar un DSL tipado con `resource`, `select`, `filters`, `aggregate`, `group_by`, `order_by` y `limit`. El sistema MUST rechazar toda columna, operador o agregación no declarada en el descriptor activo.

#### Scenario: Valid query returns rows

- GIVEN un resource activo `order` con columnas permitidas `id`, `status`, `total`, `created_at`
- WHEN se consulta con `status = refunded` y `limit: 20`
- THEN el sistema devuelve filas de la tabla mapeada con solo esas columnas permitidas

#### Scenario: Unknown resource is rejected

- GIVEN no existe un resource activo `secret_table`
- WHEN una consulta apunta a `secret_table`
- THEN el sistema responde `resource_not_found` y no ejecuta SQL

#### Scenario: Disallowed column is reported structurally

- GIVEN `password_hash` no está en el catálogo del resource
- WHEN una consulta lo incluye en `select`
- THEN el sistema responde `validation_failed` con una violación en `select[0]` y sin filas

#### Scenario: Disallowed operator is rejected

- GIVEN los operadores permitidos no incluyen `raw`
- WHEN un filtro usa `op: raw`
- THEN el sistema responde `validation_failed` sin construir la consulta

### Requirement: Deterministic and bounded results

El sistema SHALL agregar la clave primaria como último criterio de orden, SHALL limitar las filas a `max_rows` y MUST NOT exponer funciones no deterministas.

#### Scenario: Repeated query is stable

- GIVEN la misma consulta válida ejecutada dos veces
- WHEN ambas se ejecutan contra datos sin cambios
- THEN devuelven las mismas filas en el mismo orden

#### Scenario: Row cap and truncation flag

- GIVEN `max_rows` configurado en 50 y una consulta sin `limit`
- WHEN la tabla tiene 200 filas que coinciden
- THEN se devuelven como máximo 50 filas y `truncated` es `true`

#### Scenario: Sensitive columns never leak

- GIVEN una columna marcada `sensitive: true`
- WHEN una consulta la pide o filtra por ella
- THEN la columna se excluye del resultado y se registra la violación

### Requirement: Server-side tenant isolation

El sistema SHALL inyectar el predicado de tenant en la capa de datos. El DSL MUST NOT exponer un campo de tenant. Un filtro de tenant provisto por el modelo SHALL ignorarse y auditarse. Con tenancy activo y sin tenant, el sistema MUST fallar cerrado.

#### Scenario: Tenant cannot read another tenant's rows

- GIVEN filas con `tenant_id` `acme` y `globex`
- WHEN el tenant activo es `acme` y ejecuta una consulta
- THEN solo devuelve las filas de `acme`

#### Scenario: Model-supplied tenant filter is ignored

- GIVEN el modelo envía un filtro `tenant_id = globex`
- WHEN el tenant activo es `acme`
- THEN el predicado de `acme` gana y el intento queda en el audit log

#### Scenario: No tenant with tenancy enabled fails closed

- GIVEN `AI_KIT_TENANCY=true` y ningún tenant activo
- WHEN se ejecuta una consulta
- THEN el sistema rechaza la consulta sin acceder a la DB

### Requirement: Injection-safe construction

El sistema SHALL construir la consulta con query builder y bindings; MUST NOT concatenar valores provistos por el modelo.

#### Scenario: Quoted value cannot inject SQL

- GIVEN un filtro con valor `refunded' OR 1=1 --`
- WHEN se ejecuta la consulta
- THEN el valor se envía como binding y no altera el SQL
