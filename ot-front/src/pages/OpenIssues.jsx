// Página del módulo Open Issues: 3 tabs server-side (Míos / Donde participo /
// Todos o Visibles para mí) + alta + detalle. Estructura visual idéntica a
// pages/HorasExtras.jsx.
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Tabs, Table, Tag, Button, Spin, Empty } from 'antd';
import { PlusOutlined, SearchOutlined } from '@ant-design/icons';
import Header from '../components/Header';
import UserProfile from '../components/UserProfile';
import OpenIssueFilter from '../components/openissues/OpenIssueFilter';
import ModalCrearOpenIssue from '../components/openissues/ModalCrearOpenIssue';
import ModalDetalleOpenIssue from '../components/openissues/ModalDetalleOpenIssue';
import { fetchCatalogosOpenIssues, fetchOpenIssues } from '../Utils/openIssuesApi';
import { getEstadoOpenIssueInfo, getPrioridadOpenIssueInfo, formatearFechaOI, iniciales } from '../Utils/openIssues';

const PAGE_SIZE_DEFAULT = 10;

const estadoInicialTabla = () => ({
    data: [],
    loading: false,
    error: null,
    pagina: 1,
    pageSize: PAGE_SIZE_DEFAULT,
    total: 0,
});

const estadoInicialFiltros = () => ({ estado: [], prioridad: undefined, departamento_destino_id: undefined, texto: '' });

const OpenIssues = () => {
    const [searchParams, setSearchParams] = useSearchParams();

    const [catalogos, setCatalogos] = useState(null);
    const [catalogosLoading, setCatalogosLoading] = useState(true);
    const [catalogosError, setCatalogosError] = useState(null);

    const [tabActiva, setTabActiva] = useState('mios');

    const [tablaMios, setTablaMios] = useState(estadoInicialTabla());
    const [filtrosMios, setFiltrosMios] = useState(estadoInicialFiltros());

    const [tablaParticipo, setTablaParticipo] = useState(estadoInicialTabla());
    const [filtrosParticipo, setFiltrosParticipo] = useState(estadoInicialFiltros());

    const [tablaTodos, setTablaTodos] = useState(estadoInicialTabla());
    const [filtrosTodos, setFiltrosTodos] = useState(estadoInicialFiltros());

    const [modalCrearOpen, setModalCrearOpen] = useState(false);
    const [detalleOpen, setDetalleOpen] = useState(false);
    const [issueSeleccionadoId, setIssueSeleccionadoId] = useState(null);

    const cargarCatalogos = useCallback(async () => {
        setCatalogosLoading(true);
        setCatalogosError(null);
        const { ok, data, error } = await fetchCatalogosOpenIssues();
        if (ok) {
            setCatalogos(data);
        } else {
            setCatalogosError(error || 'No se pudieron cargar los catálogos de Open Issues.');
        }
        setCatalogosLoading(false);
    }, []);

    useEffect(() => {
        cargarCatalogos();
    }, [cargarCatalogos]);

    // Deep-link desde la campana de notificaciones (?issue=ID): abre el
    // detalle directo. Depende del VALOR del parámetro (no del objeto
    // searchParams entero), con un ref para no reabrir el modal en loop
    // (mismo patrón que pages/HorasExtras.jsx).
    const issueParamActual = searchParams.get('issue');
    const ultimoParamAbiertoRef = useRef(null);

    useEffect(() => {
        if (!issueParamActual) {
            ultimoParamAbiertoRef.current = null;
            return;
        }
        if (ultimoParamAbiertoRef.current === issueParamActual) return;
        ultimoParamAbiertoRef.current = issueParamActual;
        setIssueSeleccionadoId(Number(issueParamActual));
        setDetalleOpen(true);
    }, [issueParamActual]);

    const cargarMios = useCallback(async (pagina = 1, pageSize = PAGE_SIZE_DEFAULT) => {
        setTablaMios((prev) => ({ ...prev, loading: true, error: null }));
        const { ok, data, error } = await fetchOpenIssues({
            mios: 1,
            estado: filtrosMios.estado,
            prioridad: filtrosMios.prioridad,
            departamento_destino_id: filtrosMios.departamento_destino_id,
            texto: filtrosMios.texto,
            per_page: pageSize,
            page: pagina,
        });
        if (ok) {
            setTablaMios({
                data: data.data || [],
                loading: false,
                error: null,
                pagina: data.current_page || 1,
                pageSize: data.per_page || pageSize,
                total: data.total || 0,
            });
        } else {
            setTablaMios((prev) => ({ ...prev, loading: false, error: error || 'No se pudieron cargar los issues.' }));
        }
    }, [filtrosMios]);

    const cargarParticipo = useCallback(async (pagina = 1, pageSize = PAGE_SIZE_DEFAULT) => {
        setTablaParticipo((prev) => ({ ...prev, loading: true, error: null }));
        const { ok, data, error } = await fetchOpenIssues({
            participo: 1,
            estado: filtrosParticipo.estado,
            prioridad: filtrosParticipo.prioridad,
            departamento_destino_id: filtrosParticipo.departamento_destino_id,
            texto: filtrosParticipo.texto,
            per_page: pageSize,
            page: pagina,
        });
        if (ok) {
            setTablaParticipo({
                data: data.data || [],
                loading: false,
                error: null,
                pagina: data.current_page || 1,
                pageSize: data.per_page || pageSize,
                total: data.total || 0,
            });
        } else {
            setTablaParticipo((prev) => ({ ...prev, loading: false, error: error || 'No se pudieron cargar los issues.' }));
        }
    }, [filtrosParticipo]);

    const cargarTodos = useCallback(async (pagina = 1, pageSize = PAGE_SIZE_DEFAULT) => {
        setTablaTodos((prev) => ({ ...prev, loading: true, error: null }));
        const { ok, data, error } = await fetchOpenIssues({
            estado: filtrosTodos.estado,
            prioridad: filtrosTodos.prioridad,
            departamento_destino_id: filtrosTodos.departamento_destino_id,
            texto: filtrosTodos.texto,
            per_page: pageSize,
            page: pagina,
        });
        if (ok) {
            setTablaTodos({
                data: data.data || [],
                loading: false,
                error: null,
                pagina: data.current_page || 1,
                pageSize: data.per_page || pageSize,
                total: data.total || 0,
            });
        } else {
            setTablaTodos((prev) => ({ ...prev, loading: false, error: error || 'No se pudieron cargar los issues.' }));
        }
    }, [filtrosTodos]);

    // Cada vez que se entra a una tab (incluida la inicial "Míos" al montar)
    // se recarga desde la página 1: así al volver a una tab después de crear,
    // cerrar o reabrir un issue desde otra no quedan datos viejos. Los
    // filtros "mios"/"participo" los resuelve el backend con el usuario del
    // token, por eso acá no hace falta leer el user de localStorage.
    useEffect(() => {
        if (tabActiva === 'mios') cargarMios(1, tablaMios.pageSize);
        else if (tabActiva === 'participo') cargarParticipo(1, tablaParticipo.pageSize);
        else if (tabActiva === 'todos') cargarTodos(1, tablaTodos.pageSize);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tabActiva]);

    const refrescarListas = useCallback(() => {
        if (tabActiva === 'mios') cargarMios(tablaMios.pagina, tablaMios.pageSize);
        else if (tabActiva === 'participo') cargarParticipo(tablaParticipo.pagina, tablaParticipo.pageSize);
        else cargarTodos(tablaTodos.pagina, tablaTodos.pageSize);
        window.dispatchEvent(new Event('openissues:actualizado'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tabActiva, cargarMios, cargarParticipo, cargarTodos]);

    const abrirDetalle = (id) => {
        setIssueSeleccionadoId(id);
        setDetalleOpen(true);
    };

    const cerrarDetalle = () => {
        setDetalleOpen(false);
        setIssueSeleccionadoId(null);
        if (searchParams.get('issue')) {
            const next = new URLSearchParams(searchParams);
            next.delete('issue');
            setSearchParams(next, { replace: true });
        }
    };

    const filaClickeable = (record) => ({
        onClick: () => abrirDetalle(record.id),
        style: { cursor: 'pointer' },
    });

    const columnas = [
        { title: 'N°', dataIndex: 'id', key: 'id', width: 70, render: (v) => <strong>{v}</strong> },
        {
            title: 'Título',
            dataIndex: 'titulo',
            key: 'titulo',
            render: (v) => <span className="oi-tabla-titulo">{v}</span>,
        },
        {
            title: 'Depto destino',
            key: 'departamento_destino',
            render: (_, r) => r.departamento_destino?.nombre || '—',
        },
        {
            title: 'Creador',
            key: 'creador',
            render: (_, r) => r.creador?.name || '—',
        },
        {
            title: 'Involucrados',
            key: 'involucrados',
            render: (_, r) => {
                const preview = (r.involucrados_preview || []).slice(0, 3);
                const restantes = (r.involucrados_count || 0) - preview.length;
                return (
                    <div className="oi-avatares">
                        {preview.map((inv) => (
                            <span className="oi-avatar" key={inv.id} title={inv.name}>{iniciales(inv.name)}</span>
                        ))}
                        {restantes > 0 && (
                            <span className="oi-avatar oi-avatar--mas" title={`${restantes} más`}>{`+${restantes}`}</span>
                        )}
                    </div>
                );
            },
        },
        {
            title: 'Estado',
            dataIndex: 'estado',
            key: 'estado',
            render: (v) => {
                const info = getEstadoOpenIssueInfo(catalogos?.estados, v);
                return <Tag color={info.color}>{info.label}</Tag>;
            },
        },
        {
            title: 'Prioridad',
            dataIndex: 'prioridad',
            key: 'prioridad',
            render: (v) => {
                const info = getPrioridadOpenIssueInfo(catalogos?.prioridades, v);
                return <Tag color={info.color}>{info.label}</Tag>;
            },
        },
        {
            title: 'Última actualización',
            dataIndex: 'ultima_actualizacion_at',
            key: 'ultima_actualizacion_at',
            render: (v) => formatearFechaOI(v),
        },
        {
            title: 'Acción',
            key: 'accion',
            render: (_, r) => <Button size="small" onClick={() => abrirDetalle(r.id)}>Ver</Button>,
        },
    ];

    const renderTablaServidor = (tabla, filtros, setFiltros, onCargar) => (
        <>
            <div className="ot-toolbar oi-toolbar">
                <OpenIssueFilter
                    estados={catalogos?.estados || []}
                    prioridades={catalogos?.prioridades || []}
                    departamentos={catalogos?.departamentos || []}
                    estadoSeleccionado={filtros.estado}
                    prioridadSeleccionada={filtros.prioridad}
                    departamentoSeleccionado={filtros.departamento_destino_id}
                    texto={filtros.texto}
                    onChangeEstado={(estado) => setFiltros((prev) => ({ ...prev, estado }))}
                    onChangePrioridad={(prioridad) => setFiltros((prev) => ({ ...prev, prioridad }))}
                    onChangeDepartamento={(departamento_destino_id) => setFiltros((prev) => ({ ...prev, departamento_destino_id }))}
                    onChangeTexto={(texto) => setFiltros((prev) => ({ ...prev, texto }))}
                    onBuscar={() => onCargar(1, tabla.pageSize)}
                />
                <Button type="primary" icon={<SearchOutlined />} onClick={() => onCargar(1, tabla.pageSize)}>
                    Aplicar filtros
                </Button>
            </div>

            {tabla.error && <Empty description={tabla.error} className="hhee-empty" />}

            {!tabla.error && (
                <Table
                    dataSource={tabla.data}
                    columns={columnas}
                    rowKey="id"
                    loading={tabla.loading}
                    size="small"
                    scroll={{ x: 'max-content' }}
                    className="modern-table"
                    onRow={filaClickeable}
                    locale={{ emptyText: <Empty description="No hay issues para los filtros seleccionados." /> }}
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
            key: 'mios',
            label: 'Míos',
            children: renderTablaServidor(tablaMios, filtrosMios, setFiltrosMios, cargarMios),
        },
        {
            key: 'participo',
            label: 'Donde participo',
            children: renderTablaServidor(tablaParticipo, filtrosParticipo, setFiltrosParticipo, cargarParticipo),
        },
        {
            key: 'todos',
            label: catalogos?.flags?.puede_ver_todos ? 'Todos' : 'Visibles para mí',
            children: renderTablaServidor(tablaTodos, filtrosTodos, setFiltrosTodos, cargarTodos),
        },
    ];

    return (
        <div className="app-shell">
            <main className="page-container">
                <UserProfile />
                <Header />

                <div className="ot-list oi-page">
                    <div className="ot-page-heading">
                        <div>
                            <p className="ot-eyebrow">Sistema OT</p>
                            <h1>Open Issues</h1>
                        </div>
                        <button
                            className="primary-action"
                            type="button"
                            onClick={() => setModalCrearOpen(true)}
                        >
                            <PlusOutlined />
                            Nuevo issue
                        </button>
                    </div>

                    {catalogosLoading && (
                        <div className="oi-modal-loading"><Spin size="large" /></div>
                    )}

                    {!catalogosLoading && catalogosError && (
                        <Empty description={catalogosError} />
                    )}

                    {!catalogosLoading && !catalogosError && (
                        <Tabs activeKey={tabActiva} onChange={setTabActiva} items={tabItems} />
                    )}
                </div>
            </main>

            <ModalCrearOpenIssue
                open={modalCrearOpen}
                issue={null}
                catalogos={catalogos}
                onClose={() => setModalCrearOpen(false)}
                onSuccess={() => {
                    setModalCrearOpen(false);
                    refrescarListas();
                }}
            />

            <ModalDetalleOpenIssue
                open={detalleOpen}
                issueId={issueSeleccionadoId}
                catalogos={catalogos}
                onClose={cerrarDetalle}
                onChanged={refrescarListas}
            />
        </div>
    );
};

export default OpenIssues;
