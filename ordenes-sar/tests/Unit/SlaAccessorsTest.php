<?php

namespace Tests\Unit;

use App\Models\OrdenTrabajo;
use App\Support\PrioridadOT;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tests de los accessors de SLA de App\Models\OrdenTrabajo (sla_estado,
 * sla_vence_at, cumplio_sla — SPEC-prioridad-reportes.md §2).
 *
 * Los modelos se instancian en memoria (new OrdenTrabajo() + asignación
 * directa de atributos) y NUNCA se guardan (no ->save(), no ->create()):
 * los accessors solo leen atributos ya cargados, no disparan queries, así
 * que no hace falta tocar SQL Server para nada. Extiende Tests\TestCase
 * (no PHPUnit\Framework\TestCase puro) porque los accessors llaman a
 * PrioridadOT::slaHoras(), que lee config('ot.sla_horas').
 *
 * Los horarios se calculan siempre en base a Carbon::now('America/Argentina/Buenos_Aires'),
 * el mismo timezone que usan los accessors, para que las comparaciones sean estables.
 *
 * NOTA: no se pudo ejecutar esta suite en esta máquina porque
 * ordenes-sar/vendor/ no está instalado (ver resumen de la tarea).
 */
class SlaAccessorsTest extends TestCase
{
    private const TZ = 'America/Argentina/Buenos_Aires';

    /** Crea una OrdenTrabajo en memoria (sin persistir) con los atributos dados. */
    private function ordenEnMemoria(array $atributos): OrdenTrabajo
    {
        $orden = new OrdenTrabajo();
        foreach ($atributos as $clave => $valor) {
            $orden->{$clave} = $valor;
        }

        return $orden;
    }

    // =========================================================================
    // sla_estado: los 4 casos del semáforo (§2)
    // =========================================================================

    public function test_sla_estado_en_tiempo_cuando_la_orden_esta_activa_sin_asignar_y_queda_mucho_sla(): void
    {
        $ahora = Carbon::now(self::TZ);

        $orden = $this->ordenEnMemoria([
            'estado' => 'creada',
            'prioridad' => PrioridadOT::BAJA, // SLA de 168h (7 días)
            'fecha_aprobacion' => $ahora->copy()->subHour(), // hace 1h de un total de 168h -> sobra SLA de sobra
            'fecha_asignacion' => null,
        ]);

        $this->assertSame('en_tiempo', $orden->sla_estado);
    }

    public function test_sla_estado_por_vencer_cuando_queda_25_por_ciento_o_menos_del_sla(): void
    {
        $ahora = Carbon::now(self::TZ);

        // Crítica = SLA de 120 minutos. Aprobada hace 110 min -> quedan 10 min
        // (10/120 = 8.3% <= 25%): debe marcar "por_vencer", todavía no vencida.
        $orden = $this->ordenEnMemoria([
            'estado' => 'aprobada',
            'prioridad' => PrioridadOT::CRITICA,
            'fecha_aprobacion' => $ahora->copy()->subMinutes(110),
            'fecha_asignacion' => null,
        ]);

        $this->assertSame('por_vencer', $orden->sla_estado);
    }

    public function test_sla_estado_vencida_cuando_ya_paso_el_vencimiento_y_sigue_sin_asignar(): void
    {
        $ahora = Carbon::now(self::TZ);

        // Alta = SLA de 8h. Aprobada hace 9h -> ya venció.
        $orden = $this->ordenEnMemoria([
            'estado' => 'aprobada',
            'prioridad' => PrioridadOT::ALTA,
            'fecha_aprobacion' => $ahora->copy()->subHours(9),
            'fecha_asignacion' => null,
        ]);

        $this->assertSame('vencida', $orden->sla_estado);
    }

    public function test_sla_estado_sin_sla_cuando_la_orden_esta_finalizada(): void
    {
        $ahora = Carbon::now(self::TZ);

        // Aunque el SLA de "primera respuesta" ya venció hace rato, una vez
        // finalizada el semáforo deja de tener sentido (§2): sin_sla.
        $orden = $this->ordenEnMemoria([
            'estado' => 'finalizada',
            'prioridad' => PrioridadOT::CRITICA,
            'fecha_aprobacion' => $ahora->copy()->subDays(5),
            'fecha_asignacion' => $ahora->copy()->subDays(4),
        ]);

        $this->assertSame('sin_sla', $orden->sla_estado);
    }

    public function test_sla_estado_sin_sla_cuando_no_hay_fecha_base_para_calcular_el_vencimiento(): void
    {
        // Caso límite adicional: sin fecha_aprobacion NI created_at (dato
        // corrupto/histórico), sla_vence_at es null -> no se puede evaluar el
        // semáforo, se informa sin_sla en vez de romper.
        $orden = $this->ordenEnMemoria([
            'estado' => 'creada',
            'prioridad' => PrioridadOT::MEDIA,
            'fecha_aprobacion' => null,
            'fecha_asignacion' => null,
        ]);
        $orden->created_at = null;

        $this->assertSame('sin_sla', $orden->sla_estado);
    }

    public function test_sla_estado_incumplida_cuando_la_asignacion_llego_despues_del_vencimiento(): void
    {
        // Una vez que hay fecha_asignacion el reloj de "primera respuesta" se
        // detiene, pero no se informa 'en_tiempo' si la asignación llegó tarde:
        // sería contradictorio mostrar un vencimiento que ya quedó en el pasado.
        $ahora = Carbon::now(self::TZ);

        $orden = $this->ordenEnMemoria([
            'estado' => 'en_proceso',
            'prioridad' => PrioridadOT::CRITICA, // SLA de 2h
            'fecha_aprobacion' => $ahora->copy()->subDays(3),
            'fecha_asignacion' => $ahora->copy()->subDays(2), // 24h después del vencimiento
        ]);

        $this->assertSame('incumplida', $orden->sla_estado);
    }

    public function test_sla_estado_en_tiempo_una_vez_asignada_dentro_del_plazo(): void
    {
        $ahora = Carbon::now(self::TZ);

        $orden = $this->ordenEnMemoria([
            'estado' => 'en_proceso',
            'prioridad' => PrioridadOT::CRITICA, // SLA de 2h
            'fecha_aprobacion' => $ahora->copy()->subDays(3),
            'fecha_asignacion' => $ahora->copy()->subDays(3)->addHour(), // dentro de las 2h
        ]);

        $this->assertSame('en_tiempo', $orden->sla_estado);
    }

    // =========================================================================
    // cumplio_sla: NULL / anterior al vencimiento (cumplió) / posterior (no cumplió)
    // =========================================================================

    public function test_cumplio_sla_es_null_cuando_no_hay_fecha_asignacion(): void
    {
        $orden = $this->ordenEnMemoria([
            'estado' => 'aprobada',
            'prioridad' => PrioridadOT::MEDIA,
            'fecha_aprobacion' => Carbon::now(self::TZ),
            'fecha_asignacion' => null,
        ]);

        $this->assertNull($orden->cumplio_sla);
    }

    public function test_cumplio_sla_true_cuando_la_asignacion_fue_antes_del_vencimiento(): void
    {
        $ahora = Carbon::now(self::TZ);

        // Alta = SLA de 8h. Asignada 30 minutos después de aprobada -> cumplió.
        $orden = $this->ordenEnMemoria([
            'estado' => 'asignada',
            'prioridad' => PrioridadOT::ALTA,
            'fecha_aprobacion' => $ahora->copy()->subMinutes(30),
            'fecha_asignacion' => $ahora,
        ]);

        $this->assertTrue($orden->cumplio_sla);
    }

    public function test_cumplio_sla_false_cuando_la_asignacion_fue_despues_del_vencimiento(): void
    {
        $ahora = Carbon::now(self::TZ);

        // Crítica = SLA de 2h. Asignada 3h después de aprobada -> no cumplió.
        $orden = $this->ordenEnMemoria([
            'estado' => 'asignada',
            'prioridad' => PrioridadOT::CRITICA,
            'fecha_aprobacion' => $ahora->copy()->subHours(3),
            'fecha_asignacion' => $ahora,
        ]);

        $this->assertFalse($orden->cumplio_sla);
    }

    public function test_cumplio_sla_es_null_cuando_hay_asignacion_pero_no_hay_fecha_base_para_el_vencimiento(): void
    {
        // Caso límite: fecha_asignacion sí está, pero fecha_aprobacion Y
        // created_at son null (dato corrupto/histórico) -> no hay vencimiento
        // contra el cual comparar, así que no se puede afirmar ni negar el
        // cumplimiento: cumplio_sla debe ser null, no false.
        $orden = $this->ordenEnMemoria([
            'estado' => 'asignada',
            'prioridad' => PrioridadOT::MEDIA,
            'fecha_aprobacion' => null,
            'fecha_asignacion' => Carbon::now(self::TZ),
        ]);
        $orden->created_at = null;

        $this->assertNull($orden->cumplio_sla);
    }

    public function test_cumplio_sla_usa_created_at_como_fallback_cuando_no_hay_fecha_aprobacion(): void
    {
        $ahora = Carbon::now(self::TZ);

        // Sin fecha_aprobacion: el vencimiento se calcula desde created_at (fallback del §2).
        $orden = $this->ordenEnMemoria([
            'estado' => 'asignada',
            'prioridad' => PrioridadOT::ALTA, // SLA de 8h
            'fecha_aprobacion' => null,
            'fecha_asignacion' => $ahora,
        ]);
        $orden->created_at = $ahora->copy()->subHours(2); // dentro de las 8h -> cumplió

        $this->assertTrue($orden->cumplio_sla);
    }
}
