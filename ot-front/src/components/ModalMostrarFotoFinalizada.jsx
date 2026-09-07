import React, { useEffect, useState } from 'react';
import { Modal, Spin, Image, Divider, notification } from 'antd';

const APIURI = import.meta.env.VITE_API;

const safeDecode = (value) => {
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
};

const getArchivoNombre = (value) => {
    let archivo = String(value || '').trim();
    let isUrl = false;

    if (!archivo) {
        return '';
    }

    try {
        if (/^https?:\/\//i.test(archivo)) {
            archivo = new URL(archivo).pathname;
            isUrl = true;
        }
    } catch {
        // Mantiene el valor original si no es una URL valida.
    }

    archivo = archivo.replace(/\\/g, '/');

    if (isUrl) {
        archivo = archivo.split('?')[0].split('#')[0];
    }

    archivo = archivo.replace(/^(\/)?(public\/)?storage\/archivos\//, '');

    return safeDecode(archivo.split('/').filter(Boolean).pop() || '');
};

const getPublicArchivoUrl = (archivoNombre) =>
    new URL(`/storage/archivos/${encodeURIComponent(archivoNombre)}`, window.location.origin).toString();

export default function ModalMostrarFotoFinalizada({ isOpen, setIsOpen, idOrden }) {
    const [fotoFinalizada, setFotoFinalizada] = useState(null);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (!isOpen || !idOrden) {
            return;
        }

        let cancelled = false;

        setIsLoading(true);
        setFotoFinalizada(null);

        fetch(`${APIURI}ordenes-trabajo/${idOrden}/foto-finalizada`, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
                Accept: 'application/json',
            },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json();
            })
            .then((data) => {
                const archivoNombre = getArchivoNombre(data.foto_finalizada_nombre || data.foto_finalizada);

                if (!archivoNombre) {
                    notification.warning({
                        message: 'Sin Foto',
                        description: 'No se encontro ninguna foto finalizada para esta orden.',
                    });
                    return null;
                }

                return getPublicArchivoUrl(archivoNombre);
            })
            .then((archivoUrl) => {
                if (!archivoUrl) {
                    return;
                }

                setFotoFinalizada(archivoUrl);
            })
            .catch((error) => {
                if (!cancelled) {
                    console.error('Error al cargar la foto finalizada:', error);
                    notification.error({
                        message: 'Error',
                        description: 'No se pudo cargar la foto finalizada.',
                    });
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setIsLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [isOpen, idOrden]);

    const handleClose = () => {
        setFotoFinalizada(null);
        setIsOpen(false);
    };

    return (
        <Modal
            title={`Foto Finalizada de la Orden #${idOrden}`}
            open={isOpen}
            onCancel={handleClose}
            footer={null}
            width={600}
        >
            {isLoading ? (
                <div className="flex items-center justify-center mt-10">
                    <Spin size="large" />
                </div>
            ) : fotoFinalizada ? (
                <div className="text-center">
                    <Divider style={{ marginBottom: 16 }} />
                    <Image
                        src={fotoFinalizada}
                        alt="Foto Finalizada"
                        style={{
                            maxWidth: '100%',
                            maxHeight: '400px',
                            borderRadius: '8px',
                            boxShadow: '0 4px 8px rgba(0, 0, 0, 0.1)',
                        }}
                        preview={{ src: fotoFinalizada }}
                    />
                </div>
            ) : (
                <div className="text-center">
                    <p>No hay una foto finalizada para mostrar.</p>
                </div>
            )}
        </Modal>
    );
}
