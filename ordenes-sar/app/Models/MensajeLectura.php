<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MensajeLectura extends Model
{
    use HasFactory;

    protected $fillable = [
        'orden_trabajo_id',
        'user_id',
        'last_read_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    public function ordenTrabajo()
    {
        return $this->belongsTo(OrdenTrabajo::class, 'orden_trabajo_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
