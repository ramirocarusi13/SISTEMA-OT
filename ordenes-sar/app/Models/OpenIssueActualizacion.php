<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Timeline append-only de un Open Issue: la app nunca hace UPDATE ni DELETE
 * sobre esta tabla (App\Support\OpenIssueFlujo::registrarActualizacion() es
 * la única escritura). Vocabulario cerrado de 'tipo' en
 * App\Support\OpenIssueEstados::TIPOS_ACTUALIZACION_LABELS.
 *
 * NO lleva accessor archivo_url: esa URL la arma el controller con
 * App\Support\ArchivoOrden::url(), que depende de request() y no debe
 * ejecutarse en consola ni en jobs.
 */
class OpenIssueActualizacion extends Model
{
    protected $table = 'oi_actualizaciones';

    // Append-only: solo created_at.
    public $timestamps = false;

    protected $fillable = [
        'issue_id',
        'user_id',
        'tipo',
        'texto',
        'estado_anterior',
        'estado_nuevo',
        'archivo',
        'mime_type',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(OpenIssue::class, 'issue_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
