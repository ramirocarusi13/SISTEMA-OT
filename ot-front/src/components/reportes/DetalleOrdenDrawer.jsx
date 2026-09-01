// Drawer de detalle de una OT, para el botón "Detalle" de la tabla "Listado
// de OTs" de pages/Reportes.jsx. La cabecera se arma con los datos que YA
// trae cada fila de fetchOrdenesTrabajo (Utils/otApi.js) — no hace falta un
// fetch extra para eso. Las "descripciones de avance" (texto + adjuntos) se
// piden aparte a GET /api/ordenes-trabajo/{id}/descripciones (mismo endpoint
// que components/ModalDescripcion.jsx, que usa OrdenTrabajoList y que NO se
// toca acá).
//
// El manejo de archivos adjuntos (nombre, URL pública, si es imagen, ícono
// por tipo, fallback de carga) es un espejo intencional de
// components/ModalDescripcion.jsx: mismo patrón, sin inventar uno nuevo, pero
// duplicado en vez de importado para no modificar ese componente (usado por
// el módulo de OT, fuera de alcance de este cambio).
import React, { useCallback, useEffect, useState } from 'react';
import { Drawer, Tag, Image, Spin, Empty, Alert, Button } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import moment from 'moment';
import { fetchDescripcionesOrden } from '../../Utils/otApi';
import { getPrioridadInfo } from '../../Utils/prioridad';

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'jfif'];
const ICON_MAP = {
    pdf: '/iconpdf.png',
    word: '/iconword.png',
    excel: '/iconexcel.png',
    default: '/icongaleria.png',
};

const safeDecode = (value) => {
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
};

const getRawArchivoValue = (desc) => desc?.archivo_nombre || desc?.archivo_url || desc?.archivo || '';

const getArchivoNombre = (desc) => {
    let archivo = String(getRawArchivoValue(desc)).trim();
    if (!archivo) return 'archivo';

    try {
        if (/^https?:\/\//i.test(archivo)) {
            archivo = new URL(archivo).pathname;
        }
    } catch {
        // Se mantiene el valor original si no es una URL válida.
    }

    archivo = archivo
        .replace(/\\/g, '/')
        .split('?')[0]
        .split('#')[0]
        .replace(/^(\/)?(public\/)?storage\/archivos\//, '');

    const nombre = archivo.split('/').filter(Boolean).pop() || archivo;
    return safeDecode(nombre || 'archivo');
};

const getPublicArchivoUrl = (desc) =>
    new URL(`/storage/archivos/${encodeURIComponent(getArchivoNombre(desc))}`, window.location.origin).toString();

const getFileExtension = (desc) => {
    const cleanName = getArchivoNombre(desc).split('?')[0].split('#')[0];
    return cleanName.includes('.') ? cleanName.split('.').pop().toLowerCase() : '';
};

const isImageFile = (desc) => {
    const mimeType = desc?.mime_type || '';
    return mimeType.startsWith('image/') || IMAGE_EXTENSIONS.includes(getFileExtension(desc));
};

const getFileIcon = (desc) => {
    const extension = getFileExtension(desc);
    if (extension === 'pdf') return ICON_MAP.pdf;
    if (extension === 'doc' || extension === 'docx') return ICON_MAP.word;
    if (extension === 'xls' || extension === 'xlsx') return ICON_MAP.excel;
    return ICON_MAP.default;
};

const hasArchivo = (desc) => Boolean(String(getRawArchivoValue(desc)).trim());

function AdjuntoImagen({ desc }) {
    const [error, setError] = useState('');
    const archivoNombre = getArchivoNombre(desc);
    const archivoUrl = getPublicArchivoUrl(desc);

    useEffect(() => {
        setError('');
        const img = document.createElement('img');
        img.onload = () => setError('');
        img.onerror = () => setError('No se pudo cargar');
        img.src = archivoUrl;
    }, [archivoUrl]);

    if (error) {
        return (
            <div className="hhee-adjunto-error" title={`${archivoNombre} - ${error}`}>
                {error}
            </div>
        );
    }

    return (
        <Image
            src={archivoUrl}
            alt={archivoNombre}
            width={96}
            height={96}
            style={{ objectFit: 'cover', borderRadius: 10 }}
            preview={{ src: archivoUrl }}
        />
    );
}

const AdjuntoArchivo = ({ desc }) => {
    const archivoNombre = getArchivoNombre(desc);
    const archivoUrl = getPublicArchivoUrl(desc);

    return (
        <a className="hhee-adjunto-link" href={archivoUrl} target="_blank" rel="noopener noreferrer">
            <img src={getFileIcon(desc)} alt="" aria-hidden="true" />
            {archivoNombre}
        </a>
    );
};

const DetalleOrdenDrawer = ({ open, orden, onClose, labelsPorPrioridad = {}, coloresPorPrioridad = {}, categoriasLabels = {}, estadosLabel = {} }) => {
    const [descripciones, setDescripciones] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const cargar = useCallback(async () => {
        if (!orden?.id) return;
        setLoading(true);
        setError(null);
        const { ok, data, error: err } = await fetchDescripcionesOrden(orden.id);
        if (ok) {
            setDescripciones(Array.isArray(data) ? data : []);
        } else {
            setError(err || 'No se pudieron cargar las descripciones.');
        }
        setLoading(false);
    }, [orden?.id]);

    useEffect(() => {
        if (open && orden?.id) {
            cargar();
        } else {
            setDescripciones([]);
            setError(null);
        }
    }, [open, orden?.id, cargar]);

    const prioridadInfo = orden
        ? getPrioridadInfo(
            Object.entries(labelsPorPrioridad).map(([value, label]) => ({ value, label, color: coloresPorPrioridad[value] })),
            orden.prioridad
        )
        : null;

    return (
        <Drawer
            title={orden ? `OT N° ${orden.id} — ${orden.titulo}` : 'Detalle de la OT'}
            placement="right"
            width={480}
            open={open}
            onClose={onClose}
            destroyOnClose
        >
            {orden && (
                <>
                    <div className="hhee-card">
                        <div className="hhee-detalle-header hhee-detalle-header--2col">
                            <div>
                                <p className="hhee-detalle-label">Categoría</p>
                                <p className="hhee-detalle-valor">{categoriasLabels[orden.categoria] || orden.categoria || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Prioridad</p>
                                <p className="hhee-detalle-valor">
                                    <Tag color={prioridadInfo?.color?.tag || 'default'}>
                                        {(prioridadInfo?.label || orden.prioridad || 'Media').toUpperCase()}
                                    </Tag>
                                    {orden.es_seguridad && <Tag color="red" className="hhee-tag-inline">SEGURIDAD</Tag>}
                                </p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Estado</p>
                                <p className="hhee-detalle-valor">{estadosLabel[orden.estado] || orden.estado || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Departamento</p>
                                <p className="hhee-detalle-valor">{orden.departamento_creador || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Creada por</p>
                                <p className="hhee-detalle-valor">{orden.usuario_creador || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Asignada a</p>
                                <p className="hhee-detalle-valor">{orden.usuario_mantenimiento || '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Creada</p>
                                <p className="hhee-detalle-valor">{orden.created_at ? moment(orden.created_at).format('DD/MM/YYYY HH:mm') : '—'}</p>
                            </div>
                            <div>
                                <p className="hhee-detalle-label">Finalizada</p>
                                <p className="hhee-detalle-valor">{orden.fecha_finalizacion ? moment(orden.fecha_finalizacion).format('DD/MM/YYYY HH:mm') : '—'}</p>
                            </div>
                        </div>

                        {orden.descripcion && (
                            <div className="hhee-detalle-descripcion">
                                <p className="hhee-detalle-label">Descripción</p>
                                <p className="hhee-detalle-valor">{orden.descripcion}</p>
                            </div>
                        )}
                    </div>

                    <h3 className="hhee-seccion-titulo hhee-seccion-titulo--espaciada">Descripciones de avance</h3>

                    {loading && (
                        <div className="hhee-modal-loading"><Spin size="large" /></div>
                    )}

                    {!loading && error && (
                        <Alert
                            type="error"
                            showIcon
                            message="No se pudo cargar la información"
                            description={error}
                            action={<Button size="small" danger icon={<ReloadOutlined />} onClick={cargar}>Reintentar</Button>}
                        />
                    )}

                    {!loading && !error && descripciones.length === 0 && (
                        <Empty description="Esta OT no tiene descripciones cargadas." />
                    )}

                    {!loading && !error && descripciones.length > 0 && (
                        <ul className="hhee-historial-list">
                            {descripciones.map((desc) => (
                                <li key={desc.id || getArchivoNombre(desc)}>
                                    {desc.titulo && <p className="hhee-detalle-valor">{desc.titulo}</p>}
                                    <p>{desc.descripcion}</p>
                                    {/* La API hoy no devuelve quién/cuándo cargó cada descripción
                                        (tabla 'descripciones' no tiene autor); si en algún momento
                                        se agrega, alcanza con sumar acá desc.usuario?.name / desc.created_at. */}
                                    {hasArchivo(desc) && (
                                        isImageFile(desc)
                                            ? <div className="hhee-adjunto-imagen"><AdjuntoImagen desc={desc} /></div>
                                            : <AdjuntoArchivo desc={desc} />
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}

            {!orden && <Empty description="Seleccioná una OT para ver su detalle." />}
        </Drawer>
    );
};

export default DetalleOrdenDrawer;
