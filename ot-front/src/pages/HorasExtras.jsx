import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Tabs, Table, Tag, Button, Badge, Spin, Empty } from 'antd';
import { PlusOutlined, SearchOutlined } from '@ant-design/icons';
import moment from 'moment';
import Header from '../components/Header';
import UserProfile from '../components/UserProfile';
import SolicitudHheeFilter from '../components/hhee/SolicitudHheeFilter';
import ModalCrearSolicitudHhee from '../components/hhee/ModalCrearSolicitudHhee';
import ModalDetalleSolicitudHhee from '../components/hhee/ModalDetalleSolicitudHhee';
import { fetchCatalogosHhee, fetchSolicitudesHhee, fetchPendientesHhee } from '../Utils/hheeApi';
import { getEstadoHheeInfo, formatearHoras, getSectoresHhee, sectorOFallback } from '../Utils/hhee';

const PAGE_SIZE_DEFAULT = 10;

const estadoInicialTabla = () => ({
    data: [],
    loading: false,
    error: null,
    pagina: 1,
    pageSize: PAGE_SIZE_DEFAULT,
    total: 0,
});

const estadoInicialFiltros = () => ({ estado: [], rango: [], sector: undefined });

const HorasExtras = () => {
    const [searchParams, setSearchParams] = useSearchParams();

    const [usuario, setUsuario] = useState(null);
    const [catalogos, setCatalogos] = useState(null);
    const [catalogosLoading, setCatalogosLoading] = useState(true);
    const [catalogosError, setCatalogosError] = useState(null);

    const [tabActiva, setTabActiva] = useState('propias');

    const [tablaPropias, setTablaPropias] = useState(estadoInicialTabla());
    const [filtrosPropias, setFiltrosPropias] = useState(estadoInicialFiltros());

    const [tablaTodas, setTablaTodas] = useState(estadoInicialTabla());
    const [filtrosTodas, setFiltrosTodas] = useState(estadoInicialFiltros());

    const [pendientes, setPendientes] = useState({ data: [], loading: false, error: null, total: 0 });

    const [modalCrearOpen, setModalCrearOpen] = useState(false);
    const [detalleOpen, setDetalleOpen] = useState(false);
    const [solicitudSeleccionadaId, setSolicitudSeleccionadaId] = useState(null);

    useEffect(() => {
        const userData = localStorage.getItem('user');
        if (userData) {
            setUsuario(JSON.parse(userData));
        }
    }, []);

    const cargarCatalogos = useCallback(async () => {
        setCatalogosLoading(true);
        setCatalogosError(null);
        const { ok, data, error } = await fetchCatalogosHhee();
        if (ok) {
            setCatalogos(data);
        } else {
            setCatalogosError(error || 'No se pudieron cargar los catálogos de HHEE.');
        }
        setCatalogosLoading(false);
    }, []);

    useEffect(() => {
        cargarCatalogos();
    }, [cargarCatalogos]);

    // Deep-link desde la campana de notificaciones (?solicitud=ID): abre el
    // detalle directo. Depende del VALOR del parámetro (no del objeto
    // searchParams entero) para poder reaccionar también cuando la campana
    // navega estando ya parado en /horas-extras (mismo componente montado,
    // solo cambia el querystring). El ref evita reabrir el modal en loop:
    // solo actúa cuando el parámetro cambia respecto del último procesado.
    const solicitudParamActual = searchParams.get('solicitud');
    const ultimoParamAbiertoRef = useRef(null);

    useEffect(() => {
        if (!solicitudParamActual) {
            ultimoParamAbiertoRef.current = null;
            return;
        }
        if (ultimoParamAbiertoRef.current === solicitudParamActual) return;
        ultimoParamAbiertoRef.current = solicitudParamActual;
        setSolicitudSeleccionadaId(Number(solicitudParamActual));
        setDetalleOpen(true);
    }, [solicitudParamActual]);

    const cargarPropias = useCallback(async (pagina = 1, pageSize = PAGE_SIZE_DEFAULT) => {
        if (!usuario) return;
        setTablaPropias((prev) => ({ ...prev, loading: true, error: null }));
        const [fechaDesde, fechaHasta] = filtrosPropias.rango;
        const { ok, data, error } = await fetchSolicitudesHhee({
            solicitante_id: usuario.id,
            estado: filtrosPropias.estado,
            sector: filtrosPropias.sector,
            fecha_desde: fechaDesde ? fechaDesde.format('YYYY-MM-DD') : undefined,
            fecha_hasta: fechaHasta ? fechaHasta.format('YYYY-MM-DD') : undefined,
            per_page: pageSize,
            page: pagina,
        });
        if (ok) {
            setTablaPropias({
                data: data.data || [],
                loading: false,
                error: null,
                pagina: data.current_page || 1,
                pageSize: data.per_page || pageSize,
                total: data.total || 0,
            });
        } else {
            setTablaPropias((prev) => ({ ...prev, loading: false, error: error || 'No se pudieron cargar las solicitudes.' }));
        }
    }, [usuario, filtrosPropias]);

    const cargarTodas = useCallback(async (pagina = 1, pageSize = PAGE_SIZE_DEFAULT) => {
        setTablaTodas((prev) => ({ ...prev, loading: true, error: null }));
        const [fechaDesde, fechaHasta] = filtrosTodas.rango;
        const { ok, data, error } = await fetchSolicitudesHhee({
            estado: filtrosTodas.estado,
            sector: filtrosTodas.sector,
            fecha_desde: fechaDesde ? fechaDesde.format('YYYY-MM-DD') : undefined,
            fecha_hasta: fechaHasta ? fechaHasta.format('YYYY-MM-DD') : undefined,
            per_page: pageSize,
            page: pagina,
        });
        if (ok) {
            setTablaTodas({
                data: data.data || [],
                loading: false,
                error: null,
                pagina: data.current_page || 1,
                pageSize: data.per_page || pageSize,
                total: data.total || 0,
            });
        } else {
            setTablaTodas((prev) => ({ ...prev, loading: false, error: error || 'No se pudieron cargar las solicitudes.' }));
        }
    }, [filtrosTodas]);

    const cargarPendientes = useCallback(async () => {
        setPendientes((prev) => ({ ...prev, loading: true, error: null }));
        const { ok, data, error } = await fetchPendientesHhee();
        if (ok) {
            setPendientes({ data: data.solicitudes || [], loading: false, error: null, total: data.total || 0 });
        } else {
            setPendientes((prev) => ({ ...prev, loading: false, error: error || 'No se pudieron cargar las pendientes.' }));
        }
    }, []);

    useEffect(() => {
        if (usuario) cargarPropias(1, tablaPropias.pageSize);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [usuario]);

    useEffect(() => {
        cargarPendientes();
    }, [cargarPendientes]);

    const mostrarTabPendientes = (catalogos?.mis_niveles || []).length > 0;
    const mostrarTabTodas = !!catalogos?.es_contingencia || (catalogos?.mis_niveles || []).includes(2);

    useEffect(() => {
        if (tabActiva === 'todas' && mostrarTabTodas) {
            cargarTodas(1, tablaTodas.pageSize);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tabActiva, mostrarTabTodas]);

    const refrescarListas = useCallback(() => {
        if (usuario) cargarPropias(tablaPropias.pagina, tablaPropias.pageSize);
        cargarPendientes();
        if (mostrarTabTodas) cargarTodas(tablaTodas.pagina, tablaTodas.pageSize);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [usuario, cargarPropias, cargarPendientes, cargarTodas, mostrarTabTodas]);

    const abrirDetalle = (id) => {
        setSolicitudSeleccionadaId(id);
        setDetalleOpen(true);
    };

    const cerrarDetalle = () => {
        setDetalleOpen(false);
        setSolicitudSeleccionadaId(null);
        if (searchParams.get('solicitud')) {
            const next = new URLSearchParams(searchParams);
            next.delete('solicitud');
            setSearchParams(next, { replace: true });
        }
    };

    const columnasBase = (mostrarSolicitante) => {
        const columnas = [
            { title: 'N°', dataIndex: 'id', key: 'id', width: 70, render: (v) => <strong>{v}</strong> },
            {
                title: 'Fecha',
                dataIndex: 'fecha_hhee',
                key: 'fecha_hhee',
                render: (v) => (v ? moment(v).format('DD/MM/YYYY') : '—'),
            },
            { title: 'Sector', key: 'sector', render: (_, r) => sectorOFallback(r) },
            { title: 'Turno', dataIndex: 'turno', key: 'turno', render: (v) => v || '—' },
        ];

        if (mostrarSolicitante) {
            columnas.push({ title: 'Solicitante', key: 'solicitante', render: (_, r) => r.solicitante?.name || '—' });
        }

        columnas.push(
            { title: 'Empleados', key: 'empleados', render: (_, r) => r.detalles_count ?? r.total_empleados ?? '—' },
            {
                title: 'Total hs. teóricas',
                dataIndex: 'total_horas_teoricas',
                key: 'total_horas_teoricas',
                render: (v) => `${formatearHoras(v)} h`,
            },
            {
                title: 'Estado',
                dataIndex: 'estado',
                key: 'estado',
                render: (v) => {
                    const info = getEstadoHheeInfo(catalogos?.estados, v);
                    return <Tag color={info.color}>{info.label}</Tag>;
                },
            },
            {
                title: 'Creada',
                dataIndex: 'created_at',
                key: 'created_at',
                render: (v) => (v ? moment(v).format('DD/MM/YYYY HH:mm') : '—'),
            },
            {
                title: 'Acción',
                key: 'accion',
                render: (_, r) => <Button size="small" onClick={() => abrirDetalle(r.id)}>Ver</Button>,
            },
        );

        return columnas;
    };

    const filaClickeable = (record) => ({
        onClick: () => abrirDetalle(record.id),
        style: { cursor: 'pointer' },
    });

    const renderTablaServidor = (tabla, filtros, setFiltros, onCargar, mostrarSolicitante) => (
        <>
            <div className="ot-toolbar hhee-toolbar">
                <SolicitudHheeFilter
                    estados={catalogos?.estados || []}
                    sectores={getSectoresHhee(catalogos)}
                    estadoSeleccionado={filtros.estado}
                    rangoFechas={filtros.rango}
                    sectorSeleccionado={filtros.sector}
                    onChangeEstado={(estado) => setFiltros((prev) => ({ ...prev, estado }))}
                    onChangeRangoFechas={(rango) => setFiltros((prev) => ({ ...prev, rango }))}
                    onChangeSector={(sector) => setFiltros((prev) => ({ ...prev, sector }))}
                />
                <Button type="primary" icon={<SearchOutlined />} onClick={() => onCargar(1, tabla.pageSize)}>
                    Aplicar filtros
                </Button>
            </div>

            {tabla.error && <Empty description={tabla.error} className="hhee-empty" />}

            {!tabla.error && (
                <Table
                    dataSource={tabla.data}
                    columns={columnasBase(mostrarSolicitante)}
                    rowKey="id"
                    loading={tabla.loading}
                    size="small"
                    scroll={{ x: 'max-content' }}
                    className="modern-table"
                    onRow={filaClickeable}
                    locale={{ emptyText: <Empty description="No hay solicitudes para los filtros seleccionados." /> }}
                    pagination={{
                        current: tabla.pagina,
                        pageSize: tabla.pageSize,
                        total: tabla.total,
                        showSizeChanger: true,
                        onChange: (pagina, pageSize) => onCargar(pagina, pageSize),
                    }}
                />
            )}
        </>
    );

    const tabItems = [
        {
            key: 'propias',
            label: 'Mis solicitudes',
            children: renderTablaServidor(tablaPropias, filtrosPropias, setFiltrosPropias, cargarPropias, false),
        },
    ];

    if (mostrarTabPendientes) {
        tabItems.push({
            key: 'pendientes',
            label: (
                <Badge count={pendientes.total} size="small" offset={[8, 0]}>
                    <span>Pendientes de mi firma</span>
                </Badge>
            ),
            children: (
                <>
                    {pendientes.error && <Empty description={pendientes.error} />}
                    {!pendientes.error && (
                        <Table
                            dataSource={pendientes.data}
                            columns={columnasBase(true)}
                            rowKey="id"
                            loading={pendientes.loading}
                            size="small"
                            scroll={{ x: 'max-content' }}
                            className="modern-table"
                            onRow={filaClickeable}
                            locale={{ emptyText: <Empty description="No tenés solicitudes pendientes de firma." /> }}
                            pagination={{ pageSize: 10 }}
                        />
                    )}
                </>
            ),
        });
    }

    if (mostrarTabTodas) {
        tabItems.push({
            key: 'todas',
            label: 'Todas',
            children: renderTablaServidor(tablaTodas, filtrosTodas, setFiltrosTodas, cargarTodas, true),
        });
    }

    return (
        <div className="app-shell">
            <main className="page-container">
                <UserProfile />
                <Header />

                <div className="ot-list hhee-page">
                    <div className="ot-page-heading">
                        <div>
                            <p className="ot-eyebrow">Sistema OT</p>
                            <h1>Horas Extras</h1>
                        </div>
                        <button
                            className="primary-action"
                            type="button"
                            onClick={() => setModalCrearOpen(true)}
                        >
                            <PlusOutlined />
                            Nueva solicitud
                        </button>
                    </div>

                    {catalogosLoading && (
                        <div className="hhee-catalogos-loading"><Spin size="large" /></div>
                    )}

                    {!catalogosLoading && catalogosError && (
                        <Empty description={catalogosError} />
                    )}

                    {!catalogosLoading && !catalogosError && (
                        <Tabs activeKey={tabActiva} onChange={setTabActiva} items={tabItems} />
                    )}
                </div>
            </main>

            <ModalCrearSolicitudHhee
                open={modalCrearOpen}
                solicitud={null}
                sectores={getSectoresHhee(catalogos)}
                usuarios={catalogos?.usuarios || []}
                maxHorasPorEmpleado={catalogos?.max_horas_por_empleado}
                onClose={() => setModalCrearOpen(false)}
                onSuccess={() => {
                    setModalCrearOpen(false);
                    refrescarListas();
                }}
            />

            <ModalDetalleSolicitudHhee
                open={detalleOpen}
                solicitudId={solicitudSeleccionadaId}
                catalogos={catalogos}
                onClose={cerrarDetalle}
                onChanged={refrescarListas}
            />
        </div>
    );
};

export default HorasExtras;
