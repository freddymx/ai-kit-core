# Structured Connection Specification

## Purpose

Configuración de la conexión determinista, la política de consulta y el catálogo de resources, con precedencia DB sobre config y aislamiento por tenant.

## Requirements

### Requirement: Named connection is primary

La opción principal SHALL ser un nombre de conexión existente en `config/database.php`. El panel SHALL mostrar los detalles resueltos (driver, host, puerto, database) como solo lectura y MUST NOT mostrar credenciales.

#### Scenario: Admin selects an existing connection

- GIVEN `config/database.php` define `analytics_replica`
- WHEN el admin guarda `structured_connection = analytics_replica`
- THEN el resolver usa `DB::connection('analytics_replica')` y guarda solo el nombre

#### Scenario: Details are visible without secrets

- GIVEN la conexión elegida tiene host y database configurados
- WHEN se abre la sección Connection
- THEN se muestran driver/host/puerto/database con badge de origen y nunca la password

#### Scenario: Named connection test

- GIVEN una conexión named válida
- WHEN se ejecuta **Test connection**
- THEN el sistema ejecuta `SELECT 1` y notifica éxito o error sin imprimir credenciales

### Requirement: Advanced inline credentials

Las credenciales inline SHALL estar solo en una sección avanzada y colapsada, con advertencia. La password SHALL cifrarse en reposo y SHALL enmascararse en la UI.

#### Scenario: Credentials are encrypted and masked

- GIVEN el admin elige `custom` y guarda una password
- WHEN se consulta el modelo y la página
- THEN la columna está cifrada y `effective()` devuelve un valor enmascarado

#### Scenario: Empty or masked password keeps the stored secret

- GIVEN ya existe una password guardada
- WHEN el admin guarda con el campo vacío o con el mask
- THEN la password real se conserva y el mask nunca se persiste

### Requirement: Precedence and origin reporting

Los valores de DB SHALL prevalecer sobre `config/ai-kit.php` y `.env`, y `effective()` SHALL reportar el origen por campo.

#### Scenario: Database value wins

- GIVEN `.env` define `max_rows = 20` y la fila DB define `50`
- WHEN se resuelve la configuración
- THEN el valor efectivo es `50` con origen `database`

#### Scenario: Reset returns to config

- GIVEN existen overrides en DB
- WHEN se ejecuta reset
- THEN se borra la fila y los valores vuelven a config en el mismo request

#### Scenario: config:cache does not bake DB values

- GIVEN se ejecuta `php artisan config:cache`
- WHEN el resolver corre en boot
- THEN no publica valores de DB en la configuración cacheada

### Requirement: Tenant-scoped settings and catalog

La configuración y el catálogo SHALL ser por tenant. Con tenancy activo y sin tenant, las escrituras MUST lanzar `RuntimeException` y `applyToConfig()` MUST NO publicar valores de otro tenant.

#### Scenario: Per-tenant row

- GIVEN tenancy activo y dos tenants
- WHEN cada uno guarda su configuración
- THEN existen dos filas en `ai_kit_settings`, cada una con su `tenant_id`

#### Scenario: No tenant means no write

- GIVEN tenancy activo y sin tenant activo
- WHEN se intenta guardar o resetear
- THEN se lanza `RuntimeException` y la DB no se modifica

#### Scenario: Tenant switch re-applies the connection

- GIVEN el tenant A tiene una conexión y el B otra
- WHEN se cambia de A a B y luego se restaura A
- THEN la conexión efectiva es la de B y luego la de A, incluido el `finally`

#### Scenario: Catalog slug is unique per tenant

- GIVEN dos tenants activos
- WHEN ambos crean un resource `order`
- THEN ambas creaciones son válidas y cada una apunta a su tabla

### Requirement: Connection test never leaks secrets

El action de prueba SHALL capturar `Throwable`, notificar el resultado y MUST NOT imprimir credenciales, siguiendo el contrato de `LocalModelDiscovery`.

#### Scenario: Failed test shows a friendly notification

- GIVEN un host inalcanzable
- WHEN se ejecuta **Test connection**
- THEN se muestra un `Notification::warning()` y no una excepción ni la password
