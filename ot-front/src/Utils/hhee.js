// Helpers puros del módulo Horas Extras (HHEE): labels/colores de estado y
// cálculo en vivo de horas para el preview del formulario. La fuente de
// verdad de labels/colores es SIEMPRE el catálogo del backend
// (GET /api/hhee/catalogos -> estados); este archivo solo aporta un fallback
// local por si el catálogo todavía no cargó, para no dejar la UI en blanco.
import moment from 'moment';

// Espejo de App\Support\HheeEstados::ESTADOS_LABELS (fallback si el catálogo
// del backend no llegó a tiempo). NO se usa como fuente de verdad primaria.
export const ESTADOS_HHEE_FALLBACK = {
    borrador: { label: 'Borrador', color: 'default' },
    pendiente_nivel1: { label: 'Pendiente nivel 1', color: 'gold' },
    pendiente_final: { label: 'Pendiente aprobación final', color: 'orange' },
    aprobada: { label: 'Aprobada', color: 'green' },
    cerrada: { label: 'Cerrada', color: 'blue' },
    rechazada: { label: 'Rechazada', color: 'red' },
    anulada: { label: 'Anulada', color: 'default' },
};

/**
 * Info de UI (label + color de Tag AntD) para un estado, priorizando el
 * catálogo servido por el backend y cayendo al fallback local si no está.
 */
export function getEstadoHheeInfo(catalogoEstados = [], estado) {
    const delCatalogo = (catalogoEstados || []).find((e) => e.value === estado);
    if (delCatalogo) {
        return { label: delCatalogo.label, color: delCatalogo.color };
    }
    return ESTADOS_HHEE_FALLBACK[estado] || { label: estado || '—', color: 'default' };
}

/**
 * Horas entre dos horarios "HH:mm" (preview en vivo mientras se carga el
 * formulario). El número que vale de verdad siempre lo recalcula el backend
 * al guardar; esto es solo para guiar al usuario.
 *
 * Ya no hay checkbox "cruza medianoche": espeja EXACTAMENTE a
 * App\Support\HheeFlujo::calcularHoras() en su versión simplificada — si
 * `hasta` es MENOR O IGUAL que `desde` se asume automáticamente que el turno
 * cruza la medianoche (se le suma un día); si `hasta` es exactamente igual a
 * `desde` el rango es inválido (0 hs no tiene sentido), devuelve null.
 */
export function calcularHoras(horaDesde, horaHasta) {
    if (!horaDesde || !horaHasta) return null;

    const desde = moment(horaDesde, 'HH:mm', true);
    const hasta = moment(horaHasta, 'HH:mm', true);
    if (!desde.isValid() || !hasta.isValid()) return null;

    if (hasta.isSame(desde)) return null;

    const hastaAjustada = hasta.clone();
    if (hastaAjustada.isSameOrBefore(desde)) {
        hastaAjustada.add(1, 'day');
    }

    const minutos = hastaAjustada.diff(desde, 'minutes');
    if (minutos <= 0) return null;

    return Math.round((minutos / 60) * 100) / 100;
}

/**
 * True si el rango horario "HH:mm" cruza la medianoche (hasta <= desde),
 * para mostrar la aclaración "(cruza medianoche)" al lado de las horas
 * calculadas. Mismo criterio que calcularHoras(), sin el caso hasta==desde
 * (ese ya es inválido, no hace falta aclarar nada).
 */
export function cruzaMedianocheHhee(horaDesde, horaHasta) {
    if (!horaDesde || !horaHasta) return false;

    const desde = moment(horaDesde, 'HH:mm', true);
    const hasta = moment(horaHasta, 'HH:mm', true);
    if (!desde.isValid() || !hasta.isValid()) return false;

    return hasta.isSameOrBefore(desde) && !hasta.isSame(desde);
}

/** Formatea un número de horas con hasta 2 decimales, sin ceros de más (2 -> "2", 2.5 -> "2.5"). */
export function formatearHoras(valor) {
    const numero = Number(valor) || 0;
    return Number.isInteger(numero) ? String(numero) : numero.toFixed(2).replace(/0$/, '');
}

/** Normaliza "HH:mm:ss" (tal como lo devuelve el backend) a "HH:mm" para los TimePicker. */
export function horaCorta(hora) {
    if (!hora) return null;
    return hora.slice(0, 5);
}

/**
 * Iniciales de un nombre para los avatares del timeline de firmas (ej.
 * "Martín La Forgia" -> "ML"). Devuelve "?" si no hay nombre.
 */
export function iniciales(nombre) {
    if (!nombre || !nombre.trim()) return '?';
    const partes = nombre.trim().split(/\s+/).filter(Boolean);
    const primera = partes[0]?.[0] || '';
    const segunda = partes.length > 1 ? partes[partes.length - 1][0] : '';
    return `${primera}${segunda}`.toUpperCase();
}

// El departamento_id ya no se elige a mano: el backend lo resuelve del usuario
// logueado. Lo que sí carga el solicitante es el "Sector" del turno (valores
// fijos del formulario FO-008-RRH). Fallback local por si catalogos.sectores
// todavía no vino del backend (módulo en desarrollo en paralelo).
export const SECTORES_HHEE_FALLBACK = ['Corte', 'Costura', 'Mantenimiento', 'PC'];

/** Opciones de sector: catálogo del backend (catalogos.sectores) si ya está, si no el fallback fijo de arriba. */
export function getSectoresHhee(catalogos) {
    return catalogos?.sectores?.length ? catalogos.sectores : SECTORES_HHEE_FALLBACK;
}

/**
 * Sector a mostrar en tablas/detalle: 'sector' si la solicitud ya lo trae
 * (flujo nuevo), si no el nombre del departamento (solicitudes viejas,
 * cargadas antes de este cambio, que todavía tienen departamento_id/relación
 * cargada), si no "—".
 */
export function sectorOFallback(solicitud) {
    return solicitud?.sector || solicitud?.departamento?.nombre || '—';
}
