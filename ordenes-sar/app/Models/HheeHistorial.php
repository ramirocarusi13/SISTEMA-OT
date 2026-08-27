<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla append-only (auditoría): no tiene updated_at, solo created_at (que se
 * setea a mano al crear la fila, ver App\Support\HheeFlujo::registrarHistorial()).
 */
class HheeHistorial extends Model
{
    protected $table = 'hhee_historial';

    public $timestamps = false;

    protected $fillable = [
        'solicitud_id',
        'user_id',
        'accion',
        'estado_anterior',
        'estado_nuevo',
        'es_contingencia',
        'comentario',
        'created_at',
    ];

    protected $casts = [
        'es_contingencia' => 'boolean',
        'created_at' => 'datetime',
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
