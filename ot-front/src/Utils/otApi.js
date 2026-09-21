// Helpers de acceso a la API compartidos por los componentes de prioridad/reportes
// (ModalCrearOrdenTrabajo, OrdenTrabajoList, OrdenTrabajoFilter, Reportes).
// Replica el patrón ya usado en el resto del proyecto: fetch nativo + token de
// localStorage vía UserAsyncStorage + APIURI de la variable de entorno VITE_API
// (con barra final, ver .env). No se introduce axios ni ninguna librería nueva.
import { getItem } from '../storage/UserAsyncStorage';
import { cerrarSesionYIrAlLogin } from './sesion';

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
                // Con FormData el browser tiene que poner él el
                // `Content-Type: multipart/form-data; boundary=...`; si se lo
                // forzamos a `application/json`, Laravel no parsea el archivo.
                // Ningún caller existente pasa FormData, así que el
                // comportamiento de OT y HHEE no cambia.
                ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
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
        // 401: el token venció o dejó de ser válido estando ya adentro de la app
        // (RutaProtegida lo verifica al entrar, pero puede vencer después).
        if (response.status === 401) {
            // Mismo camino único que usa el interceptor global (Utils/sesion.js):
            // limpia la sesión y va al login recordando la ruta actual para
            // volver después de loguearse. Es idempotente.
            cerrarSesionYIrAlLogin();
            return { ok: false, status: 401, data, error: 'Tu sesión expiró. Volvé a iniciar sesión.' };
        }

        // 403: el usuario está autenticado pero no tiene permiso para esta acción
        // (ej. una OT de otro departamento en modo consulta). Mensaje genérico y
        // entendible en vez de propagar el texto crudo del backend.
        const mensaje = response.status === 403
            ? 'No tenés permisos para realizar esta acción.'
            : data?.error || data?.message || (data?.errors ? Object.values(data.errors).flat().join(' ') : null)
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

// GET /api/ordenes-trabajo (con filtros opcionales: departamento_id, estado[], prioridad[], categoria[], usuario_mantenimiento_id, solo_vencidas, etc.)
export function fetchOrdenesTrabajo(params) {
    const qs = buildQuery(params);
    return apiFetch(`ordenes-trabajo${qs ? `?${qs}` : ''}`);
}

// GET /api/usuarios-mantenimiento -> usuarios del departamento de mantenimiento (incluye group_leader y gerente)
export function fetchUsuariosMantenimiento() {
    return apiFetch('usuarios-mantenimiento');
}

// GET /api/ordenes-trabajo/{id}/mensajes -> hilo de mensajes de la OT (solo lectura).
// Puede devolver 403 (OT de otro departamento) o 404 (no existe); no dispara el
// endpoint de "visto" a propósito: este helper es para paneles de consulta.
export function fetchMensajesOT(ordenId) {
    return apiFetch(`ordenes-trabajo/${ordenId}/mensajes`);
}

// GET /api/ordenes-trabajo/{id}/descripciones -> descripciones de avance de la OT
// (mismo endpoint que usa components/ModalDescripcion.jsx). El backend está
// agregando un chequeo de alcance (puedeVer): puede devolver 403 si el usuario
// no tiene acceso a esa OT puntual; apiFetch ya lo mapea a un mensaje legible.
export function fetchDescripcionesOrden(ordenId) {
    return apiFetch(`ordenes-trabajo/${ordenId}/descripciones`);
}

// PUT /api/ordenes-trabajo/{id}/prioridad
export function actualizarPrioridadOT(ordenId, payload) {
    return apiFetch(`ordenes-trabajo/${ordenId}/prioridad`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

export { apiFetch, buildQuery };
