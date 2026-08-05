<?php

namespace Tests\Unit;

use App\Support\PrioridadOT;
use Tests\TestCase;

/**
 * Tests de App\Support\PrioridadOT (SPEC-prioridad-reportes.md §1 y §2).
 *
 * Extiende Tests\TestCase (no PHPUnit\Framework\TestCase puro) porque
 * PrioridadOT::slaHoras() lee config('ot.sla_horas'), lo que requiere la
 * app de Laravel booteada. No usa base de datos (no RefreshDatabase): toda
 * la lógica bajo test es pura (constantes + arrays), así que no hace falta
 * tocar SQL Server para nada.
 *
 * NOTA: no se pudo ejecutar esta suite en esta máquina porque
 * ordenes-sar/vendor/ no está instalado (ver resumen de la tarea).
 */
class PrioridadOTTest extends TestCase
{
    // =========================================================================
    // Mapa categoría -> prioridad (§1, tabla completa)
    // =========================================================================

    public static function categoriasYPrioridadEsperadaProvider(): array
    {
        return [
            'seguridad -> critica' => [PrioridadOT::CAT_SEGURIDAD, PrioridadOT::CRITICA],
            'parada_linea -> alta' => [PrioridadOT::CAT_PARADA_LINEA, PrioridadOT::ALTA],
            'calidad -> alta' => [PrioridadOT::CAT_CALIDAD, PrioridadOT::ALTA],
            'averia -> media' => [PrioridadOT::CAT_AVERIA, PrioridadOT::MEDIA],
            'mejora -> baja' => [PrioridadOT::CAT_MEJORA, PrioridadOT::BAJA],
        ];
    }

    /** @dataProvider categoriasYPrioridadEsperadaProvider */
    public function test_calcular_mapea_cada_categoria_a_su_prioridad_automatica(string $categoria, string $prioridadEsperada): void
    {
        $resultado = PrioridadOT::calcular($categoria, false);

        $this->assertSame($prioridadEsperada, $resultado['prioridad']);
        $this->assertSame(PrioridadOT::PRIORIDAD_ORDEN[$prioridadEsperada], $resultado['prioridad_orden']);
    }

    /**
     * El mapa de constantes debe seguir teniendo exactamente las 5 categorías
     * de la spec (si alguien agrega/saca una categoría sin actualizar la
     * tabla, este test lo detecta).
     */
    public function test_el_mapa_categoria_prioridad_tiene_exactamente_las_5_categorias_de_la_spec(): void
    {
        $this->assertSame(
            ['seguridad', 'parada_linea', 'calidad', 'averia', 'mejora'],
            array_keys(PrioridadOT::CATEGORIA_PRIORIDAD)
        );
    }

    // =========================================================================
    // Regla "es_seguridad fuerza crítica" (§1) sobre CADA categoría
    // =========================================================================

    /** @dataProvider categoriasYPrioridadEsperadaProvider */
    public function test_es_seguridad_true_fuerza_critica_sin_importar_la_categoria(string $categoria): void
    {
        $resultado = PrioridadOT::calcular($categoria, true);

        $this->assertSame(PrioridadOT::CRITICA, $resultado['prioridad']);
        $this->assertSame(1, $resultado['prioridad_orden']);
    }

    public function test_categoria_seguridad_es_critica_aunque_el_checkbox_es_seguridad_este_en_false(): void
    {
        // La categoría 'seguridad' por sí sola ya fuerza crítica, sin necesidad
        // del checkbox manual (son dos caminos independientes hacia el mismo resultado).
        $resultado = PrioridadOT::calcular(PrioridadOT::CAT_SEGURIDAD, false);

        $this->assertSame(PrioridadOT::CRITICA, $resultado['prioridad']);
    }

    // =========================================================================
    // slaHoras() para las 4 prioridades (§2)
    // =========================================================================

    public static function slaHorasPorPrioridadProvider(): array
    {
        return [
            'critica -> 2h' => [PrioridadOT::CRITICA, 2],
            'alta -> 8h' => [PrioridadOT::ALTA, 8],
            'media -> 48h' => [PrioridadOT::MEDIA, 48],
            'baja -> 168h' => [PrioridadOT::BAJA, 168],
        ];
    }

    /** @dataProvider slaHorasPorPrioridadProvider */
    public function test_sla_horas_devuelve_las_horas_configuradas_por_prioridad(string $prioridad, int $horasEsperadas): void
    {
        $this->assertSame($horasEsperadas, PrioridadOT::slaHoras($prioridad));
    }

    // =========================================================================
    // Casos límite: categoría / prioridad inválida (dato corrupto o legacy)
    // =========================================================================

    public function test_calcular_con_categoria_invalida_cae_en_prioridad_media_como_resguardo(): void
    {
        $resultado = PrioridadOT::calcular('categoria-que-no-existe', false);

        $this->assertSame(PrioridadOT::MEDIA, $resultado['prioridad']);
        $this->assertSame(PrioridadOT::PRIORIDAD_ORDEN[PrioridadOT::MEDIA], $resultado['prioridad_orden']);
    }

    public function test_calcular_con_categoria_invalida_y_es_seguridad_true_sigue_forzando_critica(): void
    {
        // El checkbox de seguridad manda incluso sobre una categoría corrupta/desconocida.
        $resultado = PrioridadOT::calcular('categoria-que-no-existe', true);

        $this->assertSame(PrioridadOT::CRITICA, $resultado['prioridad']);
    }

    public function test_calcular_con_categoria_vacia_no_rompe_y_cae_en_media(): void
    {
        $resultado = PrioridadOT::calcular('', false);

        $this->assertSame(PrioridadOT::MEDIA, $resultado['prioridad']);
    }

    public function test_sla_horas_con_prioridad_invalida_cae_en_el_sla_de_media_como_resguardo(): void
    {
        // Dato corrupto/legacy: una prioridad que no está en config('ot.sla_horas').
        $this->assertSame(48, PrioridadOT::slaHoras('prioridad-que-no-existe'));
    }

    public function test_sla_horas_con_prioridad_vacia_cae_en_el_sla_de_media(): void
    {
        $this->assertSame(48, PrioridadOT::slaHoras(''));
    }

    // =========================================================================
    // Listas auxiliares (usadas por Rule::in() en el controller y por el front)
    // =========================================================================

    public function test_categorias_devuelve_las_5_categorias_en_el_orden_de_la_spec(): void
    {
        $this->assertSame(
            ['seguridad', 'parada_linea', 'calidad', 'averia', 'mejora'],
            PrioridadOT::categorias()
        );
    }

    public function test_prioridades_devuelve_las_4_prioridades_ordenadas_de_mas_a_menos_urgente(): void
    {
        $this->assertSame(
            ['critica', 'alta', 'media', 'baja'],
            PrioridadOT::prioridades()
        );
    }
}
