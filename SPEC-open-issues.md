# SPEC — Módulo **Open Issues** (SISTEMA OT)

> Especificación **definitiva y cerrada**. Está escrita para que tres agentes
> (`database-sqlserver`, `backend-laravel`, `frontend-react`) la implementen **sin volver a preguntar**.
> Todo lo que en el encargo estaba marcado `[A confirmar]` acá ya está **decidido** (ver §5).
> Si algo no está en esta spec, **no se hace**.

**Repo:** `C:\DATOS\SISTEMA OT` — backend `ordenes-sar/` (Laravel 10 / PHP 8.2 / Passport),
frontend `ot-front/` (React 18 + Vite + AntD + Tailwind). Base productiva: SQL Server `ordenes_sar`.
Tests: sqlite `:memory:` (`ordenes-sar/phpunit.xml`).

### Reglas duras del trabajo (no negociables)
1. **PROHIBIDO** ejecutar cualquier comando de Artisan que recree o vacíe el schema, y cualquier SQL
   destructivo (ver la regla dura del `CLAUDE.md` global). El único comando permitido es
   `php artisan migrate` **a secas**, y lo corre el usuario: el `.env` apunta a base **productiva**.
2. **Ninguna librería nueva** (ni composer ni npm). Se reusa lo que ya hay: AntD, `moment`, `react-icons`,
   `apiFetch`/`buildQuery` de `Utils/otApi.js`, `App\Support\ArchivoOrden`.
3. **No se cambia el comportamiento de OTs ni de HHEE.** Los únicos archivos existentes que se tocan son los
   listados en §4.4 (rutas), §6.6 (front + NotificacionesController) y §2.4 (model `Notificacion`). Nada más.
4. Los tests existentes (`HheeCircuitoTest`, `HheeIntegracionTest`, `DescripcionesAlcanceTest`,
   `EsAsignableFinalizacionTest`, `GroupLeaderNoMantenimientoTest`, `UserEsSeguridadHigieneTest`, Unit)
   deben seguir **verdes sin modificarlos**.
5. El patrón de referencia es el módulo HHEE. Cuando esta spec no diga algo explícitamente, se copia
   **literalmente el estilo** de `SolicitudHheeController` / `HheeFlujo` / `HheeNotificador` / `AlcanceHhee`.

### Glosario de nombres (usar EXACTAMENTE estos)

| Concepto | Nombre |
|---|---|
| Tablas | `oi_issues`, `oi_involucrados`, `oi_actualizaciones` |
| Models | `App\Models\OpenIssue`, `App\Models\OpenIssueInvolucrado`, `App\Models\OpenIssueActualizacion` |
| Soporte | `App\Support\OpenIssueEstados`, `AlcanceOpenIssues`, `OpenIssueFlujo`, `OpenIssueNotificador`, `OpenIssueAutorizacionException` |
| Config | `config/open_issues.php` |
| Controller | `App\Http\Controllers\OpenIssueController` |
| Prefix API | `/api/open-issues` |
| Discriminador de campana | `notificaciones.tipo = 'open_issue'` |
| Ruta front | `/open-issues` |
| Evento global front | `openissues:actualizado` |
| Deep-link | `/open-issues?issue=ID` |
| Prefijo CSS | `oi-` |

---

# 1. Schema exacto

4 migraciones nuevas en `ordenes-sar/database/migrations/`. **Migraciones con clase** (no anónimas), igual
que el resto del proyecto. Sin `CHECK constraints` (convención del repo: los enums se validan en PHP con
`Rule::in()`, nunca en la DB). Todas las FK e índices **nombrados a mano**.

| # | Archivo | Clase |
|---|---|---|
| 1 | `2026_09_18_000001_create_oi_issues_table.php` | `CreateOiIssuesTable` |
| 2 | `2026_09_18_000002_create_oi_involucrados_table.php` | `CreateOiInvolucradosTable` |
| 3 | `2026_09_18_000003_create_oi_actualizaciones_table.php` | `CreateOiActualizacionesTable` |
| 4 | `2026_09_18_000004_add_open_issue_to_notificaciones_table.php` | `AddOpenIssueToNotificacionesTable` |

Cada migración usa `private string $tabla = '...'` y un `down()` con `Schema::dropIfExists($this->tabla)`
(salvo la #4, ver §1.4).

## 1.0 Decisión de cascadas (restricción de SQL Server)

Contexto real del schema existente:

- `users.departamento_id` referencia `departamentos.id` con **ON DELETE CASCADE** (migración
  `2024_09_17_002843_create_users_table.php`). O sea: **ya existe una ruta de cascada
  `departamentos -> users`.**
- `notificaciones`: `orden_trabajo_id`, `usuario_creador_id` y `usuario_mantenimiento_id` son **NO ACTION**;
  `solicitud_hhee_id` es **CASCADE** (desde `hhee_solicitudes`).

SQL Server rechaza (error 1785, "may cause cycles or multiple cascade paths") una FK cuando la tabla
destino queda alcanzable por **más de una** ruta de cascada desde un mismo origen. Decisiones:

| FK | ON DELETE | Por qué |
|---|---|---|
| `oi_involucrados.issue_id` -> `oi_issues` | **CASCADE** | Única ruta de cascada hacia `oi_involucrados`. Borrar un issue limpia sus hijos. |
| `oi_actualizaciones.issue_id` -> `oi_issues` | **CASCADE** | Ídem. |
| `notificaciones.open_issue_id` -> `oi_issues` | **CASCADE** | `oi_issues` **no tiene ningún padre en cascada** (sus FKs a `users` y `departamentos` son NO ACTION), así que no se crea una segunda ruta hacia `notificaciones`: la que ya existe viene de `hhee_solicitudes`, que es otro origen. Es seguro. |
| `oi_issues.creador_id` y `oi_issues.cerrado_por_id` -> `users` | **NO ACTION** | Auditoría: borrar un user no debe arrastrar issues. |
| `oi_issues.departamento_destino_id` -> `departamentos` | **NO ACTION** | Con CASCADE habría dos rutas desde `departamentos` (directa, y vía `users` si `creador_id` fuese cascade) -> 1785. Se corta de raíz. |
| `oi_involucrados.user_id` y `oi_involucrados.agregado_por_id` -> `users` | **NO ACTION** | **Crítico**: CASCADE en `user_id` + CASCADE en `departamento_id` daría dos rutas desde `departamentos` (`departamentos -> oi_involucrados` y `departamentos -> users -> oi_involucrados`) -> 1785 garantizado. |
| `oi_involucrados.departamento_id` -> `departamentos` | **NO ACTION** | Ídem. Es un campo de **procedencia histórica** (snapshot), no una relación viva. |
| `oi_actualizaciones.user_id` -> `users` | **NO ACTION** | Tabla append-only de auditoría. |

Consecuencia aceptada y documentada: **no se puede borrar un `user` ni un `departamento` que tenga
issues / involucrados / actualizaciones**. Es exactamente el mismo comportamiento que ya tiene HHEE
(`fk_hhee_hist_user` sin cascade) y es deliberado.

## 1.1 `oi_issues` (migración `2026_09_18_000001`)

| Columna | Definición Laravel | Tipo SQL Server | Null | Default |
|---|---|---|---|---|
| `id` | `$table->id()` | `bigint IDENTITY` | NO | — |
| `titulo` | `$table->string('titulo', 200)` | `nvarchar(200)` | NO | — |
| `descripcion` | `$table->string('descripcion', 4000)->nullable()` | `nvarchar(4000)` | SÍ | NULL |
| `departamento_destino_id` | `$table->unsignedBigInteger('departamento_destino_id')` | `bigint` | NO | — |
| `creador_id` | `$table->unsignedBigInteger('creador_id')` | `bigint` | NO | — |
| `estado` | `$table->string('estado', 20)->default('abierto')` | `nvarchar(20)` | NO | `abierto` |
| `prioridad` | `$table->string('prioridad', 20)->default('media')` | `nvarchar(20)` | NO | `media` |
| `fecha_cierre` | `$table->dateTime('fecha_cierre')->nullable()` | `datetime` | SÍ | NULL |
| `cerrado_por_id` | `$table->unsignedBigInteger('cerrado_por_id')->nullable()` | `bigint` | SÍ | NULL |
| `fecha_reapertura` | `$table->dateTime('fecha_reapertura')->nullable()` | `datetime` | SÍ | NULL |
| `created_at` / `updated_at` | `$table->timestamps()` | `datetime` | SÍ | NULL |

- `estado` acepta `abierto`, `en_progreso`, `cerrado` (validado en PHP, no en la DB).
- `prioridad` acepta `baja`, `media`, `alta`.
- **Sin** `deleted_at`: no hay soft deletes en este módulo.

FKs (dentro del `Schema::create`, patrón de `2026_08_25_000002`):

```php
$table->foreign('departamento_destino_id', 'fk_oi_issue_depto_destino')->references('id')->on('departamentos');
$table->foreign('creador_id',              'fk_oi_issue_creador')->references('id')->on('users');
$table->foreign('cerrado_por_id',          'fk_oi_issue_cerrado_por')->references('id')->on('users');
```

Sin `->onDelete()` = NO ACTION, igual que HHEE.

Índices (en un `Schema::table` posterior, como en HHEE):

```php
$table->index(['estado', 'created_at'],              'ix_oi_issues_estado_created');
$table->index(['departamento_destino_id', 'estado'], 'ix_oi_issues_depto_estado');
$table->index('creador_id',                          'ix_oi_issues_creador');
```

## 1.2 `oi_involucrados` (migración `2026_09_18_000002`)

| Columna | Definición Laravel | Null | Notas |
|---|---|---|---|
| `id` | `$table->id()` | NO | |
| `issue_id` | `$table->unsignedBigInteger('issue_id')` | NO | FK cascade |
| `user_id` | `$table->unsignedBigInteger('user_id')` | NO | FK `users`, NO ACTION |
| `origen` | `$table->string('origen', 20)` | NO | `creador`, `manual` o `departamento` |
| `departamento_id` | `$table->unsignedBigInteger('departamento_id')->nullable()` | SÍ | solo si `origen = departamento`: de qué depto se expandió (snapshot) |
| `agregado_por_id` | `$table->unsignedBigInteger('agregado_por_id')` | NO | quién lo agregó (si `origen = creador`, el propio creador) |
| `created_at` | `$table->dateTime('created_at')->nullable()` | SÍ | **sin `updated_at`** (append-only) |

```php
$table->foreign('issue_id',        'fk_oi_inv_issue')->references('id')->on('oi_issues')->onDelete('cascade');
$table->foreign('user_id',         'fk_oi_inv_user')->references('id')->on('users');
$table->foreign('departamento_id', 'fk_oi_inv_depto')->references('id')->on('departamentos');
$table->foreign('agregado_por_id', 'fk_oi_inv_agregado_por')->references('id')->on('users');
```

```php
$table->unique(['issue_id', 'user_id'], 'uq_oi_inv_issue_user'); // invariante: 1 fila por (issue, user)
$table->index('user_id',                'ix_oi_inv_user');       // resuelve "en qué issues participo"
```

`uq_oi_inv_issue_user` ya cubre los filtros por `issue_id` (es la columna líder): no hace falta otro índice.

## 1.3 `oi_actualizaciones` (migración `2026_09_18_000003`)

Tabla **append-only**: la app nunca hace UPDATE ni DELETE sobre ella.

| Columna | Definición Laravel | Null | Notas |
|---|---|---|---|
| `id` | `$table->id()` | NO | |
| `issue_id` | `$table->unsignedBigInteger('issue_id')` | NO | FK cascade |
| `user_id` | `$table->unsignedBigInteger('user_id')` | NO | autor; FK `users` NO ACTION |
| `tipo` | `$table->string('tipo', 30)` | NO | vocabulario cerrado (abajo) |
| `texto` | `$table->string('texto', 4000)->nullable()` | SÍ | |
| `estado_anterior` | `$table->string('estado_anterior', 20)->nullable()` | SÍ | |
| `estado_nuevo` | `$table->string('estado_nuevo', 20)->nullable()` | SÍ | |
| `archivo` | `$table->string('archivo', 255)->nullable()` | SÍ | nombre devuelto por `ArchivoOrden::store()` |
| `mime_type` | `$table->string('mime_type', 150)->nullable()` | SÍ | |
| `created_at` | `$table->dateTime('created_at')->nullable()` | SÍ | **sin `updated_at`** |

Vocabulario de `tipo` (8 valores, cerrado): `apertura`, `comentario`, `cambio_estado`, `cierre`,
`reapertura`, `involucrado_agregado`, `involucrado_quitado`, `edicion`.

```php
$table->foreign('issue_id', 'fk_oi_act_issue')->references('id')->on('oi_issues')->onDelete('cascade');
$table->foreign('user_id',  'fk_oi_act_user')->references('id')->on('users');
```

```php
$table->index(['issue_id', 'id'],         'ix_oi_act_issue_id');      // timeline ordenado
$table->index(['issue_id', 'created_at'], 'ix_oi_act_issue_created'); // MAX(created_at) del listado (withMax)
```

## 1.4 `notificaciones` (migración `2026_09_18_000004`, dos caminos)

Estado actual de la tabla (después de `2026_08_25_000006_add_hhee_to_notificaciones_table.php`):
`id`, `orden_trabajo_id` (nullable, FK `fk_notif_orden_trabajo` NO ACTION), `usuario_creador_id`
(= **destinatario**), `usuario_mantenimiento_id` (= **actor**), `estado_anterior` **NOT NULL**,
`estado_nuevo` **NOT NULL**, `leido` (default 0), `mensaje` (text nullable), `tipo` `nvarchar(20)` default
`'ot'`, `solicitud_hhee_id` (nullable, FK `fk_notif_solicitud_hhee` CASCADE, índice
`ix_notif_solicitud_hhee`), `timestamps`, índice `ix_notif_usuario_leido`.

Esta migración **solo agrega la columna `open_issue_id`**. NO toca `tipo` (ya existe, es `nvarchar(20)`,
entra `open_issue` que son 10 chars, y **no hay CHECK** que restrinja valores), NO toca `orden_trabajo_id`
(ya es nullable), NO toca los índices existentes.

Esqueleto (espejo de la migración HHEE):

```php
class AddOpenIssueToNotificacionesTable extends Migration
{
    private string $tabla = 'notificaciones';
    private string $fkOpenIssue = 'fk_notif_open_issue';

    public function up()
    {
        if (DB::getDriverName() === 'sqlsrv') { $this->upSqlsrv(); } else { $this->upGenerico(); }
    }

    public function down()
    {
        if (DB::getDriverName() === 'sqlsrv') { $this->downSqlsrv(); } else { $this->downGenerico(); }
    }
}
```

**`upSqlsrv()`** (producción) — `Schema::table` separados (columna primero, después FK e índice), igual
que el precedente:

```php
Schema::table($this->tabla, function (Blueprint $table) {
    $table->unsignedBigInteger('open_issue_id')->nullable();
});

Schema::table($this->tabla, function (Blueprint $table) {
    // ON DELETE CASCADE es seguro acá: oi_issues no tiene padres en cascada, así que
    // no se genera una segunda ruta de cascada hacia notificaciones (ver §1.0).
    $table->foreign('open_issue_id', $this->fkOpenIssue)
        ->references('id')->on('oi_issues')->onDelete('cascade');

    $table->index('open_issue_id', 'ix_notif_open_issue');
});
```

**`upGenerico()`** (sqlite de tests, mysql del `.env.example`) — misma forma, con la FK en su **propio**
`Schema::table` y el comentario explícito:

```php
Schema::table($this->tabla, function (Blueprint $table) {
    $table->unsignedBigInteger('open_issue_id')->nullable();
    $table->index('open_issue_id', 'ix_notif_open_issue');
});

// En sqlite, Illuminate\Database\Schema\Grammars\SQLiteGrammar::compileForeign() devuelve
// null ("Handled on table creation"): agregar una FK a una tabla YA existente es un no-op
// silencioso, no un error (Blueprint::toSql descarta los comandos que compilan a null).
// En mysql sí se crea. Los tests no dependen de que la FK exista: la integridad la
// garantizan App\Support\OpenIssueFlujo / OpenIssueNotificador.
Schema::table($this->tabla, function (Blueprint $table) {
    $table->foreign('open_issue_id', $this->fkOpenIssue)
        ->references('id')->on('oi_issues')->onDelete('cascade');
});
```

A diferencia de la migración HHEE, el camino genérico **no reconstruye la tabla**: aquella tenía que
hacerlo porque cambiaba `orden_trabajo_id` a nullable; acá solo se agrega una columna, y
`ALTER TABLE ADD COLUMN` sí lo soporta sqlite.

**`downSqlsrv()`**: `dropForeign('fk_notif_open_issue')` -> `dropIndex('ix_notif_open_issue')` ->
`dropColumn('open_issue_id')`. La columna no tiene default constraint, así que **no** hace falta el
barrido de `sys.default_constraints`.

**`downGenerico()`**: sqlite en Laravel 10 no puede `dropColumn` sin `doctrine/dbal` (no instalado), así que
se reconstruye la tabla con el esquema **post-HHEE / pre-OpenIssues**, descartando las filas
`tipo = 'open_issue'` (misma técnica y mismos comentarios que el `downGenerico()` de la migración HHEE):

1. `Schema::create('notificaciones_preoi_tmp', ...)` con: `id`; `orden_trabajo_id` nullable + FK
   `fk_notif_orden_trabajo` NO ACTION; `usuario_creador_id` y `usuario_mantenimiento_id` con FK NO ACTION;
   `estado_anterior`; `estado_nuevo`; `leido` default false; `mensaje` text nullable; `tipo` default `'ot'`;
   `solicitud_hhee_id` nullable + FK `fk_notif_solicitud_hhee` CASCADE; `timestamps`; índices
   `ix_notif_solicitud_hhee` y `ix_notif_usuario_leido`.
2. `INSERT INTO notificaciones_preoi_tmp (...) SELECT ... FROM notificaciones WHERE tipo != 'open_issue'`.
3. `Schema::drop('notificaciones')` + `Schema::rename('notificaciones_preoi_tmp', 'notificaciones')`.

> El `down()` no se usa nunca en este proyecto (rollback prohibido). Se escribe igual por completitud y
> simetría con el precedente.

---

# 2. Modelos Eloquent

Los tres modelos nuevos van en `ordenes-sar/app/Models/`.

## 2.1 `App\Models\OpenIssue`

```php
use HasFactory;                 // consistencia con el resto de los models (NO se crea el archivo de factory)

protected $table = 'oi_issues';

protected $fillable = [
    'titulo', 'descripcion', 'departamento_destino_id', 'creador_id',
    'estado', 'prioridad', 'fecha_cierre', 'cerrado_por_id', 'fecha_reapertura',
];

protected $casts = [
    'fecha_cierre'     => 'datetime',
    'fecha_reapertura' => 'datetime',
];
```

| Relación | Tipo | Definición |
|---|---|---|
| `departamentoDestino()` | `BelongsTo` | `Departamento::class, 'departamento_destino_id'` |
| `creador()` | `BelongsTo` | `User::class, 'creador_id'` |
| `cerradoPor()` | `BelongsTo` | `User::class, 'cerrado_por_id'` |
| `involucrados()` | `HasMany` | `OpenIssueInvolucrado::class, 'issue_id'` con `->orderBy('id')` |
| `actualizaciones()` | `HasMany` | `OpenIssueActualizacion::class, 'issue_id'` con `->orderBy('id')` |

Método de conveniencia (lo usan `AlcanceOpenIssues` y los flags):

```php
public function tieneInvolucrado(int $userId): bool
{
    return $this->involucrados()->where('user_id', $userId)->exists();
}
```

## 2.2 `App\Models\OpenIssueInvolucrado`

```php
protected $table = 'oi_involucrados';
public $timestamps = false;     // append-only: solo created_at, seteado a mano por OpenIssueFlujo
protected $fillable = ['issue_id', 'user_id', 'origen', 'departamento_id', 'agregado_por_id', 'created_at'];
protected $casts = ['created_at' => 'datetime'];

public const ORIGEN_CREADOR = 'creador';
public const ORIGEN_MANUAL = 'manual';
public const ORIGEN_DEPARTAMENTO = 'departamento';
```

Relaciones: `issue()` (`BelongsTo OpenIssue, 'issue_id'`), `usuario()` (`BelongsTo User, 'user_id'`),
`departamento()` (`BelongsTo Departamento, 'departamento_id'`), `agregadoPor()`
(`BelongsTo User, 'agregado_por_id'`).

## 2.3 `App\Models\OpenIssueActualizacion`

```php
protected $table = 'oi_actualizaciones';
public $timestamps = false;
protected $fillable = [
    'issue_id', 'user_id', 'tipo', 'texto',
    'estado_anterior', 'estado_nuevo', 'archivo', 'mime_type', 'created_at',
];
protected $casts = ['created_at' => 'datetime'];
```

Relaciones: `issue()` (`BelongsTo OpenIssue, 'issue_id'`), `autor()` (`BelongsTo User, 'user_id'`).

**No** lleva accessor `archivo_url`: esa URL la arma el controller con `ArchivoOrden::url()`, que depende de
`request()` y no debe ejecutarse en consola ni en jobs.

## 2.4 Modelo existente que se toca: `App\Models\Notificacion`

Único cambio: agregar `'open_issue_id'` al `$fillable` (al lado de `'tipo'` y `'solicitud_hhee_id'`) y la
relación:

```php
// Relación con el Open Issue (tipo = 'open_issue')
public function openIssue()
{
    return $this->belongsTo(OpenIssue::class, 'open_issue_id');
}
```

**Nada más.** No se tocan `$casts`, ni las otras relaciones, ni `User`, ni `Departamento`.

---

# 3. Clases de soporte

Las cuatro en `ordenes-sar/app/Support/`, más la excepción. Todas con docblock de clase explicando las
invariantes (mismo estilo que el módulo HHEE).

## 3.1 `App\Support\OpenIssueAutorizacionException`

Copia literal de `HheeAutorizacionException` (extiende `\RuntimeException`, cuerpo vacío, docblock
adaptado). El controller la atrapa y responde `response()->json(['error' => $e->getMessage()], 403)`.

## 3.2 `App\Support\OpenIssueEstados`

Única fuente de verdad del vocabulario del módulo (estados, prioridades y tipos de actualización).

```php
public const ABIERTO     = 'abierto';
public const EN_PROGRESO = 'en_progreso';
public const CERRADO     = 'cerrado';

public const ESTADOS_LABELS = [
    self::ABIERTO     => ['label' => 'Abierto',     'color' => 'gold'],
    self::EN_PROGRESO => ['label' => 'En progreso', 'color' => 'blue'],
    self::CERRADO     => ['label' => 'Cerrado',     'color' => 'green'],
];

// Máquina de estados ABSTRACTA. Quién puede disparar cada transición lo decide OpenIssueFlujo.
public const TRANSICIONES = [
    self::ABIERTO     => [self::EN_PROGRESO, self::CERRADO],
    self::EN_PROGRESO => [self::ABIERTO, self::CERRADO],
    self::CERRADO     => [self::ABIERTO],   // solo por POST /{id}/reabrir
];

public const TERMINALES = [self::CERRADO];

// Estados que POST /{id}/actualizaciones acepta en 'nuevo_estado'. Cerrar y reabrir
// tienen endpoint propio y NO se pueden pedir por ahí.
public const ESTADOS_MANUALES = [self::ABIERTO, self::EN_PROGRESO];

// Mismos colores de tag que App\Support\PrioridadOT (alta=orange, media=blue, baja=default),
// para que el sistema se vea consistente entre módulos.
public const PRIORIDADES_LABELS = [
    'baja'  => ['label' => 'Baja',  'color' => 'default'],
    'media' => ['label' => 'Media', 'color' => 'blue'],
    'alta'  => ['label' => 'Alta',  'color' => 'orange'],
];

public const TIPO_APERTURA = 'apertura';
public const TIPO_COMENTARIO = 'comentario';
public const TIPO_CAMBIO_ESTADO = 'cambio_estado';
public const TIPO_CIERRE = 'cierre';
public const TIPO_REAPERTURA = 'reapertura';
public const TIPO_INVOLUCRADO_AGREGADO = 'involucrado_agregado';
public const TIPO_INVOLUCRADO_QUITADO = 'involucrado_quitado';
public const TIPO_EDICION = 'edicion';

public const TIPOS_ACTUALIZACION_LABELS = [
    self::TIPO_APERTURA => 'Apertura',
    self::TIPO_COMENTARIO => 'Comentario',
    self::TIPO_CAMBIO_ESTADO => 'Cambio de estado',
    self::TIPO_CIERRE => 'Cierre',
    self::TIPO_REAPERTURA => 'Reapertura',
    self::TIPO_INVOLUCRADO_AGREGADO => 'Involucrado agregado',
    self::TIPO_INVOLUCRADO_QUITADO => 'Involucrado quitado',
    self::TIPO_EDICION => 'Edición',
];
```

Métodos estáticos (mismas firmas y semántica que `HheeEstados`):

| Método | Devuelve |
|---|---|
| `transicionesPermitidas(string $estado): array` | `TRANSICIONES[$estado] ?? []` |
| `puedeTransicionar(string $de, string $a): bool` | `in_array($a, self::transicionesPermitidas($de), true)` |
| `esTerminal(string $estado): bool` | `in_array($estado, self::TERMINALES, true)` |
| `estados(): array` | `array_keys(self::ESTADOS_LABELS)` |
| `prioridades(): array` | `array_keys(self::PRIORIDADES_LABELS)` |
| `catalogo(): array` | lista de `['value' => ..., 'label' => ..., 'color' => ...]` de estados |
| `catalogoPrioridades(): array` | ídem para prioridades |
| `label(string $estado): string` | `self::ESTADOS_LABELS[$estado]['label'] ?? $estado` (lo usan los mensajes de campana) |

## 3.3 `App\Support\AlcanceOpenIssues`

Espejo de `AlcanceHhee` / `AlcanceOrdenes`: **lectura amplia, escritura acotada** (mismo criterio que
`AlcanceOrdenes` con Seguridad e Higiene).

```php
public static function veTodos(User $usuario): bool
{
    return in_array($usuario->rol, config('open_issues.roles_ven_todo', ['gerente', 'admin']), true);
}

public static function aplicar(Builder $query, User $usuario): Builder
{
    if (self::veTodos($usuario)) {
        return $query;
    }

    return $query->where(function (Builder $q) use ($usuario) {
        $q->where('creador_id', $usuario->id)
            ->orWhere('departamento_destino_id', $usuario->departamento_id)
            ->orWhereHas('involucrados', fn (Builder $qq) => $qq->where('user_id', $usuario->id));
    });
}

public static function puedeVer(User $usuario, OpenIssue $issue): bool
{
    if (self::veTodos($usuario)) return true;
    if ((int) $issue->creador_id === (int) $usuario->id) return true;
    if ((int) $issue->departamento_destino_id === (int) $usuario->departamento_id) return true;

    return self::esInvolucrado($usuario, $issue);
}

public static function puedeEscribir(User $usuario, OpenIssue $issue): bool
{
    // Los gerentes NO heredan escritura de su alcance global de lectura (mismo criterio
    // que AlcanceOrdenes con SyH: ve mucho, escribe poco).
    if ((int) $issue->departamento_destino_id === (int) $usuario->departamento_id) return true;

    return self::esInvolucrado($usuario, $issue);
}

public static function esInvolucrado(User $usuario, OpenIssue $issue): bool
{
    return $issue->involucrados()->where('user_id', $usuario->id)->exists();
}

public static function aplicarParticipo(Builder $query, User $usuario): Builder
{
    return $query->whereHas('involucrados', fn (Builder $q) => $q->where('user_id', $usuario->id));
}
```

> El creador **siempre** tiene fila en `oi_involucrados` (§5.1), así que `puedeEscribir()` ya lo cubre sin
> cláusula especial. El `orWhere('creador_id', ...)` de `aplicar()` es redundante a propósito: blinda
> contra cualquier issue corrupto que quedara sin fila de creador.

## 3.4 `App\Support\OpenIssueFlujo`

**Única** clase que muta `oi_issues`, `oi_involucrados` y `oi_actualizaciones`. El controller **nunca**
escribe esas tablas. Toda mutación va dentro de `DB::transaction`. Las que cambian estado releen la fila con
`lockForUpdate()` y revalidan la transición sobre esa fila (copiar el helper `conLock()` de `HheeFlujo`
**junto con su docblock de concurrencia**, adaptado).

Errores:

- Autorización -> `OpenIssueAutorizacionException` (el controller la mapea a **403**).
- Estado o datos -> `ValidationException::withMessages([...])` (**422**).

### API pública

```php
public static function crear(array $datos, User $actor, array $adjunto = []): OpenIssue
public static function actualizar(OpenIssue $issue, array $datos, User $actor): OpenIssue
public static function agregarActualizacion(OpenIssue $issue, array $datos, User $actor, array $adjunto = []): OpenIssueActualizacion
public static function cerrar(OpenIssue $issue, User $actor, ?string $texto = null): OpenIssue
public static function reabrir(OpenIssue $issue, User $actor, ?string $texto = null): OpenIssue
public static function agregarInvolucrados(OpenIssue $issue, array $userIds, array $departamentoIds, User $actor): array
public static function quitarInvolucrado(OpenIssue $issue, int $userId, User $actor): void

/** Guard reutilizable: lanza OpenIssueAutorizacionException si el usuario no puede escribir. */
public static function asegurarPuedeEscribir(OpenIssue $issue, User $actor): void

/** Guard: lanza ValidationException si el issue está cerrado. */
public static function asegurarNoCerrado(OpenIssue $issue, string $mensaje): void
```

`$adjunto` es `['archivo' => ?string, 'mime_type' => ?string]`, **ya guardado en disco por el controller**
con `ArchivoOrden::store()` (igual que `DescripcionController::store()`). **El Flujo no toca el filesystem.**

### `crear(array $datos, User $actor, array $adjunto = [])`

`$datos`: `titulo`, `descripcion?`, `departamento_destino_id`, `prioridad?`, `involucrados_ids?`,
`departamentos_ids?`, `texto_inicial?`.

1. Todo dentro de `DB::transaction`.
2. `OpenIssue::create([...])` con `estado = ABIERTO`, `creador_id = $actor->id` y
   `prioridad = $datos['prioridad'] ?? config('open_issues.prioridad_default')`.
3. Fila del creador en `oi_involucrados`: `origen = ORIGEN_CREADOR`, `departamento_id = null`,
   `agregado_por_id = $actor->id`, `created_at = now()`.
4. `$r = self::insertarInvolucrados($issue, $datos['involucrados_ids'] ?? [], $datos['departamentos_ids'] ?? [], $actor)`
   (helper privado; misma lógica que `agregarInvolucrados()` pero **sin** registrar actualización ni notificar).
5. `registrarActualizacion($issue, $actor, TIPO_APERTURA, null, null, ABIERTO)`.
6. Si `!empty($datos['texto_inicial'])` **o** hay `$adjunto['archivo']`:
   `registrarActualizacion($issue, $actor, TIPO_COMENTARIO, $datos['texto_inicial'] ?? null, null, null, $adjunto)`.
7. `OpenIssueNotificador::notificarCreado($issue, $actor, $r['agregados_users'])`.
8. Devuelve `$issue->fresh()`.

### `actualizar(OpenIssue $issue, array $datos, User $actor)`

`$datos`: `titulo`, `descripcion?`, `prioridad?`, `departamento_destino_id?`.

1. Si `(int) $issue->creador_id !== (int) $actor->id` -> `OpenIssueAutorizacionException('Solo el creador puede editar este issue.')`.
2. `asegurarNoCerrado($issue, 'No se puede editar un issue cerrado. Reabrilo primero.')`.
3. En transacción: calcular `$cambios` comparando los valores **antes** del update, en este **orden
   canónico** de labels: `titulo` -> `título`, `descripcion` -> `descripción`, `prioridad` -> `prioridad`,
   `departamento_destino_id` -> `departamento destino`. Aplicar el update. Si `$cambios` no está vacío:
   `registrarActualizacion($issue, $actor, TIPO_EDICION, 'Editó: ' . implode(', ', $cambios))`.
   Si no cambió nada, **no** se registra fila.
4. **No notifica** (ver §3.5).
5. Devuelve `$issue->fresh()`.

### `agregarActualizacion(OpenIssue $issue, array $datos, User $actor, array $adjunto = [])`

`$datos`: `texto?`, `nuevo_estado?`.

1. `asegurarPuedeEscribir($issue, $actor)`.
2. En transacción: `$actual = self::conLock($issue);`
3. `asegurarNoCerrado($actual, 'El issue está cerrado: reabrilo para poder agregar actualizaciones.')`.
4. Normalizar: `$texto = trim((string) ($datos['texto'] ?? '')) ?: null;` y
   `$nuevoEstado = $datos['nuevo_estado'] ?? null;`. Si `$nuevoEstado === $actual->estado` ->
   `$nuevoEstado = null` (idempotente: no genera fila de cambio de estado).
5. Si `$texto === null` y no hay `$adjunto['archivo']` y `$nuevoEstado === null` -> 422
   `['texto' => 'La actualización debe tener texto, un archivo o un cambio de estado.']`.
6. Si `$nuevoEstado !== null`: tiene que estar en `ESTADOS_MANUALES` **y** cumplir
   `puedeTransicionar($actual->estado, $nuevoEstado)`; si no -> 422
   `['nuevo_estado' => 'Transición de estado inválida.']`. Si valida:
   `$estadoAnterior = $actual->estado;`, `$actual->update(['estado' => $nuevoEstado]);`,
   `$tipo = TIPO_CAMBIO_ESTADO;`. Si `$nuevoEstado === null`: `$tipo = TIPO_COMENTARIO;`,
   `$estadoAnterior = null;`.
7. `$act = registrarActualizacion($actual, $actor, $tipo, $texto, $estadoAnterior, $nuevoEstado, $adjunto);`
8. `OpenIssueNotificador::notificarActualizacion($actual, $actor, $act);`
9. Devuelve `$act`.

### `cerrar(OpenIssue $issue, User $actor, ?string $texto = null)`

1. `asegurarPuedeEscribir`, después transacción + `conLock`.
2. Si `!puedeTransicionar($actual->estado, CERRADO)` -> 422 `['estado' => 'El issue ya está cerrado.']`.
3. `$estadoAnterior = $actual->estado;` y update
   `['estado' => CERRADO, 'fecha_cierre' => now(), 'cerrado_por_id' => $actor->id]`.
4. `registrarActualizacion(..., TIPO_CIERRE, $texto, $estadoAnterior, CERRADO)`.
5. `OpenIssueNotificador::notificarCierre($actual, $actor, $estadoAnterior)` y `return $actual->fresh();`

### `reabrir(OpenIssue $issue, User $actor, ?string $texto = null)`

1. `asegurarPuedeEscribir`, después transacción + `conLock`.
2. Si `$actual->estado !== CERRADO` -> 422 `['estado' => 'Solo se puede reabrir un issue cerrado.']`.
3. Update `['estado' => ABIERTO, 'fecha_reapertura' => now(), 'fecha_cierre' => null, 'cerrado_por_id' => null]`.
   El histórico de cierres vive en `oi_actualizaciones`; estas columnas reflejan **el cierre vigente**.
4. `registrarActualizacion(..., TIPO_REAPERTURA, $texto, CERRADO, ABIERTO)`.
5. `OpenIssueNotificador::notificarReapertura($actual, $actor)` y `return $actual->fresh();`

### `agregarInvolucrados(OpenIssue $issue, array $userIds, array $departamentoIds, User $actor)`

1. `asegurarPuedeEscribir`.
2. `asegurarNoCerrado($issue, 'No se pueden modificar los involucrados de un issue cerrado.')`.
3. En transacción: `$r = self::insertarInvolucrados($issue, $userIds, $departamentoIds, $actor);`
4. Si `$r['agregados']` está vacío: **no** registra actualización ni notifica, y devuelve igual
   (es 200, no un error).
5. Si hay agregados: `registrarActualizacion($issue, $actor, TIPO_INVOLUCRADO_AGREGADO, $textoLote)`, con
   `$textoLote = 'Involucró a: ' . implode(', ', $nombres)`. Si `$departamentoIds` no está vacío se le
   concatena ` - departamentos: ` + los nombres de depto. Truncado con `Str::limit($textoLote, 3999)`.
6. `OpenIssueNotificador::notificarInvolucradosAgregados($issue, $actor, $r['agregados_users'])`.
7. Devuelve `['agregados' => (int[]), 'ignorados' => (int[]), 'agregados_users' => (Collection de User)]`.

### `insertarInvolucrados()` (privado) — duplicados y snapshot (§5.2 y §5.3)

```php
$existentes = $issue->involucrados()->pluck('user_id')->map(fn ($v) => (int) $v)->all();
$agregados = [];
$ignorados = [];

// 1) Personas sueltas PRIMERO: 'manual' le gana a 'departamento' si un id viene por los dos lados.
foreach (array_unique(array_map('intval', $userIds)) as $uid) {
    if (in_array($uid, $existentes, true)) { $ignorados[] = $uid; continue; }
    // OpenIssueInvolucrado::create(origen=ORIGEN_MANUAL, departamento_id=null,
    //                              agregado_por_id=$actor->id, created_at=now())
    $existentes[] = $uid;
    $agregados[] = $uid;
}

// 2) Expansión de departamentos: SNAPSHOT de los users que pertenecen HOY a ese depto.
foreach (array_unique(array_map('intval', $departamentoIds)) as $deptoId) {
    foreach (User::where('departamento_id', $deptoId)->pluck('id') as $uid) {
        $uid = (int) $uid;
        if (in_array($uid, $existentes, true)) { $ignorados[] = $uid; continue; }
        // OpenIssueInvolucrado::create(origen=ORIGEN_DEPARTAMENTO, departamento_id=$deptoId,
        //                              agregado_por_id=$actor->id, created_at=now())
        $existentes[] = $uid;
        $agregados[] = $uid;
    }
}

if (count($agregados) > config('open_issues.max_involucrados_por_lote', 200)) {
    throw ValidationException::withMessages([
        'involucrados' => 'Demasiados involucrados en una sola operación.',
    ]);
}
```

- Se inserta fila por fila con `OpenIssueInvolucrado::create()` (volumen chico y así corren los casts).
- `$ignorados` se devuelve sin repetidos: `array_values(array_unique($ignorados))`.
- El actor **puede** quedar agregado a sí mismo si viene en la lista: no se filtra acá (solo el notificador
  lo excluye de la campana).

### `quitarInvolucrado(OpenIssue $issue, int $userId, User $actor)`

1. `asegurarPuedeEscribir`.
2. `asegurarNoCerrado($issue, 'No se pueden modificar los involucrados de un issue cerrado.')`.
3. Si `$userId === (int) $issue->creador_id` -> 422 `['user_id' => 'No se puede quitar al creador del issue.']`.
4. `$fila = $issue->involucrados()->where('user_id', $userId)->first();`. Si no existe -> 422
   `['user_id' => 'El usuario no está involucrado en este issue.']` (**422**, no 404).
5. En transacción: `$fila->delete()`, después
   `registrarActualizacion($issue, $actor, TIPO_INVOLUCRADO_QUITADO, "Quitó a {$nombre}")` y
   `OpenIssueNotificador::notificarInvolucradoQuitado($issue, $actor, $usuarioQuitado)`.

### `registrarActualizacion()` (privado)

```php
private static function registrarActualizacion(
    OpenIssue $issue, User $actor, string $tipo, ?string $texto = null,
    ?string $estadoAnterior = null, ?string $estadoNuevo = null, array $adjunto = []
): OpenIssueActualizacion {
    return OpenIssueActualizacion::create([
        'issue_id' => $issue->id,
        'user_id' => $actor->id,
        'tipo' => $tipo,
        'texto' => $texto,
        'estado_anterior' => $estadoAnterior,
        'estado_nuevo' => $estadoNuevo,
        'archivo' => $adjunto['archivo'] ?? null,
        'mime_type' => $adjunto['mime_type'] ?? null,
        'created_at' => now(),
    ]);
}
```

## 3.5 `App\Support\OpenIssueNotificador`

Única fuente de verdad de "quién se entera de qué". **Solo campana** (tabla `notificaciones`), **sin mail en
v1**: no se crean Mailables ni Jobs. `OpenIssueFlujo` solo llama a estos métodos, nunca arma una
notificación a mano.

Destinatarios base = **los involucrados del issue** (que incluye siempre al creador, §5.1):

```php
private static function involucrados(OpenIssue $issue): Collection // Collection de User
{
    $ids = $issue->involucrados()->pluck('user_id');

    return User::whereIn('id', $ids)->get();
}
```

**No** se notifica a "todos los usuarios del departamento destino" por el solo hecho de ser el destino
(sería una bomba de ruido en deptos grandes): si se los quiere notificar, se los agrega como involucrados,
que es exactamente el feature "todo el departamento".

### Matriz EXACTA de notificaciones

| Acción en `OpenIssueFlujo` | Método del notificador | Destinatarios | Mensaje (literal) |
|---|---|---|---|
| `crear()` | `notificarCreado($issue, $actor, Collection $nuevos)` | **solo los involucrados nuevos** | `Te involucraron en el Open Issue #{id}: {titulo}` |
| `agregarInvolucrados()` | `notificarInvolucradosAgregados($issue, $actor, Collection $nuevos)` | **solo los agregados en ese lote** | `Te involucraron en el Open Issue #{id}: {titulo}` |
| `quitarInvolucrado()` | `notificarInvolucradoQuitado($issue, $actor, User $quitado)` | **solo el quitado** | `Te quitaron del Open Issue #{id}: {titulo}` |
| `agregarActualizacion()` con tipo `comentario` | `notificarActualizacion($issue, $actor, $act)` | **todos los involucrados** | `{actor} comentó en el Open Issue #{id}: {titulo}` |
| `agregarActualizacion()` con tipo `cambio_estado` | `notificarActualizacion($issue, $actor, $act)` | **todos los involucrados** | `{actor} pasó el Open Issue #{id} a {labelEstado}` |
| `cerrar()` | `notificarCierre($issue, $actor, string $estadoAnterior)` | **todos los involucrados** | `{actor} cerró el Open Issue #{id}: {titulo}` |
| `reabrir()` | `notificarReapertura($issue, $actor)` | **todos los involucrados** | `{actor} reabrió el Open Issue #{id}: {titulo}` |
| `actualizar()` (edición de título/descripción/prioridad/depto) | — | **nadie** | no genera campana (es ruido) |
| fila técnica `apertura` | — | **nadie** | la notificación de alta es la de "te involucraron" |

Reglas transversales (idénticas a `HheeNotificador`):

- **Nunca al actor**: `destinatariosUnicos()` = `$destinatarios->filter()->unique('id')->reject(fn (User $u) => (int) $u->id === (int) $actor->id)`.
- **Dedupe** por id de usuario: una sola fila de `notificaciones` por destinatario por acción.
- `{actor}` es `$actor->name`; `{titulo}` es `Str::limit($issue->titulo, 120)`;
  `{labelEstado}` es `OpenIssueEstados::label($act->estado_nuevo)`.
- Al crear un issue **no** se notifica el comentario/adjunto inicial: sale una sola campana por persona.

### Helper privado que escribe la fila

`notificaciones.estado_anterior` y `estado_nuevo` son **NOT NULL**: siempre hay que mandar un string.

```php
private static function campana(
    Collection $destinatarios, User $actor, OpenIssue $issue, string $mensaje,
    ?string $estadoAnterior = null, ?string $estadoNuevo = null
): void {
    // Fallback al estado ACTUAL del issue: la columna es NOT NULL y en acciones que no cambian
    // estado (involucrado agregado/quitado, comentario) no hay transición real que registrar.
    $anterior = $estadoAnterior ?? $issue->estado;
    $nuevo = $estadoNuevo ?? $issue->estado;

    self::destinatariosUnicos($destinatarios, $actor)->each(fn (User $d) => Notificacion::create([
        'orden_trabajo_id'         => null,
        'solicitud_hhee_id'        => null,
        'open_issue_id'            => $issue->id,
        'tipo'                     => 'open_issue',
        'usuario_creador_id'       => $d->id,      // destinatario (convención existente de la tabla)
        'usuario_mantenimiento_id' => $actor->id,  // actor
        'estado_anterior'          => $anterior,
        'estado_nuevo'             => $nuevo,
        'mensaje'                  => $mensaje,
        'leido'                    => false,
    ]));
}
```

`$estadoAnterior` **siempre** llega explícito desde el caller (capturado ANTES del `update()`), nunca con
`getOriginal()`: es el mismo bug que documenta `HheeNotificador`.

## 3.6 `ordenes-sar/config/open_issues.php`

No duplica labels: referencia las constantes de la clase (igual que `config/hhee.php` con `HheeEstados`).

```php
<?php

use App\Support\OpenIssueEstados;

return [

    // Fuente de verdad: App\Support\OpenIssueEstados. NO duplicar el mapeo acá.
    'estados_labels' => OpenIssueEstados::ESTADOS_LABELS,
    'prioridades_labels' => OpenIssueEstados::PRIORIDADES_LABELS,
    'tipos_actualizacion_labels' => OpenIssueEstados::TIPOS_ACTUALIZACION_LABELS,

    // Prioridad asignada si el alta no manda ninguna.
    'prioridad_default' => 'media',

    // Roles (users.rol) con alcance de LECTURA global: ven todos los issues y la tab "Todos".
    // 'admin' no es insertable hoy en el enum de users.rol: se deja por el mismo motivo que
    // App\Support\AlcanceOrdenes (compatibilidad legacy).
    'roles_ven_todo' => ['gerente', 'admin'],

    // Tope defensivo de filas de oi_involucrados insertadas en una sola operación
    // (protege contra expandir por error un departamento gigante).
    'max_involucrados_por_lote' => 200,

    // Paginación del listado.
    'per_page_default' => 25,
    'per_page_max' => 100,

    // Adjuntos: mismas reglas que DescripcionController::store() (App\Support\ArchivoOrden).
    'adjunto' => [
        'max_kb' => 5120,
        'mimes' => ['jpeg', 'png', 'jpg', 'gif', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
    ],

];
```

---

# 4. API

Controller: `ordenes-sar/app/Http/Controllers/OpenIssueController.php`. Todo bajo `auth:api` + `cors` +
`json.response`, con prefix `open-issues`.

## 4.1 Tabla de endpoints

| Método | Ruta | Método del controller | Body / Query |
|---|---|---|---|
| GET | `/api/open-issues/catalogos` | `catalogos` | — |
| GET | `/api/open-issues/pendientes` | `pendientes` | `?solo_total=1` |
| GET | `/api/open-issues` | `index` | filtros (§4.2) |
| POST | `/api/open-issues` | `store` | **multipart** o JSON (§4.2) |
| GET | `/api/open-issues/{id}` | `show` | — |
| PUT | `/api/open-issues/{id}` | `update` | JSON |
| POST | `/api/open-issues/{id}/actualizaciones` | `storeActualizacion` | **multipart** o JSON |
| POST | `/api/open-issues/{id}/cerrar` | `cerrar` | JSON `{texto?}` |
| POST | `/api/open-issues/{id}/reabrir` | `reabrir` | JSON `{texto?}` |
| POST | `/api/open-issues/{id}/involucrados` | `storeInvolucrados` | JSON `{user_ids?, departamento_ids?}` |
| DELETE | `/api/open-issues/{id}/involucrados/{userId}` | `destroyInvolucrado` | — |

**No existe `DELETE /api/open-issues/{id}`**: los issues no se borran, se cierran (§5.9).

## 4.2 Validación, respuestas y errores

### GET `/catalogos` -> 200

```json
{
  "estados": [
    { "value": "abierto", "label": "Abierto", "color": "gold" },
    { "value": "en_progreso", "label": "En progreso", "color": "blue" },
    { "value": "cerrado", "label": "Cerrado", "color": "green" }
  ],
  "prioridades": [
    { "value": "baja", "label": "Baja", "color": "default" },
    { "value": "media", "label": "Media", "color": "blue" },
    { "value": "alta", "label": "Alta", "color": "orange" }
  ],
  "tipos_actualizacion": {
    "apertura": "Apertura",
    "comentario": "Comentario",
    "cambio_estado": "Cambio de estado",
    "cierre": "Cierre",
    "reapertura": "Reapertura",
    "involucrado_agregado": "Involucrado agregado",
    "involucrado_quitado": "Involucrado quitado",
    "edicion": "Edición"
  },
  "departamentos": [ { "id": 1, "nombre": "IT" } ],
  "usuarios": [ { "id": 7, "name": "Ana Pérez", "departamento_id": 1 } ],
  "prioridad_default": "media",
  "adjunto": { "max_kb": 5120, "mimes": ["jpeg", "png", "jpg", "gif", "svg", "pdf", "doc", "docx", "xls", "xlsx"] },
  "flags": { "es_gerente": true, "puede_ver_todos": true, "mi_user_id": 7, "mi_departamento_id": 1 }
}
```

- `departamentos`: `Departamento::query()->select('id', 'nombre')->orderBy('nombre')->get()`.
- `usuarios`: `User::query()->select('id', 'name', 'departamento_id')->orderBy('name')->get()`
  (mismo criterio y justificación que `SolicitudHheeController::catalogos()`: payload liviano, se carga una
  sola vez al entrar; si algún día hay miles de users se separa en un endpoint paginado).
- `es_gerente` = `auth()->user()->rol === 'gerente'`;
  `puede_ver_todos` = `AlcanceOpenIssues::veTodos($user)`.
- **Nunca devuelve 403.** Errores posibles: 401 (sin token).

### GET `/pendientes`

Query: `solo_total` (`nullable|boolean`). Base del query:
`OpenIssue::query()` -> `AlcanceOpenIssues::aplicarParticipo($query, $user)` ->
`->where('estado', '!=', OpenIssueEstados::CERRADO)`.

- Con `?solo_total=1` -> `200 {"total": 12}`.
- Sin el flag -> `200 {"total": 12, "issues": [ ...filas de listado (§4.3)... ]}`, ordenado
  `created_at desc, id desc` y **limitado a 50** filas (`->limit(50)`).

Errores: 401; `catch (\Exception)` -> `500 {"error": "Error al listar los issues pendientes"}` con
`Log::error` (mismo patrón que HHEE).

### GET `/` (index) -> paginador de Laravel

Query validada con `$request->validate([...])`:

```php
'estado' => 'array',
'estado.*' => ['string', Rule::in(OpenIssueEstados::estados())],
'prioridad' => ['nullable', 'string', Rule::in(OpenIssueEstados::prioridades())],
'departamento_destino_id' => 'nullable|integer|exists:departamentos,id',
'creador_id' => 'nullable|integer|exists:users,id',
'involucrado_id' => 'nullable|integer|exists:users,id',
'mios' => 'nullable|boolean',
'participo' => 'nullable|boolean',
'texto' => 'nullable|string|max:200',
'fecha_desde' => 'nullable|date',
'fecha_hasta' => 'nullable|date',
'per_page' => 'nullable|integer|min:1|max:100',
```

Armado del query, en este orden:

1. `OpenIssue::with(['creador:id,name', 'departamentoDestino:id,nombre', 'involucrados.usuario:id,name'])
   ->withCount(['involucrados', 'actualizaciones'])
   ->withMax('actualizaciones', 'created_at')`
2. `AlcanceOpenIssues::aplicar($query, $user)`
3. Filtros: `whereIn('estado', ...)`; `where('prioridad', ...)`; `where('departamento_destino_id', ...)`;
   `where('creador_id', ...)`; `whereHas('involucrados', fn ($q) => $q->where('user_id', $involucradoId))`;
   `if ($request->boolean('mios')) $query->where('creador_id', $user->id);`
   `if ($request->boolean('participo')) $query = AlcanceOpenIssues::aplicarParticipo($query, $user);`
   `whereDate('created_at', '>=', $fechaDesde)`; `whereDate('created_at', '<=', $fechaHasta)`;
   texto -> `where(fn ($q) => $q->where('titulo', 'like', "%{$t}%")->orWhere('descripcion', 'like', "%{$t}%"))`.
4. `->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($porPagina)`, con
   `$porPagina = min((int) ($filtros['per_page'] ?? config('open_issues.per_page_default')), config('open_issues.per_page_max'))`.
5. `return response()->json($paginador->through(fn (OpenIssue $i) => $this->filaListado($i)));`

La respuesta es el paginador estándar (`current_page`, `data`, `first_page_url`, `from`, `last_page`,
`last_page_url`, `links`, `next_page_url`, `path`, `per_page`, `prev_page_url`, `to`, `total`) con cada
elemento de `data[]` con el shape de **fila de listado** (§4.3).

Errores: 422 (filtros inválidos), 401, y 500 genérico con `Log::error`.

> Performance: el `like '%texto%'` no puede usar índice (scan). Es aceptable para el volumen esperado
> (miles de filas). Si creciera mucho, la alternativa sería full-text de SQL Server: **fuera de alcance**.

### POST `/` (store) -> 201 con el shape de `show` (§4.3)

Acepta `multipart/form-data` (por el adjunto inicial) o JSON.

```php
'titulo' => 'required|string|max:200',
'descripcion' => 'nullable|string|max:4000',
'departamento_destino_id' => 'required|integer|exists:departamentos,id',
'prioridad' => ['nullable', 'string', Rule::in(OpenIssueEstados::prioridades())],
'involucrados_ids' => 'nullable|array|max:200',
'involucrados_ids.*' => 'integer|distinct|exists:users,id',
'departamentos_ids' => 'nullable|array|max:20',
'departamentos_ids.*' => 'integer|distinct|exists:departamentos,id',
'texto_inicial' => 'nullable|string|max:4000',
'archivo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:5120',
```

Flujo: validar -> `$adjunto = $this->guardarAdjunto($request)` ->
`OpenIssueFlujo::crear($validado, auth()->user(), $adjunto)` -> `201 $this->detalle($issue, $user)`.
Errores: 422, 401, 500.

### GET `/{id}` (show) -> 200 con el shape de §4.3

`OpenIssue::with([...])->findOrFail($id)`. Si `!AlcanceOpenIssues::puedeVer($user, $issue)` ->
`403 {"error": "No tiene permisos para ver este issue"}`. `ModelNotFoundException` ->
`404 {"error": "Issue no encontrado"}`. 500 genérico.

### PUT `/{id}` (update) -> 200 con el shape de §4.3

```php
'titulo' => 'required|string|max:200',
'descripcion' => 'nullable|string|max:4000',
'prioridad' => ['nullable', 'string', Rule::in(OpenIssueEstados::prioridades())],
'departamento_destino_id' => 'nullable|integer|exists:departamentos,id',
```

Errores: 404 si no existe; 403 (`OpenIssueAutorizacionException`: no es el creador); 422 (cerrado o
validación); 401; 500.

### POST `/{id}/actualizaciones` -> 201

Acepta `multipart/form-data` o JSON.

```php
'texto' => 'nullable|string|max:4000',
'nuevo_estado' => ['nullable', 'string', Rule::in(OpenIssueEstados::ESTADOS_MANUALES)],
'archivo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,xls,xlsx|max:5120',
```

Orden **obligatorio** en el controller, para no dejar archivos huérfanos si el usuario no tiene permiso:

1. `$issue = OpenIssue::findOrFail($id);` -> 404.
2. `OpenIssueFlujo::asegurarPuedeEscribir($issue, $user);` -> 403.
3. `$adjunto = $this->guardarAdjunto($request);` (recién acá el archivo se mueve a disco).
4. `OpenIssueFlujo::agregarActualizacion($issue, $validado, $user, $adjunto);`

Respuesta 201:

```json
{
  "actualizacion": {
    "id": 44,
    "tipo": "cambio_estado",
    "tipo_label": "Cambio de estado",
    "texto": "Ya lo estoy viendo",
    "estado_anterior": "abierto",
    "estado_nuevo": "en_progreso",
    "archivo_nombre": null,
    "archivo_url": null,
    "mime_type": null,
    "created_at": "2026-09-18T11:02:00.000000Z",
    "autor": { "id": 7, "name": "Ana Pérez" }
  },
  "issue": { "...shape de show..." }
}
```

Errores: 404, 403, 422, 401, 500.

### POST `/{id}/cerrar` y POST `/{id}/reabrir` -> 200

```php
'texto' => 'nullable|string|max:4000',
```

Respuesta: `{"issue": { ...shape de show... }}`. Errores: 404; 403 (no puede escribir); 422 (transición
inválida); 401; 500.

### POST `/{id}/involucrados` -> 200

```php
'user_ids' => 'nullable|array|max:200',
'user_ids.*' => 'integer|distinct|exists:users,id',
'departamento_ids' => 'nullable|array|max:20',
'departamento_ids.*' => 'integer|distinct|exists:departamentos,id',
```

Si **ambos** arrays vienen vacíos o ausentes -> 422 con
`errors: {"user_ids": ["Indicá al menos un usuario o un departamento."]}` (regla extra en el controller:
`if (empty($v['user_ids']) && empty($v['departamento_ids'])) throw ValidationException::withMessages([...]);`).

Respuesta:

```json
{ "agregados": [12, 13, 14], "ignorados": [7], "issue": { "...shape de show..." } }
```

Errores: 404; 403; 422 (issue cerrado, o tope `max_involucrados_por_lote`); 401; 500.

### DELETE `/{id}/involucrados/{userId}` -> 200

Respuesta: `{"message": "Involucrado quitado", "issue": { ...shape de show... }}`.
Errores: 404 (issue inexistente); 403; 422 (es el creador, no estaba involucrado, o el issue está cerrado);
401; 500.

## 4.3 Shapes exactos (helpers privados del controller)

### `filaListado(OpenIssue $i): array` — item de `data[]` del index y de `pendientes.issues[]`

```json
{
  "id": 31,
  "titulo": "Funda 47A con hilo suelto en costura lateral",
  "estado": "en_progreso",
  "prioridad": "alta",
  "departamento_destino_id": 4,
  "departamento_destino": { "id": 4, "nombre": "Calidad" },
  "creador_id": 7,
  "creador": { "id": 7, "name": "Ana Pérez" },
  "involucrados_count": 6,
  "actualizaciones_count": 4,
  "involucrados_preview": [
    { "id": 7, "name": "Ana Pérez" },
    { "id": 12, "name": "Luis Gómez" },
    { "id": 13, "name": "Sol Díaz" },
    { "id": 14, "name": "Juan Paz" },
    { "id": 15, "name": "Eva Ruiz" }
  ],
  "ultima_actualizacion_at": "2026-09-18T11:02:00.000000Z",
  "fecha_cierre": null,
  "created_at": "2026-09-17T14:20:00.000000Z"
}
```

Reglas:

- `involucrados_preview`: **máximo 5**, en orden de `oi_involucrados.id` (el creador queda primero porque es
  la primera fila insertada). El `+N` lo calcula el front con `involucrados_count`.
- `ultima_actualizacion_at` = `$i->actualizaciones_max_created_at` (del `withMax`), o `$i->created_at` si
  viniera null. Se serializa en ISO-8601, como cualquier `datetime` de Laravel.
- `actualizaciones_count` cuenta **todas** las filas de `oi_actualizaciones`, incluida la de `apertura`: un
  issue recién creado tiene 1 (o 2 si trajo comentario/adjunto inicial).

### `detalle(OpenIssue $i, User $user): array` — respuesta de `show`, `store`, `update`, `cerrar`, `reabrir`

```json
{
  "id": 31,
  "titulo": "Funda 47A con hilo suelto en costura lateral",
  "descripcion": "Se detectó en EOL sobre 3 unidades del turno noche.",
  "estado": "en_progreso",
  "prioridad": "alta",
  "departamento_destino_id": 4,
  "departamento_destino": { "id": 4, "nombre": "Calidad" },
  "creador_id": 7,
  "creador": { "id": 7, "name": "Ana Pérez", "departamento_id": 1 },
  "fecha_cierre": null,
  "cerrado_por_id": null,
  "cerrado_por": null,
  "fecha_reapertura": null,
  "created_at": "2026-09-17T14:20:00.000000Z",
  "updated_at": "2026-09-18T11:02:00.000000Z",
  "involucrados": [
    {
      "id": 90,
      "user_id": 7,
      "name": "Ana Pérez",
      "departamento_id": 1,
      "departamento_nombre": "IT",
      "origen": "creador",
      "departamento_origen_id": null,
      "departamento_origen_nombre": null,
      "agregado_por_id": 7,
      "agregado_por_name": "Ana Pérez",
      "created_at": "2026-09-17T14:20:00.000000Z",
      "es_creador": true,
      "puede_quitar": false
    }
  ],
  "actualizaciones": [
    {
      "id": 41,
      "tipo": "apertura",
      "tipo_label": "Apertura",
      "texto": null,
      "estado_anterior": null,
      "estado_nuevo": "abierto",
      "archivo_nombre": null,
      "archivo_url": null,
      "mime_type": null,
      "created_at": "2026-09-17T14:20:00.000000Z",
      "autor": { "id": 7, "name": "Ana Pérez" }
    }
  ],
  "flags": {
    "puede_ver": true,
    "puede_editar": true,
    "puede_actualizar": true,
    "puede_cerrar": true,
    "puede_reabrir": false,
    "puede_involucrar": true,
    "puede_quitar_involucrados": true,
    "es_creador": true,
    "es_involucrado": true
  }
}
```

Reglas del `detalle`:

- `involucrados`: ordenados por `oi_involucrados.id` ascendente. `name` y `departamento_id` salen de la
  relación `usuario`; `departamento_nombre` es el departamento **actual** del usuario;
  `departamento_origen_id` / `departamento_origen_nombre` son el snapshot de `origen = departamento`
  (null cuando el origen es `creador` o `manual`).
  `puede_quitar` = `flags.puede_quitar_involucrados && !es_creador`.
- `actualizaciones`: orden `id` ascendente (cronológico). `archivo_url = ArchivoOrden::url($a->archivo)`,
  `archivo_nombre = $a->archivo`, `tipo_label` de `OpenIssueEstados::TIPOS_ACTUALIZACION_LABELS`.
- Eager loading de `show`:
  `['creador:id,name,departamento_id', 'departamentoDestino:id,nombre', 'cerradoPor:id,name',
  'involucrados.usuario:id,name,departamento_id', 'involucrados.departamento:id,nombre',
  'involucrados.agregadoPor:id,name', 'actualizaciones.autor:id,name']`.

### `flags(OpenIssue $i, User $user): array` — el front NO recalcula permisos, nunca

```php
$esCreador = (int) $i->creador_id === (int) $user->id;
$cerrado = $i->estado === OpenIssueEstados::CERRADO;
$puedeEscribir = AlcanceOpenIssues::puedeEscribir($user, $i);

return [
    'puede_ver'                 => AlcanceOpenIssues::puedeVer($user, $i),
    'puede_editar'              => $esCreador && !$cerrado,
    'puede_actualizar'          => $puedeEscribir && !$cerrado,
    'puede_cerrar'              => $puedeEscribir && !$cerrado,
    'puede_reabrir'             => $puedeEscribir && $cerrado,
    'puede_involucrar'          => $puedeEscribir && !$cerrado,
    'puede_quitar_involucrados' => $puedeEscribir && !$cerrado,
    'es_creador'                => $esCreador,
    'es_involucrado'            => AlcanceOpenIssues::esInvolucrado($user, $i),
];
```

### `guardarAdjunto(Request $request): array` (privado)

```php
if (!$request->hasFile('archivo')) {
    return ['archivo' => null, 'mime_type' => null];
}

return [
    'mime_type' => $request->file('archivo')->getMimeType(),
    'archivo' => ArchivoOrden::store($request->file('archivo'), 'open-issue'),
];
```

Los adjuntos se sirven por el endpoint que **ya existe**: `GET /api/archivos/{archivo}`
(`DescripcionController::showArchivo`). **No** se crea ningún endpoint de archivos nuevo.

## 4.4 Ubicación en `routes/api.php` (cambio exacto)

1. Agregar el import junto a los otros: `use App\Http\Controllers\OpenIssueController;`
2. **Dentro** del grupo `['middleware' => ['auth:api', 'cors', 'json.response']]`, **inmediatamente después**
   del bloque `Route::prefix('hhee')->group(...)` y **fuera** de `bloquear.escritura.orden.ajena`:

```php
    // -------------------------------------------------------------------
    // Módulo Open Issues: dominio propio (tablas oi_*), sin relación con
    // ordenes_trabajo, así que queda FUERA de 'bloquear.escritura.orden.ajena'
    // (ese middleware solo resuelve alcance sobre una OT). La autorización la
    // resuelven App\Support\AlcanceOpenIssues / OpenIssueFlujo.
    //
    // EL ORDEN IMPORTA: /catalogos y /pendientes van ANTES de /{id} (si no,
    // 'catalogos' matchearía como {id}); además {id} está restringido a numérico.
    // -------------------------------------------------------------------
    Route::prefix('open-issues')->group(function () {
        Route::get('/catalogos', [OpenIssueController::class, 'catalogos']);
        Route::get('/pendientes', [OpenIssueController::class, 'pendientes']);

        Route::get('/', [OpenIssueController::class, 'index']);
        Route::post('/', [OpenIssueController::class, 'store']);

        Route::get('/{id}', [OpenIssueController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [OpenIssueController::class, 'update'])->whereNumber('id');

        Route::post('/{id}/actualizaciones', [OpenIssueController::class, 'storeActualizacion'])->whereNumber('id');
        Route::post('/{id}/cerrar', [OpenIssueController::class, 'cerrar'])->whereNumber('id');
        Route::post('/{id}/reabrir', [OpenIssueController::class, 'reabrir'])->whereNumber('id');
        Route::post('/{id}/involucrados', [OpenIssueController::class, 'storeInvolucrados'])->whereNumber('id');
        Route::delete('/{id}/involucrados/{userId}', [OpenIssueController::class, 'destroyInvolucrado'])
            ->whereNumber('id')->whereNumber('userId');
    });
```

**No se toca ninguna otra línea de `routes/api.php`.**

## 4.5 Manejo de errores del controller (patrón fijo en TODOS los métodos de escritura)

```php
try {
    // ...
} catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    return response()->json(['error' => 'Issue no encontrado'], 404);
} catch (OpenIssueAutorizacionException $e) {
    return response()->json(['error' => $e->getMessage()], 403);
} catch (ValidationException $e) {
    throw $e;                       // la maneja Laravel -> 422 con 'errors'
} catch (\Exception $e) {
    Log::error('Error <acción> del Open Issue: ' . $e->getMessage());
    return response()->json(['error' => 'Error <acción> del issue'], 500);
}
```

---

# 5. Reglas de negocio cerradas (todos los `[A confirmar]` decididos)

### 5.1 El creador SÍ tiene fila en `oi_involucrados`

Con `origen = 'creador'`, `departamento_id = null`, `agregado_por_id = creador_id`, insertada en la misma
transacción del alta. **No se puede quitar** (422).
*Por qué:* hace que "participo", el badge y los destinatarios de campana sean **una sola** consulta
(`exists` sobre `oi_involucrados`) en vez de un `OR creador_id` repartido por todo el código. El UNIQUE
evita duplicarlo.

### 5.2 "Todo el departamento X" es un SNAPSHOT, no un alcance dinámico

Al agregar un departamento se insertan filas para los users que pertenecen a ese depto **en ese instante**
(`origen = 'departamento'`, `departamento_id = X`). Un user que entra al depto después **no** queda
involucrado; uno que se va **sigue** involucrado.
*Por qué:* determinismo y auditoría (queda escrito quién estaba notificado en cada momento). Un alcance
dinámico haría que el histórico cambie solo.

### 5.3 Agregar un depto cuyos users ya están involucrados: se ignoran los duplicados

Sin error (HTTP 200), los repetidos van en `ignorados[]`. La fila existente **no** se modifica (no cambia su
`origen` ni su `agregado_por_id`). Si un id viene en `user_ids` **y** en la expansión de un depto, gana
`origen = 'manual'` (las personas sueltas se procesan primero).
*Por qué:* idempotencia — agregar dos veces el mismo depto no debe fallar ni duplicar campanas.

### 5.4 Los usuarios del MISMO departamento del creador NO ven el issue

Alcance de lectura = creador + involucrados + usuarios del **departamento destino** + roles de
`config('open_issues.roles_ven_todo')` (`gerente`, `admin`).
*Por qué:* el caso de uso "DX registra cada cambio chico" generaría ruido masivo si todo el depto del
creador viera todo. Si se lo quiere compartir, se agrega el depto como involucrado (feature que ya existe).

### 5.5 Quién puede ESCRIBIR

Comentar, adjuntar, cambiar estado, cerrar, reabrir, agregar/quitar involucrados: **involucrados** (incluye
al creador) **+ usuarios del departamento destino**. Los `gerente` **no** heredan escritura de su lectura
global.
*Por qué:* es un **desvío consciente** del encargo (que decía "creador o involucrado"). El issue está
*dirigido a* un departamento; si Calidad no puede responder hasta que alguien la involucre a mano, el
circuito se rompe. Es estrictamente más permisivo sobre un conjunto que **ya** tiene lectura. Para volver al
encargo literal: borrar la primera línea de `AlcanceOpenIssues::puedeEscribir()` (ver §9).

### 5.6 Quién ve la tab "Todos"

La tab **siempre se muestra**; el backend ya filtra por alcance (`AlcanceOpenIssues::aplicar`). El **label es
dinámico**: `"Todos"` si `catalogos.flags.puede_ver_todos` es true, `"Visibles para mí"` si no.
*Por qué:* cero lógica de permisos duplicada en el front y nunca una tab escondida sin explicación.

### 5.7 Estados y transiciones

`abierto <-> en_progreso`, ambos -> `cerrado`, y `cerrado -> abierto` **solo** por `POST /{id}/reabrir`. La
reapertura vuelve **siempre** a `abierto` (no recuerda si estaba `en_progreso`) y **limpia**
`fecha_cierre` / `cerrado_por_id`; el histórico de cierres queda en `oi_actualizaciones`.
`POST /{id}/actualizaciones` solo acepta `nuevo_estado` en `{abierto, en_progreso}`.
*Por qué:* máquina de estados mínima y predecible; el "volver a abierto" cubre el "lo marqué en progreso por
error".

### 5.8 `descripcion` NO es obligatoria

Nullable en la DB y en la API (`nullable|string|max:4000`). `titulo` sí es obligatorio (`max:200`).
*Por qué:* el caso "DX registra un cambio chico" suele necesitar solo un título.

### 5.9 Los issues NO se borran

No hay endpoint `DELETE /{id}` ni soft deletes: se cierran. `oi_actualizaciones` es append-only (la app
nunca hace UPDATE ni DELETE sobre ella).
*Por qué:* trazabilidad — el módulo existe justamente para *justificar* trabajo hecho.

### 5.10 Un issue cerrado queda congelado

Con `estado = 'cerrado'` se rechazan con **422**: editar, comentar, cambiar estado, agregar involucrados y
quitar involucrados. Lo único permitido es **reabrir** (por quien puede escribir).
*Por qué:* un cierre que se puede seguir editando no es un cierre.

### 5.11 Editar título / descripción / prioridad / departamento destino

**Solo el creador**, y solo si el issue no está cerrado. Registra **una** fila `tipo = 'edicion'` con
`texto = "Editó: título, prioridad"` (orden canónico: título, descripción, prioridad, departamento destino).
Si no cambió nada, no registra fila. **No notifica.**
*Por qué:* auditoría sin ruido de campana.

### 5.12 Notificaciones

Ver la matriz de §3.5. **Solo campana** (`notificaciones.tipo = 'open_issue'`), **sin mail** en v1.
**Nunca al actor**, dedupe por usuario. No se notifica a todo el depto destino por ser destino, ni la
edición, ni la fila técnica de `apertura`.
*Por qué:* consistente con `HheeNotificador` y evita spam.

### 5.13 Badge del Header

`GET /api/open-issues/pendientes?solo_total=1` = cantidad de issues con `estado != 'cerrado'` donde el
usuario **tiene fila en `oi_involucrados`** (incluye los propios, porque el creador es involucrado).
*Por qué:* endpoint liviano para polling, igual que HHEE.

### 5.14 Adjuntos

Uno por actualización (columna `archivo`), guardado con `ArchivoOrden::store($file, 'open-issue')` en
`public/storage/archivos`, servido por el `GET /api/archivos/{archivo}` **existente**. Máximo 5 MB y los
mimes de `config('open_issues.adjunto.mimes')`. El archivo se mueve a disco **después** del guard de
autorización y **antes** de abrir la transacción.
*Por qué:* reusa el 100% de la infraestructura de adjuntos de OTs, sin endpoints ni discos nuevos.

### 5.15 Actualización vacía

422 si no trae ni `texto`, ni `archivo`, ni `nuevo_estado`. Si `nuevo_estado` es igual al estado actual, se
ignora (no genera fila `cambio_estado` ni notificación de cambio).
*Por qué:* evita filas de timeline sin información y hace idempotente el checkbox "marcar en progreso".

---

# 6. Frontend

## 6.1 Árbol de archivos

```
ot-front/src/
├── pages/
│   └── OpenIssues.jsx                       [NUEVO] página de la ruta /open-issues
├── components/
│   ├── openissues/
│   │   ├── OpenIssueFilter.jsx              [NUEVO] barra de filtros (estado/prioridad/depto/texto)
│   │   ├── SelectorInvolucrados.jsx         [NUEVO] selector de personas + "todo el departamento"
│   │   ├── ModalCrearOpenIssue.jsx          [NUEVO] alta y edición de issue
│   │   └── ModalDetalleOpenIssue.jsx        [NUEVO] detalle + timeline + acciones
│   ├── Header.jsx                           [MODIFICADO] link + badge "Open Issues"
│   └── Notificacion.jsx                     [MODIFICADO] deep-link por tipo
├── Utils/
│   ├── openIssuesApi.js                     [NUEVO] wrappers de /api/open-issues/*
│   ├── openIssues.js                        [NUEVO] helpers puros (labels/colores/agrupado)
│   └── otApi.js                             [MODIFICADO] 1 línea: no forzar JSON si el body es FormData
├── App.jsx                                  [MODIFICADO] ruta protegida /open-issues
└── index.css                                [MODIFICADO] clases oi-* + piggyback en reglas hhee-*
```

**Nada más se toca.** No se crean archivos de estilo nuevos: todo va a `index.css`, dentro de
`@layer components`.

## 6.2 `Utils/openIssuesApi.js`

Usa `apiFetch` y `buildQuery` de `./otApi` (nada de axios). Todas devuelven `{ ok, status, data, error }`.

| Función | Llama a | Notas |
|---|---|---|
| `fetchCatalogosOpenIssues()` | `GET open-issues/catalogos` | |
| `fetchPendientesOpenIssues(soloTotal = false)` | `GET open-issues/pendientes` (con `?solo_total=1` si corresponde) | el Header usa `true`; tolera `{total}` o `{total, issues}` |
| `fetchOpenIssues(params)` | `GET open-issues?<buildQuery(params)>` | `estado` array se serializa como `estado[]` |
| `fetchOpenIssue(id)` | `GET open-issues/{id}` | |
| `crearOpenIssue(formData)` | `POST open-issues` con `body: formData` | **siempre FormData** (adjunto opcional) |
| `actualizarOpenIssue(id, payload)` | `PUT open-issues/{id}` JSON | sin archivo (PUT + multipart exige method spoofing) |
| `agregarActualizacionOpenIssue(id, formData)` | `POST open-issues/{id}/actualizaciones` | **siempre FormData** |
| `cerrarOpenIssue(id, payload = {})` | `POST open-issues/{id}/cerrar` JSON | `{texto?}` |
| `reabrirOpenIssue(id, payload = {})` | `POST open-issues/{id}/reabrir` JSON | `{texto?}` |
| `agregarInvolucradosOpenIssue(id, payload)` | `POST open-issues/{id}/involucrados` JSON | `{user_ids, departamento_ids}` |
| `quitarInvolucradoOpenIssue(id, userId)` | `DELETE open-issues/{id}/involucrados/{userId}` | |

Helper exportado para no repetir el armado del FormData en los dos modales:

```js
// Arma el FormData de POST / y de POST /{id}/actualizaciones: ignora null/undefined/''
// y expande los arrays como `clave[]` (mismo criterio que buildQuery de otApi.js).
export function buildOpenIssueFormData(campos = {}, archivo = null) { /* ... */ }
```

### Cambio en `Utils/otApi.js` (1 línea, dentro de `apiFetch`)

```js
// ANTES
...(options.body ? { 'Content-Type': 'application/json' } : {}),

// DESPUÉS
...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
```

Motivo (dejarlo como comentario en el código): con `FormData` el browser tiene que poner él el
`Content-Type: multipart/form-data; boundary=...`; si se lo forzamos a `application/json`, Laravel no parsea
el archivo. Ningún caller existente pasa `FormData`, así que el comportamiento de OT y HHEE no cambia.

## 6.3 `Utils/openIssues.js` (helpers puros, sin fetch)

```js
export const ESTADOS_OI_FALLBACK = {
  abierto:     { label: 'Abierto',     color: 'gold' },
  en_progreso: { label: 'En progreso', color: 'blue' },
  cerrado:     { label: 'Cerrado',     color: 'green' },
};

export const PRIORIDADES_OI_FALLBACK = {
  baja:  { label: 'Baja',  color: 'default' },
  media: { label: 'Media', color: 'blue' },
  alta:  { label: 'Alta',  color: 'orange' },
};

export function getEstadoOpenIssueInfo(catalogoEstados = [], estado)         // -> {label, color}
export function getPrioridadOpenIssueInfo(catalogoPrioridades = [], prio)    // -> {label, color}

// Color del punto del Timeline de AntD según el tipo de actualización.
export const COLOR_TIMELINE_POR_TIPO = {
  apertura: 'gray', comentario: 'blue', cambio_estado: 'gold', cierre: 'green',
  reapertura: 'orange', involucrado_agregado: 'gray', involucrado_quitado: 'gray', edicion: 'gray',
};
export function getColorTimeline(tipo)        // COLOR_TIMELINE_POR_TIPO[tipo] || 'gray'

// Opciones agrupadas por departamento para el <Select> de personas:
// [{ label: 'Calidad', options: [{ label: 'Luis Gómez', value: 12 }] }]
export function agruparUsuariosPorDepartamento(usuarios = [], departamentos = [])

// Re-export para NO duplicar la función (ya existe y está probada en el módulo HHEE).
export { iniciales } from './hhee';

export function formatearFechaOI(valor)       // moment(v).format('DD/MM/YYYY HH:mm') o '—'
export function esImagenOI(mimeType)          // /^image\//.test(mimeType || '')
```

La fuente de verdad de labels y colores es **el catálogo del backend**; el fallback local existe solo para
no dejar la UI en blanco antes de que cargue (misma doctrina que `Utils/hhee.js`).

## 6.4 `pages/OpenIssues.jsx`

Estructura visual **idéntica** a `pages/HorasExtras.jsx`:
`div.app-shell > main.page-container > <UserProfile/> <Header/> > div.ot-list.oi-page >
div.ot-page-heading` (con `p.ot-eyebrow` "Sistema OT", `h1` "Open Issues" y
`button.primary-action` "Nuevo issue" con `<PlusOutlined/>`) `> <Tabs/>`.

Shape de estado:

```js
const PAGE_SIZE_DEFAULT = 10;

const estadoInicialTabla = () => ({ data: [], loading: false, error: null, pagina: 1, pageSize: PAGE_SIZE_DEFAULT, total: 0 });
const estadoInicialFiltros = () => ({ estado: [], prioridad: undefined, departamento_destino_id: undefined, texto: '' });

const [usuario, setUsuario]                      // JSON.parse(localStorage.getItem('user'))
const [catalogos, setCatalogos]                  // data de GET /catalogos
const [catalogosLoading, setCatalogosLoading] = useState(true);
const [catalogosError, setCatalogosError]
const [tabActiva, setTabActiva] = useState('mios');   // 'mios' | 'participo' | 'todos'
const [tablaMios, setTablaMios]           // + [filtrosMios, setFiltrosMios]
const [tablaParticipo, setTablaParticipo] // + [filtrosParticipo, setFiltrosParticipo]
const [tablaTodos, setTablaTodos]         // + [filtrosTodos, setFiltrosTodos]
const [modalCrearOpen, setModalCrearOpen] = useState(false);
const [detalleOpen, setDetalleOpen] = useState(false);
const [issueSeleccionadoId, setIssueSeleccionadoId] = useState(null);
```

Loaders: `cargarMios(pagina, pageSize)` (manda `mios: 1`), `cargarParticipo(...)` (manda `participo: 1`) y
`cargarTodos(...)` (sin flags). Los tres mandan los filtros de su propia tab más `per_page` y `page`.
`refrescarListas()` recarga la tab activa y despacha
`window.dispatchEvent(new Event('openissues:actualizado'))`.

**Deep-link `?issue=ID`**: copiar **exactamente** el patrón de `HorasExtras.jsx` con `useSearchParams`,
dependiendo del **valor** del parámetro (no del objeto `searchParams`) y con un `ultimoParamAbiertoRef` para
no reabrir el modal en loop. `cerrarDetalle()` borra el parámetro con
`setSearchParams(next, { replace: true })`.

**Tabs** (siempre las tres):

| key | label | loader |
|---|---|---|
| `mios` | `Míos` | `cargarMios` |
| `participo` | `Donde participo` | `cargarParticipo` |
| `todos` | `catalogos?.flags?.puede_ver_todos ? 'Todos' : 'Visibles para mí'` | `cargarTodos` |

**Columnas de la tabla** (AntD `Table` con `className="modern-table"`, `size="small"`,
`scroll={{ x: 'max-content' }}`, `rowKey="id"` y `onRow` clickeable, como en HHEE):

| title | key | render |
|---|---|---|
| `N°` | `id` | `<strong>{v}</strong>`, width 70 |
| `Título` | `titulo` | `<span className="oi-tabla-titulo">{v}</span>` |
| `Depto destino` | `departamento_destino` | `r.departamento_destino?.nombre` o `—` |
| `Creador` | `creador` | `r.creador?.name` o `—` |
| `Involucrados` | `involucrados` | `div.oi-avatares` con hasta 3 `.oi-avatar` (iniciales, `title={name}`) más `.oi-avatar.oi-avatar--mas` con `+N` si `involucrados_count > 3` |
| `Estado` | `estado` | `<Tag color={info.color}>{info.label}</Tag>` con `getEstadoOpenIssueInfo(catalogos?.estados, v)` |
| `Prioridad` | `prioridad` | `<Tag>` con `getPrioridadOpenIssueInfo(catalogos?.prioridades, v)` |
| `Última actualización` | `ultima_actualizacion_at` | `formatearFechaOI(v)` |
| `Acción` | `accion` | `<Button size="small" onClick={() => abrirDetalle(r.id)}>Ver</Button>` |

Paginación server-side (`current`, `pageSize`, `total`, `showSizeChanger`, `onChange`), igual que HHEE.
Estados de carga: `<Spin size="large"/>` dentro de `.oi-modal-loading` mientras cargan los catálogos;
`<Empty description={error}/>` en error; y
`locale={{ emptyText: <Empty description="No hay issues para los filtros seleccionados." /> }}`.

## 6.5 Componentes

### `components/openissues/OpenIssueFilter.jsx`

Presentacional puro (espejo de `SolicitudHheeFilter.jsx`). Props:

```
estados: [{value, label, color}]              prioridades: [{value, label, color}]
departamentos: [{id, nombre}]
estadoSeleccionado: string[]                  prioridadSeleccionada: string | undefined
departamentoSeleccionado: number | undefined  texto: string
onChangeEstado(string[])                      onChangePrioridad(string | undefined)
onChangeDepartamento(number | undefined)      onChangeTexto(string)
onBuscar()                                    // Enter en el input o click en "Aplicar filtros"
```

Render: `<Select mode="multiple" placeholder="Estado">`, `<Select placeholder="Prioridad" allowClear>`,
`<Select placeholder="Depto destino" allowClear showSearch optionFilterProp="label">` y
`<Input.Search placeholder="Buscar por título o descripción" allowClear onSearch={onBuscar}>`, todo dentro
de `div.oi-toolbar`. El botón `<Button type="primary" icon={<SearchOutlined/>}>Aplicar filtros</Button>`
vive en la página (igual que en HHEE).

### `components/openissues/SelectorInvolucrados.jsx`

Lo usan el modal de alta **y** el botón "+ Involucrar" del detalle. Props:

```
usuarios: [{id, name, departamento_id}]       departamentos: [{id, nombre}]
userIds: number[]                             departamentoIds: number[]
onChangeUserIds(number[])                     onChangeDepartamentoIds(number[])
excluirUserIds?: number[]                     // ids ya involucrados: se filtran de las opciones (default [])
disabled?: boolean
```

Render:

1. `<Select mode="multiple" showSearch optionFilterProp="label" placeholder="Personas...">` con
   `options={agruparUsuariosPorDepartamento(usuarios, departamentos)}` (agrupado por departamento).
2. `<Select mode="multiple" placeholder="Agregar todo el departamento...">` con los departamentos.
3. Chips: por cada id de `userIds`, un `.oi-chip` con `.oi-chip__avatar` (iniciales), el nombre y un botón
   `.oi-chip__quitar` (`<CloseOutlined/>`) que lo saca de `userIds`. Por cada id de `departamentoIds`, un
   `.oi-chip.oi-chip--depto` con el texto `Todo {nombre} ({n} personas)` y su botón de quitar, donde `n` es
   la cantidad de `usuarios` con ese `departamento_id`.
4. `<p className="oi-selector-resumen">` con el total estimado de personas distintas (unión de `userIds` con
   los users de los deptos elegidos, sin duplicados ni los de `excluirUserIds`).

> La expansión real la hace **siempre el backend**: este conteo es solo informativo.

### `components/openissues/ModalCrearOpenIssue.jsx`

Props: `{ open, issue = null, catalogos, onClose, onSuccess }`. `esEdicion = !!issue`.

Estado local: `titulo`, `descripcion`, `departamentoDestinoId`, `prioridad` (default
`catalogos?.prioridad_default ?? 'media'`), `userIds`, `departamentoIds`, `textoInicial`, `archivo` (del
`Upload` de AntD, con `maxCount={1}` y `beforeUpload={() => false}`) y `guardando`. Un `useEffect` sobre
`[open, issue]` resetea o hidrata el formulario (patrón de `ModalCrearSolicitudHhee`).

Render: `Modal` de AntD con `width={720}`, `footer={null}`, `destroyOnClose` y título
`esEdicion ? 'Editar issue' : 'Nuevo issue'`. Adentro, `div.oi-card` con `div.hhee-form-grid`:
`Input` de título (`maxLength={200}`, `showCount`), `TextArea` de descripción (`rows={4}`,
`maxLength={4000}`), `Select` de depto destino (requerido) y `Select` de prioridad. **Solo en alta** se
muestran además: `<SelectorInvolucrados/>`, un `TextArea` "Primera actualización (opcional)" y un `<Upload>`
para el adjunto inicial.

Botonera `div.modal-actions`: `button.secondary-action` "Cancelar" y `button.primary-action`
(`esEdicion ? 'Guardar cambios' : 'Crear issue'`).

Submit:

- alta -> `crearOpenIssue(buildOpenIssueFormData({ titulo, descripcion, departamento_destino_id, prioridad, involucrados_ids: userIds, departamentos_ids: departamentoIds, texto_inicial }, archivo))`
- edición -> `actualizarOpenIssue(issue.id, { titulo, descripcion, prioridad, departamento_destino_id })`

Éxito -> `message.success(...)` y `onSuccess(data)`. Error -> `message.error(error)`. La validación previa
en el front se limita a los **campos obligatorios** (título y depto destino); el resto lo valida el backend.

### `components/openissues/ModalDetalleOpenIssue.jsx`

Props: `{ open, issueId, catalogos, onClose, onChanged }`.

Estado: `issue`, `cargando`, `error`, `editando`, `involucrarOpen`, `enviandoAccion`, `nuevoTexto`,
`marcarEnProgreso` (bool), `nuevoArchivo`, `userIdsNuevos`, `departamentoIdsNuevos`.
`cargarDetalle()` usa `fetchOpenIssue(issueId)`; `useEffect([open, cargarDetalle])` limpia el estado al
cerrar. `refrescarTodo()` = `cargarDetalle()` +
`window.dispatchEvent(new Event('openissues:actualizado'))` + `onChanged?.()`.

**La botonera y los controles se renderizan SOLO según `issue.flags`. El front no recalcula permisos.**

Secciones (AntD `Modal`, `width={960}`, `footer={null}`, `destroyOnClose`, título `Issue N° {id}`):

1. `<Alert type="success" message="Issue cerrado" description={fecha + cerrado_por.name}/>` si
   `issue.estado === 'cerrado'`.
2. `div.oi-card.oi-detalle-header` con pares `.hhee-detalle-label` / `.hhee-detalle-valor`: Estado (Tag),
   Prioridad (Tag), Depto destino, Creador, Creado, Última actualización.
3. Descripción dentro de un `div.oi-card` (o `<Empty description="Sin descripción."/>`).
4. `div.oi-card` "Involucrados (N)": `div.oi-chips` con un `.oi-chip` por involucrado (avatar de iniciales,
   `name`, y un `<Tag>` chico con `departamento_origen_nombre` si `origen === 'departamento'`). El botón
   `.oi-chip__quitar` se muestra solo si `inv.puede_quitar`, y llama a `quitarInvolucradoOpenIssue` detrás
   de un `Modal.confirm`. Si `flags.puede_involucrar`, un `button.secondary-action` "+ Involucrar" abre un
   `Modal` con `<SelectorInvolucrados excluirUserIds={ids ya involucrados}/>` que llama a
   `agregarInvolucradosOpenIssue(id, { user_ids, departamento_ids })` y muestra
   `message.success('N involucrados agregados')` o `message.info('Ya estaban todos involucrados')` según
   `agregados.length`.
5. `div.oi-card` "Actividad": `<Timeline>` de AntD con un item por actualización, con
   `color={getColorTimeline(a.tipo)}`:
   `p.oi-timeline-autor` -> `{a.autor?.name}` + `<Tag>{a.tipo_label}</Tag>`;
   `small.oi-timeline-fecha` -> `formatearFechaOI(a.created_at)`;
   si hay `a.estado_anterior` o `a.estado_nuevo`, los dos `<Tag>` de estado (anterior -> nuevo);
   `p.oi-timeline-texto` con `a.texto` (la clase respeta los saltos con `white-space: pre-wrap`);
   si hay `a.archivo_url`, un `<a className="hhee-adjunto-link" href={a.archivo_url} target="_blank" rel="noopener noreferrer">`
   con un `<img>` si `esImagenOI(a.mime_type)` o el nombre del archivo si no.
   Si no hay actualizaciones: `<Empty description="Sin actividad."/>`.
6. `div.oi-card` "Nueva actualización" (solo si `flags.puede_actualizar`): `<TextArea rows={3} maxLength={4000}>`,
   `<Upload maxCount={1} beforeUpload={() => false}>`, `<Checkbox>Marcar en progreso</Checkbox>` (solo si
   `issue.estado === 'abierto'`) y `div.oi-nueva-actualizacion__acciones` con un `button.primary-action`
   "Publicar" que llama a
   `agregarActualizacionOpenIssue(id, buildOpenIssueFormData({ texto, nuevo_estado: marcarEnProgreso ? 'en_progreso' : undefined }, archivo))`.
   El botón queda deshabilitado si no hay texto, ni archivo, ni checkbox marcado.
7. `div.modal-actions`: "Cerrar" (`secondary-action`, cierra el modal);
   si `flags.puede_editar`, "Editar" (abre `ModalCrearOpenIssue` con `issue`);
   si `flags.puede_cerrar`, "Cerrar issue" (`primary-action`, `Modal.confirm` con `<TextArea>` opcional ->
   `cerrarOpenIssue`);
   si `flags.puede_reabrir`, "Reabrir" (`secondary-action`, `Modal.confirm` con texto opcional ->
   `reabrirOpenIssue`).

Estados: `.oi-modal-loading` con `<Spin/>`; `<Alert type="error">` con el `error`;
`<Empty description="No se encontró el issue."/>` si no hay data.

## 6.6 Cambios exactos en archivos existentes

### `src/App.jsx`

```jsx
import OpenIssues from './pages/OpenIssues';      // junto a los otros imports de pages
```

y la ruta, **después** del `<Route path="/horas-extras" .../>` y **antes** del `path="/"`:

```jsx
          <Route
            path="/open-issues"
            element={(
              <RutaProtegida>
                <OpenIssues />
              </RutaProtegida>
            )}
          />
```

Nada más cambia en ese archivo.

### `src/components/Header.jsx`

```jsx
import { FaClipboardList, FaChartBar, FaRegClock, FaTasks } from 'react-icons/fa';
import { fetchPendientesOpenIssues } from '../Utils/openIssuesApi';

const [pendientesOpenIssues, setPendientesOpenIssues] = useState(0);

const cargarPendientesOpenIssues = useCallback(async () => {
    const { ok, data } = await fetchPendientesOpenIssues(true);
    if (ok) {
        const total = typeof data?.total === 'number' ? data.total : (data?.issues?.length || 0);
        setPendientesOpenIssues(total);
    }
}, []);

// useEffect PROPIO, independiente del de HHEE (ese no se toca).
useEffect(() => {
    cargarPendientesOpenIssues();
    const interval = setInterval(cargarPendientesOpenIssues, INTERVALO_POLLING_MS);
    window.addEventListener('openissues:actualizado', cargarPendientesOpenIssues);

    return () => {
        clearInterval(interval);
        window.removeEventListener('openissues:actualizado', cargarPendientesOpenIssues);
    };
}, [cargarPendientesOpenIssues]);
```

y el link nuevo, **después** del de Horas Extras (mismo markup, con `FaTasks`):

```jsx
<NavLink to="/open-issues" className={({ isActive }) => `ot-header-link ${isActive ? 'is-active' : ''}`}>
    <Badge count={pendientesOpenIssues} size="small" offset={[6, -2]} overflowCount={99}>
        <span className="ot-header-link__label">
            <FaTasks />
            Open Issues
        </span>
    </Badge>
</NavLink>
```

El badge y el polling de HHEE quedan **intactos**.

### `src/components/Notificacion.jsx`

Reemplazar `handleClickNotificacion` y las tres expresiones inline `notificacion.tipo === 'hhee'` por un
helper, para que las de OT sigan sin navegar y las de HHEE sigan yendo a donde iban:

```jsx
// Mapa tipo de notificación -> destino del deep-link. Las de OT ('tipo' ausente o 'ot')
// NO navegan a ningún lado (comportamiento histórico, sin cambios).
const destinoNotificacion = (n) => {
    if (n.tipo === 'hhee' && n.solicitud_hhee_id) return `/horas-extras?solicitud=${n.solicitud_hhee_id}`;
    if (n.tipo === 'open_issue' && n.open_issue_id) return `/open-issues?issue=${n.open_issue_id}`;
    return null;
};

const handleClickNotificacion = (notificacion) => {
    const destino = destinoNotificacion(notificacion);
    if (!destino) return;
    setIsModalVisible(false);
    navigate(destino);
};
```

y dentro del `map`:

```jsx
const esClickeable = !!destinoNotificacion(notificacion);
// ...
className={`notification-item ${notificacion.leido ? 'is-read' : ''} ${esClickeable ? 'is-clickable' : ''}`}
role={esClickeable ? 'button' : undefined}
tabIndex={esClickeable ? 0 : undefined}
```

### Backend: `NotificacionesController::index()`

Agregar **una** rama al `if` existente, sin tocar las otras dos:

```php
if ($notificacion->tipo === 'hhee') {
    // ... sin cambios
} elseif ($notificacion->tipo === 'open_issue') {
    $notificacion->detalle = "Open Issue #{$notificacion->open_issue_id} – {$notificacion->mensaje}";
} else {
    // ... sin cambios (OT)
}
```

`tipo` y `open_issue_id` ya viajan en el JSON por ser columnas del modelo: alcanza con el `$fillable` de §2.4.

### `src/index.css`

**(a) Piggyback**: agregar el selector `oi-*` a reglas `hhee-*` que ya existen, **sin cambiar sus
propiedades** (así no se duplica CSS ni se inventan tokens nuevos):

| Regla existente | Selector a agregar |
|---|---|
| `.hhee-card` | `.oi-card` |
| `.hhee-card + .hhee-card` | `.oi-card + .oi-card` |
| `.hhee-catalogos-loading, .hhee-modal-loading` | `.oi-modal-loading` |
| `.hhee-detalle-header` (y sus dos overrides en los `@media` de 1100px y 720px) | `.oi-detalle-header` |
| `.hhee-toolbar` (y su override en el `@media` de 720px) | `.oi-toolbar` |

**(b) Bloque nuevo**, al **final** de `@layer components` (justo antes de la llave que cierra el layer,
después de las reglas `.notification-item.*`), con un comentario de cabecera al estilo del bloque HHEE:

```
.oi-page                          margin-top: 4px;
.oi-tabla-titulo                  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
                                  overflow:hidden; font-weight:600;
.oi-chips                         display:flex; flex-wrap:wrap; gap:8px;
.oi-chip                          display:inline-flex; align-items:center; gap:6px; padding:4px 10px 4px 4px;
                                  border:1px solid var(--line); border-radius:999px; background:#fff; font-size:12px;
.oi-chip--depto                   border-color:rgba(37,99,235,.35); background:#eff6ff;
.oi-chip__avatar                  width:22px; height:22px; border-radius:999px; display:inline-flex;
                                  align-items:center; justify-content:center; font-size:10px; font-weight:800;
                                  color:#0f766e; background:#ccfbf1;
.oi-chip__quitar                  border:0; background:transparent; cursor:pointer; color:var(--muted);
                                  line-height:1; padding:0 2px;
.oi-chip__quitar:hover            color:#dc2626;
.oi-avatares                      display:flex; align-items:center;
.oi-avatar                        width:26px; height:26px; border-radius:999px; margin-left:-8px;
                                  border:2px solid #fff; background:#ccfbf1; color:#0f766e;
                                  display:inline-flex; align-items:center; justify-content:center;
                                  font-size:10px; font-weight:800;
.oi-avatar:first-child            margin-left:0;
.oi-avatar--mas                   background:#e2e8f0; color:#475569;
.oi-timeline-autor                margin:0; font-weight:700; font-size:13px; color:#111827;
.oi-timeline-fecha                color:var(--muted); font-size:11px;
.oi-timeline-texto                margin:6px 0 0; white-space:pre-wrap; font-size:13px; color:#334155;
.oi-nueva-actualizacion__acciones display:flex; justify-content:flex-end; gap:8px; margin-top:12px;
.oi-selector-resumen              margin:8px 0 0; font-size:12px; color:var(--muted);
```

Se reusan tal cual (sin duplicar): `.ot-list`, `.ot-page-heading`, `.ot-eyebrow`, `.primary-action`,
`.secondary-action`, `.danger-action`, `.modern-table`, `.modal-actions`, `.form-field`, `.form-label`,
`.hhee-form-grid`, `.hhee-seccion-titulo`, `.hhee-detalle-label`, `.hhee-detalle-valor`,
`.hhee-adjunto-link`, `.hhee-tag-inline`, `.hhee-empty`, `.ot-header-link` y `.ot-header-link__label`.
**Ningún color ni token nuevo**: todo sale de las variables de `:root` y de la paleta teal/azul existente.

---

# 7. Tests — `ordenes-sar/tests/Feature/OpenIssuesCircuitoTest.php`

Un único archivo, con `use RefreshDatabase`, sqlite `:memory:` y `Laravel\Passport\Passport::actingAs()`.
Copiar el docblock de clase y los helpers de `HheeCircuitoTest` (`departamento()`, `usuario()`), más helpers
propios: `payloadIssue(array $overrides = [])` y `crearIssueVia(User $actor, array $overrides = [])`.

| # | Test | Assert clave |
|---|---|---|
| 1 | `test_crear_issue_devuelve_201_con_detalle_y_flags` | 201; `estado = abierto`; `prioridad = media` por default; `flags.puede_editar` true; `actualizaciones` tiene 1 fila `tipo = apertura` |
| 2 | `test_creador_queda_involucrado_con_origen_creador` | fila en `oi_involucrados` con `origen = creador`, `departamento_id` null, `agregado_por_id` = creador |
| 3 | `test_crear_con_departamentos_ids_expande_a_todos_los_users_del_depto` | 3 users en el depto destino -> 3 filas `origen = departamento` con el `departamento_id` correcto; `involucrados_count = 4` contando al creador |
| 4 | `test_expansion_de_departamento_es_snapshot_no_dinamica` | crear el issue con el depto expandido, crear **después** un user nuevo en ese depto, y verificar que `show` NO lo lista |
| 5 | `test_persona_suelta_gana_sobre_departamento_y_duplicados_se_ignoran` | mismo id en `involucrados_ids` y en la expansión -> 1 sola fila con `origen = manual`; un segundo POST del mismo depto devuelve `agregados = []`, `ignorados` no vacío, 200, y `oi_involucrados` no crece |
| 6 | `test_crear_notifica_solo_a_involucrados_y_nunca_al_actor` | filas en `notificaciones` con `tipo = open_issue` y `open_issue_id` seteado, `usuario_creador_id` = cada involucrado nuevo; **ninguna** con `usuario_creador_id` = actor; una sola por destinatario |
| 7 | `test_comentario_notifica_a_todos_los_involucrados_menos_al_actor` | POST de actualización con texto -> N-1 notificaciones; `estado_anterior` y `estado_nuevo` NOT NULL (iguales al estado actual) |
| 8 | `test_flujo_completo_comentario_en_progreso_cierre_reapertura` | `nuevo_estado = en_progreso` -> fila `cambio_estado`; `/cerrar` -> `estado = cerrado` con `fecha_cierre` y `cerrado_por_id` seteados y fila `cierre`; `/reabrir` -> `estado = abierto`, `fecha_cierre` null, `cerrado_por_id` null, `fecha_reapertura` seteada y fila `reapertura` |
| 9 | `test_alcance_del_listado_por_tipo_de_usuario` | lo ven: creador, involucrado, user del **depto destino** y `gerente`; NO lo ve un user ajeno (otro depto, no involucrado, rol `team_member`): `total = 0` |
| 10 | `test_show_403_para_usuario_sin_alcance_y_404_si_no_existe` | 403 con `{"error": ...}`; 404 con `{"error": "Issue no encontrado"}` |
| 11 | `test_gerente_ve_pero_no_puede_escribir` | `show` 200 con `flags.puede_actualizar` false; POST de actualización -> 403 |
| 12 | `test_usuario_del_departamento_destino_puede_comentar_sin_estar_involucrado` | 201 (regla §5.5) |
| 13 | `test_solo_el_creador_puede_editar_y_registra_actualizacion_edicion` | involucrado no creador -> PUT 403; creador -> 200 y fila `tipo = edicion` cuyo `texto` empieza con `Editó: ` |
| 14 | `test_issue_cerrado_rechaza_editar_comentar_e_involucrar_con_422` | PUT 422; POST actualización 422; POST involucrados 422; DELETE involucrado 422 |
| 15 | `test_transiciones_invalidas_devuelven_422` | cerrar dos veces -> 422; reabrir un issue abierto -> 422; `nuevo_estado = cerrado` en actualizaciones -> 422 (no está en `ESTADOS_MANUALES`) |
| 16 | `test_actualizacion_vacia_422_y_nuevo_estado_igual_al_actual_se_ignora` | sin texto/archivo/estado -> 422; `nuevo_estado` igual al actual + texto -> 201 con `tipo = comentario` (no `cambio_estado`) |
| 17 | `test_no_se_puede_quitar_al_creador` | DELETE del creador -> 422; quitar a otro -> 200, fila `involucrado_quitado` y notificación **solo** al quitado |
| 18 | `test_quitar_involucrado_inexistente_devuelve_422` | mensaje "no está involucrado" |
| 19 | `test_validaciones_422_del_alta` | sin `titulo`; `titulo` de 201 chars; `departamento_destino_id` inexistente; `prioridad = urgente`; `involucrados_ids` con id inexistente |
| 20 | `test_adjunto_en_actualizacion_guarda_archivo_y_mime` | `UploadedFile::fake()->image('foto.jpg')` -> columnas `archivo` y `mime_type` no nulas y `archivo_url` en la respuesta; **borrar el archivo al final** con `File::delete(public_path('storage/archivos/' . $nombre))`, porque `ArchivoOrden` escribe en `public/` y no hay `Storage::fake` |
| 21 | `test_pendientes_solo_total_cuenta_no_cerrados_donde_participo` | `?solo_total=1` -> `{total: N}`; al cerrar el issue el total baja; un issue ajeno no cuenta |
| 22 | `test_catalogos_devuelve_estados_prioridades_departamentos_usuarios_y_flags` | keys presentes; `flags.es_gerente` true solo con `rol = gerente` |
| 23 | `test_listado_filtra_por_estado_prioridad_departamento_texto_mios_y_participo` | cada filtro reduce el `total` al valor esperado |
| 24 | `test_regresion_notificaciones_de_ot_y_hhee_no_cambian` | crear una `Notificacion` `tipo = ot` (con `orden_trabajo_id`), otra `hhee` y otra `open_issue`; `GET /api/notificaciones` devuelve las 3 con el `detalle` correcto de cada tipo |
| 25 | `test_listado_trae_counts_y_ultima_actualizacion` | `involucrados_count`, `actualizaciones_count`, `involucrados_preview` (máx 5) y `ultima_actualizacion_at` mayor que `created_at` después de comentar |

Opcional pero recomendado (mismo estilo que `tests/Unit/HheeEstadosTest.php`):
`tests/Unit/OpenIssueEstadosTest.php` con transiciones válidas e inválidas, `esTerminal()`, y que
`catalogo()` / `catalogoPrioridades()` devuelvan `value`, `label` y `color`.

Criterio de aceptación: `php artisan test` **completo** en verde (los 6 archivos Feature existentes, los 5
Unit y el nuevo) y `php artisan migrate` (a secas) aplicando limpio en SQL Server.

---

# 8. Orden de implementación y responsable por archivo

### Paso 1 — agente `database-sqlserver`

| Archivo | Acción |
|---|---|
| `ordenes-sar/database/migrations/2026_09_18_000001_create_oi_issues_table.php` | crear (§1.1) |
| `ordenes-sar/database/migrations/2026_09_18_000002_create_oi_involucrados_table.php` | crear (§1.2) |
| `ordenes-sar/database/migrations/2026_09_18_000003_create_oi_actualizaciones_table.php` | crear (§1.3) |
| `ordenes-sar/database/migrations/2026_09_18_000004_add_open_issue_to_notificaciones_table.php` | crear (§1.4, dos caminos) |

Verificación permitida: `php artisan migrate --pretend` y correr la suite existente (sqlite). **No** se
ejecuta nada contra la base productiva: eso lo hace el usuario, con backup.

### Paso 2 — agente `backend-laravel`

| Archivo | Acción |
|---|---|
| `app/Models/OpenIssue.php` | crear (§2.1) |
| `app/Models/OpenIssueInvolucrado.php` | crear (§2.2) |
| `app/Models/OpenIssueActualizacion.php` | crear (§2.3) |
| `app/Models/Notificacion.php` | **modificar**: `$fillable` + relación `openIssue()` (§2.4) |
| `app/Support/OpenIssueAutorizacionException.php` | crear (§3.1) |
| `app/Support/OpenIssueEstados.php` | crear (§3.2) |
| `app/Support/AlcanceOpenIssues.php` | crear (§3.3) |
| `app/Support/OpenIssueFlujo.php` | crear (§3.4) |
| `app/Support/OpenIssueNotificador.php` | crear (§3.5) |
| `config/open_issues.php` | crear (§3.6) |
| `app/Http/Controllers/OpenIssueController.php` | crear (§4) |
| `app/Http/Controllers/NotificacionesController.php` | **modificar**: rama `open_issue` en `index()` (§6.6) |
| `routes/api.php` | **modificar**: import + bloque `Route::prefix('open-issues')` (§4.4) |

### Paso 3 — agente `frontend-react`

| Archivo | Acción |
|---|---|
| `ot-front/src/Utils/otApi.js` | **modificar**: 1 línea (FormData) (§6.2) |
| `ot-front/src/Utils/openIssuesApi.js` | crear (§6.2) |
| `ot-front/src/Utils/openIssues.js` | crear (§6.3) |
| `ot-front/src/components/openissues/OpenIssueFilter.jsx` | crear (§6.5) |
| `ot-front/src/components/openissues/SelectorInvolucrados.jsx` | crear (§6.5) |
| `ot-front/src/components/openissues/ModalCrearOpenIssue.jsx` | crear (§6.5) |
| `ot-front/src/components/openissues/ModalDetalleOpenIssue.jsx` | crear (§6.5) |
| `ot-front/src/pages/OpenIssues.jsx` | crear (§6.4) |
| `ot-front/src/App.jsx` | **modificar**: import + ruta (§6.6) |
| `ot-front/src/components/Header.jsx` | **modificar**: badge + link (§6.6) |
| `ot-front/src/components/Notificacion.jsx` | **modificar**: deep-link por tipo (§6.6) |
| `ot-front/src/index.css` | **modificar**: piggyback + bloque `oi-*` (§6.6) |

Verificación: `npm run build` sin errores.

### Paso 4 — agente `qa-tester`

`ordenes-sar/tests/Feature/OpenIssuesCircuitoTest.php` (crear, §7) y, opcionalmente,
`tests/Unit/OpenIssueEstadosTest.php`. Corre `php artisan test` completo y verifica que **ningún** test
preexistente se rompa.

### Paso 5 — agente `code-reviewer`

Checklist específico:

- (a) `notificaciones.estado_anterior` y `estado_nuevo` nunca llegan en null.
- (b) Ninguna escritura a `oi_*` fuera de `OpenIssueFlujo`.
- (c) `DB::transaction` + `lockForUpdate` en todo cambio de estado.
- (d) El front no recalcula permisos: solo lee `flags`.
- (e) OTs y HHEE intactos (tests viejos en verde, sin modificarlos).
- (f) Ningún archivo se mueve al filesystem antes del guard de autorización.

---

# 9. Riesgos y decisiones a confirmar (no bloquean la implementación)

1. **Escritura del departamento destino (§5.5)** — es el único desvío del encargo. Para volver al encargo
   literal ("solo creador o involucrado") se borra la primera línea de
   `AlcanceOpenIssues::puedeEscribir()` y se ajustan los tests #11 y #12.
   **Recomendación: dejarlo como está**; si no, el circuito "le pido algo a Calidad" obliga a adivinar de
   antemano a quién involucrar.
2. **`rol = 'gerente'` como alcance global de lectura** — hoy el enum de `users.rol` es
   `gerente | group_leader | team_member | analista`, con un gerente por departamento: eso significa que
   **todos** los gerentes verán **todos** los issues de la planta. Si se quiere acotar a "gerente de su
   propio departamento o del departamento destino", son 3 líneas en `AlcanceOpenIssues` más el ajuste de
   `config('open_issues.roles_ven_todo')`. Conviene confirmarlo con el usuario.
3. **Notificar a todo el departamento destino** — decidí que **no** (§5.12). Si se quisiera, la palanca es
   una config `notificar_departamento_destino` y una línea en `OpenIssueNotificador`. Ojo con los deptos
   grandes: son N filas de `notificaciones` por evento.
4. **Borrado físico de involucrados** (DELETE real en `oi_involucrados`) en vez de baja lógica: elegido
   porque el evento queda igualmente auditado en `oi_actualizaciones` (`involucrado_quitado`) y así el
   UNIQUE permite volver a agregar a la persona sin lógica extra.
5. **`descripcion` como `nvarchar(4000)`** (no `nvarchar(max)`): si el negocio necesitara textos más largos,
   habría que migrar la columna a `text()`. 4000 caracteres son unas 600 palabras: alcanza de sobra para el
   caso de uso descrito.
6. **Búsqueda por texto con `LIKE '%...%'`** — no usa índice. Aceptable hoy; si el volumen creciera mucho,
   la alternativa sería full-text de SQL Server (fuera de alcance).
7. **La migración en producción la corre el usuario**, no los agentes, con backup hecho y después de
   verificar que `DB_DATABASE` del `.env` sea la base esperada (`ordenes_sar`).
