// Wrappers de los endpoints de /api/hhee/* (módulo Horas Extras). Reusa el
// mismo apiFetch/buildQuery de Órdenes de Trabajo (ver Utils/otApi.js): no se
// duplica el manejo de token/401/403, ni se introduce ninguna librería nueva.
import { apiFetch, buildQuery } from './otApi';

// GET /api/hhee/catalogos -> { estados, roles_labels, tipos_hora, mis_niveles,
// es_contingencia, max_horas_por_empleado, departamentos }
export function fetchCatalogosHhee() {
    return apiFetch('hhee/catalogos');
}

// GET /api/hhee/pendientes -> { total, solicitudes } (para el badge de la campana/nav y la tab "Pendientes de mi firma").
// El polling del Header solo necesita el número: se pide con soloTotal=true para
// mandar `?solo_total=1` (hoy SolicitudHheeController::pendientes() todavía no lo
// lee y devuelve siempre las filas completas; el parámetro queda ignorado sin
// romper nada, listo para que backend-laravel lo optimice cuando lo agregue). El
// front NO asume el shape de la respuesta: components/Header.jsx acepta tanto
// {total} solo como {total, solicitudes} completo.
export function fetchPendientesHhee(soloTotal = false) {
    return apiFetch(`hhee/pendientes${soloTotal ? '?solo_total=1' : ''}`);
}

// GET /api/hhee/solicitudes (paginado estilo Laravel). Filtros soportados:
// estado[], fecha_desde, fecha_hasta, departamento_id, solicitante_id, solo_pendientes_mias, per_page, page
export function fetchSolicitudesHhee(params) {
    const qs = buildQuery(params);
    return apiFetch(`hhee/solicitudes${qs ? `?${qs}` : ''}`);
}

// GET /api/hhee/solicitudes/{id} -> detalle + flags de autorización
export function fetchSolicitudHhee(id) {
    return apiFetch(`hhee/solicitudes/${id}`);
}

// POST /api/hhee/solicitudes (payload.enviar=true para crear y enviar en un solo paso)
export function crearSolicitudHhee(payload) {
    return apiFetch('hhee/solicitudes', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

// PUT /api/hhee/solicitudes/{id} (solo borradores propios, ver flags.puede_editar)
export function actualizarSolicitudHhee(id, payload) {
    return apiFetch(`hhee/solicitudes/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

// DELETE /api/hhee/solicitudes/{id} (solo borradores propios)
export function eliminarSolicitudHhee(id) {
    return apiFetch(`hhee/solicitudes/${id}`, { method: 'DELETE' });
}

// POST /api/hhee/solicitudes/{id}/enviar -> borrador -> pendiente_nivel1
export function enviarSolicitudHhee(id) {
    return apiFetch(`hhee/solicitudes/${id}/enviar`, { method: 'POST' });
}

// POST /api/hhee/solicitudes/{id}/aprobar { comentario? }
export function aprobarSolicitudHhee(id, payload = {}) {
    return apiFetch(`hhee/solicitudes/${id}/aprobar`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

// POST /api/hhee/solicitudes/{id}/rechazar { motivo }
export function rechazarSolicitudHhee(id, payload) {
    return apiFetch(`hhee/solicitudes/${id}/rechazar`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

// POST /api/hhee/solicitudes/{id}/anular
export function anularSolicitudHhee(id) {
    return apiFetch(`hhee/solicitudes/${id}/anular`, { method: 'POST' });
}

// POST /api/hhee/solicitudes/{id}/horas-reales { detalles: [{detalle_id, hs_reales_50, hs_reales_100, hs_reales_50n, hs_reales_100n, fecha_realizacion}] }
export function cargarHorasRealesHhee(id, payload) {
    return apiFetch(`hhee/solicitudes/${id}/horas-reales`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}
