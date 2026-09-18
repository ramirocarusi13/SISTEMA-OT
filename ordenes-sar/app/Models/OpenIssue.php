<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un "Open Issue" es un pedido/incidencia dirigida a un departamento destino,
 * con un timeline de actualizaciones (oi_actualizaciones) y una lista de
 * involucrados (oi_involucrados) que son los destinatarios de campana (ver
 * App\Support\OpenIssueNotificador). El creador SIEMPRE queda como
 * involucrado (origen='creador', ver App\Support\OpenIssueFlujo::crear()).
 *
 * Toda mutación de este modelo y de sus tablas relacionadas pasa por
 * App\Support\OpenIssueFlujo: este modelo no tiene lógica de negocio, solo
 * relaciones y el helper de conveniencia tieneInvolucrado().
 */
class OpenIssue extends Model
{
    use HasFactory;

    protected $table = 'oi_issues';

    protected $fillable = [
        'titulo',
        'descripcion',
        'departamento_destino_id',
        'creador_id',
        'estado',
        'prioridad',
        'fecha_cierre',
        'cerrado_por_id',
        'fecha_reapertura',
    ];

    protected $casts = [
        'fecha_cierre' => 'datetime',
        'fecha_reapertura' => 'datetime',
    ];

    public function departamentoDestino(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_destino_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creador_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por_id');
    }

    public function involucrados(): HasMany
    {
        return $this->hasMany(OpenIssueInvolucrado::class, 'issue_id')->orderBy('id');
    }

    public function actualizaciones(): HasMany
    {
        return $this->hasMany(OpenIssueActualizacion::class, 'issue_id')->orderBy('id');
    }

    /**
     * True si $userId tiene fila en oi_involucrados para este issue. Usado por
     * App\Support\AlcanceOpenIssues y por los flags del detalle.
     */
    public function tieneInvolucrado(int $userId): bool
    {
        return $this->involucrados()->where('user_id', $userId)->exists();
    }
}
