<?php

namespace Tests\Unit;

use App\Support\OpenIssueEstados;
use Tests\TestCase;

/**
 * Tests de App\Support\OpenIssueEstados: máquina de estados de
 * oi_issues.estado (abierto/en_progreso/cerrado) y catálogos de
 * prioridades/tipos de actualización. Pura (constantes + arrays), no toca DB.
 */
class OpenIssueEstadosTest extends TestCase
{
    // =========================================================================
    // Matriz COMPLETA de transiciones válidas
    // =========================================================================

    public static function transicionesValidasProvider(): array
    {
        return [
            'abierto -> en_progreso' => [OpenIssueEstados::ABIERTO, OpenIssueEstados::EN_PROGRESO],
            'abierto -> cerrado' => [OpenIssueEstados::ABIERTO, OpenIssueEstados::CERRADO],
            'en_progreso -> abierto' => [OpenIssueEstados::EN_PROGRESO, OpenIssueEstados::ABIERTO],
            'en_progreso -> cerrado' => [OpenIssueEstados::EN_PROGRESO, OpenIssueEstados::CERRADO],
            'cerrado -> abierto (solo via reabrir)' => [OpenIssueEstados::CERRADO, OpenIssueEstados::ABIERTO],
        ];
    }

    /** @dataProvider transicionesValidasProvider */
    public function test_transiciones_validas_estan_permitidas(string $de, string $a): void
    {
        $this->assertTrue(OpenIssueEstados::puedeTransicionar($de, $a));
        $this->assertContains($a, OpenIssueEstados::transicionesPermitidas($de));
    }

    /**
     * El total de transiciones válidas debe ser EXACTAMENTE 5 (las del
     * provider de arriba): si alguien agrega/saca una transición sin
     * actualizar este test, se detecta acá.
     */
    public function test_la_cantidad_total_de_transiciones_validas_es_5(): void
    {
        $total = collect(OpenIssueEstados::TRANSICIONES)->sum(fn ($destinos) => count($destinos));

        $this->assertSame(5, $total);
    }

    // =========================================================================
    // Transiciones inválidas
    // =========================================================================

    public static function transicionesInvalidasProvider(): array
    {
        return [
            'abierto -> abierto' => [OpenIssueEstados::ABIERTO, OpenIssueEstados::ABIERTO],
            'en_progreso -> en_progreso' => [OpenIssueEstados::EN_PROGRESO, OpenIssueEstados::EN_PROGRESO],
            'cerrado -> cerrado' => [OpenIssueEstados::CERRADO, OpenIssueEstados::CERRADO],
            'cerrado -> en_progreso (reapertura siempre vuelve a abierto)' => [OpenIssueEstados::CERRADO, OpenIssueEstados::EN_PROGRESO],
        ];
    }

    /** @dataProvider transicionesInvalidasProvider */
    public function test_transiciones_invalidas_estan_bloqueadas(string $de, string $a): void
    {
        $this->assertFalse(OpenIssueEstados::puedeTransicionar($de, $a));
        $this->assertNotContains($a, OpenIssueEstados::transicionesPermitidas($de));
    }

    // =========================================================================
    // esTerminal()
    // =========================================================================

    public function test_cerrado_es_terminal(): void
    {
        $this->assertTrue(OpenIssueEstados::esTerminal(OpenIssueEstados::CERRADO));
    }

    public static function estadosNoTerminalesProvider(): array
    {
        return [
            'abierto' => [OpenIssueEstados::ABIERTO],
            'en_progreso' => [OpenIssueEstados::EN_PROGRESO],
        ];
    }

    /** @dataProvider estadosNoTerminalesProvider */
    public function test_estados_no_terminales(string $estado): void
    {
        $this->assertFalse(OpenIssueEstados::esTerminal($estado));
    }

    // =========================================================================
    // ESTADOS_MANUALES: los únicos que POST /{id}/actualizaciones acepta en
    // 'nuevo_estado'. Cerrar/reabrir tienen endpoint propio.
    // =========================================================================

    public function test_estados_manuales_no_incluye_cerrado(): void
    {
        $this->assertSame([OpenIssueEstados::ABIERTO, OpenIssueEstados::EN_PROGRESO], OpenIssueEstados::ESTADOS_MANUALES);
        $this->assertNotContains(OpenIssueEstados::CERRADO, OpenIssueEstados::ESTADOS_MANUALES);
    }

    // =========================================================================
    // Catálogo / listas auxiliares
    // =========================================================================

    public function test_estados_devuelve_las_3_claves(): void
    {
        $this->assertSame(['abierto', 'en_progreso', 'cerrado'], OpenIssueEstados::estados());
    }

    public function test_prioridades_devuelve_las_3_claves(): void
    {
        $this->assertSame(['baja', 'media', 'alta'], OpenIssueEstados::prioridades());
    }

    public function test_catalogo_devuelve_los_3_estados_con_value_label_y_color(): void
    {
        $catalogo = OpenIssueEstados::catalogo();

        $this->assertCount(3, $catalogo);

        foreach ($catalogo as $item) {
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('color', $item);
            $this->assertNotEmpty($item['label']);
            $this->assertNotEmpty($item['color']);
        }

        $this->assertSame(['abierto', 'en_progreso', 'cerrado'], collect($catalogo)->pluck('value')->all());
    }

    public function test_catalogo_prioridades_devuelve_las_3_prioridades_con_value_label_y_color(): void
    {
        $catalogo = OpenIssueEstados::catalogoPrioridades();

        $this->assertCount(3, $catalogo);

        foreach ($catalogo as $item) {
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('color', $item);
        }

        $this->assertSame(['baja', 'media', 'alta'], collect($catalogo)->pluck('value')->all());
    }

    public function test_label_devuelve_el_label_en_espanol_y_hace_fallback_al_valor_crudo_si_no_existe(): void
    {
        $this->assertSame('Abierto', OpenIssueEstados::label(OpenIssueEstados::ABIERTO));
        $this->assertSame('En progreso', OpenIssueEstados::label(OpenIssueEstados::EN_PROGRESO));
        $this->assertSame('Cerrado', OpenIssueEstados::label(OpenIssueEstados::CERRADO));
        $this->assertSame('inexistente', OpenIssueEstados::label('inexistente'));
    }
}
