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
        'legajo',
        'nombre',
        'user_id',
        'motivo',
        'necesita_transporte',
        'localidad',
        'hora_desde',
        'hora_hasta',
        'cruza_medianoche',
        'hs_teoricas_50',
        'hs_teoricas_100',
        'hs_teoricas_50n',
        'hs_teoricas_100n',
        'hs_reales_50',
        'hs_reales_100',
        'hs_reales_50n',
        'hs_reales_100n',
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
