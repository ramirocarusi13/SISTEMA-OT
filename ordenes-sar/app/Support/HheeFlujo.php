<?php

namespace App\Support;

use App\Models\AprobacionHhee;
use App\Models\HheeHistorial;
use App\Models\SolicitudHhee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orquestación transaccional del módulo HHEE: única clase que muta
 * hhee_solicitudes/hhee_solicitud_detalles/hhee_aprobaciones/hhee_historial.
 * Los controllers NO tocan estas tablas directamente (salvo destroy(), que es
 * un DELETE trivial sobre un borrador propio, sin máquina de estados).
 *
 * Cada acción de negocio sigue el mismo patrón:
 *   DB::transaction -> validar transición (HheeEstados) -> mutar
 *   solicitud/detalles/aprobaciones -> fila en hhee_historial -> notificar
 *   (HheeNotificador).
 *
 * Errores:
 * - Estado/datos inválidos (ValidationException, 422): "no se puede editar
 *   una solicitud que no es borrador", "el desglose no cierra", etc.
 * - Autorización (HheeAutorizacionException, 403 en el controller): "no sos
 *   el solicitante", "no tenés el rol para firmar este nivel", etc.
 *
 * departamento_id vs. sector: el formulario ya NO deja elegir departamento
 * (antes era libre); 'sector' es un campo puramente descriptivo de 4 opciones
 * fijas (ver config('hhee.sectores')). hhee_solicitudes.departamento_id se
 * sigue guardando -y sigue siendo el que rutea la firma de nivel 1 (ver
 * App\Support\HheeAprobadores)-, pero SIEMPRE se toma de
 * $usuario->departamento_id (el solicitante logueado), nunca del payload
 * (crear()/actualizar() ignoran cualquier departamento_id que venga en
 * $datos). Efecto colateral buscado: ya no existe la posibilidad de "desviar"
 * el circuito de aprobación eligiendo a mano el departamento de la solicitud
 * (antes era un campo libre en el form) -- el jefe que firma nivel 1 es
 * siempre el de la SOLICITANTE, no uno arbitrario.
 */
class HheeFlujo
{
    // =========================================================================
    // CRUD de la solicitud (solo estado borrador)
    // =========================================================================

    public static function crear(array $datos, User $usuario): SolicitudHhee
    {
        self::validarDetalles($datos['detalles']);

        return DB::transaction(function () use ($datos, $usuario) {
            $solicitud = SolicitudHhee::create([
                'solicitante_id' => $usuario->id,
                // Siempre el del solicitante logueado, NUNCA del payload
                // (ver comentario de clase).
                'departamento_id' => $usuario->departamento_id,
                'sector' => $datos['sector'],
                'fecha_hhee' => $datos['fecha_hhee'],
                'turno' => $datos['turno'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'estado' => HheeEstados::BORRADOR,
            ]);

            self::guardarDetalles($solicitud, $datos['detalles']);
            self::recalcularTotales($solicitud);

            self::registrarHistorial($solicitud, $usuario, 'creada', null, HheeEstados::BORRADOR);

            if (!empty($datos['enviar'])) {
                return self::enviar($solicitud->fresh(), $usuario);
            }

            return $solicitud->fresh(['detalles']);
        });
    }

    public static function actualizar(SolicitudHhee $solicitud, array $datos, User $usuario): SolicitudHhee
    {
        if ((int) $solicitud->solicitante_id !== (int) $usuario->id) {
            throw new HheeAutorizacionException('Solo el solicitante puede modificar esta solicitud.');
        }

        if ($solicitud->estado !== HheeEstados::BORRADOR) {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede editar una solicitud en estado borrador.',
            ]);
        }

        self::validarDetalles($datos['detalles']);

        return DB::transaction(function () use ($solicitud, $datos, $usuario) {
            $solicitud->update([
                // Siempre el del solicitante logueado (mismo usuario que creó
                // el borrador, ver chequeo de arriba), NUNCA del payload.
                'departamento_id' => $usuario->departamento_id,
                'sector' => $datos['sector'],
                'fecha_hhee' => $datos['fecha_hhee'],
                'turno' => $datos['turno'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            // Reemplazo completo de detalles (delete + insert), más simple y
            // menos propenso a errores que hacer un diff fila por fila.
            $solicitud->detalles()->delete();
            self::guardarDetalles($solicitud, $datos['detalles']);
            self::recalcularTotales($solicitud);

            self::registrarHistorial($solicitud, $usuario, 'editada', HheeEstados::BORRADOR, HheeEstados::BORRADOR);

            if (!empty($datos['enviar'])) {
                return self::enviar($solicitud->fresh(), $usuario);
            }

            return $solicitud->fresh(['detalles']);
        });
    }

    // =========================================================================
    // Transiciones de estado
    // =========================================================================

    /**
     * NOTA de concurrencia (aplica a enviar/aprobar/rechazar/anular/
     * cargarHorasReales): las validaciones de OWNERSHIP (solo el
     * solicitante...) no cambian con la concurrencia (solicitante_id es
     * inmutable una vez creada la solicitud), así que se chequean con el
     * objeto tal cual llega. Pero el ESTADO sí puede cambiar entre que el
     * controller cargó $solicitud y que esta clase efectivamente escribe: dos
     * requests concurrentes sobre la MISMA solicitud (dos firmantes del mismo
     * nivel, o un rechazo en simultáneo con una aprobación) pueden pisarse. El
     * UNIQUE(solicitud_id, nivel) de hhee_aprobaciones NO protege esto: la fila
     * ya existe desde que se envía, así que firmar/rechazar es un UPDATE, no
     * un INSERT (no hay violación de constraint que abortar la segunda
     * escritura).
     *
     * Por eso cada transacción vuelve a leer la solicitud con
     * lockForUpdate() (SELECT ... FOR UPDATE: en sqlsrv emite el hint
     * correcto; en sqlite -usado en tests- Illuminate\Database\Query\Grammars\
     * SQLiteGrammar no compila ningún lock, es no-op inofensivo) y RE-RESUELVE
     * el nivel pendiente / la transición de estado a partir de esa fila recién
     * bloqueada, nunca del objeto $solicitud que recibió el método. Así, el
     * segundo firmante que llega después de que el primero ya commiteó ve el
     * estado REAL (no el que tenía en memoria al empezar) y su acción se
     * revalida contra eso: si ya no hay nada pendiente (o pasó a terminal) se
     * aborta con 422; si el nivel pendiente cambió (ej. pasó de nivel 1 a
     * nivel final) su autorización se re-evalúa para ESE nivel, así que en vez
     * de pisar la firma anterior, o bien firma legítimamente el nivel que
     * sigue, o bien es rechazado con 403 por no tener el rol de ese nivel.
     */
    private static function conLock(SolicitudHhee $solicitud): SolicitudHhee
    {
        return SolicitudHhee::where('id', $solicitud->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Comentario fijo de la firma implícita de nivel 1 (ver enviar()), para no
     * repetir el string y que HheeCircuitoTest pueda buscarlo por constante.
     */
    public const COMENTARIO_FIRMA_IMPLICITA = 'Firma implícita: el solicitante es jefe/gerente del área';

    public static function enviar(SolicitudHhee $solicitud, User $usuario): SolicitudHhee
    {
        if ((int) $solicitud->solicitante_id !== (int) $usuario->id) {
            throw new HheeAutorizacionException('Solo el solicitante puede enviar esta solicitud.');
        }

        return DB::transaction(function () use ($solicitud, $usuario) {
            $actual = self::conLock($solicitud);

            if (!HheeEstados::puedeTransicionar($actual->estado, HheeEstados::PENDIENTE_NIVEL1)) {
                throw ValidationException::withMessages([
                    'estado' => 'La solicitud no puede enviarse desde su estado actual.',
                ]);
            }

            if ($actual->detalles()->count() < 1) {
                throw ValidationException::withMessages([
                    'detalles' => 'La solicitud debe tener al menos un empleado cargado para poder enviarse.',
                ]);
            }

            $estadoAntesDeEnviar = $actual->estado;
            $actual->estado = HheeEstados::PENDIENTE_NIVEL1;
            $actual->fecha_envio = now();
            $actual->save();

            foreach (array_keys(config('hhee.niveles', [])) as $nivel) {
                AprobacionHhee::updateOrCreate(
                    ['solicitud_id' => $actual->id, 'nivel' => $nivel],
                    ['estado' => 'pendiente']
                );
            }

            self::registrarHistorial($actual, $usuario, 'enviada', $estadoAntesDeEnviar, $actual->estado);

            // Firma implícita de nivel 1: si el propio solicitante YA es
            // aprobador LEGÍTIMO de nivel 1 (jefe/gerente_area) para el
            // departamento de la solicitud -por match de depto o por alcance
            // global de su rol nivel 1-, no tiene sentido hacerle firmar desde
            // la bandeja de aprobaciones lo que él mismo acaba de cargar: se
            // autofirma en la MISMA transacción y la solicitud pasa directo a
            // pendiente_final. Caso real: un gerente de IT carga la solicitud
            // -> solo falta la firma final de gerencia general.
            //
            // Deliberadamente usa rolLegitimoParaNivel() (NUNCA
            // esContingencia()/rolConQueFirma() con fallback a contingencia):
            // la contingencia jamás dispara esta firma implícita, solo un rol
            // legítimo de nivel 1. Y esto NO es "autoaprobación del circuito":
            // el nivel final sigue exigiendo la firma de otra persona (ver
            // validarAutorizacionDeFirma(), sin cambios para nivel 2).
            $rolLegitimoNivel1 = HheeAprobadores::rolLegitimoParaNivel($usuario, 1, $actual);

            if ($rolLegitimoNivel1 !== null) {
                $aprobacionNivel1 = $actual->aprobacionNivel(1)->firstOrFail();
                $aprobacionNivel1->update([
                    'estado' => 'aprobada',
                    'aprobador_id' => $usuario->id,
                    'rol_aprobador' => $rolLegitimoNivel1,
                    'es_contingencia' => false,
                    'comentario' => self::COMENTARIO_FIRMA_IMPLICITA,
                    'firmado_at' => now(),
                ]);

                $estadoAntesDeFirmaImplicita = $actual->estado;
                $actual->estado = HheeEstados::PENDIENTE_FINAL;
                $actual->fecha_aprobacion_nivel1 = now();
                $actual->save();

                self::registrarHistorial(
                    $actual,
                    $usuario,
                    'aprobada_nivel1',
                    $estadoAntesDeFirmaImplicita,
                    $actual->estado,
                    false,
                    self::COMENTARIO_FIRMA_IMPLICITA
                );

                // Notifica a los aprobadores de nivel FINAL (no a los de
                // nivel 1: no hay nada pendiente de nivel 1, ya se autofirmó).
                HheeNotificador::notificarAprobadaNivel1($actual, $usuario, false, $estadoAntesDeFirmaImplicita);

                return $actual->fresh(['detalles', 'aprobaciones']);
            }

            HheeNotificador::notificarEnviada($actual, $usuario, $estadoAntesDeEnviar);

            return $actual->fresh(['detalles', 'aprobaciones']);
        });
    }

    public static function aprobar(SolicitudHhee $solicitud, User $usuario, ?string $comentario): SolicitudHhee
    {
        return DB::transaction(function () use ($solicitud, $usuario, $comentario) {
            $actual = self::conLock($solicitud);

            $nivel = self::nivelPendiente($actual);

            if ($nivel === null) {
                throw ValidationException::withMessages([
                    'estado' => 'La solicitud no tiene (o ya no tiene) una aprobación pendiente.',
                ]);
            }

            self::validarAutorizacionDeFirma($actual, $usuario, $nivel, 'aprobar');

            $esContingencia = HheeAprobadores::esContingencia($usuario, $nivel, $actual);
            $rol = HheeAprobadores::rolConQueFirma($usuario, $nivel, $actual);
            $esFinal = $nivel === 2;

            $aprobacion = $actual->aprobacionNivel($nivel)->firstOrFail();
            $aprobacion->update([
                'estado' => 'aprobada',
                'aprobador_id' => $usuario->id,
                'rol_aprobador' => $rol,
                'es_contingencia' => $esContingencia,
                'comentario' => $comentario,
                'firmado_at' => now(),
            ]);

            $estadoAnterior = $actual->estado;

            if ($esFinal) {
                $actual->estado = HheeEstados::APROBADA;
                $actual->fecha_aprobacion_final = now();
            } else {
                $actual->estado = HheeEstados::PENDIENTE_FINAL;
                $actual->fecha_aprobacion_nivel1 = now();
            }

            $actual->save();

            self::registrarHistorial(
                $actual,
                $usuario,
                $esFinal ? 'aprobada_final' : 'aprobada_nivel1',
                $estadoAnterior,
                $actual->estado,
                $esContingencia,
                $comentario
            );

            if ($esFinal) {
                HheeNotificador::notificarAprobadaFinal($actual, $usuario, $esContingencia, $estadoAnterior);
            } else {
                HheeNotificador::notificarAprobadaNivel1($actual, $usuario, $esContingencia, $estadoAnterior);
            }

            return $actual->fresh(['detalles', 'aprobaciones']);
        });
    }

    public static function rechazar(SolicitudHhee $solicitud, User $usuario, string $motivo): SolicitudHhee
    {
        return DB::transaction(function () use ($solicitud, $usuario, $motivo) {
            $actual = self::conLock($solicitud);

            $nivel = self::nivelPendiente($actual);

            if ($nivel === null) {
                throw ValidationException::withMessages([
                    'estado' => 'La solicitud no tiene (o ya no tiene) una aprobación pendiente para rechazar.',
                ]);
            }

            self::validarAutorizacionDeFirma($actual, $usuario, $nivel, 'rechazar');

            $esContingencia = HheeAprobadores::esContingencia($usuario, $nivel, $actual);
            $rol = HheeAprobadores::rolConQueFirma($usuario, $nivel, $actual);

            $aprobacion = $actual->aprobacionNivel($nivel)->first();

            if ($aprobacion) {
                $aprobacion->update([
                    'estado' => 'rechazada',
                    'aprobador_id' => $usuario->id,
                    'rol_aprobador' => $rol,
                    'es_contingencia' => $esContingencia,
                    'comentario' => $motivo,
                    'firmado_at' => now(),
                ]);
            }

            $estadoAnterior = $actual->estado;
            $actual->estado = HheeEstados::RECHAZADA;
            $actual->fecha_rechazo = now();
            $actual->rechazado_por_id = $usuario->id;
            $actual->motivo_rechazo = $motivo;
            $actual->save();

            self::registrarHistorial($actual, $usuario, 'rechazada', $estadoAnterior, $actual->estado, $esContingencia, $motivo);

            HheeNotificador::notificarRechazada($actual, $usuario, $esContingencia, $estadoAnterior);

            return $actual->fresh(['detalles', 'aprobaciones']);
        });
    }

    public static function anular(SolicitudHhee $solicitud, User $usuario): SolicitudHhee
    {
        if ((int) $solicitud->solicitante_id !== (int) $usuario->id) {
            throw new HheeAutorizacionException('Solo el solicitante puede anular esta solicitud.');
        }

        return DB::transaction(function () use ($solicitud, $usuario) {
            $actual = self::conLock($solicitud);

            if (HheeEstados::esTerminal($actual->estado)) {
                throw ValidationException::withMessages([
                    'estado' => 'No se puede anular una solicitud que ya está en un estado terminal.',
                ]);
            }

            $estadoAnterior = $actual->estado;
            $actual->estado = HheeEstados::ANULADA;
            $actual->save();

            self::registrarHistorial($actual, $usuario, 'anulada', $estadoAnterior, $actual->estado);

            HheeNotificador::notificarAnulada($actual, $usuario, $estadoAnterior);

            return $actual->fresh(['detalles', 'aprobaciones']);
        });
    }

    /**
     * Carga las horas reales por empleado (post-aprobación) y cierra la
     * solicitud. $detallesReales: [['detalle_id' => .., 'horas_reales' => ..,
     * 'fecha_realizacion' => ..], ...] (un solo número de horas por empleado,
     * ya no desglosado por tipo).
     *
     * Exige cobertura COMPLETA (un detalle_id por cada fila de
     * hhee_solicitud_detalles de la solicitud, ver validarHorasReales()): si
     * faltara alguno, quedaría en 0 para siempre (la solicitud pasa a
     * 'cerrada', que es terminal, y no hay forma de recargar horas reales
     * después).
     */
    public static function cargarHorasReales(SolicitudHhee $solicitud, array $detallesReales, User $usuario): SolicitudHhee
    {
        if ((int) $solicitud->solicitante_id !== (int) $usuario->id) {
            throw new HheeAutorizacionException('Solo el solicitante puede cargar las horas reales de esta solicitud.');
        }

        return DB::transaction(function () use ($solicitud, $detallesReales, $usuario) {
            $actual = self::conLock($solicitud);

            if ($actual->estado !== HheeEstados::APROBADA) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se pueden cargar horas reales de una solicitud aprobada.',
                ]);
            }

            self::validarHorasReales($actual, $detallesReales);

            foreach ($detallesReales as $detalleReal) {
                $detalle = $actual->detalles()->find($detalleReal['detalle_id']);

                $detalle->update([
                    'horas_reales' => $detalleReal['horas_reales'] ?? 0,
                    'fecha_realizacion' => $detalleReal['fecha_realizacion'],
                ]);
            }

            self::recalcularTotales($actual);

            self::registrarHistorial($actual, $usuario, 'horas_reales_cargadas', HheeEstados::APROBADA, HheeEstados::APROBADA);

            $estadoAnterior = $actual->estado;
            $actual->estado = HheeEstados::CERRADA;
            $actual->fecha_cierre = now();
            $actual->save();

            self::registrarHistorial($actual, $usuario, 'cerrada', $estadoAnterior, $actual->estado);

            HheeNotificador::notificarCerrada($actual, $usuario, $estadoAnterior);

            return $actual->fresh(['detalles', 'aprobaciones']);
        });
    }

    // =========================================================================
    // Cálculo de horas (un solo total por empleado, sin desglose por tipo)
    // =========================================================================

    /**
     * Horas entre $desde y $hasta (formato "H:i"). Ya NO recibe un flag
     * "cruza medianoche": se infiere solo del horario, según
     * cruzaMedianoche($desde, $hasta) ($hasta <= $desde). Casos:
     * - $hasta > $desde: turno normal dentro del mismo día.
     * - $hasta < $desde: se asume que cruza medianoche (se le suma un día a
     *   $hasta antes de restar). Ej: 22:00 -> 02:00 = 4hs.
     * - $hasta === $desde: horario inválido (no se puede saber si es un
     *   turno de 0hs o de 24hs) -> ValidationException (422).
     *
     * $index (si se pasa) arma la clave "detalles.$index" del error de
     * horario inválido, para que el front pueda marcar la fila puntual del
     * formulario (mismo patrón que tenía validarDesglose(), ya eliminado).
     */
    public static function calcularHoras(string $desde, string $hasta, $index = null): float
    {
        if ($desde === $hasta) {
            $clave = $index === null ? 'detalles' : "detalles.$index";

            throw ValidationException::withMessages([
                $clave => 'Horario inválido: la hora de inicio y la hora de fin no pueden ser iguales.',
            ]);
        }

        $inicio = Carbon::createFromFormat('H:i', $desde);
        $fin = Carbon::createFromFormat('H:i', $hasta);

        if (self::cruzaMedianoche($desde, $hasta)) {
            $fin->addDay();
        }

        $minutos = $inicio->diffInMinutes($fin);

        return round($minutos / 60, 2);
    }

    /**
     * True si el turno cruza medianoche ($hasta <= $desde). Comparación de
     * STRINGS a propósito (sin Carbon): al venir validado como "H:i" de 24hs
     * con cero-padding (date_format:H:i), el orden lexicográfico coincide
     * exactamente con el orden horario, así que no hace falta parsear fechas
     * para esto. Se persiste como dato informativo en
     * hhee_solicitud_detalles.cruza_medianoche (ya no es un input del
     * usuario, ver guardarDetalles()).
     */
    public static function cruzaMedianoche(string $desde, string $hasta): bool
    {
        return $hasta <= $desde;
    }

    /**
     * Horas TEÓRICAS de un detalle: calcularHoras(hora_desde, hora_hasta) +
     * tope config('hhee.max_horas_por_empleado') (422 si lo supera).
     * Reemplaza a validarDesglose() (eliminado): ya no hay desglose por tipo
     * de hora que el cliente pueda mandar mal, el backend calcula el ÚNICO
     * número de horas_teoricas a partir del horario.
     */
    public static function calcularHorasTeoricas(array $detalle, $index = null): float
    {
        $horas = self::calcularHoras($detalle['hora_desde'], $detalle['hora_hasta'], $index);

        $clave = $index === null ? 'detalles' : "detalles.$index";
        $maxHoras = (float) config('hhee.max_horas_por_empleado', 12);

        if ($horas > $maxHoras) {
            throw ValidationException::withMessages([
                $clave => "\"{$detalle['nombre']}\" supera el máximo de {$maxHoras} horas permitidas por empleado.",
            ]);
        }

        return $horas;
    }

    /**
     * Valida el payload de horas REALES (POST .../horas-reales) contra los
     * detalles reales de $solicitud (requiere DB: consulta
     * $solicitud->detalles()):
     * - Cobertura COMPLETA: todos los detalle_id de la solicitud deben venir
     *   en el payload (si falta alguno, quedaría en 0 para siempre porque la
     *   solicitud pasa a 'cerrada', que es terminal).
     * - Ningún detalle_id ajeno (que no pertenezca a esta solicitud).
     * - Por cada detalle, horas_reales (un solo número, ya no desglosado por
     *   tipo) no puede superar config('hhee.max_horas_por_empleado').
     * Lanza ValidationException (422) si no cumple.
     */
    public static function validarHorasReales(SolicitudHhee $solicitud, array $detallesReales): void
    {
        $idsSolicitud = $solicitud->detalles()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $idsPayload = collect($detallesReales)->pluck('detalle_id')->map(fn ($id) => (int) $id)->all();

        $idsAjenos = array_values(array_diff($idsPayload, $idsSolicitud));
        if (!empty($idsAjenos)) {
            throw ValidationException::withMessages([
                'detalles' => 'Uno o más detalle_id no pertenecen a esta solicitud: ' . implode(', ', $idsAjenos) . '.',
            ]);
        }

        $idsFaltantes = array_values(array_diff($idsSolicitud, $idsPayload));
        if (!empty($idsFaltantes)) {
            throw ValidationException::withMessages([
                'detalles' => 'Faltan cargar las horas reales de todos los empleados de la solicitud (detalle_id faltantes: ' . implode(', ', $idsFaltantes) . ').',
            ]);
        }

        $maxHoras = (float) config('hhee.max_horas_por_empleado', 12);

        foreach ($detallesReales as $i => $detalleReal) {
            $horas = (float) ($detalleReal['horas_reales'] ?? 0);

            if ($horas > $maxHoras) {
                throw ValidationException::withMessages([
                    "detalles.$i" => "El detalle {$detalleReal['detalle_id']} supera el máximo de {$maxHoras} horas reales permitidas por empleado.",
                ]);
            }
        }
    }

    /**
     * Nivel (1 o 2) que está pendiente de firma según el estado actual de la
     * solicitud. Null si no hay ninguna aprobación pendiente (borrador,
     * aprobada, cerrada, rechazada o anulada).
     *
     * NOTA: hardcodea el mapeo estado -> nivel (2 niveles fijos) porque así
     * está modelado el dominio (ver hhee_solicitudes.estado y
     * hhee_aprobaciones.nivel); config('hhee.niveles') gobierna los ROLES de
     * cada nivel, no la cantidad de niveles del flujo.
     */
    public static function nivelPendiente(SolicitudHhee $solicitud): ?int
    {
        return match ($solicitud->estado) {
            HheeEstados::PENDIENTE_NIVEL1 => 1,
            HheeEstados::PENDIENTE_FINAL => 2,
            default => null,
        };
    }

    public static function recalcularTotales(SolicitudHhee $solicitud): void
    {
        $detalles = $solicitud->detalles()->get();

        $solicitud->total_horas_teoricas = round($detalles->sum(fn ($detalle) => (float) $detalle->horas_teoricas), 2);
        $solicitud->total_horas_reales = round($detalles->sum(fn ($detalle) => (float) $detalle->horas_reales), 2);
        $solicitud->total_empleados = $detalles->count();
        $solicitud->save();
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    /**
     * Valida el horario+tope de CADA detalle (calcularHorasTeoricas()) y que
     * no haya dos filas para el mismo empleado (mismo nombre normalizado) en
     * la misma solicitud. Ya NO compara por legajo (se sacó del form): dos
     * empleados sin legajo con el mismo nombre se consideran duplicados.
     */
    private static function validarDetalles(array $detalles): void
    {
        $vistos = [];

        foreach ($detalles as $i => $detalle) {
            self::calcularHorasTeoricas($detalle, $i);

            $clave = strtolower(trim($detalle['nombre']));

            if (isset($vistos[$clave])) {
                throw ValidationException::withMessages([
                    "detalles.$i" => "El empleado \"{$detalle['nombre']}\" está duplicado en la solicitud.",
                ]);
            }

            $vistos[$clave] = true;
        }
    }

    private static function guardarDetalles(SolicitudHhee $solicitud, array $detalles): void
    {
        foreach ($detalles as $i => $detalle) {
            $solicitud->detalles()->create([
                'nombre' => $detalle['nombre'],
                'user_id' => $detalle['user_id'] ?? null,
                'motivo' => $detalle['motivo'],
                'necesita_transporte' => (bool) ($detalle['necesita_transporte'] ?? false),
                'localidad' => $detalle['localidad'] ?? null,
                'hora_desde' => $detalle['hora_desde'],
                'hora_hasta' => $detalle['hora_hasta'],
                // cruza_medianoche y horas_teoricas son 100% derivados del
                // horario, nunca input del usuario (ver calcularHoras()/
                // cruzaMedianoche()/calcularHorasTeoricas()).
                'cruza_medianoche' => self::cruzaMedianoche($detalle['hora_desde'], $detalle['hora_hasta']),
                'horas_teoricas' => self::calcularHorasTeoricas($detalle, $i),
                'orden' => $i,
            ]);
        }
    }

    /**
     * Autoriza que $usuario pueda firmar (aprobar/rechazar) $nivel de
     * $solicitud: debe estar en HheeAprobadores::nivelesQuePuedeFirmar(), y si
     * es el propio solicitante, config('hhee.permitir_autoaprobacion') debe
     * estar en true.
     */
    private static function validarAutorizacionDeFirma(SolicitudHhee $solicitud, User $usuario, int $nivel, string $accion): void
    {
        $esSolicitante = (int) $solicitud->solicitante_id === (int) $usuario->id;

        if ($esSolicitante && !config('hhee.permitir_autoaprobacion', false)) {
            throw new HheeAutorizacionException("No puede {$accion} su propia solicitud.");
        }

        $nivelesPermitidos = HheeAprobadores::nivelesQuePuedeFirmar($usuario, $solicitud);

        if (!in_array($nivel, $nivelesPermitidos, true)) {
            throw new HheeAutorizacionException("No tiene permisos para {$accion} este nivel de la solicitud.");
        }
    }

    private static function registrarHistorial(
        SolicitudHhee $solicitud,
        User $usuario,
        string $accion,
        ?string $estadoAnterior,
        ?string $estadoNuevo,
        bool $esContingencia = false,
        ?string $comentario = null
    ): void {
        HheeHistorial::create([
            'solicitud_id' => $solicitud->id,
            'user_id' => $usuario->id,
            'accion' => $accion,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'es_contingencia' => $esContingencia,
            'comentario' => $comentario,
            'created_at' => now(),
        ]);
    }
}
