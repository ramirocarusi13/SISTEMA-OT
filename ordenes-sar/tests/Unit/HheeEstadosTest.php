<?php

namespace Tests\Unit;

use App\Support\HheeEstados;
use Tests\TestCase;

/**
 * Tests de App\Support\HheeEstados: máquina de estados de
 * hhee_solicitudes.estado. Pura (constantes + arrays), no toca DB.
 */
class HheeEstadosTest extends TestCase
{
    // =========================================================================
    // Matriz COMPLETA de transiciones válidas
    // =========================================================================

    public static function transicionesValidasProvider(): array
    {
        return [
            'borrador -> pendiente_nivel1' => [HheeEstados::BORRADOR, HheeEstados::PENDIENTE_NIVEL1],
            'borrador -> anulada' => [HheeEstados::BORRADOR, HheeEstados::ANULADA],
            'pendiente_nivel1 -> pendiente_final' => [HheeEstados::PENDIENTE_NIVEL1, HheeEstados::PENDIENTE_FINAL],
            'pendiente_nivel1 -> rechazada' => [HheeEstados::PENDIENTE_NIVEL1, HheeEstados::RECHAZADA],
            'pendiente_nivel1 -> anulada' => [HheeEstados::PENDIENTE_NIVEL1, HheeEstados::ANULADA],
            'pendiente_final -> aprobada' => [HheeEstados::PENDIENTE_FINAL, HheeEstados::APROBADA],
            'pendiente_final -> rechazada' => [HheeEstados::PENDIENTE_FINAL, HheeEstados::RECHAZADA],
            'pendiente_final -> anulada' => [HheeEstados::PENDIENTE_FINAL, HheeEstados::ANULADA],
            'aprobada -> cerrada' => [HheeEstados::APROBADA, HheeEstados::CERRADA],
            'aprobada -> anulada' => [HheeEstados::APROBADA, HheeEstados::ANULADA],
        ];
    }

    /** @dataProvider transicionesValidasProvider */
    public function test_transiciones_validas_estan_permitidas(string $de, string $a): void
    {
        $this->assertTrue(HheeEstados::puedeTransicionar($de, $a));
        $this->assertContains($a, HheeEstados::transicionesPermitidas($de));
    }

    /**
     * El total de transiciones válidas debe ser EXACTAMENTE 10 (las del
     * provider de arriba): si alguien agrega/saca una transición sin
     * actualizar este test, se detecta acá.
     */
    public function test_la_cantidad_total_de_transiciones_validas_es_10(): void
    {
        $total = collect(HheeEstados::TRANSICIONES)->sum(fn ($destinos) => count($destinos));

        $this->assertSame(10, $total);
    }

    // =========================================================================
    // Transiciones inválidas (saltos de nivel, retrocesos, desde terminales)
    // =========================================================================

    public static function transicionesInvalidasProvider(): array
    {
        return [
            'borrador -> pendiente_final (saltea nivel1)' => [HheeEstados::BORRADOR, HheeEstados::PENDIENTE_FINAL],
            'borrador -> aprobada' => [HheeEstados::BORRADOR, HheeEstados::APROBADA],
            'borrador -> rechazada' => [HheeEstados::BORRADOR, HheeEstados::RECHAZADA],
            'borrador -> cerrada' => [HheeEstados::BORRADOR, HheeEstados::CERRADA],
            'borrador -> borrador' => [HheeEstados::BORRADOR, HheeEstados::BORRADOR],
            'pendiente_nivel1 -> borrador (retrocede)' => [HheeEstados::PENDIENTE_NIVEL1, HheeEstados::BORRADOR],
            'pendiente_nivel1 -> aprobada (saltea nivel final)' => [HheeEstados::PENDIENTE_NIVEL1, HheeEstados::APROBADA],
            'pendiente_nivel1 -> cerrada' => [HheeEstados::PENDIENTE_NIVEL1, HheeEstados::CERRADA],
            'pendiente_final -> borrador' => [HheeEstados::PENDIENTE_FINAL, HheeEstados::BORRADOR],
            'pendiente_final -> pendiente_nivel1 (retrocede)' => [HheeEstados::PENDIENTE_FINAL, HheeEstados::PENDIENTE_NIVEL1],
            'pendiente_final -> cerrada (saltea aprobada)' => [HheeEstados::PENDIENTE_FINAL, HheeEstados::CERRADA],
            'aprobada -> pendiente_final (retrocede)' => [HheeEstados::APROBADA, HheeEstados::PENDIENTE_FINAL],
            'aprobada -> rechazada' => [HheeEstados::APROBADA, HheeEstados::RECHAZADA],
            'cerrada -> borrador' => [HheeEstados::CERRADA, HheeEstados::BORRADOR],
            'cerrada -> pendiente_nivel1' => [HheeEstados::CERRADA, HheeEstados::PENDIENTE_NIVEL1],
            'rechazada -> pendiente_nivel1' => [HheeEstados::RECHAZADA, HheeEstados::PENDIENTE_NIVEL1],
            'rechazada -> anulada' => [HheeEstados::RECHAZADA, HheeEstados::ANULADA],
            'anulada -> borrador' => [HheeEstados::ANULADA, HheeEstados::BORRADOR],
            'anulada -> pendiente_nivel1' => [HheeEstados::ANULADA, HheeEstados::PENDIENTE_NIVEL1],
        ];
    }

    /** @dataProvider transicionesInvalidasProvider */
    public function test_transiciones_invalidas_estan_bloqueadas(string $de, string $a): void
    {
        $this->assertFalse(HheeEstados::puedeTransicionar($de, $a));
        $this->assertNotContains($a, HheeEstados::transicionesPermitidas($de));
    }

    // =========================================================================
    // esTerminal()
    // =========================================================================

    public static function estadosTerminalesProvider(): array
    {
        return [
            'cerrada' => [HheeEstados::CERRADA],
            'rechazada' => [HheeEstados::RECHAZADA],
            'anulada' => [HheeEstados::ANULADA],
        ];
    }

    /** @dataProvider estadosTerminalesProvider */
    public function test_estados_terminales(string $estado): void
    {
        $this->assertTrue(HheeEstados::esTerminal($estado));
        $this->assertSame([], HheeEstados::transicionesPermitidas($estado));
    }

    public static function estadosNoTerminalesProvider(): array
    {
        return [
            'borrador' => [HheeEstados::BORRADOR],
            'pendiente_nivel1' => [HheeEstados::PENDIENTE_NIVEL1],
            'pendiente_final' => [HheeEstados::PENDIENTE_FINAL],
            'aprobada' => [HheeEstados::APROBADA],
        ];
    }

    /** @dataProvider estadosNoTerminalesProvider */
    public function test_estados_no_terminales(string $estado): void
    {
        $this->assertFalse(HheeEstados::esTerminal($estado));
    }

    // =========================================================================
    // Catálogo / listas auxiliares
    // =========================================================================

    public function test_estados_devuelve_las_7_claves_en_el_orden_del_flujo(): void
    {
        $this->assertSame(
            ['borrador', 'pendiente_nivel1', 'pendiente_final', 'aprobada', 'cerrada', 'rechazada', 'anulada'],
            HheeEstados::estados()
        );
    }

    public function test_catalogo_devuelve_los_7_estados_con_label_y_color(): void
    {
        $catalogo = HheeEstados::catalogo();

        $this->assertCount(7, $catalogo);

        foreach ($catalogo as $item) {
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('color', $item);
            $this->assertNotEmpty($item['label']);
        }
    }
}
