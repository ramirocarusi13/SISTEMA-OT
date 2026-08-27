<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HheeRolAprobacion extends Model
{
    use HasFactory;

    protected $table = 'hhee_roles_aprobacion';

    protected $fillable = [
        'user_id',
        'rol',
        'departamento_id',
        'activo',
        'observacion',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }
}
