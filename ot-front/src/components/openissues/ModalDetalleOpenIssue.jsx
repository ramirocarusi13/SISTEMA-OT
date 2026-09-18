// Detalle de un Open Issue: cabecera + involucrados + timeline de actividad +
// caja de nueva actualización + botonera, renderizada SOLO según los `flags`
// que ya vienen resueltos del backend (nunca se recalculan permisos acá).
// Espejo de components/hhee/ModalDetalleSolicitudHhee.jsx.
import React, { useCallback, useEffect, useState } from 'react';
import { Modal, Tag, Alert, Spin, Empty, Input, Upload, Checkbox, Timeline, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import {
    fetchOpenIssue,
    agregarActualizacionOpenIssue,
    cerrarOpenIssue,
    reabrirOpenIssue,
    agregarInvolucradosOpenIssue,
    quitarInvolucradoOpenIssue,
    buildOpenIssueFormData,
} from '../../Utils/openIssuesApi';
import { getEstadoOpenIssueInfo, getPrioridadOpenIssueInfo, getColorTimeline, formatearFechaOI, esImagenOI, iniciales } from '../../Utils/openIssues';
import ModalCrearOpenIssue from './ModalCrearOpenIssue';
import SelectorInvolucrados from './SelectorInvolucrados';

const { TextArea } = Input;

const notificarCambio = () => window.dispatchEvent(new Event('openissues:actualizado'));

const ModalDetalleOpenIssue = ({ open, issueId, catalogos, onClose, onChanged }) => {
    const [issue, setIssue] = useState(null);
    const [cargando, setCargando] = useState(false);
    const [error, setError] = useState(null);

    const [editando, setEditando] = useState(false);
    const [involucrarOpen, setInvolucrarOpen] = useState(false);
    const [userIdsNuevos, setUserIdsNuevos] = useState([]);
    const [departamentoIdsNuevos, setDepartamentoIdsNuevos] = useState([]);
    const [enviandoInvolucrados, setEnviandoInvolucrados] = useState(false);

    const [enviandoAccion, setEnviandoAccion] = useState(false);

    const [nuevoTexto, setNuevoTexto] = useState('');
    const [marcarEnProgreso, setMarcarEnProgreso] = useState(false);
    const [nuevoArchivo, setNuevoArchivo] = useState(null);
    const [publicando, setPublicando] = useState(false);

    const cargarDetalle = useCallback(async () => {
        if (!issueId) return;
        setCargando(true);
        setError(null);
        const { ok, data, error: err } = await fetchOpenIssue(issueId);
        setCargando(false);
        if (ok) {
            setIssue(data);
        } else {
            setError(err || 'No se pudo cargar el issue.');
        }
    }, [issueId]);

    useEffect(() => {
        if (open) {
            cargarDetalle();
        } else {
            setIssue(null);
            setError(null);
            setNuevoTexto('');
            setMarcarEnProgreso(false);
            setNuevoArchivo(null);
            setUserIdsNuevos([]);
            setDepartamentoIdsNuevos([]);
            setInvolucrarOpen(false);
            setEditando(false);
        }
    }, [open, cargarDetalle]);

    const refrescarTodo = () => {
        cargarDetalle();
        notificarCambio();
        onChanged?.();
    };

    if (!open) return null;

    const flags = issue?.flags || {};

    const handlePublicar = async () => {
        setPublicando(true);
        const { ok, error: err } = await agregarActualizacionOpenIssue(
            issue.id,
            buildOpenIssueFormData(
                { texto: nuevoTexto.trim() || null, nuevo_estado: marcarEnProgreso ? 'en_progreso' : undefined },
                nuevoArchivo?.originFileObj || null
            )
        );
        setPublicando(false);
        if (ok) {
            message.success('Actualización publicada.');
            setNuevoTexto('');
            setMarcarEnProgreso(false);
            setNuevoArchivo(null);
            refrescarTodo();
        } else {
            message.error(err || 'No se pudo publicar la actualización.');
        }
    };

    const handleCerrar = () => {
        let textoCierre = '';
        Modal.confirm({
            title: 'Cerrar issue',
            content: (
                <TextArea
                    rows={3}
                    maxLength={4000}
                    placeholder="Comentario de cierre (opcional)"
                    onChange={(e) => { textoCierre = e.target.value; }}
                />
            ),
            okText: 'Sí, cerrar',
            cancelText: 'Cancelar',
            onOk: async () => {
                setEnviandoAccion(true);
                const { ok, error: err } = await cerrarOpenIssue(issue.id, { texto: textoCierre.trim() || undefined });
                setEnviandoAccion(false);
                if (ok) {
                    message.success('Issue cerrado.');
                    refrescarTodo();
                } else {
                    message.error(err || 'No se pudo cerrar el issue.');
                }
            },
        });
    };

    const handleReabrir = () => {
        let textoReapertura = '';
        Modal.confirm({
            title: 'Reabrir issue',
            content: (
                <TextArea
                    rows={3}
                    maxLength={4000}
                    placeholder="Comentario de reapertura (opcional)"
                    onChange={(e) => { textoReapertura = e.target.value; }}
                />
            ),
            okText: 'Sí, reabrir',
            cancelText: 'Cancelar',
            onOk: async () => {
                setEnviandoAccion(true);
                const { ok, error: err } = await reabrirOpenIssue(issue.id, { texto: textoReapertura.trim() || undefined });
                setEnviandoAccion(false);
                if (ok) {
                    message.success('Issue reabierto.');
                    refrescarTodo();
                } else {
                    message.error(err || 'No se pudo reabrir el issue.');
                }
            },
        });
    };

    const handleQuitarInvolucrado = (inv) => {
        Modal.confirm({
            title: 'Quitar involucrado',
            content: `¿Confirma quitar a ${inv.name} de este issue?`,
            okText: 'Sí, quitar',
            okType: 'danger',
            cancelText: 'Cancelar',
            onOk: async () => {
                const { ok, error: err } = await quitarInvolucradoOpenIssue(issue.id, inv.user_id);
                if (ok) {
                    message.success('Involucrado quitado.');
                    refrescarTodo();
                } else {
                    message.error(err || 'No se pudo quitar el involucrado.');
                }
            },
        });
    };

    const handleAgregarInvolucrados = async () => {
        setEnviandoInvolucrados(true);
        const { ok, data, error: err } = await agregarInvolucradosOpenIssue(issue.id, {
            user_ids: userIdsNuevos,
            departamento_ids: departamentoIdsNuevos,
        });
        setEnviandoInvolucrados(false);
        if (ok) {
            const cantidad = (data?.agregados || []).length;
            if (cantidad > 0) {
                message.success(`${cantidad} involucrado${cantidad === 1 ? '' : 's'} agregado${cantidad === 1 ? '' : 's'}`);
            } else {
                message.info('Ya estaban todos involucrados');
            }
            setUserIdsNuevos([]);
            setDepartamentoIdsNuevos([]);
            setInvolucrarOpen(false);
            refrescarTodo();
        } else {
            message.error(err || 'No se pudieron agregar los involucrados.');
        }
    };

    const estadoInfo = issue ? getEstadoOpenIssueInfo(catalogos?.estados, issue.estado) : null;
    const prioridadInfo = issue ? getPrioridadOpenIssueInfo(catalogos?.prioridades, issue.prioridad) : null;
    const idsYaInvolucrados = (issue?.involucrados || []).map((inv) => inv.user_id);

    const propsUploadActualizacion = {
        maxCount: 1,
        beforeUpload: () => false,
        fileList: nuevoArchivo ? [nuevoArchivo] : [],
        onChange: ({ fileList }) => setNuevoArchivo(fileList[0] || null),
        onRemove: () => setNuevoArchivo(null),
    };

    const publicarDeshabilitado = !nuevoTexto.trim() && !nuevoArchivo && !marcarEnProgreso;

    return (
        <>
            <Modal
                title={issue ? `Issue N° ${issue.id}` : 'Open Issue'}
                open={open}
                onCancel={onClose}
                footer={null}
                width={960}
                destroyOnClose
            >
                {cargando && (
                    <div className="oi-modal-loading"><Spin size="large" /></div>
                )}

                {!cargando && error && (
                    <Alert type="error" showIcon message={error} />
                )}

                {!cargando && !error && !issue && (
                    <Empty description="No se encontró el issue." />
                )}

                {!cargando && !error && issue && (
                    <>
                        {issue.estado === 'cerrado' && (
                            <Alert
                                type="success"
                                showIcon
                                message="Issue cerrado"
                                description={`${formatearFechaOI(issue.fecha_cierre)}${issue.cerrado_por?.name ? ` — ${issue.cerrado_por.name}` : ''}`}
                                className="hhee-alert-estado"
                            />
                        )}

                        <div className="oi-card oi-detalle-header">
                            <div>
                                <p className="hhee-detalle-label">Estado</p>
                                <Tag color={estadoInfo?.color}>{estadoInfo?.label}</Tag>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Prioridad</p>
                                <Tag color={prioridadInfo?.color}>{prioridadInfo?.label}</Tag>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Depto destino</p>
                                <p className="hhee-detalle-valor">{issue.departamento_destino?.nombre || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Creador</p>
                                <p className="hhee-detalle-valor">{issue.creador?.name || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Creado</p>
                                <p className="hhee-detalle-valor">{formatearFechaOI(issue.created_at)}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Última actualización</p>
                                <p className="hhee-detalle-valor">{formatearFechaOI(issue.ultima_actualizacion_at || issue.updated_at)}</p>
                            </div>
                        </div>

                        <div className="oi-card">
                            <h3 className="hhee-seccion-titulo">Descripción</h3>
                            {issue.descripcion
                                ? <p className="oi-timeline-texto">{issue.descripcion}</p>
                                : <Empty description="Sin descripción." />}
                        </div>

                        <div className="oi-card">
                            <h3 className="hhee-seccion-titulo">{`Involucrados (${(issue.involucrados || []).length})`}</h3>
                            <div className="oi-chips">
                                {(issue.involucrados || []).map((inv) => (
                                    <span className="oi-chip" key={inv.id}>
                                        <span className="oi-chip__avatar">{iniciales(inv.name)}</span>
                                        {inv.name}
                                        {inv.origen === 'departamento' && inv.departamento_origen_nombre && (
                                            <Tag className="hhee-tag-inline">{inv.departamento_origen_nombre}</Tag>
                                        )}
                                        {inv.puede_quitar && (
                                            <button
                                                type="button"
                                                className="oi-chip__quitar"
                                                onClick={() => handleQuitarInvolucrado(inv)}
                                                aria-label={`Quitar a ${inv.name}`}
                                            >
                                                ×
                                            </button>
                                        )}
                                    </span>
                                ))}
                            </div>

                            {flags.puede_involucrar && (
                                <button
                                    type="button"
                                    className="secondary-action"
                                    style={{ marginTop: 12 }}
                                    onClick={() => setInvolucrarOpen(true)}
                                >
                                    + Involucrar
                                </button>
                            )}
                        </div>

                        <div className="oi-card">
                            <h3 className="hhee-seccion-titulo">Actividad</h3>
                            {(issue.actualizaciones || []).length === 0 ? (
                                <Empty description="Sin actividad." />
                            ) : (
                                <Timeline
                                    items={(issue.actualizaciones || []).map((a) => ({
                                        color: getColorTimeline(a.tipo),
                                        children: (
                                            <div key={a.id}>
                                                <p className="oi-timeline-autor">
                                                    {a.autor?.name || 'Usuario'} <Tag>{a.tipo_label}</Tag>
                                                </p>
                                                <small className="oi-timeline-fecha">{formatearFechaOI(a.created_at)}</small>
                                                {(a.estado_anterior || a.estado_nuevo) && (
                                                    <div style={{ marginTop: 6 }}>
                                                        {a.estado_anterior && (
                                                            <Tag color={getEstadoOpenIssueInfo(catalogos?.estados, a.estado_anterior).color}>
                                                                {getEstadoOpenIssueInfo(catalogos?.estados, a.estado_anterior).label}
                                                            </Tag>
                                                        )}
                                                        {a.estado_anterior && a.estado_nuevo && ' → '}
                                                        {a.estado_nuevo && (
                                                            <Tag color={getEstadoOpenIssueInfo(catalogos?.estados, a.estado_nuevo).color}>
                                                                {getEstadoOpenIssueInfo(catalogos?.estados, a.estado_nuevo).label}
                                                            </Tag>
                                                        )}
                                                    </div>
                                                )}
                                                {a.texto && <p className="oi-timeline-texto">{a.texto}</p>}
                                                {a.archivo_url && (
                                                    <a
                                                        className="hhee-adjunto-link"
                                                        href={a.archivo_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        {esImagenOI(a.mime_type)
                                                            ? <img src={a.archivo_url} alt={a.archivo_nombre || 'adjunto'} />
                                                            : (a.archivo_nombre || 'Ver adjunto')}
                                                    </a>
                                                )}
                                            </div>
                                        ),
                                    }))}
                                />
                            )}
                        </div>

                        {flags.puede_actualizar && (
                            <div className="oi-card">
                                <h3 className="hhee-seccion-titulo">Nueva actualización</h3>
                                <TextArea
                                    rows={3}
                                    maxLength={4000}
                                    value={nuevoTexto}
                                    onChange={(e) => setNuevoTexto(e.target.value)}
                                    placeholder="Escribí un comentario..."
                                />

                                <div style={{ marginTop: 12 }}>
                                    <Upload {...propsUploadActualizacion}>
                                        <button type="button" className="secondary-action">
                                            <UploadOutlined /> Adjuntar archivo
                                        </button>
                                    </Upload>
                                </div>

                                {issue.estado === 'abierto' && (
                                    <div style={{ marginTop: 12 }}>
                                        <Checkbox checked={marcarEnProgreso} onChange={(e) => setMarcarEnProgreso(e.target.checked)}>
                                            Marcar en progreso
                                        </Checkbox>
                                    </div>
                                )}

                                <div className="oi-nueva-actualizacion__acciones">
                                    <button
                                        type="button"
                                        className="primary-action"
                                        onClick={handlePublicar}
                                        disabled={publicarDeshabilitado || publicando}
                                    >
                                        {publicando ? 'Publicando...' : 'Publicar'}
                                    </button>
                                </div>
                            </div>
                        )}

                        <div className="modal-actions">
                            <button type="button" className="secondary-action" onClick={onClose}>Cerrar</button>
                            {flags.puede_editar && (
                                <button type="button" className="secondary-action" onClick={() => setEditando(true)}>Editar</button>
                            )}
                            {flags.puede_cerrar && (
                                <button type="button" className="primary-action" onClick={handleCerrar} disabled={enviandoAccion}>
                                    Cerrar issue
                                </button>
                            )}
                            {flags.puede_reabrir && (
                                <button type="button" className="secondary-action" onClick={handleReabrir} disabled={enviandoAccion}>
                                    Reabrir
                                </button>
                            )}
                        </div>
                    </>
                )}
            </Modal>

            {issue && (
                <ModalCrearOpenIssue
                    open={editando}
                    issue={issue}
                    catalogos={catalogos}
                    onClose={() => setEditando(false)}
                    onSuccess={() => {
                        setEditando(false);
                        refrescarTodo();
                    }}
                />
            )}

            {issue && (
                <Modal
                    title="Involucrar personas"
                    open={involucrarOpen}
                    onCancel={() => setInvolucrarOpen(false)}
                    onOk={handleAgregarInvolucrados}
                    okText="Agregar"
                    cancelText="Cancelar"
                    confirmLoading={enviandoInvolucrados}
                    okButtonProps={{ disabled: !userIdsNuevos.length && !departamentoIdsNuevos.length }}
                    destroyOnClose
                >
                    <SelectorInvolucrados
                        usuarios={catalogos?.usuarios || []}
                        departamentos={catalogos?.departamentos || []}
                        userIds={userIdsNuevos}
                        departamentoIds={departamentoIdsNuevos}
                        onChangeUserIds={setUserIdsNuevos}
                        onChangeDepartamentoIds={setDepartamentoIdsNuevos}
                        excluirUserIds={idsYaInvolucrados}
                    />
                </Modal>
            )}
        </>
    );
};

export default ModalDetalleOpenIssue;
