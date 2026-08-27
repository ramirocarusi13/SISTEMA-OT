<?php

namespace Tests\Unit;

use App\Support\HheeFlujo;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests de App\Support\HheeFlujo::calcularHoras() y ::validarDesglose().
 * Extiende Tests\TestCase (no PHPUnit\Framework\TestCase puro) porque
 * validarDesglose() lee config('hhee.max_horas_por_empleado'), lo que
 * requiere la app de Laravel booteada (igual que PrioridadOTTest). No usa
 * base de datos: calcularHoras() es aritmética pura sobre Carbon y
 * validarDesglose() solo lanza Illuminate\Validation\ValidationException (no
 * pega a la base ni resuelve traducciones en el momento de lanzarla).
 */
class HheeHorasTest extends TestCase
{
    // =========================================================================
    // calcularHoras()
    // =========================================================================

    public function test_calcular_horas_turno_normal_de_8_horas(): void
    {
        $this->assertSame(8.0, HheeFlujo::calcularHoras('08:00', '16:00', false));
    }

    public function test_calcular_horas_turno_corto_con_minutos(): void
    {
        $this->assertSame(1.5, HheeFlujo::calcularHoras('20:00', '21:30', false));
    }

    public function test_calcular_horas_cruzando_medianoche(): void
    {
        // 22:00 a 02:00 del día siguiente = 4 horas
        $this->assertSame(4.0, HheeFlujo::calcularHoras('22:00', '02:00', true));
    }

    public function test_calcular_horas_cruzando_medianoche_hasta_justo_las_00(): void
    {
        // 22:00 a 00:00 (del día siguiente) = 2 horas
        $this->assertSame(2.0, HheeFlujo::calcularHoras('22:00', '00:00', true));
    }

    public function test_calcular_horas_desde_igual_a_hasta_sin_cruce_da_cero(): void
    {
        $this->assertSame(0.0, HheeFlujo::calcularHoras('08:00', '08:00', false));
    }

    public function test_calcular_horas_sin_marcar_cruce_pero_hasta_menor_a_desde_da_negativo(): void
    {
        // Si el front NO marca cruza_medianoche pero el horario cruza en los
        // hechos, el resultado da NEGATIVO a propósito (no se "adivina" el
        // cruce): así el desglose nunca puede cerrar y validarDesglose()
        // fuerza a marcar cruza_medianoche en vez de dar un resultado
        // engañoso.
        $this->assertSame(-20.0, HheeFlujo::calcularHoras('22:00', '02:00', false));
    }

    // =========================================================================
    // validarDesglose()
    // =========================================================================

    private function detalleBase(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Juan Pérez',
            'legajo' => '123',
            'hora_desde' => '08:00',
            'hora_hasta' => '16:00',
            'cruza_medianoche' => false,
            'hs_teoricas_50' => 8,
            'hs_teoricas_100' => 0,
            'hs_teoricas_50n' => 0,
            'hs_teoricas_100n' => 0,
        ], $overrides);
    }

    public function test_validar_desglose_no_lanza_cuando_la_suma_coincide_con_las_horas_del_turno(): void
    {
        HheeFlujo::validarDesglose($this->detalleBase());

        $this->assertTrue(true); // llegó hasta acá sin lanzar excepción
    }

    public function test_validar_desglose_acepta_el_desglose_repartido_en_varios_tipos_de_hora(): void
    {
        HheeFlujo::validarDesglose($this->detalleBase([
            'hs_teoricas_50' => 5,
            'hs_teoricas_100' => 3,
        ]));

        $this->assertTrue(true);
    }

    public function test_validar_desglose_tolera_diferencias_menores_o_iguales_a_0_01(): void
    {
        HheeFlujo::validarDesglose($this->detalleBase(['hs_teoricas_50' => 8.005]));

        $this->assertTrue(true);
    }

    public function test_validar_desglose_lanza_si_la_suma_no_coincide_con_las_horas_del_turno(): void
    {
        $this->expectException(ValidationException::class);

        HheeFlujo::validarDesglose($this->detalleBase(['hs_teoricas_50' => 5]));
    }

    public function test_validar_desglose_lanza_si_falta_marcar_cruza_medianoche(): void
    {
        // 22:00 a 02:00 sin marcar cruce da -20hs (ver test de calcularHoras):
        // el desglose de 4hs nunca puede coincidir con eso.
        $this->expectException(ValidationException::class);

        HheeFlujo::validarDesglose($this->detalleBase([
            'hora_desde' => '22:00',
            'hora_hasta' => '02:00',
            'cruza_medianoche' => false,
            'hs_teoricas_50' => 4,
        ]));
    }

    public function test_validar_desglose_lanza_si_supera_el_maximo_de_horas_por_empleado(): void
    {
        // Turno de 15hs (08:00 a 23:00), desglose que cierra pero supera el
        // tope configurado (default 12hs, ver config('hhee.max_horas_por_empleado')).
        $this->expectException(ValidationException::class);

        HheeFlujo::validarDesglose($this->detalleBase([
            'hora_hasta' => '23:00',
            'hs_teoricas_50' => 15,
        ]));
    }

    public function test_validar_desglose_incluye_el_indice_del_detalle_en_la_clave_del_error_si_se_pasa(): void
    {
        try {
            HheeFlujo::validarDesglose($this->detalleBase(['hs_teoricas_50' => 5]), 2);
            $this->fail('Se esperaba que validarDesglose() lanzara ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('detalles.2', $e->errors());
        }
    }

    // NOTA: la validación de EMPLEADOS DUPLICADOS dentro de una misma
    // solicitud (App\Support\HheeFlujo::validarDetalles(), privado) no tiene
    // test unitario dedicado: no está en el alcance pedido para esta suite
    // (calcularHoras/validación de desglose) y su lógica es una comparación
    // de strings trivial sobre el array ya validado por validarDesglose().
}
