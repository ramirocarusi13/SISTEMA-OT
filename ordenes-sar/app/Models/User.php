<?php

// app/Models/User.php

namespace App\Models;

use App\Support\Departamentos;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
// use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'departamento_id',
        'rol',
        'turno',
        // Marca INDIVIDUAL (no por rol) de qué usuarios pueden recibir OTs
        // asignadas y finalizarlas además de los group_leader de siempre. Ver
        // OrdenTrabajoController::updateEstado() y
        // UserController::getUsuariosMantenimiento().
        'es_asignable',
        // Celular (para WhatsApp, ver App\Support\WhatsApp::chatIdDesdeCelular())
        // y flag INDIVIDUAL que habilita el canal para este usuario. Se cargan
        // con el comando `whatsapp:usuario` (App\Console\Commands\WhatsAppUsuarioCommand).
        'celular',
        'whatsapp_activo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'es_asignable' => 'boolean',
        'whatsapp_activo' => 'boolean',
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
    /**
     * Departamentos ADICIONALES (además de departamento_id) entre los que el
     * usuario puede hacer switch desde el front. Ver
     * App\Http\Middleware\DepartamentoActivo.
     */
    public function departamentosAdicionales(): BelongsToMany
    {
        return $this->belongsToMany(Departamento::class, 'user_departamentos_adicionales', 'user_id', 'departamento_id');
    }

    /**
     * Datos del switch de departamento para el front (login y GET /user):
     * departamento base (el real de la tabla users) + los que puede activar.
     * Solo se arma en esos dos endpoints (no va en $appends para no agregar
     * una query por cada User serializado en los listados).
     */
    public function datosSwitchDepartamento(): array
    {
        // De la base y no del modelo: con el switch activo, departamento_id
        // en memoria es el elegido (ver DepartamentoActivo), no el base.
        $base = (int) static::whereKey($this->getKey())->value('departamento_id');
        try {
            $adicionales = $this->departamentosAdicionales()->pluck('departamentos.id');
        } catch (\Throwable $e) {
            // Sin la tabla (migración no corrida) el login y GET /user NO se
            // rompen: el usuario queda sin switch, como antes de esta feature.
            Log::error('No se pudieron leer los departamentos adicionales del usuario ' . $this->getKey() . ': ' . $e->getMessage());
            $adicionales = collect();
        }

        $departamentos = Departamento::whereIn('id', $adicionales->push($base))
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->sortBy(fn ($d) => (int) $d->id === $base ? 0 : 1)
            ->values()
            ->map(fn ($d) => ['id' => (int) $d->id, 'nombre' => $d->nombre]);

        return [
            'departamento_base_id' => $base,
            'departamentos_disponibles' => $departamentos->all(),
        ];
    }

    public function gerente(): HasOne
    {
        return $this->hasOne(User::class, 'departamento_id')->where('rol', 'gerente');
    }
}
