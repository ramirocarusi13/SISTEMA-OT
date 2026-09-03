<?php

namespace Tests\Unit;

use App\Support\HheeFlujo;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests de App\Support\HheeFlujo::calcularHoras()/cruzaMedianoche()/
 * calcularHorasTeoricas(). Extiende Tests\TestCase (no PHPUnit\Framework\
 * TestCase puro) porque calcularHorasTeoricas() lee
 * config('hhee.max_horas_por_empleado'), lo que requiere la app de Laravel
 * booteada (igual que PrioridadOTTest). No usa base de datos: todo es
 * aritmética pura sobre Carbon/strings + ValidationException (no pega a la
 * base ni resuelve traducciones en el momento de lanzarla).
 *
 * Simplificación de la carga (ver spec del cambio): ya no hay desglose por
 * tipo de hora ni flag cruza_medianoche mandado por el cliente -- el cruce de
 * medianoche se infiere SOLO del horario (hasta <= desde) y validarDesglose()
 * desapareció (reemplazada por calcularHorasTeoricas()).
 */
class HheeHorasTest extends TestCase
{
    // =========================================================================
    // calcularHoras()
    // =========================================================================

    public function test_calcular_horas_turno_normal_de_8_horas(): void
    {
        $this->assertSame(8.0, HheeFlujo::calcularHoras('08:00', '16:00'));
    }

    public function test_calcular_horas_turno_corto_con_minutos(): void
    {
        $this->assertSame(1.5, HheeFlujo::calcularHoras('20:00', '21:30'));
    }

    public function test_calcular_horas_cruzando_medianoche_sin_flag(): void
    {
        // 22:00 a 02:00 del día siguiente = 4 horas, SIN pasar ningún flag:
        // se infiere solo porque hasta (02:00) <= desde (22:00).
        $this->assertSame(4.0, HheeFlujo::calcularHoras('22:00', '02:00'));
    }

    public function test_calcular_horas_cruzando_medianoche_hasta_justo_las_00(): void
    {
        // 22:00 a 00:00 (del día siguiente) = 2 horas
        $this->assertSame(2.0, HheeFlujo::calcularHoras('22:00', '00:00'));
    }

    public function test_calcular_horas_desde_igual_a_hasta_lanza_horario_invalido(): void
    {
        $this->expectException(ValidationException::class);

        HheeFlujo::calcularHoras('08:00', '08:00');
    }

    public function test_calcular_horas_desde_igual_a_hasta_incluye_el_indice_en_la_clave_del_error_si_se_pasa(): void
    {
        try {
            HheeFlujo::calcularHoras('08:00', '08:00', 3);
            $this->fail('Se esperaba que calcularHoras() lanzara ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('detalles.3', $e->errors());
        }
    }

    public function test_calcular_horas_desde_igual_a_hasta_sin_indice_usa_la_clave_generica(): void
    {
        try {
            HheeFlujo::calcularHoras('08:00', '08:00');
            $this->fail('Se esperaba que calcularHoras() lanzara ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('detalles', $e->errors());
        }
    }

    // =========================================================================
    // cruzaMedianoche()
    // =========================================================================

    public function test_cruza_medianoche_false_para_un_turno_normal(): void
    {
        $this->assertFalse(HheeFlujo::cruzaMedianoche('08:00', '16:00'));
    }

    public function test_cruza_medianoche_true_cuando_hasta_es_menor_que_desde(): void
    {
        $this->assertTrue(HheeFlujo::cruzaMedianoche('22:00', '02:00'));
    }

    public function test_cruza_medianoche_true_cuando_hasta_es_igual_a_desde(): void
    {
        // cruzaMedianoche() es pura comparación (hasta <= desde): no lanza,
        // aunque ese caso puntual (horario inválido) lo rechaza
        // calcularHoras() antes de llegar acá.
        $this->assertTrue(HheeFlujo::cruzaMedianoche('08:00', '08:00'));
    }

    // =========================================================================
    // calcularHorasTeoricas()
    // =========================================================================

    private function detalleBase(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Juan Pérez',
            'hora_desde' => '08:00',
            'hora_hasta' => '16:00',
        ], $overrides);
    }

    public function test_calcular_horas_teoricas_devuelve_las_horas_del_turno(): void
    {
        $this->assertSame(8.0, HheeFlujo::calcularHorasTeoricas($this->detalleBase()));
    }

    public function test_calcular_horas_teoricas_cruzando_medianoche(): void
    {
        $horas = HheeFlujo::calcularHorasTeoricas($this->detalleBase([
            'hora_desde' => '22:00',
            'hora_hasta' => '02:00',
        ]));

        $this->assertSame(4.0, $horas);
    }

    public function test_calcular_horas_teoricas_lanza_si_supera_el_maximo_de_horas_por_empleado(): void
    {
        // Turno de 15hs (08:00 a 23:00), supera el tope configurado (default
        // 12hs, ver config('hhee.max_horas_por_empleado')).
        $this->expectException(ValidationException::class);

        HheeFlujo::calcularHorasTeoricas($this->detalleBase(['hora_hasta' => '23:00']));
    }

    public function test_calcular_horas_teoricas_lanza_si_el_horario_es_invalido(): void
    {
        $this->expectException(ValidationException::class);

        HheeFlujo::calcularHorasTeoricas($this->detalleBase(['hora_hasta' => '08:00']));
    }

    public function test_calcular_horas_teoricas_incluye_el_indice_del_detalle_en_la_clave_del_error_si_se_pasa(): void
    {
        try {
            HheeFlujo::calcularHorasTeoricas($this->detalleBase(['hora_hasta' => '23:00']), 2);
            $this->fail('Se esperaba que calcularHorasTeoricas() lanzara ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('detalles.2', $e->errors());
        }
    }

    // NOTA: la validación de EMPLEADOS DUPLICADOS dentro de una misma
    // solicitud (App\Support\HheeFlujo::validarDetalles(), privado) no tiene
    // test unitario dedicado: no está en el alcance pedido para esta suite y
    // su lógica es una comparación de strings trivial (nombre normalizado)
    // sobre el array ya validado por calcularHorasTeoricas().
}
