<?php

namespace App\Support;

use App\Models\Departamento;
use App\Models\OpenIssue;
use App\Models\OpenIssueActualizacion;
use App\Models\OpenIssueInvolucrado;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orquestación transaccional del módulo Open Issues: única clase que muta
 * oi_issues/oi_involucrados/oi_actualizaciones. El controller NUNCA escribe
 * esas tablas directamente. Toda mutación va dentro de DB::transaction. Las
 * que cambian estado releen la fila con lockForUpdate() (conLock()) y
 * revalidan la transición sobre esa fila recién bloqueada.
 *
 * Errores:
 * - Autorización (OpenIssueAutorizacionException, 403 en el controller): "no
 *   sos el creador", "no podés escribir en este issue", etc.
 * - Estado/datos inválidos (ValidationException, 422): "el issue ya está
 *   cerrado", "transición inválida", etc.
 */
class OpenIssueFlujo
{
    /**
     * NOTA de concurrencia (copiada de App\Support\HheeFlujo::conLock(),
     * adaptada): las validaciones de OWNERSHIP (solo el creador puede
     * editar...) no cambian con la concurrencia (creador_id es inmutable),
     * así que se chequean con el objeto tal cual llega. Pero el ESTADO sí
     * puede cambiar entre que el controller cargó $issue y que esta clase
     * efectivamente escribe: dos requests concurrentes sobre el MISMO issue
     * (dos personas comentando/cerrando a la vez) pueden pisarse.
     *
     * Por eso cada transacción que muta el estado vuelve a leer el issue con
     * lockForUpdate() (SELECT ... FOR UPDATE: en sqlsrv emite el hint
     * correcto; en sqlite -usado en tests- Illuminate\Database\Query\
     * Grammars\SQLiteGrammar no compila ningún lock, es no-op inofensivo) y
     * RE-RESUELVE la transición de estado a partir de esa fila recién
     * bloqueada, nunca del objeto $issue que recibió el método.
     */
    private static function conLock(OpenIssue $issue): OpenIssue
    {
        return OpenIssue::where('id', $issue->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Guard reutilizable: lanza OpenIssueAutorizacionException si el usuario
     * no puede escribir en este issue (ver App\Support\AlcanceOpenIssues).
     */
    public static function asegurarPuedeEscribir(OpenIssue $issue, User $actor): void
    {
        if (!AlcanceOpenIssues::puedeEscribir($actor, $issue)) {
            throw new OpenIssueAutorizacionException('No tiene permisos para escribir en este issue.');
        }
    }

    /**
     * Guard: lanza ValidationException si el issue está cerrado (§5.10 de la
     * spec: un issue cerrado queda congelado, lo único permitido es reabrir).
     */
    public static function asegurarNoCerrado(OpenIssue $issue, string $mensaje): void
    {
        if ($issue->estado === OpenIssueEstados::CERRADO) {
            throw ValidationException::withMessages(['estado' => $mensaje]);
        }
    }

    // =========================================================================
    // Alta / edición
    // =========================================================================

    /**
     * $datos: titulo, descripcion?, departamento_destino_id, prioridad?,
     * involucrados_ids?, departamentos_ids?, texto_inicial?.
     *
     * $adjunto: ['archivo' => ?string, 'mime_type' => ?string], YA guardado
     * en disco por el controller con App\Support\ArchivoOrden::store(). Este
     * método NO toca el filesystem.
     */
    public static function crear(array $datos, User $actor, array $adjunto = []): OpenIssue
    {
        return DB::transaction(function () use ($datos, $actor, $adjunto) {
            $issue = OpenIssue::create([
                'titulo' => $datos['titulo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'departamento_destino_id' => $datos['departamento_destino_id'],
                'creador_id' => $actor->id,
                'estado' => OpenIssueEstados::ABIERTO,
                'prioridad' => $datos['prioridad'] ?? config('open_issues.prioridad_default'),
            ]);

            // El creador SIEMPRE queda involucrado (§5.1): hace que
            // "participo"/el badge/los destinatarios de campana sean una
            // sola consulta (exists sobre oi_involucrados).
            OpenIssueInvolucrado::create([
                'issue_id' => $issue->id,
                'user_id' => $actor->id,
                'origen' => OpenIssueInvolucrado::ORIGEN_CREADOR,
                'departamento_id' => null,
                'agregado_por_id' => $actor->id,
                'created_at' => now(),
            ]);

            $r = self::insertarInvolucrados(
                $issue,
                $datos['involucrados_ids'] ?? [],
                $datos['departamentos_ids'] ?? [],
                $actor
            );

            self::registrarActualizacion($issue, $actor, OpenIssueEstados::TIPO_APERTURA, null, null, OpenIssueEstados::ABIERTO);

            if (!empty($datos['texto_inicial']) || !empty($adjunto['archivo'])) {
                self::registrarActualizacion(
                    $issue,
                    $actor,
                    OpenIssueEstados::TIPO_COMENTARIO,
                    $datos['texto_inicial'] ?? null,
                    null,
                    null,
                    $adjunto
                );
            }

            OpenIssueNotificador::notificarCreado($issue, $actor, $r['agregados_users']);

            return $issue->fresh();
        });
    }

    /**
     * $datos: titulo, descripcion?, prioridad?, departamento_destino_id?.
     * Solo el creador, y solo si el issue no está cerrado. No notifica
     * (§5.12: la edición no genera campana, es ruido).
     */
    public static function actualizar(OpenIssue $issue, array $datos, User $actor): OpenIssue
    {
        // Ownership (creador_id es inmutable): no necesita el lock, se chequea
        // con el objeto tal cual llega (ver nota de conLock() más arriba).
        if ((int) $issue->creador_id !== (int) $actor->id) {
            throw new OpenIssueAutorizacionException('Solo el creador puede editar este issue.');
        }

        return DB::transaction(function () use ($issue, $datos, $actor) {
            $actual = self::conLock($issue);

            // Guard de estado revalidado sobre la fila recién bloqueada (revisión #5):
            // dos ediciones/cierres concurrentes no deben pisarse.
            self::asegurarNoCerrado($actual, 'No se puede editar un issue cerrado. Reabrilo primero.');

            // Orden canónico de labels de la §3.4: título, descripción, prioridad, departamento destino.
            $camposLabels = [
                'titulo' => 'título',
                'descripcion' => 'descripción',
                'prioridad' => 'prioridad',
                'departamento_destino_id' => 'departamento destino',
            ];

            $cambios = [];

            foreach ($camposLabels as $campo => $label) {
                if (!array_key_exists($campo, $datos)) {
                    continue;
                }

                if ((string) $actual->{$campo} !== (string) $datos[$campo]) {
                    $cambios[] = $label;
                }
            }

            $actual->update([
                'titulo' => $datos['titulo'] ?? $actual->titulo,
                'descripcion' => array_key_exists('descripcion', $datos) ? $datos['descripcion'] : $actual->descripcion,
                'prioridad' => $datos['prioridad'] ?? $actual->prioridad,
                'departamento_destino_id' => $datos['departamento_destino_id'] ?? $actual->departamento_destino_id,
            ]);

            if (!empty($cambios)) {
                self::registrarActualizacion($actual, $actor, OpenIssueEstados::TIPO_EDICION, 'Editó: ' . implode(', ', $cambios));
            }

            return $actual->fresh();
        });
    }

    // =========================================================================
    // Timeline / transiciones de estado
    // =========================================================================

    /**
     * $datos: texto?, nuevo_estado?.
     */
    public static function agregarActualizacion(OpenIssue $issue, array $datos, User $actor, array $adjunto = []): OpenIssueActualizacion
    {
        self::asegurarPuedeEscribir($issue, $actor);

        return DB::transaction(function () use ($issue, $datos, $actor, $adjunto) {
            $actual = self::conLock($issue);

            self::asegurarNoCerrado($actual, 'El issue está cerrado: reabrilo para poder agregar actualizaciones.');

            $texto = trim((string) ($datos['texto'] ?? '')) ?: null;
            $nuevoEstado = $datos['nuevo_estado'] ?? null;

            // Idempotente: pedir el mismo estado que ya tiene no genera fila de cambio de estado.
            if ($nuevoEstado === $actual->estado) {
                $nuevoEstado = null;
            }

            if ($texto === null && empty($adjunto['archivo']) && $nuevoEstado === null) {
                throw ValidationException::withMessages([
                    'texto' => 'La actualización debe tener texto, un archivo o un cambio de estado.',
                ]);
            }

            $estadoAnterior = null;
            $tipo = OpenIssueEstados::TIPO_COMENTARIO;

            if ($nuevoEstado !== null) {
                $esManual = in_array($nuevoEstado, OpenIssueEstados::ESTADOS_MANUALES, true);
                $esValido = $esManual && OpenIssueEstados::puedeTransicionar($actual->estado, $nuevoEstado);

                if (!$esValido) {
                    throw ValidationException::withMessages([
                        'nuevo_estado' => 'Transición de estado inválida.',
                    ]);
                }

                $estadoAnterior = $actual->estado;
                $actual->update(['estado' => $nuevoEstado]);
                $tipo = OpenIssueEstados::TIPO_CAMBIO_ESTADO;
            }

            $act = self::registrarActualizacion($actual, $actor, $tipo, $texto, $estadoAnterior, $nuevoEstado, $adjunto);

            OpenIssueNotificador::notificarActualizacion($actual, $actor, $act);

            return $act;
        });
    }

    public static function cerrar(OpenIssue $issue, User $actor, ?string $texto = null): OpenIssue
    {
        self::asegurarPuedeEscribir($issue, $actor);

        return DB::transaction(function () use ($issue, $actor, $texto) {
            $actual = self::conLock($issue);

            if (!OpenIssueEstados::puedeTransicionar($actual->estado, OpenIssueEstados::CERRADO)) {
                throw ValidationException::withMessages(['estado' => 'El issue ya está cerrado.']);
            }

            $estadoAnterior = $actual->estado;

            $actual->update([
                'estado' => OpenIssueEstados::CERRADO,
                'fecha_cierre' => now(),
                'cerrado_por_id' => $actor->id,
            ]);

            self::registrarActualizacion($actual, $actor, OpenIssueEstados::TIPO_CIERRE, $texto, $estadoAnterior, OpenIssueEstados::CERRADO);

            OpenIssueNotificador::notificarCierre($actual, $actor, $estadoAnterior, $texto);

            return $actual->fresh();
        });
    }

    public static function reabrir(OpenIssue $issue, User $actor, ?string $texto = null): OpenIssue
    {
        self::asegurarPuedeEscribir($issue, $actor);

        return DB::transaction(function () use ($issue, $actor, $texto) {
            $actual = self::conLock($issue);

            if ($actual->estado !== OpenIssueEstados::CERRADO) {
                throw ValidationException::withMessages(['estado' => 'Solo se puede reabrir un issue cerrado.']);
            }

            // El histórico de cierres vive en oi_actualizaciones; estas
            // columnas reflejan EL CIERRE VIGENTE (§3.4 de la spec).
            $actual->update([
                'estado' => OpenIssueEstados::ABIERTO,
                'fecha_reapertura' => now(),
                'fecha_cierre' => null,
                'cerrado_por_id' => null,
            ]);

            self::registrarActualizacion($actual, $actor, OpenIssueEstados::TIPO_REAPERTURA, $texto, OpenIssueEstados::CERRADO, OpenIssueEstados::ABIERTO);

            OpenIssueNotificador::notificarReapertura($actual, $actor);

            return $actual->fresh();
        });
    }

    // =========================================================================
    // Involucrados
    // =========================================================================

    /**
     * @return array{agregados: int[], ignorados: int[], agregados_users: \Illuminate\Support\Collection}
     *
     * NOTA de concurrencia (revisión #12): el conLock() de acá abajo serializa
     * TODAS las llamadas a agregarInvolucrados()/quitarInvolucrado() sobre el
     * mismo issue (la segunda transacción concurrente queda bloqueada en el
     * SELECT ... FOR UPDATE hasta que la primera hace commit). Como
     * insertarInvolucrados() lee 'existentes' y hace sus INSERT DESPUÉS de
     * tomar ese lock, dos altas de involucrados concurrentes ya no pueden
     * pisarse contra el UNIQUE (uq_oi_inv_issue_user): quedan en fila. No hace
     * falta además capturar QueryException de violación de unique -en sqlsrv
     * una excepción dentro de una transacción explícita la aborta, así que
     * sumar ese catch complicaría el rollback sin ganar nada que el lock no
     * cubra ya.
     */
    public static function agregarInvolucrados(OpenIssue $issue, array $userIds, array $departamentoIds, User $actor): array
    {
        self::asegurarPuedeEscribir($issue, $actor);

        return DB::transaction(function () use ($issue, $userIds, $departamentoIds, $actor) {
            $actual = self::conLock($issue);

            self::asegurarNoCerrado($actual, 'No se pueden modificar los involucrados de un issue cerrado.');

            $r = self::insertarInvolucrados($actual, $userIds, $departamentoIds, $actor);

            if (empty($r['agregados'])) {
                // Sin error (200): todos ya estaban involucrados. No registra
                // actualización ni notifica (§5.3 de la spec).
                return $r;
            }

            $nombres = $r['agregados_users']->pluck('name')->all();
            $textoLote = 'Involucró a: ' . implode(', ', $nombres);

            if (!empty($departamentoIds)) {
                $nombresDeptos = Departamento::whereIn('id', $departamentoIds)->pluck('nombre')->all();
                $textoLote .= ' - departamentos: ' . implode(', ', $nombresDeptos);
            }

            // Str::limit(..., 3990) deja margen para el '...' que agrega (hasta 3 chars más) y
            // no pasa de nvarchar(4000) en SQL Server (revisión #3: con 3999 se pasaba a 4002).
            self::registrarActualizacion($actual, $actor, OpenIssueEstados::TIPO_INVOLUCRADO_AGREGADO, Str::limit($textoLote, 3990));

            OpenIssueNotificador::notificarInvolucradosAgregados($actual, $actor, $r['agregados_users']);

            return $r;
        });
    }

    public static function quitarInvolucrado(OpenIssue $issue, int $userId, User $actor): void
    {
        self::asegurarPuedeEscribir($issue, $actor);

        // Ownership del creador (inmutable): no necesita el lock.
        if ($userId === (int) $issue->creador_id) {
            throw ValidationException::withMessages(['user_id' => 'No se puede quitar al creador del issue.']);
        }

        DB::transaction(function () use ($issue, $userId, $actor) {
            $actual = self::conLock($issue);

            self::asegurarNoCerrado($actual, 'No se pueden modificar los involucrados de un issue cerrado.');

            $fila = $actual->involucrados()->where('user_id', $userId)->first();

            if (!$fila) {
                throw ValidationException::withMessages(['user_id' => 'El usuario no está involucrado en este issue.']);
            }

            $usuarioQuitado = $fila->usuario;
            $nombre = $usuarioQuitado->name ?? 'un usuario';

            $fila->delete();

            self::registrarActualizacion($actual, $actor, OpenIssueEstados::TIPO_INVOLUCRADO_QUITADO, "Quitó a {$nombre}");

            if ($usuarioQuitado) {
                OpenIssueNotificador::notificarInvolucradoQuitado($actual, $actor, $usuarioQuitado);
            }
        });
    }

    /**
     * Inserta filas de oi_involucrados por personas sueltas ($userIds) y por
     * expansión de departamentos ($departamentoIds, SNAPSHOT de quiénes
     * pertenecen HOY a ese depto, §5.2 de la spec). Duplicados se ignoran sin
     * error (§5.3): si un id viene por los dos lados, gana 'manual' porque
     * las personas sueltas se procesan PRIMERO.
     *
     * El tope 'max_involucrados_por_lote' se valida ANTES de insertar nada
     * (revisión #4): se arma primero la lista completa de candidatos a
     * agregar (sueltos + expansión de deptos, sin los que ya están) y si se
     * pasa del tope se lanza la ValidationException sin haber tocado la
     * tabla, evitando altas parciales.
     *
     * @return array{agregados: int[], ignorados: int[], agregados_users: \Illuminate\Support\Collection}
     */
    private static function insertarInvolucrados(OpenIssue $issue, array $userIds, array $departamentoIds, User $actor): array
    {
        $existentes = $issue->involucrados()->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        $manualIds = array_values(array_unique(array_map('intval', $userIds)));

        // Snapshot de la expansión de cada depto, calculado una sola vez y reutilizado
        // tanto para el conteo previo como para el insert (evita repetir la query).
        $deptoIds = array_values(array_unique(array_map('intval', $departamentoIds)));
        $usersPorDepto = [];
        foreach ($deptoIds as $deptoId) {
            $usersPorDepto[$deptoId] = User::where('departamento_id', $deptoId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        $candidatos = $manualIds;
        foreach ($usersPorDepto as $uids) {
            $candidatos = array_merge($candidatos, $uids);
        }
        $candidatos = array_values(array_unique($candidatos));
        $aInsertar = array_values(array_diff($candidatos, $existentes));

        if (count($aInsertar) > config('open_issues.max_involucrados_por_lote', 200)) {
            throw ValidationException::withMessages([
                'involucrados' => 'Demasiados involucrados en una sola operación.',
            ]);
        }

        $agregados = [];
        $ignorados = [];

        // 1) Personas sueltas PRIMERO: 'manual' le gana a 'departamento' si un id viene por los dos lados.
        foreach ($manualIds as $uid) {
            if (in_array($uid, $existentes, true)) {
                $ignorados[] = $uid;
                continue;
            }

            OpenIssueInvolucrado::create([
                'issue_id' => $issue->id,
                'user_id' => $uid,
                'origen' => OpenIssueInvolucrado::ORIGEN_MANUAL,
                'departamento_id' => null,
                'agregado_por_id' => $actor->id,
                'created_at' => now(),
            ]);

            $existentes[] = $uid;
            $agregados[] = $uid;
        }

        // 2) Expansión de departamentos: SNAPSHOT de los users que pertenecen HOY a ese depto.
        foreach ($usersPorDepto as $deptoId => $uids) {
            foreach ($uids as $uid) {
                if (in_array($uid, $existentes, true)) {
                    $ignorados[] = $uid;
                    continue;
                }

                OpenIssueInvolucrado::create([
                    'issue_id' => $issue->id,
                    'user_id' => $uid,
                    'origen' => OpenIssueInvolucrado::ORIGEN_DEPARTAMENTO,
                    'departamento_id' => $deptoId,
                    'agregado_por_id' => $actor->id,
                    'created_at' => now(),
                ]);

                $existentes[] = $uid;
                $agregados[] = $uid;
            }
        }

        return [
            'agregados' => $agregados,
            'ignorados' => array_values(array_unique($ignorados)),
            'agregados_users' => User::whereIn('id', $agregados)->get(),
        ];
    }

    /**
     * Registra una fila en el timeline append-only oi_actualizaciones.
     */
    private static function registrarActualizacion(
        OpenIssue $issue,
        User $actor,
        string $tipo,
        ?string $texto = null,
        ?string $estadoAnterior = null,
        ?string $estadoNuevo = null,
        array $adjunto = []
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
}
