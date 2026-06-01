<?php

// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
// use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'departamento_id',
        'rol',
        'turno',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Relación con las órdenes creadas por el usuario
    public function ordenesTrabajo(): HasMany
    {
        return $this->hasMany(OrdenTrabajo::class, 'usuario_id');
    }

    // Relación con las órdenes asignadas al usuario de mantenimiento
    public function ordenesMantenimiento(): HasMany
    {
        return $this->hasMany(OrdenTrabajo::class, 'usuario_mantenimiento_id');
    }
    public function departamento() : HasOne {
        return $this->hasOne(Departamento::class,'id','departamento_id');
    }
    public function gerente(): HasOne
    {
        return $this->hasOne(User::class, 'departamento_id')->where('rol', 'gerente');
    }
}
