<?php

namespace App\Support;

use App\Models\HheeRolAprobacion;
use App\Models\SolicitudHhee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Única fuente de verdad de autorización de aprobadores de HHEE. Cruza los
 * roles ACTIVOS de hhee_roles_aprobacion contra la matriz nivel -> roles de
 * config('hhee.niveles'). Los controllers/HheeFlujo NUNCA deben repetir esta
 * lógica a mano.
 *
 * Reglas (ver spec del módulo):
 * - Nivel 1 (jefe/gerente_area) y nivel 2 (gerencia_general/rrhh/presidencia):
 *   el rol matchea si departamento_id de la fila es NULL (alcance global) o
 *   igual al departamento_id de la solicitud. Esto permite "finales de área"
 *   (una última firma acotada a ciertos departamentos) conviviendo con
 *   finales globales (fila con departamento_id NULL, ej. gerencia general).
 * - contingencia: puede firmar CUALQUIER nivel pendiente. Si el usuario
 *   además tiene el rol legítimo para ese nivel, la firma es "normal"
 *   (rolConQueFirma() devuelve el rol legítimo y esContingencia() da false).
 * - Autoaprobación (config('hhee.permitir_autoaprobacion')): esta clase NO la
 *   resuelve (no conoce quién es el "actor" que está evaluando, solo cruza
 *   roles); la valida App\Support\HheeFlujo antes de llamar a aprobar/rechazar.
 *
 * Diseño interno: los métodos "por usuario" (requieren DB: consultan
 * hhee_roles_aprobacion) son delgados -- solo traen las filas activas del
 * usuario y delegan el matching en los métodos "...DeRoles()/...EnRoles()",
 * que son PUROS (reciben una Collection de roles ya resuelta + primitivos, sin
 * tocar Eloquent). Esto permite testear la lógica de negocio
 * (HheeAprobadoresTest) armando la Collection a mano, sin DB.
 */
class HheeAprobadores
{
    // =========================================================================
    // API pública "por usuario" (pegan a la base: consultan hhee_roles_aprobacion)
    // =========================================================================

    /**
     * Niveles (1, 2) que el usuario puede firmar para ESTA solicitud puntual,
     * ya sea por rol legítimo o por contingencia.
     *
     * @return int[]
     */
    public static function nivelesQuePuedeFirmar(User $usuario, SolicitudHhee $solicitud): array
    {
        return self::nivelesDeRoles(self::rolesActivosDeUsuario($usuario), (int) $solicitud->departamento_id);
    }

    /**
     * True si, PARA ESE NIVEL puntual, el usuario firma por contingencia (no
     * tiene el rol legítimo del nivel pero sí el rol 'contingencia' activo).
     */
    public static function esContingencia(User $usuario, int $nivel, SolicitudHhee $solicitud): bool
    {
        return self::esContingenciaDeRoles(self::rolesActivosDeUsuario($usuario), $nivel, (int) $solicitud->departamento_id);
    }

    /**
     * Rol concreto con el que el usuario firmaría ese nivel: el rol legítimo
     * si lo tiene, o 'contingencia' si no lo tiene pero puede firmar por
     * contingencia. Null si no puede firmar ese nivel en absoluto.
     */
    public static function rolConQueFirma(User $usuario, int $nivel, SolicitudHhee $solicitud): ?string
    {
        $roles = self::rolesActivosDeUsuario($usuario);

        $rolLegitimo = self::rolLegitimoDeRoles($roles, $nivel, (int) $solicitud->departamento_id);
        if ($rolLegitimo !== null) {
            return $rolLegitimo;
        }

        return self::tieneRolContingenciaEnRoles($roles) ? config('hhee.rol_contingencia', 'contingencia') : null;
    }

    /**
     * Rol LEGÍTIMO (nunca contingencia) con el que el usuario firmaría ese
     * nivel para esta solicitud puntual. Null si no tiene ningún rol legítimo
     * de ese nivel (aunque tenga contingencia: para eso está rolConQueFirma()/
     * esContingencia()). Pensado para reglas que deben excluir explícitamente
     * la contingencia, como la firma implícita de nivel 1 al enviar
     * (ver HheeFlujo::enviar()).
     */
    public static function rolLegitimoParaNivel(User $usuario, int $nivel, SolicitudHhee $solicitud): ?string
    {
        return self::rolLegitimoDeRoles(self::rolesActivosDeUsuario($usuario), $nivel, (int) $solicitud->departamento_id);
    }

    /**
     * Usuarios únicos con rol ACTIVO y LEGÍTIMO para ese nivel (sin contar
     * contingencia): son los destinatarios de "pendiente de tu firma". Para
     * nivel 1 respeta el alcance por departamento (filas con departamento_id
     * NULL o igual a $departamentoId); el resto de niveles (alcance global)
     * ignoran $departamentoId.
     */
    public static function aprobadoresDeNivel(int $nivel, ?int $departamentoId): Collection
    {
        $rolesNivel = config("hhee.niveles.$nivel", []);

        if (empty($rolesNivel)) {
            return collect();
        }

        $query = HheeRolAprobacion::query()->whereIn('rol', $rolesNivel)->where('activo', true);

        if (self::esNivelConAlcanceDepartamental($nivel)) {
            $query->where(function ($q) use ($departamentoId) {
                $q->whereNull('departamento_id')->orWhere('departamento_id', $departamentoId);
            });
        }

        $userIds = $query->pluck('user_id')->unique();

        return User::whereIn('id', $userIds)->get();
    }

    /**
     * True si el usuario tiene al menos un rol de aprobación activo (de
     * cualquier nivel, incluida contingencia).
     */
    public static function esAprobador(User $usuario): bool
    {
        return self::rolesActivosDeUsuario($usuario)->isNotEmpty();
    }

    /**
     * True si el usuario ve TODAS las solicitudes sin filtro de departamento:
     * tiene un rol de nivel final (alcance global por definición) o el rol
     * contingencia (que puede firmar cualquier nivel de cualquier
     * departamento).
     */
    public static function tieneAlcanceGlobal(User $usuario): bool
    {
        $roles = self::rolesActivosDeUsuario($usuario);

        if (self::tieneRolContingenciaEnRoles($roles)) {
            return true;
        }

        $rolesNivelFinal = self::rolesDeNivelFinal();

        return $roles->contains(fn ($rolFila) => in_array($rolFila->rol, $rolesNivelFinal, true));
    }

    public static function tieneRolContingenciaActivo(User $usuario): bool
    {
        return self::tieneRolContingenciaEnRoles(self::rolesActivosDeUsuario($usuario));
    }

    /**
     * Niveles que el usuario puede firmar EN GENERAL (sin una solicitud
     * puntual): para nivel 1 alcanza con tener el rol en CUALQUIER
     * departamento (o global). Pensado para el catálogo del front
     * ('mis_niveles'), NO para autorizar una firma real (para eso usar
     * nivelesQuePuedeFirmar(), que sí mira el departamento de la solicitud).
     */
    public static function nivelesGenerales(User $usuario): array
    {
        $roles = self::rolesActivosDeUsuario($usuario);
        $niveles = [];

        foreach (config('hhee.niveles', []) as $nivel => $rolesNivel) {
            if ($roles->contains(fn ($rolFila) => in_array($rolFila->rol, $rolesNivel, true))) {
                $niveles[] = (int) $nivel;
            }
        }

        if (self::tieneRolContingenciaEnRoles($roles)) {
            $niveles = array_map('intval', array_keys(config('hhee.niveles', [])));
        }

        return collect($niveles)->unique()->sort()->values()->all();
    }

    /**
     * True si el usuario es aprobador de nivel 1 en AL MENOS un departamento
     * (o de forma global). No mira contingencia (para eso, tieneAlcanceGlobal()).
     */
    public static function esAprobadorNivel1(User $usuario): bool
    {
        return self::esAprobadorNivel1Global($usuario) || self::departamentosNivel1($usuario)->isNotEmpty();
    }

    /**
     * True si el usuario tiene una fila de rol nivel 1 con departamento_id
     * NULL (alcance global para nivel 1: ve/firma nivel 1 de CUALQUIER
     * departamento).
     */
    public static function esAprobadorNivel1Global(User $usuario): bool
    {
        return HheeRolAprobacion::where('user_id', $usuario->id)
            ->where('activo', true)
            ->whereIn('rol', config('hhee.niveles.1', []))
            ->whereNull('departamento_id')
            ->exists();
    }

    /**
     * True si el usuario tiene un rol LEGÍTIMO del nivel final (hoy: nivel 2,
     * gerencia_general/rrhh/presidencia) en AL MENOS un departamento (o de
     * forma global), sin contar contingencia (eso se resuelve aparte, igual
     * que esAprobadorNivel1()).
     */
    public static function esAprobadorNivelFinal(User $usuario): bool
    {
        $rolesNivelFinal = self::rolesDeNivelFinal();

        if (empty($rolesNivelFinal)) {
            return false;
        }

        return HheeRolAprobacion::where('user_id', $usuario->id)
            ->where('activo', true)
            ->whereIn('rol', $rolesNivelFinal)
            ->exists();
    }

    /**
     * True si el usuario tiene una fila de rol nivel final con departamento_id
     * NULL (alcance global: firma la final de CUALQUIER departamento, como
     * gerencia general). Espejo de esAprobadorNivel1Global().
     */
    public static function esAprobadorNivelFinalGlobal(User $usuario): bool
    {
        return HheeRolAprobacion::where('user_id', $usuario->id)
            ->where('activo', true)
            ->whereIn('rol', self::rolesDeNivelFinal())
            ->whereNull('departamento_id')
            ->exists();
    }

    /**
     * Departamentos (ids únicos) para los que el usuario es aprobador de
     * nivel final CON alcance acotado (departamento_id no nulo en la fila).
     * Espejo de departamentosNivel1().
     */
    public static function departamentosNivelFinal(User $usuario): Collection
    {
        return HheeRolAprobacion::where('user_id', $usuario->id)
            ->where('activo', true)
            ->whereIn('rol', self::rolesDeNivelFinal())
            ->whereNotNull('departamento_id')
            ->pluck('departamento_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * Departamentos (ids únicos) para los que el usuario es aprobador de
     * nivel 1 CON alcance acotado (departamento_id no nulo en la fila).
     */
    public static function departamentosNivel1(User $usuario): Collection
    {
        return HheeRolAprobacion::where('user_id', $usuario->id)
            ->where('activo', true)
            ->whereIn('rol', config('hhee.niveles.1', []))
            ->whereNotNull('departamento_id')
            ->pluck('departamento_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    // =========================================================================
    // Lógica PURA (sin DB): recibe la Collection de roles ya resuelta. Cada
    // fila de $roles necesita únicamente ->rol y ->departamento_id (funciona
    // tanto con App\Models\HheeRolAprobacion como con stdClass armados a mano
    // en los tests, ver tests/Unit/HheeAprobadoresTest.php).
    // =========================================================================

    public static function nivelesDeRoles(Collection $roles, ?int $departamentoId): array
    {
        $niveles = [];

        foreach (array_keys(config('hhee.niveles', [])) as $nivel) {
            if (self::rolLegitimoDeRoles($roles, (int) $nivel, $departamentoId) !== null) {
                $niveles[] = (int) $nivel;
            }
        }

        if (self::tieneRolContingenciaEnRoles($roles)) {
            $niveles = array_map('intval', array_keys(config('hhee.niveles', [])));
        }

        return collect($niveles)->unique()->sort()->values()->all();
    }

    public static function esContingenciaDeRoles(Collection $roles, int $nivel, ?int $departamentoId): bool
    {
        if (self::rolLegitimoDeRoles($roles, $nivel, $departamentoId) !== null) {
            return false;
        }

        return self::tieneRolContingenciaEnRoles($roles);
    }

    /**
     * Primer rol (en el orden de config('hhee.niveles.N')) que la Collection
     * de roles matchea para ese nivel, respetando el alcance departamental
     * SOLO en el nivel 1 (el resto de niveles son de alcance global). Null si
     * ninguno matchea (aunque el usuario tenga contingencia: eso se resuelve
     * aparte, ver esContingenciaDeRoles()/rolConQueFirma()).
     */
    public static function rolLegitimoDeRoles(Collection $roles, int $nivel, ?int $departamentoId): ?string
    {
        $rolesNivel = config("hhee.niveles.$nivel", []);
        $esDepartamental = self::esNivelConAlcanceDepartamental($nivel);

        foreach ($rolesNivel as $rolBuscado) {
            $match = $roles->first(function ($rolFila) use ($rolBuscado, $esDepartamental, $departamentoId) {
                if ($rolFila->rol !== $rolBuscado) {
                    return false;
                }

                if (!$esDepartamental) {
                    return true;
                }

                return $rolFila->departamento_id === null
                    || (int) $rolFila->departamento_id === (int) $departamentoId;
            });

            if ($match) {
                return $rolBuscado;
            }
        }

        return null;
    }

    public static function tieneRolContingenciaEnRoles(Collection $roles): bool
    {
        $rolContingencia = config('hhee.rol_contingencia', 'contingencia');

        return $roles->contains(fn ($rolFila) => $rolFila->rol === $rolContingencia);
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    /**
     * Roles de aprobación ACTIVOS del usuario (una query por llamada: no se
     * memoiza porque esta clase es stateless y hhee_roles_aprobacion es una
     * tabla chica; no es un hot path como AlcanceOrdenes).
     */
    private static function rolesActivosDeUsuario(User $usuario): Collection
    {
        return HheeRolAprobacion::where('user_id', $usuario->id)->where('activo', true)->get();
    }

    /**
     * TODOS los niveles respetan departamento_id de la fila: NULL = alcance
     * global, un id concreto = solo ese departamento. Esto permite finales
     * "de área" (ej. una última firma solo para Produccion/PC/Mantenimiento)
     * conviviendo con finales globales como gerencia general (fila con
     * departamento_id NULL). Ver comentario de config('hhee.niveles').
     */
    private static function esNivelConAlcanceDepartamental(int $nivel): bool
    {
        return true;
    }

    /**
     * Roles del último nivel configurado (hoy: nivel 2, "aprobación final").
     */
    private static function rolesDeNivelFinal(): array
    {
        $niveles = config('hhee.niveles', []);

        if (empty($niveles)) {
            return [];
        }

        return $niveles[max(array_keys($niveles))] ?? [];
    }
}
