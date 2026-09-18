<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de "quién participa" de un Open Issue (destinatario de campana). Tabla
 * append-only en la práctica: solo se inserta (App\Support\OpenIssueFlujo) o
 * se borra físicamente al quitar un involucrado (el evento queda igual
 * auditado en oi_actualizaciones, tipo 'involucrado_quitado'). Nunca se
 * actualiza una fila existente.
 *
 * 'origen' documenta CÓMO llegó cada involucrado:
 * - creador: el propio creador del issue (insertado siempre en el alta).
 * - manual: agregado a mano (persona suelta).
 * - departamento: agregado por expansión de "todo el departamento X" en el
 *   instante del alta/agregado (snapshot, no un alcance dinámico: ver §5.2
 *   de la spec del módulo).
 */
class OpenIssueInvolucrado extends Model
{
    protected $table = 'oi_involucrados';

    // Append-only: solo created_at, seteado a mano por OpenIssueFlujo.
    public $timestamps = false;

    protected $fillable = [
        'issue_id',
        'user_id',
        'origen',
        'departamento_id',
        'agregado_por_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const ORIGEN_CREADOR = 'creador';
    public const ORIGEN_MANUAL = 'manual';
    public const ORIGEN_DEPARTAMENTO = 'departamento';

    public function issue(): BelongsTo
    {
        return $this->belongsTo(OpenIssue::class, 'issue_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function agregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agregado_por_id');
    }
}
