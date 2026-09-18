// Helpers puros del módulo Open Issues: labels/colores de estado y prioridad,
// agrupado de usuarios por departamento y formateo de fechas/archivos. La
// fuente de verdad de labels/colores es SIEMPRE el catálogo del backend
// (GET /api/open-issues/catalogos); este archivo solo aporta un fallback
// local por si el catálogo todavía no cargó, para no dejar la UI en blanco
// (misma doctrina que Utils/hhee.js).
import moment from 'moment';

// Espejo de App\Support\OpenIssueEstados::ESTADOS_LABELS (fallback si el
// catálogo del backend no llegó a tiempo). NO se usa como fuente de verdad primaria.
export const ESTADOS_OI_FALLBACK = {
    abierto: { label: 'Abierto', color: 'gold' },
    en_progreso: { label: 'En progreso', color: 'blue' },
    cerrado: { label: 'Cerrado', color: 'green' },
};

// Espejo de App\Support\OpenIssueEstados::PRIORIDADES_LABELS (fallback).
export const PRIORIDADES_OI_FALLBACK = {
    baja: { label: 'Baja', color: 'default' },
    media: { label: 'Media', color: 'blue' },
    alta: { label: 'Alta', color: 'orange' },
};

/** Info de UI (label + color de Tag AntD) para un estado, priorizando el catálogo del backend. */
export function getEstadoOpenIssueInfo(catalogoEstados = [], estado) {
    const delCatalogo = (catalogoEstados || []).find((e) => e.value === estado);
    if (delCatalogo) {
        return { label: delCatalogo.label, color: delCatalogo.color };
    }
    return ESTADOS_OI_FALLBACK[estado] || { label: estado || '—', color: 'default' };
}

/** Info de UI (label + color de Tag AntD) para una prioridad, priorizando el catálogo del backend. */
export function getPrioridadOpenIssueInfo(catalogoPrioridades = [], prioridad) {
    const delCatalogo = (catalogoPrioridades || []).find((p) => p.value === prioridad);
    if (delCatalogo) {
        return { label: delCatalogo.label, color: delCatalogo.color };
    }
    return PRIORIDADES_OI_FALLBACK[prioridad] || { label: prioridad || '—', color: 'default' };
}

// Color del punto del Timeline de AntD según el tipo de actualización.
export const COLOR_TIMELINE_POR_TIPO = {
    apertura: 'gray',
    comentario: 'blue',
    cambio_estado: 'gold',
    cierre: 'green',
    reapertura: 'orange',
    involucrado_agregado: 'gray',
    involucrado_quitado: 'gray',
    edicion: 'gray',
};

/** Color del punto del Timeline de AntD para un tipo de actualización dado. */
export function getColorTimeline(tipo) {
    return COLOR_TIMELINE_POR_TIPO[tipo] || 'gray';
}

/**
 * Opciones agrupadas por departamento para el <Select> de personas:
 * [{ label: 'Calidad', options: [{ label: 'Luis Gómez', value: 12 }] }]
 * Los usuarios sin departamento reconocido quedan en un grupo "Sin departamento".
 */
export function agruparUsuariosPorDepartamento(usuarios = [], departamentos = []) {
    const nombrePorDepto = new Map(departamentos.map((d) => [d.id, d.nombre]));
    const grupos = new Map();

    usuarios.forEach((u) => {
        const nombreGrupo = nombrePorDepto.get(u.departamento_id) || 'Sin departamento';
        if (!grupos.has(nombreGrupo)) grupos.set(nombreGrupo, []);
        grupos.get(nombreGrupo).push({ label: u.name, value: u.id });
    });

    return Array.from(grupos.entries())
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([label, options]) => ({ label, options }));
}

// Re-export para NO duplicar la función (ya existe y está probada en el módulo HHEE).
export { iniciales } from './hhee';

/** Formatea una fecha ISO como "DD/MM/YYYY HH:mm", o '—' si no hay valor. */
export function formatearFechaOI(valor) {
    return valor ? moment(valor).format('DD/MM/YYYY HH:mm') : '—';
}

/** True si el mime_type corresponde a una imagen (para previsualizar el adjunto). */
export function esImagenOI(mimeType) {
    return /^image\//.test(mimeType || '');
}
