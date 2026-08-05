<?php

namespace App\Models;

use App\Support\ArchivoOrden;
use App\Support\PrioridadOT;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenTrabajo extends Model
{
    use HasFactory;

    protected $table = 'ordenes_trabajo';

    protected $fillable = [
        'usuario_id',
        'titulo',
        'estado',
        'comentarios',
        'fecha_aprobacion',
        'fecha_estimacion',
        'fecha_asignacion',
        'fecha_finalizacion',
        'usuario_mantenimiento_id',
        'estado_anterior',
        'mensaje_finalizacion',
        'foto_finalizada',
        'horas_ot', // Agrega esta línea si es necesario
        'finalizado_por_id',
        // Prioridad / categoría (ver App\Support\PrioridadOT y SPEC §1 y §3)
        'categoria',
        'prioridad',
        'prioridad_orden',
        'es_seguridad',
        'prioridad_definida_por_id',
        'prioridad_motivo',
        'fecha_primera_respuesta',
    ];

    protected $casts = [
        'es_seguridad' => 'boolean',
        'fecha_aprobacion' => 'datetime',
        'fecha_estimacion' => 'datetime',
        'fecha_asignacion' => 'datetime',
        'fecha_finalizacion' => 'datetime',
        'fecha_primera_respuesta' => 'datetime',
    ];


    // Relación con el usuario que creó la orden
    public function creador()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }


    // Relación con el usuario de mantenimiento a cargo
    public function usuarioMantenimiento()
    {
        return $this->belongsTo(User::class, 'usuario_mantenimiento_id');
    }


    // Relación con el usuario que finalizó la orden
    public function finalizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizado_por_id');
    }


    // Relación con la descripción de la orden
    public function descripcion()
    {
        return $this->belongsTo(Descripcion::class, 'descripcion_id');
    }
    // OrdenesTrabajo.php
    public function descripciones()
    {
        return $this->hasMany(Descripcion::class, 'orden_id');
    }
    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    // Relación con los mensajes del chat de la orden
    public function mensajes()
    {
        return $this->hasMany(Mensaje::class, 'orden_trabajo_id');
    }

    // Relación con los registros de lectura del chat (uno por usuario)
    public function mensajeLecturas()
    {
        return $this->hasMany(MensajeLectura::class, 'orden_trabajo_id');
    }
    public function getFotoFinalizadaAttribute($value)
    {
        return ArchivoOrden::url($value);
    }

    // Relación con el usuario que overrideó manualmente la prioridad calculada (ver App\Support\PrioridadOT)
    public function prioridadDefinidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prioridad_definida_por_id');
    }

    // =========================================================================
    // Accessors del semáforo de SLA (§2 de la spec). Ninguno dispara queries:
    // todos operan solo sobre atributos ya cargados en el modelo.
    // =========================================================================

    /**
     * Horas de SLA de primera respuesta configuradas para la prioridad actual
     * de la orden (config('ot.sla_horas'), ver App\Support\PrioridadOT::slaHoras()).
     */
    public function getSlaHorasAttribute(): int
    {
        return PrioridadOT::slaHoras($this->prioridad);
    }

    /**
     * Momento en que vence el SLA de primera respuesta: fecha_aprobacion
     * (fallback created_at) + sla_horas de la prioridad.
     *
     * La aprobación solo cuenta como inicio del reloj si ocurrió antes de la
     * asignación: cuando una OT pasa de 'creada' directo a 'asignada' ambas
     * fechas se estampan juntas y el SLA se cumpliría siempre. Misma regla que
     * App\Support\ReporteQueries::baseSlaSql(), para que el semáforo de la lista
     * y el KPI del reporte no se contradigan.
     */
    public function getSlaVenceAtAttribute(): ?Carbon
    {
        $aprobacion = $this->fecha_aprobacion;
        $aprobacionValida = $aprobacion
            && ($this->fecha_asignacion === null || $aprobacion->lessThan($this->fecha_asignacion));

        $base = $aprobacionValida ? $aprobacion : $this->created_at;

        if (!$base) {
            return null;
        }

        return $base->copy()->addHours($this->sla_horas);
    }

    /**
     * Estado del semáforo de SLA (§2):
     * - 'sin_sla': la orden ya está finalizada (el SLA de primera respuesta
     *   deja de tener sentido; para saber si se cumplió o no se usa
     *   cumplio_sla, evaluado contra la fecha_asignacion real).
     * - 'en_tiempo' / 'por_vencer' / 'vencida': solo aplican mientras la
     *   orden sigue activa Y todavía no tiene fecha_asignacion (el reloj de
     *   "primera respuesta" sigue corriendo).
     * - 'incumplida': ya está asignada, pero la asignación llegó después del
     *   vencimiento. No se informa 'en_tiempo' en ese caso porque sería
     *   contradictorio con un vencimiento que ya quedó en el pasado.
     */
    public function getSlaEstadoAttribute(): string
    {
        if ($this->estado === 'finalizada') {
            return 'sin_sla';
        }

        // Ya respondida: el reloj de primera respuesta se detuvo. Se informa si
        // llegó a tiempo o no en vez de un genérico 'en_tiempo'.
        if ($this->fecha_asignacion !== null) {
            return $this->cumplio_sla === false ? 'incumplida' : 'en_tiempo';
        }

        $vencimiento = $this->sla_vence_at;
        if ($vencimiento === null) {
            return 'sin_sla';
        }

        $ahora = Carbon::now('America/Argentina/Buenos_Aires');

        if ($ahora->greaterThan($vencimiento)) {
            return 'vencida';
        }

        // "por_vencer" cuando queda <= 25% del SLA total hasta el vencimiento.
        // Acá ya sabemos que $ahora <= $vencimiento (si no, ya hubiese retornado
        // 'vencida' arriba), así que el valor absoluto es siempre el remanente real.
        $minutosRestantes = $ahora->diffInMinutes($vencimiento);
        $minutosSlaTotal = $this->sla_horas * 60;
        $umbralPorVencer = $minutosSlaTotal * 0.25;

        return $minutosRestantes <= $umbralPorVencer ? 'por_vencer' : 'en_tiempo';
    }

    /**
     * Si la orden cumplió el SLA de primera respuesta: fecha_asignacion <=
     * vencimiento. Null si todavía no hay fecha_asignacion (no hay dato para
     * evaluar: puede seguir en tiempo o ya estar vencida, ver sla_estado).
     */
    public function getCumplioSlaAttribute(): ?bool
    {
        if ($this->fecha_asignacion === null) {
            return null;
        }

        $vencimiento = $this->sla_vence_at;
        if ($vencimiento === null) {
            return null;
        }

        return $this->fecha_asignacion->lessThanOrEqualTo($vencimiento);
    }
}
