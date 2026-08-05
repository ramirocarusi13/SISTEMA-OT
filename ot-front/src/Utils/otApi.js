// Helpers de acceso a la API compartidos por los componentes de prioridad/reportes
// (ModalCrearOrdenTrabajo, OrdenTrabajoList, OrdenTrabajoFilter, Reportes).
// Replica el patrón ya usado en el resto del proyecto: fetch nativo + token de
// localStorage vía UserAsyncStorage + APIURI de la variable de entorno VITE_API
// (con barra final, ver .env). No se introduce axios ni ninguna librería nueva.
import { getItem } from '../storage/UserAsyncStorage';

const APIURI = import.meta.env.VITE_API;

/**
 * Fetch autenticado genérico. Devuelve { ok, status, data } en vez de tirar
 * excepciones para que cada pantalla decida cómo mostrar loading/error/éxito.
 */
async function apiFetch(path, options = {}) {
    const token = await getItem();

    let response;
    try {
        response = await fetch(`${APIURI}${path}`, {
            ...options,
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...options.headers,
            },
        });
    } catch (error) {
        // Error de red (servidor caído, sin conexión, etc.)
        return { ok: false, status: 0, data: null, error: 'No se pudo conectar al servidor. Verificá tu conexión e intentá nuevamente.' };
    }

    let data = null;
    try {
        data = await response.json();
    } catch (error) {
        data = null;
    }

    if (!response.ok) {
        const mensaje = data?.error || data?.message || (data?.errors ? Object.values(data.errors).flat().join(' ') : null)
            || 'Ocurrió un error inesperado. Intentá nuevamente.';
        return { ok: false, status: response.status, data, error: mensaje };
    }

    return { ok: true, status: response.status, data, error: null };
}

/** Arma un querystring ignorando valores vacíos/undefined/null y arrays vacíos. */
function buildQuery(params = {}) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        if (Array.isArray(value)) {
            if (value.length === 0) return;
            value.forEach((item) => query.append(`${key}[]`, item));
            return;
        }
        query.append(key, value);
    });
    return query.toString();
}

// GET /api/ot/catalogos -> { categorias, prioridades, sla_horas }
export function fetchCatalogosOT() {
    return apiFetch('ot/catalogos');
}

// GET /api/departamentos -> [{id, nombre}]
export function fetchDepartamentosApi() {
    return apiFetch('departamentos');
}

// GET /api/reportes/resumen
export function fetchReporteResumen(params) {
    const qs = buildQuery(params);
    return apiFetch(`reportes/resumen${qs ? `?${qs}` : ''}`);
}

// GET /api/reportes/departamentos
export function fetchReporteDepartamentos(params) {
    const qs = buildQuery(params);
    return apiFetch(`reportes/departamentos${qs ? `?${qs}` : ''}`);
}

// GET /api/reportes/mantenimiento (403 si el rol es analista)
export function fetchReporteMantenimiento(params) {
    const qs = buildQuery(params);
    return apiFetch(`reportes/mantenimiento${qs ? `?${qs}` : ''}`);
}

// GET /api/reportes/tendencia?agrupar_por=semana|mes
export function fetchReporteTendencia(params) {
    const qs = buildQuery(params);
    return apiFetch(`reportes/tendencia${qs ? `?${qs}` : ''}`);
}

// GET /api/ordenes-trabajo (con filtros opcionales: departamento_id, prioridad[], categoria[], solo_vencidas, etc.)
export function fetchOrdenesTrabajo(params) {
    const qs = buildQuery(params);
    return apiFetch(`ordenes-trabajo${qs ? `?${qs}` : ''}`);
}

// PUT /api/ordenes-trabajo/{id}/prioridad
export function actualizarPrioridadOT(ordenId, payload) {
    return apiFetch(`ordenes-trabajo/${ordenId}/prioridad`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

export { buildQuery };
