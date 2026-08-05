// Utilidades de prioridad/SLA para el front (SPEC-prioridad-reportes.md §1 y §2).
// Los labels, colores y horas de SLA SIEMPRE se piden a /api/ot/catalogos (ver
// Utils/otApi.js::fetchCatalogosOT) y nunca se hardcodean acá.
//
// El mapeo categoría -> prioridad lo sirve el backend en /api/ot/catalogos
// (cada categoría trae su campo `prioridad`). El objeto de abajo es solo un
// fallback defensivo por si el catálogo todavía no cargó; debe reflejar
// ordenes-sar/app/Support/PrioridadOT.php::CATEGORIA_PRIORIDAD.
export const CATEGORIA_A_PRIORIDAD = {
    seguridad: 'critica',
    parada_linea: 'alta',
    calidad: 'alta',
    averia: 'media',
    mejora: 'baja',
};

export const PRIORIDAD_ORDEN_FALLBACK = {
    critica: 1,
    alta: 2,
    media: 3,
    baja: 4,
};

/**
 * Calcula la prioridad resultante en el cliente, igual que PrioridadOT::calcular().
 * `esSeguridad=true` fuerza `critica`, sea cual sea la categoría.
 * Usa el mapeo que sirve el backend en /api/ot/catalogos; el objeto local es fallback.
 */
export function calcularPrioridadCliente(categoria, esSeguridad, categorias) {
    if (esSeguridad || categoria === 'seguridad') return 'critica';

    const delCatalogo = Array.isArray(categorias)
        ? categorias.find((c) => c.value === categoria)?.prioridad
        : null;

    return delCatalogo || CATEGORIA_A_PRIORIDAD[categoria] || 'media';
}

/** Busca el objeto {value,label,color,orden} de una prioridad dentro del catálogo del backend. */
export function getPrioridadInfo(prioridades, valor) {
    if (!Array.isArray(prioridades)) return null;
    return prioridades.find((p) => p.value === valor) || null;
}

/** Ordena una lista de OTs por prioridad_orden ASC y luego created_at DESC (orden por defecto de §7.1). */
export function ordenarPorPrioridad(lista) {
    if (!Array.isArray(lista)) return [];
    return [...lista].sort((a, b) => {
        const ordenA = a.prioridad_orden ?? PRIORIDAD_ORDEN_FALLBACK[a.prioridad] ?? 99;
        const ordenB = b.prioridad_orden ?? PRIORIDAD_ORDEN_FALLBACK[b.prioridad] ?? 99;
        if (ordenA !== ordenB) return ordenA - ordenB;
        return new Date(b.created_at) - new Date(a.created_at);
    });
}

// Semáforo de SLA (§2): color + texto por estado.
export const SLA_ESTADO_UI = {
    vencida: { color: '#dc2626', label: 'Vencida' },
    incumplida: { color: '#dc2626', label: 'Respondida fuera de SLA' },
    por_vencer: { color: '#d97706', label: 'Por vencer' },
    en_tiempo: { color: '#16a34a', label: 'En tiempo' },
    sin_sla: { color: '#94a3b8', label: 'Sin SLA' },
};

export function getSlaEstadoUi(slaEstado) {
    return SLA_ESTADO_UI[slaEstado] || SLA_ESTADO_UI.sin_sla;
}
