import React, { useEffect, useState } from 'react';
import { Modal, Button, notification, Spin, Image, Divider, Typography } from 'antd';

const APIURI = import.meta.env.VITE_API

const { Title } = Typography;
const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'jfif'];
const apiBaseUrl = new URL(APIURI, window.location.origin);

const iconMap = {
    pdf: '/iconpdf.png',
    word: '/iconword.png',
    excel: '/iconexcel.png',
    image: '/icongaleria.png',
    default: '/icongaleria.png',
};

const imageTileStyle = {
    width: 150,
    height: 150,
    borderRadius: '8px',
    boxShadow: '0 4px 8px rgba(0, 0, 0, 0.1)',
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

    if (!archivo) {
        return 'archivo';
    }

    try {
        if (/^https?:\/\//i.test(archivo)) {
            archivo = new URL(archivo).pathname;
        }
    } catch {
        // Mantiene el valor original si no es una URL válida.
    }

    archivo = archivo
        .replace(/\\/g, '/')
        .split('?')[0]
        .split('#')[0]
        .replace(/^(\/)?(public\/)?storage\/archivos\//, '');

    const nombre = archivo.split('/').filter(Boolean).pop() || archivo;

    return safeDecode(nombre || 'archivo');
};

const getApiArchivoUrl = (desc) =>
    new URL(`archivos/${encodeURIComponent(getArchivoNombre(desc))}`, apiBaseUrl.href).toString();

function AuthenticatedImage({ desc }) {
    const [src, setSrc] = useState('');
    const [error, setError] = useState('');
    const archivoNombre = getArchivoNombre(desc);
    const archivoUrl = getApiArchivoUrl(desc);

    useEffect(() => {
        let cancelled = false;
        let objectUrl = '';

        setSrc('');
        setError('');

        fetch(archivoUrl, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
                Accept: 'image/*',
            },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.blob();
            })
            .then((blob) => {
                objectUrl = URL.createObjectURL(blob);

                if (cancelled) {
                    URL.revokeObjectURL(objectUrl);
                    return;
                }

                setSrc(objectUrl);
            })
            .catch((err) => {
                if (!cancelled) {
                    setError(err.message || 'No se pudo cargar');
                }
            });

        return () => {
            cancelled = true;

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        };
    }, [archivoUrl]);

    if (error) {
        return (
            <div
                title={`${archivoNombre} - ${error}`}
                style={{
                    ...imageTileStyle,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: 8,
                    border: '1px solid #d9d9d9',
                    color: '#8c8c8c',
                    fontSize: 12,
                    textAlign: 'center',
                }}
            >
                No se pudo cargar
            </div>
        );
    }

    if (!src) {
        return (
            <div
                style={{
                    ...imageTileStyle,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                }}
            >
                <Spin size="small" />
            </div>
        );
    }

    return (
        <Image
            src={src}
            alt={archivoNombre}
            width={150}
            height={150}
            style={{
                objectFit: 'cover',
                borderRadius: '8px',
                boxShadow: '0 4px 8px rgba(0, 0, 0, 0.1)',
                padding: '2px',
            }}
            preview={{ src }}
        />
    );
}

export default function ModalDescripcion({ isOpen, setIsOpen, idOrden }) {

    const [descripciones, setDescripciones] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [archivoAbriendoId, setArchivoAbriendoId] = useState(null);

    useEffect(() => {
        if (!isOpen || !idOrden) {
            return;
        }

        setIsLoading(true);

        fetch(`${APIURI}ordenes-trabajo/${idOrden}/descripciones`, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
                Accept: 'application/json',
            },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('No se pudieron cargar las descripciones.');
                }

                return response.json();
            })
            .then((data) => {
                setDescripciones(Array.isArray(data) ? data : []);
            })
            .catch((error) => {
                console.error('Error fetching data:', error);
                notification.error({
                    message: 'Error',
                    description: 'No se pudieron cargar las descripciones.',
                });
            })
            .finally(() => setIsLoading(false));
    }, [isOpen, idOrden]);

    const handleOk = () => setIsOpen(false);
    const handleCancel = () => setIsOpen(false);

    const getFileExtension = (desc) => {
        const cleanName = getArchivoNombre(desc).split('?')[0].split('#')[0];

        return cleanName.includes('.') ? cleanName.split('.').pop().toLowerCase() : '';
    };

    const isImageFile = (desc) => {
        const mimeType = desc?.mime_type || '';

        return mimeType.startsWith('image/') || imageExtensions.includes(getFileExtension(desc));
    };

    const getFileIcon = (desc) => {
        const extension = getFileExtension(desc);

        if (extension === 'pdf') return iconMap.pdf;
        if (extension === 'doc' || extension === 'docx') return iconMap.word;
        if (extension === 'xls' || extension === 'xlsx') return iconMap.excel;
        if (imageExtensions.includes(extension)) return iconMap.image;

        return iconMap.default;
    };

    const hasArchivo = (desc) => Boolean(String(getRawArchivoValue(desc)).trim());

    const abrirArchivo = async (desc) => {
        const archivoNombre = getArchivoNombre(desc);
        const archivoKey = desc.id || archivoNombre;

        try {
            setArchivoAbriendoId(archivoKey);

            const response = await fetch(getApiArchivoUrl(desc), {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const opened = window.open(objectUrl, '_blank', 'noopener,noreferrer');

            if (!opened) {
                const link = document.createElement('a');
                link.href = objectUrl;
                link.download = archivoNombre;
                link.click();
            }

            setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
        } catch (error) {
            console.error('Error abriendo archivo:', error);
            notification.error({
                message: 'Error',
                description: `No se pudo abrir ${archivoNombre}.`,
            });
        } finally {
            setArchivoAbriendoId(null);
        }
    };

    const fotos = descripciones.filter((desc) => hasArchivo(desc) && isImageFile(desc));

    const archivos = descripciones.filter((desc) => hasArchivo(desc) && !isImageFile(desc));


    return (
        <Modal className='overflow-auto'
            title={`Descripciones de la Orden #${idOrden}`}
            open={isOpen}
            onOk={handleOk}
            onCancel={handleCancel}
            width={800}
        >
            {/* Spinner de carga */}
            {isLoading && (
                <div className="flex items-center justify-center mt-10">
                    <Spin />
                </div>
            )}

            {/* Descripción Principal */}
            {descripciones.length > 0 && (
                <div className="detail-panel">
                    <p>
                        <strong>Descripción:</strong> {descripciones[0].descripcion}
                    </p>
                </div>
            )}

            {fotos.length > 0 && (
                <div className="mt-4 ">
                    <Title level={4} style={{ fontSize: '18px', padding: 0, marginBottom: 4 }}>Fotos</Title>
                    <Divider style={{ marginBottom: 8 }} />
                    <Image.PreviewGroup >
                        {fotos.map((desc) => (
                            <AuthenticatedImage
                                key={desc.id || getArchivoNombre(desc)}
                                desc={desc}
                            />
                        ))}
                    </Image.PreviewGroup>
                </div>
            )}


            {archivos.length > 0 && (
                <div className="mt-4">
                    <Title level={4} style={{ fontSize: '18px', padding: 0, marginBottom: 4 }}>Archivos</Title>
                    <Divider style={{ marginBottom: 8 }} />
                    {archivos.map((desc) => {
                        const archivoNombre = getArchivoNombre(desc);
                        const archivoKey = desc.id || archivoNombre;

                        return (
                            <div key={archivoKey} className="mb-3 flex items-center">
                                <img
                                    src={getFileIcon(desc)}
                                    alt="Archivo"
                                    style={{ width: 24, height: 24, marginRight: 8 }}
                                />
                                <Button
                                    type="link"
                                    loading={archivoAbriendoId === archivoKey}
                                    onClick={() => abrirArchivo(desc)}
                                    style={{ fontSize: '14px', paddingInline: 0, whiteSpace: 'normal', textAlign: 'left' }}
                                >
                                    {archivoNombre}
                                </Button>
                            </div>
                        );
                    })}
                </div>
            )}

        </Modal>
    );
}
