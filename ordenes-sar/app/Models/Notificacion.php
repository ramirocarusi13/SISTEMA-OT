<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        // Discriminador 'ot'|'hhee' + FK opcional a hhee_solicitudes (ver
        // migración 2026_08_25_000006_add_hhee_to_notificaciones_table).
        'tipo',
        'solicitud_hhee_id',
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
}