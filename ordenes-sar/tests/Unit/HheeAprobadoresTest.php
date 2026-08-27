<?php

namespace Tests\Unit;

use App\Support\HheeAprobadores;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Tests de App\Support\HheeAprobadores.
 *
 * App\Support\HheeAprobadores está deliberadamente partida en dos capas para
 * poder testearla sin DB (ver comentario de diseño en la clase):
 * - Métodos "por usuario" (nivelesQuePuedeFirmar(), esContingencia(),
 *   aprobadoresDeNivel(), esAprobadorNivel1(), etc.) consultan
 *   hhee_roles_aprobacion -> NO se testean acá (necesitan DB/factories, fuera
 *   del alcance de esta suite unitaria sin DB).
 * - Métodos "...DeRoles()/...EnRoles()" son PUROS: reciben una
 *   Illuminate\Support\Collection de roles ya resuelta (cada fila solo
 *   necesita ->rol y ->departamento_id, por eso acá se arman con stdClass en
 *   vez de App\Models\HheeRolAprobacion) + primitivos (nivel, departamentoId).
 *   Son los que se testean acá: mapeo rol -> nivel (vía config('hhee.niveles'))
 *   y la lógica de contingencia/alcance departamental.
 *
 * Extiende Tests\TestCase (no PHPUnit\Framework\TestCase puro) porque estos
 * métodos leen config('hhee.niveles') y config('hhee.rol_contingencia'), lo
 * que requiere la app de Laravel booteada (igual que PrioridadOTTest). No usa
 * base de datos.
 *
 * Cobertura documentada como NO incluida en esta suite (queda para un test de
 * integración con DB si se necesita más adelante):
 * - aprobadoresDeNivel() (query real a hhee_roles_aprobacion + users).
 * - esAprobadorNivel1()/esAprobadorNivel1Global()/departamentosNivel1() (idem,
 *   son queries directas, sin lógica de negocio propia más allá del filtro).
 * - tieneAlcanceGlobal()/nivelesGenerales()/tieneRolContingenciaActivo() en su
 *   variante "por usuario" (son wrappers finitos sobre
 *   rolesActivosDeUsuario() + las funciones puras ya cubiertas acá).
 */
class HheeAprobadoresTest extends TestCase
{
    private function rol(string $rol, ?int $departamentoId = null): object
    {
        return (object) ['rol' => $rol, 'departamento_id' => $departamentoId];
    }

    private function roles(array $roles): Collection
    {
        return collect($roles);
    }

    // =========================================================================
    // rolLegitimoDeRoles() — mapeo rol -> nivel (config('hhee.niveles')) +
    // alcance departamental (solo nivel 1)
    // =========================================================================

    public function test_rol_legitimo_nivel1_matchea_por_mismo_departamento(): void
    {
        $roles = $this->roles([$this->rol('jefe', 10)]);

        $this->assertSame('jefe', HheeAprobadores::rolLegitimoDeRoles($roles, 1, 10));
    }

    public function test_rol_legitimo_nivel1_no_matchea_con_departamento_distinto(): void
    {
        $roles = $this->roles([$this->rol('jefe', 10)]);

        $this->assertNull(HheeAprobadores::rolLegitimoDeRoles($roles, 1, 99));
    }

    public function test_rol_legitimo_nivel1_con_departamento_id_null_en_la_fila_es_alcance_global(): void
    {
        $roles = $this->roles([$this->rol('gerente_area', null)]);

        $this->assertSame('gerente_area', HheeAprobadores::rolLegitimoDeRoles($roles, 1, 10));
        $this->assertSame('gerente_area', HheeAprobadores::rolLegitimoDeRoles($roles, 1, 999));
    }

    public function test_rol_legitimo_nivel_final_ignora_departamento_de_la_solicitud(): void
    {
        // rrhh es nivel 2 (alcance global): matchea aunque su fila tenga un
        // departamento_id distinto al de la solicitud.
        $roles = $this->roles([$this->rol('rrhh', 3)]);

        $this->assertSame('rrhh', HheeAprobadores::rolLegitimoDeRoles($roles, 2, 999));
    }

    public function test_rol_legitimo_devuelve_null_si_no_tiene_ningun_rol_del_nivel(): void
    {
        $roles = $this->roles([$this->rol('rrhh', null)]);

        $this->assertNull(HheeAprobadores::rolLegitimoDeRoles($roles, 1, 10));
    }

    public function test_rol_legitimo_devuelve_null_si_solo_tiene_contingencia(): void
    {
        // Contingencia NO es un rol "legítimo" de ningún nivel: se resuelve
        // aparte (ver esContingenciaDeRoles()/rolConQueFirma()).
        $roles = $this->roles([$this->rol('contingencia', null)]);

        $this->assertNull(HheeAprobadores::rolLegitimoDeRoles($roles, 1, 10));
        $this->assertNull(HheeAprobadores::rolLegitimoDeRoles($roles, 2, 10));
    }

    public function test_rol_legitimo_devuelve_el_primero_que_matchea_en_el_orden_de_la_config(): void
    {
        // config('hhee.niveles.1') = ['jefe', 'gerente_area']: si tiene ambos,
        // gana 'jefe' (primero en la lista).
        $roles = $this->roles([$this->rol('gerente_area', 10), $this->rol('jefe', 10)]);

        $this->assertSame('jefe', HheeAprobadores::rolLegitimoDeRoles($roles, 1, 10));
    }

    // =========================================================================
    // tieneRolContingenciaEnRoles()
    // =========================================================================

    public function test_tiene_rol_contingencia_true_si_esta_en_la_coleccion(): void
    {
        $roles = $this->roles([$this->rol('jefe', 10), $this->rol('contingencia', null)]);

        $this->assertTrue(HheeAprobadores::tieneRolContingenciaEnRoles($roles));
    }

    public function test_tiene_rol_contingencia_false_si_no_esta(): void
    {
        $roles = $this->roles([$this->rol('jefe', 10)]);

        $this->assertFalse(HheeAprobadores::tieneRolContingenciaEnRoles($roles));
    }

    public function test_tiene_rol_contingencia_false_con_coleccion_vacia(): void
    {
        $this->assertFalse(HheeAprobadores::tieneRolContingenciaEnRoles($this->roles([])));
    }

    // =========================================================================
    // esContingenciaDeRoles()
    // =========================================================================

    public function test_es_contingencia_true_cuando_solo_tiene_el_rol_contingencia(): void
    {
        $roles = $this->roles([$this->rol('contingencia', null)]);

        $this->assertTrue(HheeAprobadores::esContingenciaDeRoles($roles, 1, 10));
        $this->assertTrue(HheeAprobadores::esContingenciaDeRoles($roles, 2, 10));
    }

    public function test_es_contingencia_false_cuando_ademas_tiene_el_rol_legitimo_del_nivel(): void
    {
        // Tiene contingencia PERO también 'jefe' (legítimo de nivel 1) en el
        // mismo departamento: la firma es "normal", no por contingencia.
        $roles = $this->roles([$this->rol('contingencia', null), $this->rol('jefe', 10)]);

        $this->assertFalse(HheeAprobadores::esContingenciaDeRoles($roles, 1, 10));
    }

    public function test_es_contingencia_false_sin_rol_contingencia_ni_legitimo(): void
    {
        $roles = $this->roles([$this->rol('rrhh', null)]);

        $this->assertFalse(HheeAprobadores::esContingenciaDeRoles($roles, 1, 10));
    }

    public function test_es_contingencia_true_para_nivel1_de_un_departamento_distinto_al_de_su_rol_legitimo(): void
    {
        // Es jefe del depto 10, pero la solicitud es del depto 20: para ESE
        // nivel (1) en ESE departamento no tiene rol legítimo, así que si
        // tiene contingencia firma por contingencia.
        $roles = $this->roles([$this->rol('jefe', 10), $this->rol('contingencia', null)]);

        $this->assertTrue(HheeAprobadores::esContingenciaDeRoles($roles, 1, 20));
    }

    // =========================================================================
    // nivelesDeRoles() — niveles que puede firmar dado un departamento puntual
    // =========================================================================

    public function test_niveles_de_roles_devuelve_solo_el_nivel_1_si_solo_tiene_ese_rol(): void
    {
        $roles = $this->roles([$this->rol('jefe', 10)]);

        $this->assertSame([1], HheeAprobadores::nivelesDeRoles($roles, 10));
    }

    public function test_niveles_de_roles_vacio_si_el_departamento_no_coincide_y_no_tiene_contingencia(): void
    {
        $roles = $this->roles([$this->rol('jefe', 10)]);

        $this->assertSame([], HheeAprobadores::nivelesDeRoles($roles, 999));
    }

    public function test_niveles_de_roles_devuelve_ambos_niveles_si_tiene_uno_de_cada_uno(): void
    {
        $roles = $this->roles([$this->rol('jefe', 10), $this->rol('rrhh', null)]);

        $this->assertSame([1, 2], HheeAprobadores::nivelesDeRoles($roles, 10));
    }

    public function test_niveles_de_roles_con_contingencia_devuelve_todos_los_niveles_sin_importar_departamento(): void
    {
        $roles = $this->roles([$this->rol('contingencia', null)]);

        $this->assertSame([1, 2], HheeAprobadores::nivelesDeRoles($roles, 999));
    }

    public function test_niveles_de_roles_vacio_con_coleccion_vacia(): void
    {
        $this->assertSame([], HheeAprobadores::nivelesDeRoles($this->roles([]), 10));
    }
}
