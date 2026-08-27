<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AprobacionHhee extends Model
{
    use HasFactory;

    protected $table = 'hhee_aprobaciones';

    protected $fillable = [
        'solicitud_id',
        'nivel',
        'estado',
        'aprobador_id',
        'rol_aprobador',
        'es_contingencia',
        'comentario',
        'firmado_at',
    ];

    protected $casts = [
        'nivel' => 'integer',
        'es_contingencia' => 'boolean',
        'firmado_at' => 'datetime',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudHhee::class, 'solicitud_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobador_id');
    }
}
