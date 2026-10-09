<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Switch de departamento para usuarios con departamentos ADICIONALES (tabla
 * user_departamentos_adicionales; hoy solo Agustín Otero: Mantenimiento +
 * Ingeniería).
 *
 * El front manda el departamento elegido en el header X-Departamento-Activo.
 * Si el usuario tiene ese departamento entre sus adicionales, durante ESTE
 * request su departamento_id pasa a ser ese (solo en memoria, nunca se
 * guarda en la base). Así todo el código existente que decide por
 * $user->departamento_id (alcance de OTs, aprobar, reportes, etc.) funciona
 * igual que para un gerente de ese departamento, sin tocarlo.
 *
 * Sin header, header con su departamento base o con un departamento que no
 * tiene habilitado -> no cambia nada (mismo comportamiento de siempre).
 */
class DepartamentoActivo
{
    public const HEADER = 'X-Departamento-Activo';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $activo = $request->header(self::HEADER);

        if ($user && is_numeric($activo) && (int) $activo !== (int) $user->departamento_id) {
            $habilitado = $user->departamentosAdicionales()
                ->where('departamentos.id', (int) $activo)
                ->exists();

            if ($habilitado) {
                $user->departamento_id = (int) $activo;
                // Que no quede "dirty": si algo hace $user->save() en este
                // request, no persiste el departamento del switch.
                $user->syncOriginalAttribute('departamento_id');
                $user->unsetRelation('departamento');
            }
        }

        return $next($request);
    }
}
