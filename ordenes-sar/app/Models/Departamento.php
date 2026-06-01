<?php

// app/Models/Departamento.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Departamento extends Model
{
    use HasFactory;

    protected $fillable = ['nombre'];

    // Relación para obtener al gerente del departamento
    public function gerente(): HasOne
    {
        return $this->hasOne(User::class, 'departamento_id', 'id')->where('rol', 'gerente');
    }
    public function gerentes()
    {
        return $this->hasMany(User::class, 'departamento_id')->where('rol', 'gerente');
    }
}
