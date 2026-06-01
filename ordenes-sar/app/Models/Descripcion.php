<?php

// app/Models/Descripcion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Descripcion extends Model
{
    use HasFactory;

    protected $table = 'descripciones';

    protected $fillable = [
        'titulo',
        'descripcion',
        'orden_id',
        'archivo',
        'mime_type',
        
    ];

    // Relación con las órdenes de trabajo
    public function ordenesTrabajo(): HasMany
    {
        return $this->hasMany(OrdenTrabajo::class, 'descripcion_id');
    }
    // Descripcion.php
    public function orden()
    {
        return $this->belongsTo(OrdenTrabajo::class, 'orden_id');
    }
}
