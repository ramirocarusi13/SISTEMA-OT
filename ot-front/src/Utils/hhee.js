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
export const SECTORES_HHEE_FALLBACK = ['Administración', 'Calidad', 'Corte', 'Costura', 'Mantenimiento', 'PC', 'Staff'];

/** Opciones de sector: catálogo del backend (catalogos.sectores) si ya está, si no el fallback fijo de arriba. */
export function getSectoresHhee(catalogos) {
    return catalogos?.sectores?.length ? catalogos.sectores : SECTORES_HHEE_FALLBACK;
}

// Espejo de config('hhee.motivos') del backend (fallback si el catálogo no llegó).
export const MOTIVOS_HHEE_FALLBACK = ['Nuevo proyecto', 'Mantenimiento', 'Reparaciones', 'Stock / volumen'];

/** Opciones del desplegable de motivo: catalogos.motivos si ya está, si no el fallback. */
export function getMotivosHhee(catalogos) {
    return catalogos?.motivos?.length ? catalogos.motivos : MOTIVOS_HHEE_FALLBACK;
}

// Días de la semana para la planificación por rango (ISO: 1 = lunes ... 7 = domingo,
// igual que dias_semana del backend).
export const DIAS_SEMANA_HHEE = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 7, label: 'Dom' },
];

/**
 * Fechas (moment) del rango [desde, hasta] que caen en los días de la semana
 * elegidos. Espejo de App\Support\HheeFlujo::fechasPlanificadas() solo para
 * mostrar la vista previa; las fechas que valen las calcula el backend.
 * Se corta en `tope + 1` para no iterar de más con rangos enormes.
 */
export function fechasPlanificadasHhee(desde, hasta, diasSemana, tope = 62) {
    if (!desde || !hasta || !diasSemana?.length) return [];
    // El RangePicker de antd 5 devuelve dayjs (inmutable); se pasa todo a
    // moment por el string para iterar igual venga lo que venga.
    const dia = moment(desde.format('YYYY-MM-DD'), 'YYYY-MM-DD');
    const fin = moment(hasta.format('YYYY-MM-DD'), 'YYYY-MM-DD');
    const fechas = [];
    while (!dia.isAfter(fin, 'day') && fechas.length <= tope) {
        if (diasSemana.includes(dia.isoWeekday())) fechas.push(dia.clone());
        dia.add(1, 'day');
    }
    return fechas;
}

/** "Lun 03/11" (sin depender del locale de moment). */
export function fechaCortaConDia(fecha) {
    const m = moment(fecha);
    return `${DIAS_SEMANA_HHEE[m.isoWeekday() - 1].label} ${m.format('DD/MM')}`;
}

/**
 * Normaliza un texto para comparar sin mayúsculas ni acentos
 * ("Administración" == "administracion").
 */
export function normalizarTexto(texto) {
    return (texto || '')
        .toString()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
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
