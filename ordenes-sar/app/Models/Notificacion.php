<?php

namespace App\Models;

use App\Support\WhatsAppNotificador;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Notificacion extends Model
{
    use HasFactory;

    protected $table = 'notificaciones';

    protected $fillable = [
        'orden_trabajo_id',
        'usuario_creador_id',
        'usuario_mantenimiento_id',
        'estado_anterior',
        'estado_nuevo',
        'mensaje',
        'leido',
        // Discriminador 'ot'|'hhee'|'open_issue' + FKs opcionales a
        // hhee_solicitudes/oi_issues (ver migraciones
        // 2026_08_25_000006_add_hhee_to_notificaciones_table y
        // 2026_09_18_000004_add_open_issue_to_notificaciones_table).
        'tipo',
        'solicitud_hhee_id',
        'open_issue_id',
    ];

    // Relación con la orden de trabajo
    public function ordenTrabajo()
    {
        return $this->belongsTo(OrdenTrabajo::class);
    }

    // Relación con la solicitud de HHEE (tipo='hhee')
    public function solicitudHhee()
    {
        return $this->belongsTo(SolicitudHhee::class, 'solicitud_hhee_id');
    }

    // Relación con el Open Issue (tipo = 'open_issue')
    public function openIssue()
    {
        return $this->belongsTo(OpenIssue::class, 'open_issue_id');
    }

    // Relación con el usuario creador de la orden
    public function usuarioCreador()
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    // Relación con el usuario de mantenimiento que cambió el estado
    public function usuarioMantenimiento()
    {
        return $this->belongsTo(User::class, 'usuario_mantenimiento_id');
    }

    /**
     * Texto legible de la campana según 'tipo'. Única fuente de verdad:
     * antes vivía duplicado a mano dentro de
     * NotificacionesController::index() (el map() que arma 'detalle'); se
     * movió acá para que App\Support\WhatsAppNotificador pueda reusar
     * EXACTAMENTE el mismo texto en el mensaje de WhatsApp sin duplicar la
     * lógica. Usa el optional-chaining ?-> porque, a diferencia del
     * controller (que hace with('usuarioMantenimiento') antes de mapear),
     * acá 'usuarioMantenimiento' puede no estar cargada todavía (lazy load).
     */
    public function textoDetalle(): string
    {
        if ($this->tipo === 'hhee') {
            return "Solicitud HHEE #{$this->solicitud_hhee_id} – {$this->mensaje}";
        }

        if ($this->tipo === 'open_issue') {
            return "Open Issue #{$this->open_issue_id} – {$this->mensaje}";
        }

        $usuarioNombre = $this->usuarioMantenimiento?->name ?? 'Usuario desconocido';

        return "{$usuarioNombre} ha cambiado el estado de la orden de trabajo a {$this->estado_nuevo}";
    }

    /**
     * Único punto de enganche del canal WhatsApp: cualquier notificación
     * creada (OT, HHEE, Open Issue) intenta reenviarse por WhatsApp acá, sin
     * que cada notificador (HheeNotificador, OpenIssueNotificador, el
     * controller de OT) tenga que acordarse de llamarlo. Envuelto en
     * try/catch: una falla del canal WhatsApp NUNCA debe romper la creación
     * de la notificación (la campana en el front es lo importante).
     */
    protected static function booted()
    {
        static::created(function (Notificacion $notificacion) {
            try {
                WhatsAppNotificador::desdeNotificacion($notificacion);
            } catch (\Throwable $e) {
                Log::error('Error enganchando WhatsApp desde Notificacion::created: ' . $e->getMessage());
            }
        });
    }
}