<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un item es un punto puntual dentro de un Open Issue (§10 de la spec del
 * módulo): título, estado propio (pendiente/en_progreso/hecho/descartado),
 * responsable opcional y su propio timeline de actualizaciones
 * (oi_actualizaciones.item_id, ver OpenIssueActualizacion::item()).
 *
 * Los items NO se borran físicamente (igual criterio que los issues, §5.9):
 * uno que sobra se pasa a 'descartado'. Así la FK NO ACTION de
 * oi_actualizaciones.item_id nunca molesta y el historial queda íntegro.
 *
 * Toda mutación pasa por App\Support\OpenIssueFlujo: este modelo no tiene
 * lógica de negocio, solo relaciones.
 */
class OpenIssueItem extends Model
{
    protected $table = 'oi_items';

    protected $fillable = [
        'issue_id',
        'titulo',
        'detalle',
        'estado',
        'responsable_id',
        'creado_por_id',
        'resuelto_por_id',
        'fecha_resuelto',
        'orden',
    ];

    protected $casts = [
        'fecha_resuelto' => 'datetime',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(OpenIssue::class, 'issue_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por_id');
    }

    public function actualizaciones(): HasMany
    {
        return $this->hasMany(OpenIssueActualizacion::class, 'item_id')->orderBy('id');
    }
}
