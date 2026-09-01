// Página de Reportes/KPIs de Órdenes de Trabajo (SPEC-prioridad-reportes.md §7.2).
// Dashboard de presentación: layout propio (no la cáscara app-shell/ot-list del
// listado), pensado para proyectar. Sin librerías de gráficos nuevas: las barras
// y el donut se resuelven con divs/Tailwind y SVG inline, y las tablas/KPIs con
// componentes de AntD.
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Badge, Button, DatePicker, Drawer, Empty, Select, Skeleton, Table, Tag, Tooltip } from 'antd';
import { ReloadOutlined, BarChartOutlined, MessageOutlined, FileTextOutlined } from '@ant-design/icons';
import { Link } from 'react-router-dom';
import { FaArrowLeft } from 'react-icons/fa';
import moment from 'moment';
import {
    fetchDepartamentosApi,
    fetchMensajesOT,
    fetchOrdenesTrabajo,
    fetchReporteDepartamentos,
    fetchReporteMantenimiento,
    fetchReporteResumen,
    fetchReporteTendencia,
    fetchUsuariosMantenimiento,
} from '../Utils/otApi';
import { getPrioridadInfo, getSlaEstadoUi, ordenarPorPrioridad } from '../Utils/prioridad';
import DetalleOrdenDrawer from '../components/reportes/DetalleOrdenDrawer';

const { RangePicker } = DatePicker;

const ESTADOS_ORDEN = ['creada', 'aprobada', 'asignada', 'en_proceso', 'finalizada'];
const ESTADOS_LABEL = {
    creada: 'Creada',
    aprobada: 'Aprobada',
    asignada: 'Asignada',
    en_proceso: 'En proceso',
    finalizada: 'Finalizada',
};
const PRIORIDADES_ORDEN = ['critica', 'alta', 'media', 'baja'];

const formatHoras = (valor) => (valor === null || valor === undefined ? '—' : `${valor} h`);
const formatPct = (valor) => (valor === null || valor === undefined ? '—' : `${valor}%`);

/** Umbral de color para el KPI de cumplimiento de SLA. */
const slaVariant = (pct) => {
    if (pct === null || pct === undefined) return 'neutral';
    if (pct >= 90) return 'success';
    if (pct >= 70) return 'warning';
    return 'danger';
};

const RANGO_PRESETS = [
    { label: 'Últimos 7 días', value: [moment().subtract(6, 'days'), moment()] },
    { label: 'Últimos 30 días', value: [moment().subtract(29, 'days'), moment()] },
    { label: 'Últimos 90 días', value: [moment().subtract(89, 'days'), moment()] },
    { label: 'Este mes', value: [moment().startOf('month'), moment()] },
    { label: 'Último año', value: [moment().subtract(1, 'year'), moment()] },
];

// ---------------------------------------------------------------------------
// Sub-componentes de estado (loading/error/vacío) y de gráficos, todos locales
// a esta pantalla porque solo se usan acá.
// ---------------------------------------------------------------------------

const EstadoAsync = ({ loading, error, isEmpty, onRetry, skeletonRows = 3, emptyDescription = 'No hay datos para el rango seleccionado.', children }) => {
    if (loading) return <Skeleton active paragraph={{ rows: skeletonRows }} />;
    if (error) {
        return (
            <Alert
                type="error"
                showIcon
                message="No se pudo cargar la información"
                description={error}
                action={
                    <Button size="small" danger onClick={onRetry} icon={<ReloadOutlined />}>
                        Reintentar
                    </Button>
                }
            />
        );
    }
    if (isEmpty) return <Empty description={emptyDescription} />;
    return children;
};

const KpiCard = ({ label, value, hint, variant = 'neutral' }) => (
    <div className={`dash-kpi-card dash-kpi-card--${variant}`}>
        <span className="dash-kpi-card__label">{label}</span>
        <strong className="dash-kpi-card__value">{value}</strong>
        {hint && <small className="dash-kpi-card__hint">{hint}</small>}
    </div>
);

/** Envoltorio visual consistente para cada panel del tablero (gráfico o tabla). */
const DashPanel = ({ title, extra, children, className = '', style }) => (
    <div className={`dash-panel ${className}`} style={style}>
        <div className="dash-panel__header">
            <h3 className="dash-panel__title">{title}</h3>
            {extra}
        </div>
        <div className="dash-panel__body">{children}</div>
    </div>
);

/** Barras horizontales de cantidad de OTs por estado. */
const BarraEstados = ({ porEstado }) => {
    const total = Math.max(...ESTADOS_ORDEN.map((e) => porEstado?.[e] || 0), 1);
    return (
        <div className="reportes-barras">
            {ESTADOS_ORDEN.map((estado) => {
                const cantidad = porEstado?.[estado] || 0;
                const pct = Math.round((cantidad / total) * 100);
                return (
                    <div className="reportes-barra-row" key={estado}>
                        <span className="reportes-barra-label">{ESTADOS_LABEL[estado]}</span>
                        <div className="reportes-barra-track">
                            <div
                                className={`reportes-barra-fill reportes-barra-fill--${estado}`}
                                style={{ width: `${cantidad === 0 ? 0 : Math.max(pct, 4)}%` }}
                            />
                        </div>
                        <span className="reportes-barra-valor">{cantidad}</span>
                    </div>
                );
            })}
        </div>
    );
};

/** Dona de distribución por prioridad, dibujada con <circle> apilados (SVG inline, sin libs). */
const DonutPrioridad = ({ porPrioridad, coloresPorPrioridad, labelsPorPrioridad }) => {
    const total = PRIORIDADES_ORDEN.reduce((acc, p) => acc + (porPrioridad?.[p] || 0), 0);
    const radio = 52;
    const circunferencia = 2 * Math.PI * radio;
    let acumulado = 0;

    if (total === 0) {
        return <Empty description="Sin órdenes en el rango seleccionado." />;
    }

    return (
        <div className="reportes-donut-wrap">
            <svg viewBox="0 0 130 130" width="150" height="150" role="img" aria-label="Distribución de OTs por prioridad">
                <circle cx="65" cy="65" r={radio} fill="none" stroke="#eef2f7" strokeWidth="18" />
                {PRIORIDADES_ORDEN.map((prioridad) => {
                    const cantidad = porPrioridad?.[prioridad] || 0;
                    if (cantidad === 0) return null;
                    const fraccion = cantidad / total;
                    const largo = fraccion * circunferencia;
                    const offset = circunferencia - acumulado;
                    acumulado += largo;
                    const color = coloresPorPrioridad?.[prioridad]?.hex || '#8c8c8c';
                    return (
                        <circle
                            key={prioridad}
                            cx="65"
                            cy="65"
                            r={radio}
                            fill="none"
                            stroke={color}
                            strokeWidth="18"
                            strokeDasharray={`${largo} ${circunferencia - largo}`}
                            strokeDashoffset={offset}
                            transform="rotate(-90 65 65)"
                        />
                    );
                })}
                <text x="65" y="60" textAnchor="middle" className="reportes-donut-total">{total}</text>
                <text x="65" y="78" textAnchor="middle" className="reportes-donut-total-label">OTs</text>
            </svg>
            <ul className="reportes-donut-legend">
                {PRIORIDADES_ORDEN.map((prioridad) => (
                    <li key={prioridad}>
                        <span className="reportes-donut-dot" style={{ backgroundColor: coloresPorPrioridad?.[prioridad]?.hex || '#8c8c8c' }} />
                        {labelsPorPrioridad?.[prioridad] || prioridad}: <strong>{porPrioridad?.[prioridad] || 0}</strong>
                    </li>
                ))}
            </ul>
        </div>
    );
};

/** Barras agrupadas (creadas vs finalizadas) por período, con tooltip del tiempo de respuesta prom. */
const GraficoTendencia = ({ tendencia }) => {
    if (!Array.isArray(tendencia) || tendencia.length === 0) {
        return <Empty description="No hay datos de tendencia para el rango seleccionado." />;
    }

    const maxValor = Math.max(...tendencia.map((p) => Math.max(p.creadas || 0, p.finalizadas || 0)), 1);

    return (
        <div className="reportes-tendencia">
            {tendencia.map((punto) => (
                <div className="reportes-tendencia-col" key={punto.periodo}>
                    <Tooltip
                        title={`Creadas: ${punto.creadas} · Finalizadas: ${punto.finalizadas} · Resp. prom: ${formatHoras(punto.tiempo_respuesta_prom)}`}
                    >
                        <div className="reportes-tendencia-bars">
                            <div
                                className="reportes-tendencia-bar reportes-tendencia-bar--creadas"
                                style={{ height: `${Math.max((punto.creadas / maxValor) * 100, punto.creadas ? 4 : 0)}%` }}
                            />
                            <div
                                className="reportes-tendencia-bar reportes-tendencia-bar--finalizadas"
                                style={{ height: `${Math.max((punto.finalizadas / maxValor) * 100, punto.finalizadas ? 4 : 0)}%` }}
                            />
                        </div>
                    </Tooltip>
                    <span className="reportes-tendencia-periodo">{moment(punto.periodo).format('DD/MM')}</span>
                </div>
            ))}
            <div className="reportes-tendencia-legend">
                <span><i className="reportes-tendencia-dot reportes-tendencia-dot--creadas" />Creadas</span>
                <span><i className="reportes-tendencia-dot reportes-tendencia-dot--finalizadas" />Finalizadas</span>
            </div>
        </div>
    );
};

/** Banner de estado vacío honesto: en vez de un tablero lleno de ceros, explica y ofrece un atajo. */
const DashboardVacio = ({ onAmpliarRango }) => (
    <div className="dash-empty-banner">
        <BarChartOutlined className="dash-empty-banner__icon" aria-hidden="true" />
        <h2>No hay OTs creadas en el rango seleccionado</h2>
        <p>Probá ampliar las fechas para ver el panorama completo.</p>
        <Button type="primary" onClick={onAmpliarRango}>Ver último año</Button>
    </div>
);

// ---------------------------------------------------------------------------
// Página principal
// ---------------------------------------------------------------------------

const Reportes = () => {
    const [usuario, setUsuario] = useState(null);
    useEffect(() => {
        const userData = localStorage.getItem('user');
        if (userData) {
            try {
                setUsuario(JSON.parse(userData));
            } catch {
                setUsuario(null);
            }
        }
    }, []);

    const veTodosLosDepartamentos = usuario
        ? (parseInt(usuario.departamento_id) === 2 || usuario.rol === 'admin')
        : false;

    // Gerente de Seguridad e Higiene (SyH): además de su propio departamento
    // ve, en /api/reportes/*, las OT de seguridad de CUALQUIER área (el
    // alcance ya lo resuelve el backend vía AlcanceOrdenes). El flag
    // 'es_seguridad_higiene' lo agrega el backend al user del login; tolerante
    // a que todavía no exista en localStorage (usuarios ya logueados antes del
    // deploy van a necesitar volver a loguearse para que aparezca) — mientras
    // tanto cae a `false` y este bloque queda como el fallback ya sabido.
    const esSeguridadHigiene = !!usuario?.es_seguridad_higiene;

    // "Ve resumen multi-departamento": tanto el rol con alcance global (MTTO/admin)
    // como SyH necesitan el comparativo por departamento y su fetch. La diferencia
    // es que a SyH NO se le muestra el Select de filtro por departamento (más abajo,
    // sigue atado solo a `veTodosLosDepartamentos`): pedir un departamento ajeno
    // explícito le da 403 en el backend, así que para SyH el resumen viaja SIEMPRE
    // sin 'departamento_id' y el backend recorta solo a su alcance.
    const veResumenMultiDepartamento = veTodosLosDepartamentos || esSeguridadHigiene;

    const esAnalista = usuario?.rol === 'analista';

    const [rango, setRango] = useState([moment().subtract(29, 'days'), moment()]);
    const [departamentoId, setDepartamentoId] = useState(null);
    const [departamentos, setDepartamentos] = useState([]);

    // Carga la lista de departamentos solo para quienes pueden ver el comparativo
    // multi-departamento (aunque a SyH no se le muestre el Select de filtro, la
    // lista se usa igual para resolver nombres en la tabla/el scopeLabel).
    useEffect(() => {
        if (!veResumenMultiDepartamento) return;
        (async () => {
            const { ok, data } = await fetchDepartamentosApi();
            if (ok && Array.isArray(data)) setDepartamentos(data);
        })();
    }, [veResumenMultiDepartamento]);

    const params = useMemo(() => ({
        fecha_inicio: rango?.[0] ? rango[0].format('YYYY-MM-DD') : undefined,
        fecha_fin: rango?.[1] ? rango[1].format('YYYY-MM-DD') : undefined,
        departamento_id: departamentoId || undefined,
    }), [rango, departamentoId]);

    // --- Resumen (KPIs + por_estado + por_prioridad) -----------------------
    const [resumen, setResumen] = useState(null);
    const [resumenLoading, setResumenLoading] = useState(false);
    const [resumenError, setResumenError] = useState(null);

    const cargarResumen = useCallback(async () => {
        setResumenLoading(true);
        setResumenError(null);
        const { ok, data, error } = await fetchReporteResumen(params);
        if (ok) {
            setResumen(data);
        } else {
            setResumenError(error);
        }
        setResumenLoading(false);
    }, [params]);

    useEffect(() => { cargarResumen(); }, [cargarResumen]);

    // --- Comparativo por departamento --------------------------------------
    const [deptoRows, setDeptoRows] = useState([]);
    const [deptoLoading, setDeptoLoading] = useState(false);
    const [deptoError, setDeptoError] = useState(null);

    const cargarDeptoRows = useCallback(async () => {
        if (!veResumenMultiDepartamento) return;
        setDeptoLoading(true);
        setDeptoError(null);
        const { ok, data, error } = await fetchReporteDepartamentos(params);
        if (ok) {
            setDeptoRows(data.departamentos || []);
        } else {
            setDeptoError(error);
        }
        setDeptoLoading(false);
    }, [params, veResumenMultiDepartamento]);

    useEffect(() => { cargarDeptoRows(); }, [cargarDeptoRows]);

    // --- OTs (listado filtrable por estado / técnico asignado) -------------
    // Estado por defecto: replica la vista "activas" que tenía el dashboard antes
    // (todo menos finalizada), pero ahora es un multi-select editable por el
    // usuario y el filtrado lo hace el backend (query param estado[]) en vez de
    // bajar TODAS las OTs y filtrar acá.
    const ESTADOS_DEFAULT = useMemo(() => ESTADOS_ORDEN.filter((e) => e !== 'finalizada'), []);
    const [filtroEstados, setFiltroEstados] = useState(ESTADOS_DEFAULT);
    const [filtroTecnicoId, setFiltroTecnicoId] = useState(null);
    const [tecnicosFiltro, setTecnicosFiltro] = useState([]);

    // Lista de técnicos de mantenimiento para el selector de "Técnico asignado"
    // (mismo endpoint y mismo filtro por rol que usa ModalCrearOrdenTrabajo/OrdenTrabajoList).
    useEffect(() => {
        (async () => {
            const { ok, data } = await fetchUsuariosMantenimiento();
            if (ok && Array.isArray(data)) {
                setTecnicosFiltro(data.filter((u) => u.rol === 'group_leader'));
            }
        })();
    }, []);

    const [otsActivas, setOtsActivas] = useState([]);
    const [otsLoading, setOtsLoading] = useState(false);
    const [otsError, setOtsError] = useState(null);

    const cargarOtsActivas = useCallback(async () => {
        setOtsLoading(true);
        setOtsError(null);
        const { ok, data, error } = await fetchOrdenesTrabajo({
            departamento_id: departamentoId || undefined,
            estado: filtroEstados,
            usuario_mantenimiento_id: filtroTecnicoId || undefined,
        });
        if (ok) {
            setOtsActivas(ordenarPorPrioridad(Array.isArray(data) ? data : []));
        } else {
            setOtsError(error);
        }
        setOtsLoading(false);
    }, [departamentoId, filtroEstados, filtroTecnicoId]);

    useEffect(() => { cargarOtsActivas(); }, [cargarOtsActivas]);

    const filtrosTablaSonDefault = filtroTecnicoId === null
        && filtroEstados.length === ESTADOS_DEFAULT.length
        && filtroEstados.every((e) => ESTADOS_DEFAULT.includes(e));

    const restablecerFiltrosTabla = () => {
        setFiltroEstados(ESTADOS_DEFAULT);
        setFiltroTecnicoId(null);
    };

    // --- Drawer de mensajes de una OT (solo lectura, SPEC de este cambio) ---
    // A propósito NO se llama a PUT .../mensajes/visto acá: el reporte es de
    // consulta y no debe alterar el contador de "no leídos" del listado de OTs.
    const [mensajesDrawerOpen, setMensajesDrawerOpen] = useState(false);
    const [mensajesOrden, setMensajesOrden] = useState(null);
    const [mensajes, setMensajes] = useState([]);
    const [mensajesLoading, setMensajesLoading] = useState(false);
    const [mensajesError, setMensajesError] = useState(null);

    const cargarMensajesOrden = useCallback(async (ordenId) => {
        setMensajesLoading(true);
        setMensajesError(null);
        const { ok, data, error } = await fetchMensajesOT(ordenId);
        if (ok) {
            setMensajes(Array.isArray(data) ? data : []);
        } else {
            // El backend puede responder 403 (OT de otro departamento) o 404 (no existe);
            // el mensaje ya viene listo para mostrar (apiFetch lo extrae de data.error).
            setMensajesError(error);
        }
        setMensajesLoading(false);
    }, []);

    const abrirMensajes = (orden) => {
        setMensajesOrden(orden);
        setMensajes([]);
        setMensajesDrawerOpen(true);
        cargarMensajesOrden(orden.id);
    };

    const cerrarMensajes = () => {
        setMensajesDrawerOpen(false);
        setMensajesOrden(null);
        setMensajes([]);
        setMensajesError(null);
    };

    // --- Drawer de detalle de una OT (categoría/prioridad/estado + descripciones
    // de avance con sus adjuntos). La fila ya trae todos los datos de cabecera
    // (fetchOrdenesTrabajo), así que acá solo se guarda la orden seleccionada;
    // DetalleOrdenDrawer hace su propio fetch de las descripciones al abrirse.
    const [detalleDrawerOpen, setDetalleDrawerOpen] = useState(false);
    const [detalleOrden, setDetalleOrden] = useState(null);

    const abrirDetalleOrden = (orden) => {
        setDetalleOrden(orden);
        setDetalleDrawerOpen(true);
    };

    const cerrarDetalleOrden = () => {
        setDetalleDrawerOpen(false);
        setDetalleOrden(null);
    };

    // --- Performance MTTO: técnicos + tendencia ----------------------------
    // El reporte "por técnico" es pura performance de Mantenimiento: el backend
    // le da 403 a propósito a SyH (no tiene técnicos propios, solo ve OT de
    // seguridad de otras áreas). No tiene sentido pedirlo para después mostrar
    // el cartel de error en cada visita, así que directamente no se pide.
    const puedeVerTecnicos = !esAnalista && !esSeguridadHigiene;

    const [tecnicos, setTecnicos] = useState([]);
    const [tecnicosLoading, setTecnicosLoading] = useState(false);
    const [tecnicosError, setTecnicosError] = useState(null);

    const cargarTecnicos = useCallback(async () => {
        if (!puedeVerTecnicos) return;
        setTecnicosLoading(true);
        setTecnicosError(null);
        const { ok, data, error } = await fetchReporteMantenimiento(params);
        if (ok) {
            setTecnicos(data.tecnicos || []);
        } else {
            setTecnicosError(error);
        }
        setTecnicosLoading(false);
    }, [params, puedeVerTecnicos]);

    const [tendencia, setTendencia] = useState([]);
    const [agruparPor, setAgruparPor] = useState('semana');
    const [tendenciaLoading, setTendenciaLoading] = useState(false);
    const [tendenciaError, setTendenciaError] = useState(null);

    const cargarTendencia = useCallback(async () => {
        if (esAnalista) return;
        setTendenciaLoading(true);
        setTendenciaError(null);
        const { ok, data, error } = await fetchReporteTendencia({ ...params, agrupar_por: agruparPor });
        if (ok) {
            setTendencia(data.tendencia || []);
        } else {
            setTendenciaError(error);
        }
        setTendenciaLoading(false);
    }, [params, agruparPor, esAnalista]);

    useEffect(() => {
        if (!esAnalista) {
            cargarTendencia();
            if (puedeVerTecnicos) cargarTecnicos();
        }
    }, [esAnalista, puedeVerTecnicos, cargarTecnicos, cargarTendencia]);

    const kpis = resumen?.resumen;
    const coloresPorPrioridad = resumen?.prioridad_colores || {};
    const labelsPorPrioridad = resumen?.prioridades || {};

    // El resumen es honesto: si no hay una sola OT creada en el rango, no tiene
    // sentido mostrar KPIs y gráficos en cero (parece roto). Se avisa y se ofrece
    // un atajo para ampliar el rango en vez de proyectar una pantalla vacía.
    const totalPeriodo = useMemo(() => {
        if (!kpis) return null;
        const sumaPorEstado = Object.values(kpis.por_estado || {}).reduce((acc, v) => acc + (v || 0), 0);
        if (sumaPorEstado > 0) return sumaPorEstado;
        return (kpis.activas || 0) + (kpis.finalizadas || 0);
    }, [kpis]);
    const dashboardVacio = kpis && totalPeriodo === 0;

    const scopeLabel = veTodosLosDepartamentos
        ? (departamentoId ? (departamentos.find((d) => d.id === departamentoId)?.nombre || 'Departamento seleccionado') : 'Todos los departamentos')
        : esSeguridadHigiene
            ? `${usuario?.departamento?.nombre || 'Su departamento'} + OTs de seguridad de toda la planta`
            : (usuario?.departamento?.nombre || 'Tu departamento');

    const columnasOtsActivas = [
        { title: 'N° Orden', dataIndex: 'id', key: 'id', className: 'text-center' },
        { title: 'Título', dataIndex: 'titulo', key: 'titulo' },
        { title: 'Creador', dataIndex: 'usuario_creador', key: 'usuario_creador' },
        { title: 'Departamento', dataIndex: 'departamento_creador', key: 'departamento_creador' },
        {
            title: 'Asignada a',
            dataIndex: 'usuario_mantenimiento',
            key: 'usuario_mantenimiento',
            render: (nombre) => (nombre ? nombre : <span className="dash-sin-asignar">Sin asignar</span>),
        },
        { title: 'Estado', dataIndex: 'estado', key: 'estado', render: (estado) => ESTADOS_LABEL[estado] || estado },
        {
            title: 'Prioridad',
            dataIndex: 'prioridad',
            key: 'prioridad',
            className: 'text-center',
            render: (prioridad) => {
                const info = getPrioridadInfo(
                    Object.entries(labelsPorPrioridad).map(([value, label]) => ({ value, label, color: coloresPorPrioridad[value] })),
                    prioridad
                );
                return <Tag color={info?.color?.tag || 'default'}>{(info?.label || prioridad || 'Media').toUpperCase()}</Tag>;
            },
        },
        {
            title: 'SLA',
            dataIndex: 'sla_estado',
            key: 'sla_estado',
            className: 'text-center',
            render: (slaEstado, orden) => {
                const ui = getSlaEstadoUi(slaEstado);
                const tooltipTexto = orden.sla_vence_at
                    ? `${ui.label} — vence ${moment(orden.sla_vence_at).format('DD/MM/YYYY HH:mm')}`
                    : ui.label;
                return (
                    <Tooltip title={tooltipTexto}>
                        <span className="ot-sla-dot" style={{ backgroundColor: ui.color }} aria-label={tooltipTexto} />
                    </Tooltip>
                );
            },
        },
        {
            title: 'Detalle',
            key: 'detalle',
            className: 'text-center',
            render: (_, orden) => (
                <Button size="small" icon={<FileTextOutlined />} onClick={() => abrirDetalleOrden(orden)}>
                    Detalle
                </Button>
            ),
        },
        {
            title: 'Mensajes',
            key: 'mensajes',
            className: 'text-center',
            // Badge rojo con los no leídos (igual que el listado de OTs) para detectar de un
            // vistazo dónde aclararon algo. Si la OT tiene conversación pero ya está leída,
            // se muestra el total en gris: el contador de no leídos por sí solo no distingue
            // "sin mensajes" de "mensajes ya leídos".
            render: (_, orden) => {
                const noLeidos = orden.mensajes_no_leidos || 0;
                const total = orden.mensajes_total || 0;

                const boton = (
                    <Button size="small" icon={<MessageOutlined />} onClick={() => abrirMensajes(orden)}>
                        Ver
                    </Button>
                );

                if (noLeidos > 0) {
                    return (
                        <Tooltip title={`${noLeidos} mensaje${noLeidos > 1 ? 's' : ''} sin leer de ${total}`}>
                            <Badge count={noLeidos} size="small" overflowCount={99}>
                                {boton}
                            </Badge>
                        </Tooltip>
                    );
                }

                if (total > 0) {
                    return (
                        <Tooltip title={`${total} mensaje${total > 1 ? 's' : ''}, sin novedades`}>
                            <Badge count={total} size="small" overflowCount={99} color="#94a3b8">
                                {boton}
                            </Badge>
                        </Tooltip>
                    );
                }

                return (
                    <Tooltip title="Sin mensajes">
                        <span className="dash-sin-mensajes">{boton}</span>
                    </Tooltip>
                );
            },
        },
    ];

    const columnasDepartamentos = [
        { title: 'Departamento', dataIndex: 'departamento_nombre', key: 'departamento_nombre' },
        { title: 'Total', dataIndex: 'total', key: 'total', className: 'text-center' },
        { title: 'Activas', dataIndex: 'activas', key: 'activas', className: 'text-center' },
        { title: 'Finalizadas', dataIndex: 'finalizadas', key: 'finalizadas', className: 'text-center' },
        { title: 'Vencidas', dataIndex: 'vencidas', key: 'vencidas', className: 'text-center' },
        { title: 'Resp. prom.', dataIndex: 'tiempo_respuesta_prom', key: 'tiempo_respuesta_prom', className: 'text-center', render: formatHoras },
        { title: 'Resol. prom.', dataIndex: 'tiempo_resolucion_prom', key: 'tiempo_resolucion_prom', className: 'text-center', render: formatHoras },
        { title: 'Cumpl. SLA', dataIndex: 'cumplimiento_sla_pct', key: 'cumplimiento_sla_pct', className: 'text-center', render: formatPct },
    ];

    const columnasTecnicos = [
        { title: 'Técnico', dataIndex: 'tecnico_nombre', key: 'tecnico_nombre' },
        { title: 'Asignadas', dataIndex: 'asignadas', key: 'asignadas', className: 'text-center' },
        { title: 'Finalizadas', dataIndex: 'finalizadas', key: 'finalizadas', className: 'text-center' },
        { title: 'Activas', dataIndex: 'activas', key: 'activas', className: 'text-center' },
        { title: 'Resol. prom.', dataIndex: 'tiempo_resolucion_prom', key: 'tiempo_resolucion_prom', className: 'text-center', render: formatHoras },
        { title: 'Cumpl. SLA', dataIndex: 'cumplimiento_sla_pct', key: 'cumplimiento_sla_pct', className: 'text-center', render: formatPct },
    ];

    return (
        <div className="dash-shell">
            <div className="dash-page">
                <header className="dash-topbar">
                    <div className="dash-topbar__brand">
                        <p className="dash-eyebrow">Sistema OT</p>
                        <h1 className="dash-title">Tablero de Reportes</h1>
                        <p className="dash-subtitle">
                            {scopeLabel} · {rango?.[0]?.format('DD/MM/YYYY')} – {rango?.[1]?.format('DD/MM/YYYY')}
                        </p>
                    </div>
                    <div className="dash-topbar__controls">
                        <RangePicker
                            value={rango}
                            onChange={(fechas) => setRango(fechas && fechas.length === 2 ? fechas : [moment().subtract(29, 'days'), moment()])}
                            format="YYYY-MM-DD"
                            allowClear={false}
                            presets={RANGO_PRESETS}
                            aria-label="Rango de fechas del reporte"
                        />
                        {veTodosLosDepartamentos && (
                            <Select
                                placeholder="Todos los departamentos"
                                aria-label="Filtrar por departamento"
                                allowClear
                                style={{ minWidth: 220 }}
                                value={departamentoId}
                                onChange={(value) => setDepartamentoId(value || null)}
                                options={departamentos.map((d) => ({ value: d.id, label: d.nombre }))}
                            />
                        )}
                        <Link to="/home" className="dash-back-link">
                            <FaArrowLeft aria-hidden="true" />
                            Volver al sistema
                        </Link>
                    </div>
                </header>

                <main className="dash-body">
                    <EstadoAsync loading={resumenLoading} error={resumenError} isEmpty={false} onRetry={cargarResumen} skeletonRows={3}>
                        {kpis && kpis.sin_datos > 0 && (
                            <Alert
                                type="info"
                                showIcon
                                className="dash-alert-sin-datos"
                                message={`${kpis.sin_datos} OTs históricas no tienen fecha de asignación registrada y quedan fuera del promedio de respuesta.`}
                            />
                        )}

                        {dashboardVacio ? (
                            <DashboardVacio onAmpliarRango={() => setRango([moment().subtract(1, 'year'), moment()])} />
                        ) : (
                            <>
                                <section className="dash-kpi-grid" aria-label="Indicadores principales">
                                    <KpiCard label="Activas" value={kpis?.activas ?? '—'} />
                                    <KpiCard label="Vencidas" value={kpis?.vencidas ?? '—'} variant={(kpis?.vencidas || 0) > 0 ? 'danger' : 'neutral'} />
                                    <KpiCard label="Cumplimiento SLA" value={formatPct(kpis?.cumplimiento_sla_pct)} variant={slaVariant(kpis?.cumplimiento_sla_pct)} />
                                    <KpiCard label="Resp. prom." value={formatHoras(kpis?.tiempo_respuesta_prom)} hint={`mediana ${formatHoras(kpis?.tiempo_respuesta_mediana)}`} />
                                    <KpiCard label="Resol. prom." value={formatHoras(kpis?.tiempo_resolucion_prom)} hint={`mediana ${formatHoras(kpis?.tiempo_resolucion_mediana)}`} />
                                    <KpiCard label="Finalizadas" value={kpis?.finalizadas ?? '—'} />
                                </section>

                                <section className="dash-charts-grid">
                                    <DashPanel title="OTs por estado">
                                        <BarraEstados porEstado={kpis?.por_estado} />
                                    </DashPanel>
                                    <DashPanel title="OTs por prioridad">
                                        <DonutPrioridad
                                            porPrioridad={kpis?.por_prioridad}
                                            coloresPorPrioridad={coloresPorPrioridad}
                                            labelsPorPrioridad={labelsPorPrioridad}
                                        />
                                    </DashPanel>
                                </section>
                            </>
                        )}
                    </EstadoAsync>

                    {veResumenMultiDepartamento && (
                        <DashPanel title="Comparativo por departamento" className="dash-panel--table">
                            <EstadoAsync
                                loading={deptoLoading}
                                error={deptoError}
                                isEmpty={deptoRows.length === 0}
                                onRetry={cargarDeptoRows}
                                emptyDescription="No hay órdenes registradas en este rango."
                            >
                                <Table
                                    dataSource={deptoRows}
                                    columns={columnasDepartamentos}
                                    rowKey="departamento_id"
                                    pagination={false}
                                    size="small"
                                    scroll={{ x: 'max-content' }}
                                    className="modern-table"
                                />
                            </EstadoAsync>
                        </DashPanel>
                    )}

                    <DashPanel title="Listado de OTs" className="dash-panel--table">
                        <div className="dash-table-filters">
                            <div className="dash-table-filters__controls">
                                <Select
                                    mode="multiple"
                                    allowClear
                                    placeholder="Todos los estados"
                                    aria-label="Filtrar por estado"
                                    style={{ minWidth: 240 }}
                                    maxTagCount="responsive"
                                    value={filtroEstados}
                                    onChange={setFiltroEstados}
                                    options={ESTADOS_ORDEN.map((estado) => ({ value: estado, label: ESTADOS_LABEL[estado] }))}
                                />
                                <Select
                                    allowClear
                                    showSearch
                                    optionFilterProp="label"
                                    placeholder="Todos los técnicos"
                                    aria-label="Filtrar por técnico asignado"
                                    style={{ minWidth: 220 }}
                                    value={filtroTecnicoId}
                                    onChange={(value) => setFiltroTecnicoId(value || null)}
                                    options={tecnicosFiltro.map((tecnico) => ({ value: tecnico.id, label: tecnico.name }))}
                                />
                                {!filtrosTablaSonDefault && (
                                    <Button type="link" onClick={restablecerFiltrosTabla}>Restablecer</Button>
                                )}
                            </div>
                            <p className="dash-table-filters__note">
                                Estos filtros de estado y técnico aplican solo a este listado. Los indicadores y gráficos de arriba muestran el total del período, sin este filtro.
                            </p>
                        </div>
                        <EstadoAsync
                            loading={otsLoading}
                            error={otsError}
                            isEmpty={otsActivas.length === 0}
                            onRetry={cargarOtsActivas}
                            emptyDescription="No hay órdenes que coincidan con los filtros seleccionados."
                        >
                            <Table
                                dataSource={otsActivas}
                                columns={columnasOtsActivas}
                                rowKey="id"
                                pagination={{ pageSize: 10 }}
                                size="small"
                                scroll={{ x: 'max-content' }}
                                className="modern-table"
                            />
                        </EstadoAsync>
                    </DashPanel>

                    {!esAnalista && (
                        <section className="dash-section">
                            <h2 className="dash-section-title">Performance de mantenimiento</h2>
                            <div className="dash-mtto-grid">
                                {puedeVerTecnicos && (
                                    <DashPanel title="Por técnico" className="dash-panel--table">
                                        <EstadoAsync
                                            loading={tecnicosLoading}
                                            error={tecnicosError}
                                            isEmpty={tecnicos.length === 0}
                                            onRetry={cargarTecnicos}
                                            emptyDescription="No hay técnicos con OTs asignadas en este rango."
                                        >
                                            <Table
                                                dataSource={tecnicos}
                                                columns={columnasTecnicos}
                                                rowKey="tecnico_id"
                                                pagination={{ pageSize: 10 }}
                                                size="small"
                                                scroll={{ x: 'max-content' }}
                                                className="modern-table"
                                            />
                                        </EstadoAsync>
                                    </DashPanel>
                                )}

                                <DashPanel
                                    title="Tendencia: creadas vs finalizadas"
                                    style={puedeVerTecnicos ? undefined : { gridColumn: '1 / -1' }}
                                    extra={(
                                        <Select
                                            value={agruparPor}
                                            onChange={setAgruparPor}
                                            style={{ width: 140 }}
                                            options={[{ value: 'semana', label: 'Por semana' }, { value: 'mes', label: 'Por mes' }]}
                                        />
                                    )}
                                >
                                    <EstadoAsync
                                        loading={tendenciaLoading}
                                        error={tendenciaError}
                                        isEmpty={tendencia.length === 0}
                                        onRetry={cargarTendencia}
                                    >
                                        <GraficoTendencia tendencia={tendencia} />
                                    </EstadoAsync>
                                </DashPanel>
                            </div>
                        </section>
                    )}
                </main>
            </div>

            <Drawer
                title={mensajesOrden ? `Mensajes — OT N° ${mensajesOrden.id}: ${mensajesOrden.titulo}` : 'Mensajes de la OT'}
                placement="right"
                width={420}
                open={mensajesDrawerOpen}
                onClose={cerrarMensajes}
                destroyOnClose
            >
                <p className="dash-drawer-note">
                    Vista de solo lectura: acá no se puede escribir ni se marcan los mensajes como leídos.
                </p>
                <EstadoAsync
                    loading={mensajesLoading}
                    error={mensajesError}
                    isEmpty={mensajes.length === 0}
                    onRetry={() => mensajesOrden && cargarMensajesOrden(mensajesOrden.id)}
                    emptyDescription="Todavía no hay mensajes en esta orden."
                    skeletonRows={4}
                >
                    <div className="message-list">
                        {mensajes.map((mensaje) => (
                            <div
                                className={`message-row ${mensaje.usuario?.id === usuario?.id ? 'is-own' : 'is-other'}`}
                                key={mensaje.id}
                            >
                                <div className={`message-bubble break-words ${mensaje.usuario?.id === usuario?.id ? 'is-own' : 'is-other'}`}>
                                    <p className="message-author">
                                        {mensaje.usuario?.name || 'Usuario'}: <span className="font-normal">{mensaje.mensaje}</span>
                                    </p>
                                    <p className="message-time">{moment(mensaje.created_at).format('DD/MM/YYYY HH:mm')}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </EstadoAsync>
            </Drawer>

            <DetalleOrdenDrawer
                open={detalleDrawerOpen}
                orden={detalleOrden}
                onClose={cerrarDetalleOrden}
                labelsPorPrioridad={labelsPorPrioridad}
                coloresPorPrioridad={coloresPorPrioridad}
                categoriasLabels={resumen?.categorias || {}}
                estadosLabel={ESTADOS_LABEL}
            />
        </div>
    );
};

export default Reportes;
