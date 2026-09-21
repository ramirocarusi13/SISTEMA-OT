// Card "Items" del detalle de un Open Issue (§10.6): puntos propios del
// issue con estado, responsable opcional y sus propias actualizaciones.
// El front NUNCA recalcula permisos: todo lo que depende de quién puede
// gestionar items viene resuelto en `flags.puede_gestionar_items`. Cada
// acción (cambiar estado, comentar, agregar, editar) llama a su endpoint y
// actualiza el detalle del issue con la respuesta completa que ya devuelve
// el backend, sin volver a pedir el show (lo hace el padre vía
// onIssueActualizado).
import React, { useEffect, useState } from 'react';
import { Progress, Alert, Tag, Select, Empty, Input, Upload, Image, Modal, message } from 'antd';
import { UploadOutlined, PlusOutlined } from '@ant-design/icons';
import {
    agregarItemsOpenIssue,
    cambiarEstadoItemOpenIssue,
    agregarActualizacionOpenIssue,
    buildOpenIssueFormData,
} from '../../Utils/openIssuesApi';
import { getEstadoItemInfo, formatearFechaOI, esImagenOI, iniciales } from '../../Utils/openIssues';
import ModalEditarItemOpenIssue from './ModalEditarItemOpenIssue';

const { TextArea } = Input;

// Fallback si el backend todavia no manda catalogos.max_items (ver OpenIssueController::catalogos).
const MAX_ITEMS_FALLBACK = 50;

const ItemsOpenIssue = ({ issue, catalogos, flags, onIssueActualizado, onSolicitarCierre, cerrandoIssue = false }) => {
    const items = issue?.items || [];
    const progreso = issue?.progreso || {
        total: 0, hechos: 0, descartados: 0, pendientes: 0, en_progreso: 0, porcentaje: 0, completo: false,
    };
    const puedeGestionar = !!flags?.puede_gestionar_items;
    const maxItems = Number(catalogos?.max_items) || MAX_ITEMS_FALLBACK;

    const [expandidos, setExpandidos] = useState({});
    const [comentarios, setComentarios] = useState({});
    const [enviandoComentario, setEnviandoComentario] = useState({});
    const [estadoEnCurso, setEstadoEnCurso] = useState({});
    const [itemEditando, setItemEditando] = useState(null);
    const [agregandoItem, setAgregandoItem] = useState(false);
    const [nuevoItemTitulo, setNuevoItemTitulo] = useState('');
    const [guardandoNuevoItem, setGuardandoNuevoItem] = useState(false);

    // El modal de detalle puede cambiar de issue SIN desmontarse (deep-link de
    // la campana estando ya abierto): el estado por-item (expandidos, borradores
    // de comentario, alta inline) es de OTRO issue y hay que descartarlo.
    const issueIdActual = issue?.id;
    useEffect(() => {
        setExpandidos({});
        setComentarios({});
        setEnviandoComentario({});
        setEstadoEnCurso({});
        setItemEditando(null);
        setAgregandoItem(false);
        setNuevoItemTitulo('');
    }, [issueIdActual]);

    const toggleExpandido = (itemId) => {
        setExpandidos((prev) => ({ ...prev, [itemId]: !prev[itemId] }));
    };

    const comentarioDe = (itemId) => comentarios[itemId] || { texto: '', archivo: null };

    const setComentarioTexto = (itemId, texto) => {
        setComentarios((prev) => ({ ...prev, [itemId]: { ...comentarioDe(itemId), texto } }));
    };

    const setComentarioArchivo = (itemId, archivo) => {
        setComentarios((prev) => ({ ...prev, [itemId]: { ...comentarioDe(itemId), archivo } }));
    };

    const handleCambiarEstado = (item, nuevoEstado) => {
        if (nuevoEstado === item.estado) return;

        const infoNuevo = getEstadoItemInfo(catalogos?.item_estados, nuevoEstado);
        let texto = '';

        Modal.confirm({
            title: `Marcar item como "${infoNuevo.label}"`,
            content: (
                <TextArea
                    rows={3}
                    maxLength={4000}
                    placeholder="Comentario opcional"
                    onChange={(e) => { texto = e.target.value; }}
                />
            ),
            okText: 'Confirmar',
            cancelText: 'Cancelar',
            onOk: async () => {
                setEstadoEnCurso((prev) => ({ ...prev, [item.id]: true }));
                const { ok, data, error } = await cambiarEstadoItemOpenIssue(issue.id, item.id, {
                    estado: nuevoEstado,
                    texto: texto.trim() || undefined,
                });
                setEstadoEnCurso((prev) => ({ ...prev, [item.id]: false }));
                if (ok) {
                    message.success('Estado del item actualizado.');
                    onIssueActualizado?.(data);
                } else {
                    message.error(error || 'No se pudo cambiar el estado del item.');
                }
            },
        });
    };

    const handlePublicarComentario = async (item) => {
        const { texto, archivo } = comentarioDe(item.id);
        if (!texto.trim() && !archivo) {
            message.error('Escribí un comentario o adjuntá un archivo.');
            return;
        }

        setEnviandoComentario((prev) => ({ ...prev, [item.id]: true }));
        const { ok, data, error } = await agregarActualizacionOpenIssue(
            issue.id,
            buildOpenIssueFormData({ texto: texto.trim() || null, item_id: item.id }, archivo?.originFileObj || null)
        );
        setEnviandoComentario((prev) => ({ ...prev, [item.id]: false }));

        if (ok) {
            message.success('Comentario publicado.');
            setComentarios((prev) => ({ ...prev, [item.id]: { texto: '', archivo: null } }));
            onIssueActualizado?.(data.issue);
        } else {
            message.error(error || 'No se pudo publicar el comentario.');
        }
    };

    const handleAgregarItemInline = async () => {
        // Guard contra doble submit: el botón ya se deshabilita, pero el Enter
        // del input entra por acá y dos Enter rápidos creaban el item duplicado.
        if (guardandoNuevoItem) return;
        const titulo = nuevoItemTitulo.trim();
        if (!titulo) {
            message.error('El título del item es obligatorio.');
            return;
        }
        if (items.length >= maxItems) {
            message.warning(`Máximo ${maxItems} items por issue.`);
            return;
        }

        setGuardandoNuevoItem(true);
        const { ok, data, error } = await agregarItemsOpenIssue(issue.id, [{ titulo }]);
        setGuardandoNuevoItem(false);

        if (ok) {
            message.success('Item agregado.');
            setNuevoItemTitulo('');
            onIssueActualizado?.(data);
        } else {
            message.error(error || 'No se pudo agregar el item.');
        }
    };

    return (
        <div className="oi-card oi-item-card">
            <div className="oi-item-header">
                <h3 className="hhee-seccion-titulo">Items</h3>
                <div className="oi-item-header__progreso">
                    <span className="oi-item-header__contador">{`${progreso.hechos}/${progreso.total}`}</span>
                    <Progress percent={progreso.porcentaje} size="small" className="oi-item-header__barra" />
                </div>
            </div>

            {progreso.completo && flags?.puede_cerrar && (
                <Alert
                    type="success"
                    showIcon
                    className="hhee-alert-estado"
                    message="Todos los items están hechos. Podés cerrar el issue."
                    action={(
                        <button type="button" className="primary-action" onClick={onSolicitarCierre} disabled={cerrandoIssue}>
                            Cerrar issue
                        </button>
                    )}
                />
            )}

            {items.length === 0 && (
                <Empty description="Este issue no tiene items. Agregá uno para dividir el trabajo en puntos." />
            )}

            {items.length > 0 && (
                <div className="oi-item-lista">
                    {items.map((item) => {
                        const estadoInfo = getEstadoItemInfo(catalogos?.item_estados, item.estado);
                        const resuelto = item.estado === 'hecho' || item.estado === 'descartado';
                        const expandido = !!expandidos[item.id];
                        const actualizacionesItem = (issue.actualizaciones || [])
                            .filter((a) => Number(a.item_id) === Number(item.id));
                        const comentario = comentarioDe(item.id);
                        const propsUploadItem = {
                            maxCount: 1,
                            beforeUpload: () => false,
                            fileList: comentario.archivo ? [comentario.archivo] : [],
                            onChange: ({ fileList }) => setComentarioArchivo(item.id, fileList[0] || null),
                            onRemove: () => setComentarioArchivo(item.id, null),
                        };

                        return (
                            <div className="oi-item-fila" key={item.id}>
                                <div className="oi-item-fila__principal">
                                    <div className="oi-item-fila__estado">
                                        {puedeGestionar ? (
                                            <Select
                                                size="small"
                                                value={item.estado}
                                                onChange={(v) => handleCambiarEstado(item, v)}
                                                loading={!!estadoEnCurso[item.id]}
                                                disabled={!!estadoEnCurso[item.id]}
                                                options={(catalogos?.item_estados || []).map((e) => ({ value: e.value, label: e.label }))}
                                                className="oi-item-fila__select-estado"
                                                aria-label={`Cambiar estado del item ${item.titulo}`}
                                            />
                                        ) : (
                                            <Tag color={estadoInfo.color}>{estadoInfo.label}</Tag>
                                        )}
                                    </div>

                                    <div className="oi-item-fila__info">
                                        <p className={`oi-item-fila__titulo${resuelto ? ' oi-item-fila__titulo--resuelto' : ''}`}>
                                            {item.titulo}
                                        </p>
                                        {item.detalle && <p className="oi-item-fila__detalle">{item.detalle}</p>}
                                    </div>

                                    <div className="oi-item-fila__responsable">
                                        {item.responsable ? (
                                            <span className="oi-chip">
                                                <span className="oi-chip__avatar">{iniciales(item.responsable.name)}</span>
                                                {item.responsable.name}
                                            </span>
                                        ) : (
                                            <span className="oi-item-sin-responsable">Sin responsable</span>
                                        )}
                                    </div>

                                    <div className="oi-item-fila__acciones">
                                        <button
                                            type="button"
                                            className="secondary-action oi-item-btn-chico"
                                            onClick={() => toggleExpandido(item.id)}
                                        >
                                            {`Ver / comentar (${item.actualizaciones_count || 0})`}
                                        </button>
                                        {puedeGestionar && (
                                            <button
                                                type="button"
                                                className="secondary-action oi-item-btn-chico"
                                                onClick={() => setItemEditando(item)}
                                                aria-label={`Editar item ${item.titulo}`}
                                            >
                                                Editar
                                            </button>
                                        )}
                                    </div>
                                </div>

                                {expandido && (
                                    <div className="oi-item-expandido">
                                        {actualizacionesItem.length === 0 ? (
                                            <p className="oi-item-sin-actividad">Sin comentarios en este item todavía.</p>
                                        ) : (
                                            <ul className="oi-item-comentarios">
                                                {actualizacionesItem.map((a) => (
                                                    <li key={a.id} className="oi-item-comentario">
                                                        <p className="oi-timeline-autor">
                                                            {a.autor?.name || 'Usuario'} <Tag>{a.tipo_label}</Tag>
                                                        </p>
                                                        <small className="oi-timeline-fecha">{formatearFechaOI(a.created_at)}</small>
                                                        {(a.estado_anterior || a.estado_nuevo) && (
                                                            <div className="oi-item-comentario__estados">
                                                                {a.estado_anterior && (
                                                                    <Tag color={getEstadoItemInfo(catalogos?.item_estados, a.estado_anterior).color}>
                                                                        {getEstadoItemInfo(catalogos?.item_estados, a.estado_anterior).label}
                                                                    </Tag>
                                                                )}
                                                                {a.estado_anterior && a.estado_nuevo && ' → '}
                                                                {a.estado_nuevo && (
                                                                    <Tag color={getEstadoItemInfo(catalogos?.item_estados, a.estado_nuevo).color}>
                                                                        {getEstadoItemInfo(catalogos?.item_estados, a.estado_nuevo).label}
                                                                    </Tag>
                                                                )}
                                                            </div>
                                                        )}
                                                        {a.texto && <p className="oi-timeline-texto">{a.texto}</p>}
                                                        {a.archivo_url && esImagenOI(a.mime_type) && (
                                                            <div className="oi-adjunto-imagen">
                                                                <Image
                                                                    src={a.archivo_url}
                                                                    alt={a.archivo_nombre || 'adjunto'}
                                                                    width={120}
                                                                    height={90}
                                                                    style={{ objectFit: 'cover', borderRadius: 8 }}
                                                                    preview={{ src: a.archivo_url }}
                                                                />
                                                            </div>
                                                        )}
                                                        {a.archivo_url && !esImagenOI(a.mime_type) && (
                                                            <a
                                                                className="hhee-adjunto-link"
                                                                href={a.archivo_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                {a.archivo_nombre || 'Ver adjunto'}
                                                            </a>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}

                                        {puedeGestionar && (
                                            <div className="oi-item-comentario-caja">
                                                <TextArea
                                                    rows={2}
                                                    maxLength={4000}
                                                    value={comentario.texto}
                                                    onChange={(e) => setComentarioTexto(item.id, e.target.value)}
                                                    placeholder="Comentar este item..."
                                                    aria-label={`Comentario para el item ${item.titulo}`}
                                                />
                                                <div className="oi-item-comentario-caja__acciones">
                                                    <Upload {...propsUploadItem}>
                                                        <button type="button" className="secondary-action oi-item-btn-chico">
                                                            <UploadOutlined /> Adjuntar
                                                        </button>
                                                    </Upload>
                                                    <button
                                                        type="button"
                                                        className="primary-action oi-item-btn-chico"
                                                        onClick={() => handlePublicarComentario(item)}
                                                        disabled={!!enviandoComentario[item.id] || (!comentario.texto.trim() && !comentario.archivo)}
                                                    >
                                                        {enviandoComentario[item.id] ? 'Publicando...' : 'Publicar'}
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            {puedeGestionar && (
                <div className="oi-item-agregar">
                    {agregandoItem ? (
                        <div className="oi-item-agregar__form">
                            <Input
                                value={nuevoItemTitulo}
                                onChange={(e) => setNuevoItemTitulo(e.target.value)}
                                onPressEnter={handleAgregarItemInline}
                                placeholder="Título del nuevo item"
                                maxLength={300}
                                aria-label="Título del nuevo item"
                                autoFocus
                            />
                            <button
                                type="button"
                                className="primary-action"
                                onClick={handleAgregarItemInline}
                                disabled={guardandoNuevoItem}
                            >
                                {guardandoNuevoItem ? 'Agregando...' : 'Agregar'}
                            </button>
                            <button
                                type="button"
                                className="secondary-action"
                                onClick={() => { setAgregandoItem(false); setNuevoItemTitulo(''); }}
                            >
                                Cancelar
                            </button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            className="secondary-action"
                            onClick={() => setAgregandoItem(true)}
                            disabled={items.length >= maxItems}
                        >
                            <PlusOutlined /> Agregar item
                        </button>
                    )}
                </div>
            )}

            {itemEditando && (
                <ModalEditarItemOpenIssue
                    open={!!itemEditando}
                    issueId={issue.id}
                    item={itemEditando}
                    catalogos={catalogos}
                    onClose={() => setItemEditando(null)}
                    onGuardado={(nuevoDetalle) => {
                        setItemEditando(null);
                        onIssueActualizado?.(nuevoDetalle);
                    }}
                />
            )}
        </div>
    );
};

export default ItemsOpenIssue;
