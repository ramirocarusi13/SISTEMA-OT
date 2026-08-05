// Página de Reportes/KPIs de Órdenes de Trabajo (SPEC-prioridad-reportes.md §7.2).
// Sin librerías de gráficos nuevas: las barras y el donut se resuelven con
// divs/Tailwind y SVG inline, y las tablas/KPIs con componentes de AntD.
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Button, DatePicker, Empty, Select, Skeleton, Table, Tabs, Tag, Tooltip } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import moment from 'moment';
import Header from '../components/Header';
import UserProfile from '../components/UserProfile';
import {
    fetchDepartamentosApi,
    fetchOrdenesTrabajo,
    fetchReporteDepartamentos,
    fetchReporteMantenimiento,
    fetchReporteResumen,
    fetchReporteTendencia,
} from '../Utils/otApi';
import { getPrioridadInfo, getSlaEstadoUi, ordenarPorPrioridad } from '../Utils/prioridad';

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

const KpiCard = ({ label, value, hint }) => (
    <div className="reportes-kpi-card">
        <span>{label}</span>
        <strong>{value}</strong>
        {hint && <small>{hint}</small>}
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
    const esAnalista = usuario?.rol === 'analista';

    const [tab, setTab] = useState('mi_departamento');
    const [rango, setRango] = useState([moment().subtract(29, 'days'), moment()]);
    const [departamentoId, setDepartamentoId] = useState(null);
    const [departamentos, setDepartamentos] = useState([]);

    // Carga la lista de departamentos solo para quienes pueden filtrar por más de uno.
    useEffect(() => {
        if (!veTodosLosDepartamentos) return;
        (async () => {
            const { ok, data } = await fetchDepartamentosApi();
            if (ok && Array.isArray(data)) setDepartamentos(data);
        })();
    }, [veTodosLosDepartamentos]);

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
        if (!veTodosLosDepartamentos) return;
        setDeptoLoading(true);
        setDeptoError(null);
        const { ok, data, error } = await fetchReporteDepartamentos(params);
        if (ok) {
            setDeptoRows(data.departamentos || []);
        } else {
            setDeptoError(error);
        }
        setDeptoLoading(false);
    }, [params, veTodosLosDepartamentos]);

    useEffect(() => { cargarDeptoRows(); }, [cargarDeptoRows]);

    // --- OTs activas ordenadas por prioridad -------------------------------
    const [otsActivas, setOtsActivas] = useState([]);
    const [otsLoading, setOtsLoading] = useState(false);
    const [otsError, setOtsError] = useState(null);

    const cargarOtsActivas = useCallback(async () => {
        setOtsLoading(true);
        setOtsError(null);
        const { ok, data, error } = await fetchOrdenesTrabajo({ departamento_id: departamentoId || undefined });
        if (ok) {
            const activas = Array.isArray(data) ? data.filter((o) => o.estado !== 'finalizada') : [];
            setOtsActivas(ordenarPorPrioridad(activas));
        } else {
            setOtsError(error);
        }
        setOtsLoading(false);
    }, [departamentoId]);

    useEffect(() => {
        if (tab === 'mi_departamento') cargarOtsActivas();
    }, [tab, cargarOtsActivas]);

    // --- Performance MTTO: técnicos + tendencia ----------------------------
    const [tecnicos, setTecnicos] = useState([]);
    const [tecnicosLoading, setTecnicosLoading] = useState(false);
    const [tecnicosError, setTecnicosError] = useState(null);

    const cargarTecnicos = useCallback(async () => {
        if (esAnalista) return;
        setTecnicosLoading(true);
        setTecnicosError(null);
        const { ok, data, error } = await fetchReporteMantenimiento(params);
        if (ok) {
            setTecnicos(data.tecnicos || []);
        } else {
            setTecnicosError(error);
        }
        setTecnicosLoading(false);
    }, [params, esAnalista]);

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
        if (tab === 'performance_mtto' && !esAnalista) {
            cargarTecnicos();
            cargarTendencia();
        }
    }, [tab, esAnalista, cargarTecnicos, cargarTendencia]);

    const kpis = resumen?.resumen;
    const coloresPorPrioridad = resumen?.prioridad_colores || {};
    const labelsPorPrioridad = resumen?.prioridades || {};

    const columnasOtsActivas = [
        { title: 'N° Orden', dataIndex: 'id', key: 'id', className: 'text-center' },
        { title: 'Título', dataIndex: 'titulo', key: 'titulo' },
        { title: 'Creador', dataIndex: 'usuario_creador', key: 'usuario_creador' },
        { title: 'Departamento', dataIndex: 'departamento_creador', key: 'departamento_creador' },
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

    const filtrosComunes = (
        <div className="reportes-filtros">
            <RangePicker
                value={rango}
                onChange={(fechas) => setRango(fechas && fechas.length === 2 ? fechas : [moment().subtract(29, 'days'), moment()])}
                format="YYYY-MM-DD"
                allowClear={false}
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
        </div>
    );

    const contenidoMiDepartamento = (
        <div className="reportes-tab-content">
            <EstadoAsync loading={resumenLoading} error={resumenError} isEmpty={false} onRetry={cargarResumen} skeletonRows={2}>
                {kpis && kpis.sin_datos > 0 && (
                    <Alert
                        type="info"
                        showIcon
                        className="reportes-alert-sin-datos"
                        message={`${kpis.sin_datos} OTs históricas no tienen fecha de asignación registrada y quedan fuera del promedio de respuesta.`}
                    />
                )}

                <div className="reportes-kpi-grid">
                    <KpiCard label="Activas" value={kpis?.activas ?? '—'} />
                    <KpiCard label="Finalizadas" value={kpis?.finalizadas ?? '—'} />
                    <KpiCard label="Vencidas" value={kpis?.vencidas ?? '—'} />
                    <KpiCard label="Resp. prom." value={formatHoras(kpis?.tiempo_respuesta_prom)} hint={`mediana ${formatHoras(kpis?.tiempo_respuesta_mediana)}`} />
                    <KpiCard label="Resol. prom." value={formatHoras(kpis?.tiempo_resolucion_prom)} hint={`mediana ${formatHoras(kpis?.tiempo_resolucion_mediana)}`} />
                    <KpiCard label="Cumplimiento SLA" value={formatPct(kpis?.cumplimiento_sla_pct)} />
                </div>

                <div className="reportes-charts-row">
                    <div className="reportes-chart-card">
                        <h3 className="table-section-title">OTs por estado</h3>
                        <BarraEstados porEstado={kpis?.por_estado} />
                    </div>
                    <div className="reportes-chart-card">
                        <h3 className="table-section-title">OTs por prioridad</h3>
                        <DonutPrioridad
                            porPrioridad={kpis?.por_prioridad}
                            coloresPorPrioridad={coloresPorPrioridad}
                            labelsPorPrioridad={labelsPorPrioridad}
                        />
                    </div>
                </div>
            </EstadoAsync>

            {veTodosLosDepartamentos && (
                <div className="table-section">
                    <h2 className="table-section-title">Comparativo por departamento</h2>
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
                </div>
            )}

            <div className="table-section">
                <h2 className="table-section-title">OTs activas por prioridad</h2>
                <EstadoAsync
                    loading={otsLoading}
                    error={otsError}
                    isEmpty={otsActivas.length === 0}
                    onRetry={cargarOtsActivas}
                    emptyDescription="No hay órdenes activas."
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
            </div>
        </div>
    );

    const contenidoPerformanceMtto = (
        <div className="reportes-tab-content">
            <div className="table-section">
                <h2 className="table-section-title">Performance por técnico</h2>
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
            </div>

            <div className="reportes-chart-card">
                <div className="reportes-tendencia-header">
                    <h3 className="table-section-title">Tendencia: creadas vs finalizadas</h3>
                    <Select
                        value={agruparPor}
                        onChange={setAgruparPor}
                        style={{ width: 140 }}
                        options={[{ value: 'semana', label: 'Por semana' }, { value: 'mes', label: 'Por mes' }]}
                    />
                </div>
                <EstadoAsync
                    loading={tendenciaLoading}
                    error={tendenciaError}
                    isEmpty={tendencia.length === 0}
                    onRetry={cargarTendencia}
                >
                    <GraficoTendencia tendencia={tendencia} />
                </EstadoAsync>
            </div>
        </div>
    );

    const tabItems = [
        { key: 'mi_departamento', label: 'Mi departamento', children: contenidoMiDepartamento },
    ];
    if (!esAnalista) {
        tabItems.push({ key: 'performance_mtto', label: 'Performance MTTO', children: contenidoPerformanceMtto });
    }

    return (
        <div className="app-shell">
            <main className="page-container">
                <UserProfile />
                <Header />

                <div className="ot-list">
                    <div className="ot-page-heading">
                        <div>
                            <p className="ot-eyebrow">Sistema OT</p>
                            <h1>Reportes</h1>
                        </div>
                        {filtrosComunes}
                    </div>

                    <Tabs activeKey={tab} onChange={setTab} items={tabItems} />
                </div>
            </main>
        </div>
    );
};

export default Reportes;
