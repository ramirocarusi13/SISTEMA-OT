import React, { useEffect, useState } from 'react';
import { Modal, Button, notification, Spin, Image, Divider, Typography } from 'antd';

const APIURI = import.meta.env.VITE_API

const { Title } = Typography;
const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'jfif'];

const iconMap = {
    pdf: '/iconpdf.png',
    word: '/iconword.png',
    excel: '/iconexcel.png',
    image: '/icongaleria.png',
    default: '/icongaleria.png',
};

export default function ModalDescripcion({ isOpen, setIsOpen, idOrden }) {

    const [descripciones, setDescripciones] = useState([]);
    const [isLoading, setIsLoading] = useState(false);

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

    const safeDecode = (value) => {
        try {
            return decodeURIComponent(value);
        } catch {
            return value;
        }
    };

    const getArchivoUrl = (desc) => desc?.archivo_url || desc?.archivo || '';

    const getArchivoNombre = (desc) => {
        const archivo = desc?.archivo_nombre || getArchivoUrl(desc).split('/').pop() || 'archivo';

        return safeDecode(archivo);
    };

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

    const fotos = descripciones.filter((desc) => getArchivoUrl(desc) && isImageFile(desc));

    const archivos = descripciones.filter((desc) => getArchivoUrl(desc) && !isImageFile(desc));


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
                        {fotos.map((desc) => {
                            const archivoUrl = getArchivoUrl(desc);

                            return (
                                <Image
                                    key={desc.id || archivoUrl}
                                    src={archivoUrl}
                                    alt={getArchivoNombre(desc)}
                                    width={150}
                                    height={150}
                                    fallback={iconMap.image}
                                    style={{
                                        objectFit: 'cover',
                                        borderRadius: '8px',
                                        boxShadow: '0 4px 8px rgba(0, 0, 0, 0.1)',
                                        padding: '2px'
                                    }}
                                    preview={{ src: archivoUrl }}
                                />
                            );
                        })}
                    </Image.PreviewGroup>
                </div>
            )}


            {archivos.length > 0 && (
                <div className="mt-4">
                    <Title level={4} style={{ fontSize: '18px', padding: 0, marginBottom: 4 }}>Archivos</Title>
                    <Divider style={{ marginBottom: 8 }} />
                    {archivos.map((desc) => {
                        const archivoUrl = getArchivoUrl(desc);
                        const archivoNombre = getArchivoNombre(desc);

                        return (
                            <div key={desc.id || archivoUrl} className="mb-3 flex items-center">
                                <img
                                    src={getFileIcon(desc)}
                                    alt="Archivo"
                                    style={{ width: 24, height: 24, marginRight: 8 }}
                                />
                                <Button
                                    type="link"
                                    href={archivoUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
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
