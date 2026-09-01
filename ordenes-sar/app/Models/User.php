<?php

// app/Models/User.php

namespace App\Models;

use App\Support\Departamentos;
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

    /**
     * 'es_seguridad_higiene' viaja SIEMPRE que se serializa un User
     * (toArray()/response()->json($user)), sin que cada controller tenga que
     * acordarse de agregarlo a mano. Hace falta así (accessor + $appends) y no
     * solo un campo calculado en UserController::getAuthenticatedUser()
     * (como estaba antes) porque AuthController::login() devuelve el modelo
     * User CRUDO (response()->json(['user' => $user, ...])) y el front NO
     * vuelve a pedir GET /api/user después de loguearse: guarda directo en
     * localStorage el 'user' que vino del login. Si el flag se calculara solo
     * en getAuthenticatedUser(), el front nunca lo vería ahí (bug real: un
     * usuario de SyH no veía el resumen por departamentos en Reportes). El
     * cálculo es liviano (App\Support\Departamentos memoiza el id del
     * departamento SyH de forma estática, una sola query por proceso/request),
     * así que no es un problema tenerlo en cualquier serialización de User.
     */
    protected $appends = ['es_seguridad_higiene'];

    /**
     * True si este usuario pertenece al departamento de Seguridad e Higiene
     * (SyH). Única fuente de verdad: App\Support\Departamentos::esSeguridad()
     * (la misma que usa App\Support\AlcanceOrdenes para decidir alcance de
     * lectura/escritura de OTs) -- no se duplica el cálculo acá.
     */
    public function getEsSeguridadHigieneAttribute(): bool
    {
        return Departamentos::esSeguridad($this);
    }

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
