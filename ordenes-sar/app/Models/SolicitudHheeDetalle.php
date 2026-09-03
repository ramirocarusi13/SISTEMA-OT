<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudHheeDetalle extends Model
{
    use HasFactory;

    protected $table = 'hhee_solicitud_detalles';

    protected $fillable = [
        'solicitud_id',
        // legajo ya NO se pide en el form (ver App\Support\HheeFlujo): queda
        // en $fillable/schema por compatibilidad con filas viejas, pero la
        // app nunca vuelve a escribirlo.
        'legajo',
        'nombre',
        'user_id',
        'motivo',
        'necesita_transporte',
        'localidad',
        'hora_desde',
        'hora_hasta',
        // Se sigue escribiendo, pero ahora es 100% derivado (hora_hasta <=
        // hora_desde), nunca un input del usuario. Ver
        // App\Support\HheeFlujo::cruzaMedianoche().
        'cruza_medianoche',
        // Desglose por tipo de hora (hs_teoricas_*/hs_reales_*): OBSOLETO, ya
        // no se pide en el form ni se escribe desde la app (ver
        // App\Support\HheeFlujo). Quedan listadas en $fillable/schema por
        // compatibilidad con filas viejas.
        'hs_teoricas_50',
        'hs_teoricas_100',
        'hs_teoricas_50n',
        'hs_teoricas_100n',
        'hs_reales_50',
        'hs_reales_100',
        'hs_reales_50n',
        'hs_reales_100n',
        // Horas TOTALES, un solo número por empleado (reemplazan al
        // desglose): horas_teoricas la calcula el backend a partir de
        // hora_desde/hora_hasta; horas_reales se carga post-aprobación.
        'horas_teoricas',
        'horas_reales',
        'fecha_realizacion',
        'orden',
    ];

    protected $casts = [
        'necesita_transporte' => 'boolean',
        'cruza_medianoche' => 'boolean',
        'hs_teoricas_50' => 'decimal:2',
        'hs_teoricas_100' => 'decimal:2',
        'hs_teoricas_50n' => 'decimal:2',
        'hs_teoricas_100n' => 'decimal:2',
        'hs_reales_50' => 'decimal:2',
        'hs_reales_100' => 'decimal:2',
        'hs_reales_50n' => 'decimal:2',
        'hs_reales_100n' => 'decimal:2',
        'horas_teoricas' => 'decimal:2',
        'horas_reales' => 'decimal:2',
        'fecha_realizacion' => 'date',
        'orden' => 'integer',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudHhee::class, 'solicitud_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
