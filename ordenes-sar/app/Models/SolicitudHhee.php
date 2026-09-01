<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SolicitudHhee extends Model
{
    use HasFactory;

    protected $table = 'hhee_solicitudes';

    protected $fillable = [
        'solicitante_id',
        'departamento_id',
        // Sector fijo (Corte/Costura/Mantenimiento/PC, ver
        // config('hhee.sectores')), distinto de departamento_id: sector es
        // descriptivo/libre-elegido por el solicitante, departamento_id
        // sigue rigiendo el ruteo de aprobadores de nivel 1 y ahora SIEMPRE
        // sale del propio solicitante (ver App\Support\HheeFlujo).
        'sector',
        'fecha_hhee',
        'turno',
        'observaciones',
        'estado',
        'total_horas_teoricas',
        'total_horas_reales',
        'total_empleados',
        'fecha_envio',
        'fecha_aprobacion_nivel1',
        'fecha_aprobacion_final',
        'fecha_cierre',
        'fecha_rechazo',
        'rechazado_por_id',
        'motivo_rechazo',
    ];

    protected $casts = [
        'fecha_hhee' => 'date',
        'fecha_envio' => 'datetime',
        'fecha_aprobacion_nivel1' => 'datetime',
        'fecha_aprobacion_final' => 'datetime',
        'fecha_cierre' => 'datetime',
        'fecha_rechazo' => 'datetime',
        'total_horas_teoricas' => 'decimal:2',
        'total_horas_reales' => 'decimal:2',
        'total_empleados' => 'integer',
    ];

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function rechazadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rechazado_por_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SolicitudHheeDetalle::class, 'solicitud_id')->orderBy('orden');
    }

    public function aprobaciones(): HasMany
    {
        return $this->hasMany(AprobacionHhee::class, 'solicitud_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(HheeHistorial::class, 'solicitud_id')->orderBy('id');
    }

    /**
     * Fila de hhee_aprobaciones para un nivel puntual (1 o 2). Relación
     * 'hasOne' filtrada por nivel: hhee_aprobaciones tiene UNIQUE
     * (solicitud_id, nivel), así que como máximo devuelve una fila.
     */
    public function aprobacionNivel(int $nivel): HasOne
    {
        return $this->hasOne(AprobacionHhee::class, 'solicitud_id')->where('nivel', $nivel);
    }
}
