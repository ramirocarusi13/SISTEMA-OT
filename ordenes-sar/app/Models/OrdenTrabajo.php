<?php

namespace App\Models;

use App\Support\ArchivoOrden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenTrabajo extends Model
{
    use HasFactory;

    protected $table = 'ordenes_trabajo';

    protected $fillable = [
        'usuario_id',
        'titulo',
        'estado',
        'comentarios',
        'fecha_aprobacion',
        'fecha_estimacion',
        'fecha_asignacion',
        'fecha_finalizacion',
        'usuario_mantenimiento_id',
        'estado_anterior',
        'mensaje_finalizacion',
        'foto_finalizada',
        'horas_ot', // Agrega esta línea si es necesario
        'finalizado_por_id',

    ];


    // Relación con el usuario que creó la orden
    public function creador()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }


    // Relación con el usuario de mantenimiento a cargo
    public function usuarioMantenimiento()
    {
        return $this->belongsTo(User::class, 'usuario_mantenimiento_id');
    }


    // Relación con el usuario que finalizó la orden
    public function finalizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizado_por_id');
    }


    // Relación con la descripción de la orden
    public function descripcion()
    {
        return $this->belongsTo(Descripcion::class, 'descripcion_id');
    }
    // OrdenesTrabajo.php
    public function descripciones()
    {
        return $this->hasMany(Descripcion::class, 'orden_id');
    }
    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }
    public function getFotoFinalizadaAttribute($value)
    {
        return ArchivoOrden::url($value);
    }
}
