<?php

namespace App\Support;

use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alcance de solicitudes HHEE por permisos (espejo de App\Support\AlcanceOrdenes
 * para el dominio de órdenes de trabajo). Reglas (ver spec del módulo):
 *
 * - Los borradores (estado='borrador') SOLO los ve su dueño (solicitante_id),
 *   sin importar rol de aprobador.
 * - El solicitante ve SIEMPRE sus propias solicitudes, en cualquier estado.
 * - Aprobador nivel 1 (jefe/gerente_area): ve las solicitudes NO borrador de
 *   su(s) departamento(s), o de TODOS los departamentos si su rol nivel 1 es
 *   de alcance global (departamento_id NULL en hhee_roles_aprobacion).
 * - Aprobador nivel final (gerencia_general/rrhh/presidencia) o contingencia:
 *   alcance global -> ve TODAS las solicitudes NO borrador, más las propias.
 * - Cualquier otro usuario (sin rol de aprobación): solo ve las propias.
 */
class AlcanceHhee
{
    /**
     * Aplica el filtro de alcance sobre un query builder de SolicitudHhee.
     */
    public static function aplicar(Builder $query, User $usuario): Builder
    {
        if (HheeAprobadores::tieneAlcanceGlobal($usuario)) {
            return $query->where(function (Builder $q) use ($usuario) {
                $q->where('estado', '!=', HheeEstados::BORRADOR)
                    ->orWhere('solicitante_id', $usuario->id);
            });
        }

        if (HheeAprobadores::esAprobadorNivel1($usuario)) {
            $esGlobal = HheeAprobadores::esAprobadorNivel1Global($usuario);
            $departamentos = HheeAprobadores::departamentosNivel1($usuario);

            return $query->where(function (Builder $q) use ($usuario, $esGlobal, $departamentos) {
                $q->where('solicitante_id', $usuario->id)
                    ->orWhere(function (Builder $qq) use ($esGlobal, $departamentos) {
                        $qq->where('estado', '!=', HheeEstados::BORRADOR);

                        if (!$esGlobal) {
                            $qq->whereIn('departamento_id', $departamentos);
                        }
                    });
            });
        }

        return $query->where('solicitante_id', $usuario->id);
    }

    /**
     * Versión "por instancia" de aplicar(): true si el usuario puede ver esta
     * solicitud puntual, dado su alcance (mismas reglas que aplicar(), pero
     * evaluadas en PHP sobre un modelo ya cargado en vez de en SQL).
     */
    public static function puedeVer(User $usuario, SolicitudHhee $solicitud): bool
    {
        if ((int) $solicitud->solicitante_id === (int) $usuario->id) {
            return true;
        }

        if ($solicitud->estado === HheeEstados::BORRADOR) {
            // Ya se sabe que no es el dueño (chequeado arriba): un borrador
            // ajeno nunca es visible, sin importar rol de aprobador.
            return false;
        }

        if (HheeAprobadores::tieneAlcanceGlobal($usuario)) {
            return true;
        }

        if (HheeAprobadores::esAprobadorNivel1Global($usuario)) {
            return true;
        }

        return HheeAprobadores::departamentosNivel1($usuario)->contains((int) $solicitud->departamento_id);
    }

    /**
     * Filtra $query a las solicitudes REALMENTE pendientes de LA FIRMA de
     * $usuario (usado por GET /api/hhee/pendientes y por el filtro
     * solo_pendientes_mias del listado). A diferencia de aplicar()/puedeVer()
     * (que son de VISIBILIDAD, más amplios), acá cada nivel se habilita por
     * separado según si el usuario puede firmar ESE nivel puntual:
     * - pendiente_nivel1: solo si tiene rol nivel 1 (con match de depto o
     *   global) o contingencia.
     * - pendiente_final: solo si tiene rol de nivel final o contingencia.
     * Antes de este fix, un rol de nivel final (ej. rrhh) heredaba
     * "alcance global" y veía TAMBIÉN las pendiente_nivel1 en su badge, aunque
     * no tuviera ningún rol para firmarlas (después daba 403 al intentar).
     *
     * Además excluye las solicitudes propias del usuario salvo que
     * config('hhee.permitir_autoaprobacion') esté en true: si la
     * autoaprobación está bloqueada (default), un aprobador jamás puede
     * firmar su propia solicitud, así que no tiene sentido mostrarla como
     * "pendiente de tu firma" (el 403 de autoaprobación la rechazaría igual).
     */
    public static function aplicarPendientesDeMiFirma(Builder $query, User $usuario): Builder
    {
        $tieneContingencia = HheeAprobadores::tieneRolContingenciaActivo($usuario);
        $puedeNivel1 = HheeAprobadores::esAprobadorNivel1($usuario) || $tieneContingencia;
        $puedeNivelFinal = HheeAprobadores::esAprobadorNivelFinal($usuario) || $tieneContingencia;

        if (!$puedeNivel1 && !$puedeNivelFinal) {
            // No tiene ningún rol de aprobación (ni contingencia): nada
            // pendiente de su firma.
            return $query->whereRaw('1 = 0');
        }

        $esGlobalNivel1 = $tieneContingencia || HheeAprobadores::esAprobadorNivel1Global($usuario);
        $departamentosNivel1 = HheeAprobadores::departamentosNivel1($usuario);

        $query->where(function (Builder $q) use ($puedeNivel1, $puedeNivelFinal, $esGlobalNivel1, $departamentosNivel1) {
            if ($puedeNivel1) {
                $q->orWhere(function (Builder $qq) use ($esGlobalNivel1, $departamentosNivel1) {
                    $qq->where('estado', HheeEstados::PENDIENTE_NIVEL1);

                    if (!$esGlobalNivel1) {
                        $qq->whereIn('departamento_id', $departamentosNivel1);
                    }
                });
            }

            if ($puedeNivelFinal) {
                $q->orWhere('estado', HheeEstados::PENDIENTE_FINAL);
            }
        });

        if (!config('hhee.permitir_autoaprobacion', false)) {
            $query->where('solicitante_id', '!=', $usuario->id);
        }

        return $query;
    }
}
