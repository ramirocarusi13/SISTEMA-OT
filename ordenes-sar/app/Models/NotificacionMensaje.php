<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificacionMensaje extends Model
{
    use HasFactory;

    protected $fillable = [
        'mensaje_id',
        'usuario_receptor_id',
        'leido',
    ];

    public function mensaje()
    {
        return $this->belongsTo(Mensaje::class);
    }

    public function usuarioReceptor()
    {
        return $this->belongsTo(User::class, 'usuario_receptor_id');
    }
}
