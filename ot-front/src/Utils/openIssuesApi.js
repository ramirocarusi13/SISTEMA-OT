// Wrappers de los endpoints de /api/open-issues/* (módulo Open Issues). Reusa
// el mismo apiFetch/buildQuery de Órdenes de Trabajo (ver Utils/otApi.js): no
// se duplica el manejo de token/401/403, ni se introduce ninguna librería
// nueva (nada de axios).
import { apiFetch, buildQuery } from './otApi';

// GET /api/open-issues/catalogos -> { estados, prioridades,
// tipos_actualizacion, departamentos, usuarios, prioridad_default, adjunto, flags }
export function fetchCatalogosOpenIssues() {
    return apiFetch('open-issues/catalogos');
}

// GET /api/open-issues/pendientes -> { total } o { total, issues } (para el
// badge del Header y la tab "Donde participo"). El Header pide soloTotal=true
// y tolera ambos shapes (mismo criterio que Utils/hheeApi.js::fetchPendientesHhee).
export function fetchPendientesOpenIssues(soloTotal = false) {
    return apiFetch(`open-issues/pendientes${soloTotal ? '?solo_total=1' : ''}`);
}

// GET /api/open-issues (paginado estilo Laravel). Filtros soportados: estado[]
// (array de strings), prioridad, departamento_destino_id, creador_id,
// involucrado_id, mios, participo, texto, fecha_desde, fecha_hasta, per_page, page.
export function fetchOpenIssues(params) {
    const qs = buildQuery(params);
    return apiFetch(`open-issues${qs ? `?${qs}` : ''}`);
}

// GET /api/open-issues/{id} -> detalle + flags de autorización
export function fetchOpenIssue(id) {
    return apiFetch(`open-issues/${id}`);
}

// POST /api/open-issues (siempre FormData: puede traer un adjunto inicial)
export function crearOpenIssue(formData) {
    return apiFetch('open-issues', {
        method: 'POST',
        body: formData,
    });
}

// PUT /api/open-issues/{id} (JSON: la edición no acepta archivo, PUT +
// multipart exigiría method spoofing)
export function actualizarOpenIssue(id, payload) {
    return apiFetch(`open-issues/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

// POST /api/open-issues/{id}/actualizaciones (siempre FormData: puede traer adjunto)
export function agregarActualizacionOpenIssue(id, formData) {
    return apiFetch(`open-issues/${id}/actualizaciones`, {
        method: 'POST',
        body: formData,
    });
}

// POST /api/open-issues/{id}/cerrar { texto? }
export function cerrarOpenIssue(id, payload = {}) {
    return apiFetch(`open-issues/${id}/cerrar`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

// POST /api/open-issues/{id}/reabrir { texto? }
export function reabrirOpenIssue(id, payload = {}) {
    return apiFetch(`open-issues/${id}/reabrir`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

// POST /api/open-issues/{id}/involucrados { user_ids?, departamento_ids? }
export function agregarInvolucradosOpenIssue(id, payload) {
    return apiFetch(`open-issues/${id}/involucrados`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

// DELETE /api/open-issues/{id}/involucrados/{userId}
export function quitarInvolucradoOpenIssue(id, userId) {
    return apiFetch(`open-issues/${id}/involucrados/${userId}`, { method: 'DELETE' });
}

/**
 * Arma el FormData de POST /open-issues y de POST /open-issues/{id}/actualizaciones:
 * ignora null/undefined/'' y expande los arrays como `clave[]` (mismo criterio
 * que buildQuery de otApi.js), para no repetir este armado en los dos modales.
 */
export function buildOpenIssueFormData(campos = {}, archivo = null) {
    const formData = new FormData();

    Object.entries(campos).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        if (Array.isArray(value)) {
            if (value.length === 0) return;
            value.forEach((item) => formData.append(`${key}[]`, item));
            return;
        }
        formData.append(key, value);
    });

    if (archivo) {
        formData.append('archivo', archivo);
    }

    return formData;
}
