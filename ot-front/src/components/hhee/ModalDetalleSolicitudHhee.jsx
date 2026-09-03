// Detalle de una solicitud de HHEE: cabecera + renglones + circuito de firmas
// (Steps) + historial completo + botonera renderizada SOLO según los `flags`
// que ya vienen resueltos del backend (nunca se recalculan permisos acá).
import React, { useCallback, useEffect, useState } from 'react';
import { Modal, Table, Tag, Collapse, Alert, Spin, Empty, Input, message } from 'antd';
import { CheckOutlined, CloseOutlined, ClockCircleOutlined } from '@ant-design/icons';
import moment from 'moment';
import {
    fetchSolicitudHhee,
    enviarSolicitudHhee,
    aprobarSolicitudHhee,
    anularSolicitudHhee,
} from '../../Utils/hheeApi';
import { getEstadoHheeInfo, formatearHoras, cruzaMedianocheHhee, horaCorta, iniciales, getSectoresHhee, sectorOFallback } from '../../Utils/hhee';
import ModalCrearSolicitudHhee from './ModalCrearSolicitudHhee';
import ModalRechazarSolicitudHhee from './ModalRechazarSolicitudHhee';
import ModalHorasRealesHhee from './ModalHorasRealesHhee';

const { TextArea } = Input;

const notificarCambio = () => window.dispatchEvent(new Event('hhee:actualizado'));

const ModalDetalleSolicitudHhee = ({ open, solicitudId, catalogos, onClose, onChanged }) => {
    const [solicitud, setSolicitud] = useState(null);
    const [cargando, setCargando] = useState(false);
    const [error, setError] = useState(null);

    const [editando, setEditando] = useState(false);
    const [rechazando, setRechazando] = useState(false);
    const [cargandoReales, setCargandoReales] = useState(false);
    const [aprobarOpen, setAprobarOpen] = useState(false);
    const [comentarioAprobar, setComentarioAprobar] = useState('');
    const [enviandoAccion, setEnviandoAccion] = useState(false);

    const cargarDetalle = useCallback(async () => {
        if (!solicitudId) return;
        setCargando(true);
        setError(null);
        const { ok, data, error: err } = await fetchSolicitudHhee(solicitudId);
        setCargando(false);
        if (ok) {
            setSolicitud(data);
        } else {
            setError(err || 'No se pudo cargar la solicitud.');
        }
    }, [solicitudId]);

    useEffect(() => {
        if (open) {
            cargarDetalle();
        } else {
            setSolicitud(null);
            setError(null);
        }
    }, [open, cargarDetalle]);

    const refrescarTodo = () => {
        cargarDetalle();
        notificarCambio();
        onChanged?.();
    };

    const handleEnviar = () => {
        Modal.confirm({
            title: 'Enviar a aprobación',
            content: 'Una vez enviada, la solicitud no se podrá editar. ¿Confirma el envío?',
            okText: 'Sí, enviar',
            cancelText: 'Cancelar',
            onOk: async () => {
                setEnviandoAccion(true);
                const { ok, error: err } = await enviarSolicitudHhee(solicitud.id);
                setEnviandoAccion(false);
                if (ok) {
                    message.success('Solicitud enviada a aprobación.');
                    refrescarTodo();
                } else {
                    message.error(err || 'No se pudo enviar la solicitud.');
                }
            },
        });
    };

    const handleAnular = () => {
        Modal.confirm({
            title: 'Anular solicitud',
            content: 'Esta acción no se puede deshacer. ¿Confirma la anulación?',
            okText: 'Sí, anular',
            okType: 'danger',
            cancelText: 'Cancelar',
            onOk: async () => {
                setEnviandoAccion(true);
                const { ok, error: err } = await anularSolicitudHhee(solicitud.id);
                setEnviandoAccion(false);
                if (ok) {
                    message.success('Solicitud anulada.');
                    refrescarTodo();
                } else {
                    message.error(err || 'No se pudo anular la solicitud.');
                }
            },
        });
    };

    const handleAprobar = async () => {
        setEnviandoAccion(true);
        const { ok, error: err } = await aprobarSolicitudHhee(solicitud.id, { comentario: comentarioAprobar.trim() || undefined });
        setEnviandoAccion(false);
        if (ok) {
            message.success('Firma registrada.');
            setAprobarOpen(false);
            setComentarioAprobar('');
            refrescarTodo();
        } else {
            message.error(err || 'No se pudo registrar la aprobación.');
        }
    };

    if (!open) return null;

    const flags = solicitud?.flags || {};
    const estadoInfo = solicitud ? getEstadoHheeInfo(catalogos?.estados, solicitud.estado) : null;
    const aprobacionNivel1 = (solicitud?.aprobaciones || []).find((a) => a.nivel === 1);
    const aprobacionNivel2 = (solicitud?.aprobaciones || []).find((a) => a.nivel === 2);
    const mostrarReales = solicitud && (solicitud.estado === 'aprobada' || solicitud.estado === 'cerrada');

    const columnasDetalle = [
        { title: 'Empleado', dataIndex: 'nombre', key: 'nombre' },
        { title: 'Motivo', dataIndex: 'motivo', key: 'motivo' },
        {
            title: 'Transporte',
            key: 'transporte',
            render: (_, fila) => (fila.necesita_transporte ? `Sí — ${fila.localidad || 's/d'}` : 'No'),
        },
        {
            title: 'Horario previsto',
            key: 'horario',
            render: (_, fila) => {
                const desde = horaCorta(fila.hora_desde);
                const hasta = horaCorta(fila.hora_hasta);
                return (
                    <span>
                        {desde} a {hasta}
                        {desde && hasta && cruzaMedianocheHhee(desde, hasta) && (
                            <Tag className="hhee-tag-inline" color="blue">Cruza medianoche</Tag>
                        )}
                    </span>
                );
            },
        },
        {
            title: 'Hs. teóricas',
            key: 'teoricas',
            render: (_, fila) => `${formatearHoras(fila.horas_teoricas)} h`,
        },
        ...(mostrarReales ? [{
            title: 'Hs. reales',
            key: 'reales',
            render: (_, fila) => (fila.fecha_realizacion
                ? `${formatearHoras(fila.horas_reales)} h (${moment(fila.fecha_realizacion).format('DD/MM/YYYY')})`
                : 'Sin cargar'),
        }] : []),
    ];

    const renderFirma = (aprobacion) => {
        if (!aprobacion || aprobacion.estado === 'pendiente') {
            return <span className="hhee-firma-pendiente">Pendiente</span>;
        }
        return (
            <div className="hhee-firma-info">
                <p>
                    <strong>{aprobacion.aprobador?.name || 'Usuario'}</strong>
                    {aprobacion.rol_aprobador && ` — ${catalogos?.roles_labels?.[aprobacion.rol_aprobador] || aprobacion.rol_aprobador}`}
                    {aprobacion.es_contingencia && <Tag color="orange" className="hhee-tag-inline">Contingencia</Tag>}
                </p>
                {aprobacion.firmado_at && <small>{moment(aprobacion.firmado_at).format('DD/MM/YYYY HH:mm')}</small>}
                {aprobacion.comentario && <p className="hhee-firma-comentario">{`"${aprobacion.comentario}"`}</p>}
            </div>
        );
    };

    // Estado visual de cada paso del timeline (independiente del status de
    // AntD Steps: acá se dibuja a mano para tener avatares con iniciales).
    const estadoDelPaso = (aprobacion, enCurso) => {
        if (aprobacion?.estado === 'aprobada') return 'done';
        if (aprobacion?.estado === 'rechazada') return 'rejected';
        if (enCurso) return 'active';
        return 'pending';
    };

    // Los títulos de los pasos son genéricos a propósito: no nombran roles ni
    // personas (ver feedback del módulo — la firma final ahora puede ser
    // gerencia_general en firma normal, sin ningún tratamiento especial). La
    // firma implícita (solicitante que es jefe/gerente del área) tampoco tiene
    // tratamiento especial: se muestra como cualquier otra firma con su
    // comentario ("Firma implícita..." si así viene del backend).
    const pasosTimeline = solicitud ? [
        {
            key: 'carga',
            titulo: 'Carga',
            estado: 'done',
            avatarNombre: solicitud.solicitante?.name,
            cuerpo: (
                <div className="hhee-firma-info">
                    <p><strong>{solicitud.solicitante?.name || 'Solicitante'}</strong></p>
                    <small>
                        {solicitud.fecha_envio
                            ? `Enviada: ${moment(solicitud.fecha_envio).format('DD/MM/YYYY HH:mm')}`
                            : `Borrador desde: ${moment(solicitud.created_at).format('DD/MM/YYYY HH:mm')}`}
                    </small>
                </div>
            ),
        },
        {
            key: 'nivel1',
            titulo: 'Firma de área',
            estado: estadoDelPaso(aprobacionNivel1, solicitud.estado === 'pendiente_nivel1'),
            avatarNombre: aprobacionNivel1 && aprobacionNivel1.estado !== 'pendiente' ? aprobacionNivel1.aprobador?.name : null,
            cuerpo: renderFirma(aprobacionNivel1),
        },
        {
            key: 'nivel2',
            titulo: 'Aprobación final',
            estado: estadoDelPaso(aprobacionNivel2, solicitud.estado === 'pendiente_final'),
            avatarNombre: aprobacionNivel2 && aprobacionNivel2.estado !== 'pendiente' ? aprobacionNivel2.aprobador?.name : null,
            cuerpo: renderFirma(aprobacionNivel2),
        },
        {
            key: 'cierre',
            titulo: 'Cierre',
            estado: solicitud.estado === 'cerrada' ? 'done' : 'pending',
            avatarNombre: null,
            cuerpo: solicitud.fecha_cierre
                ? <small>{moment(solicitud.fecha_cierre).format('DD/MM/YYYY HH:mm')}</small>
                : <span className="hhee-firma-pendiente">Pendiente carga de horas reales</span>,
        },
    ] : [];

    return (
        <>
            <Modal
                title={solicitud ? `Solicitud N° ${solicitud.id}` : 'Solicitud de horas extras'}
                open={open}
                onCancel={onClose}
                footer={null}
                width={960}
                destroyOnClose
            >
                {cargando && (
                    <div className="hhee-modal-loading"><Spin size="large" /></div>
                )}

                {!cargando && error && (
                    <Alert type="error" showIcon message={error} />
                )}

                {!cargando && !error && !solicitud && (
                    <Empty description="No se encontró la solicitud." />
                )}

                {!cargando && !error && solicitud && (
                    <>
                        {solicitud.estado === 'anulada' && (
                            <Alert
                                type="warning"
                                showIcon
                                message="Solicitud anulada"
                                className="hhee-alert-estado"
                            />
                        )}
                        {solicitud.estado === 'rechazada' && (
                            <Alert
                                type="error"
                                showIcon
                                message="Solicitud rechazada"
                                description={(
                                    <>
                                        {solicitud.motivo_rechazo && <p>Motivo: {solicitud.motivo_rechazo}</p>}
                                        {solicitud.fecha_rechazo && <small>{moment(solicitud.fecha_rechazo).format('DD/MM/YYYY HH:mm')}</small>}
                                    </>
                                )}
                                className="hhee-alert-estado"
                            />
                        )}

                        <div className="hhee-card hhee-detalle-header">
                            <div>
                                <p className="hhee-detalle-label">Fecha prevista</p>
                                <p className="hhee-detalle-valor">{moment(solicitud.fecha_hhee).format('DD/MM/YYYY')}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Sector</p>
                                <p className="hhee-detalle-valor">{sectorOFallback(solicitud)}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Turno</p>
                                <p className="hhee-detalle-valor">{solicitud.turno || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Solicitante</p>
                                <p className="hhee-detalle-valor">{solicitud.solicitante?.name || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Estado</p>
                                <Tag color={estadoInfo?.color}>{estadoInfo?.label}</Tag>
                            </div>
                        </div>

                        {solicitud.observaciones && (
                            <Alert type="info" message={solicitud.observaciones} className="hhee-alert-estado" />
                        )}

                        <div className="hhee-card">
                            <h3 className="hhee-seccion-titulo">Empleados</h3>
                            <Table
                                dataSource={solicitud.detalles || []}
                                columns={columnasDetalle}
                                rowKey="id"
                                pagination={false}
                                size="small"
                                scroll={{ x: 'max-content' }}
                                className="modern-table"
                            />
                        </div>

                        <div className="hhee-card">
                            <h3 className="hhee-seccion-titulo">Circuito de aprobación</h3>
                            <div className="hhee-timeline">
                                {pasosTimeline.map((paso, idx) => (
                                    <div className="hhee-timeline-item" key={paso.key}>
                                        <div className="hhee-timeline-marker">
                                            <div className={`hhee-timeline-avatar hhee-timeline-avatar--${paso.estado}`}>
                                                {paso.avatarNombre ? iniciales(paso.avatarNombre) : (
                                                    paso.estado === 'done' ? <CheckOutlined />
                                                        : paso.estado === 'rejected' ? <CloseOutlined />
                                                        : paso.estado === 'active' ? <ClockCircleOutlined />
                                                        : null
                                                )}
                                            </div>
                                            {idx < pasosTimeline.length - 1 && <div className="hhee-timeline-connector" />}
                                        </div>
                                        <div className="hhee-timeline-content">
                                            <p className="hhee-timeline-title">{paso.titulo}</p>
                                            {paso.cuerpo}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <Collapse
                            className="hhee-historial-collapse"
                            items={[{
                                key: 'historial',
                                label: `Historial completo (${(solicitud.historial || []).length})`,
                                children: (solicitud.historial || []).length === 0
                                    ? <Empty description="Sin movimientos." />
                                    : (
                                        <ul className="hhee-historial-list">
                                            {solicitud.historial.map((h) => (
                                                <li key={h.id}>
                                                    <strong>{h.accion}</strong> — {h.usuario?.name || 'Sistema'}
                                                    {h.es_contingencia && <Tag color="orange" className="hhee-tag-inline">Contingencia</Tag>}
                                                    <br />
                                                    <small>{moment(h.created_at).format('DD/MM/YYYY HH:mm')}</small>
                                                    {h.comentario && <p className="hhee-firma-comentario">{`"${h.comentario}"`}</p>}
                                                </li>
                                            ))}
                                        </ul>
                                    ),
                            }]}
                        />

                        <div className="modal-actions">
                            <button type="button" className="secondary-action" onClick={onClose}>Cerrar</button>
                            {flags.puede_editar && (
                                <button type="button" className="secondary-action" onClick={() => setEditando(true)}>Editar</button>
                            )}
                            {flags.puede_enviar && (
                                <button type="button" className="primary-action" onClick={handleEnviar} disabled={enviandoAccion}>Enviar a aprobación</button>
                            )}
                            {flags.puede_aprobar && (
                                <button type="button" className="primary-action" onClick={() => setAprobarOpen(true)}>Aprobar</button>
                            )}
                            {flags.puede_rechazar && (
                                <button type="button" className="danger-action" onClick={() => setRechazando(true)}>Rechazar</button>
                            )}
                            {flags.puede_cargar_reales && (
                                <button type="button" className="primary-action" onClick={() => setCargandoReales(true)}>Cargar horas reales</button>
                            )}
                            {flags.puede_anular && (
                                <button type="button" className="danger-action" onClick={handleAnular} disabled={enviandoAccion}>Anular</button>
                            )}
                        </div>
                    </>
                )}
            </Modal>

            <Modal
                title="Aprobar solicitud"
                open={aprobarOpen}
                onCancel={() => setAprobarOpen(false)}
                onOk={handleAprobar}
                okText="Confirmar aprobación"
                cancelText="Cancelar"
                confirmLoading={enviandoAccion}
            >
                <div className="form-field">
                    <label className="form-label" htmlFor="hhee-comentario-aprobar">Comentario (opcional)</label>
                    <TextArea
                        id="hhee-comentario-aprobar"
                        rows={3}
                        maxLength={1000}
                        value={comentarioAprobar}
                        onChange={(e) => setComentarioAprobar(e.target.value)}
                    />
                </div>
            </Modal>

            {solicitud && (
                <ModalCrearSolicitudHhee
                    open={editando}
                    solicitud={solicitud}
                    sectores={getSectoresHhee(catalogos)}
                    usuarios={catalogos?.usuarios || []}
                    maxHorasPorEmpleado={catalogos?.max_horas_por_empleado}
                    onClose={() => setEditando(false)}
                    onSuccess={() => {
                        setEditando(false);
                        refrescarTodo();
                    }}
                />
            )}

            {solicitud && (
                <ModalRechazarSolicitudHhee
                    open={rechazando}
                    solicitudId={solicitud.id}
                    onClose={() => setRechazando(false)}
                    onSuccess={refrescarTodo}
                />
            )}

            {solicitud && (
                <ModalHorasRealesHhee
                    open={cargandoReales}
                    solicitud={solicitud}
                    onClose={() => setCargandoReales(false)}
                    onSuccess={refrescarTodo}
                />
            )}
        </>
    );
};

export default ModalDetalleSolicitudHhee;
