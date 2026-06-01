import React, { useEffect, useState } from 'react';
import { Modal, Spin, Image, Typography, Divider, notification } from 'antd';

const APIURI = import.meta.env.VITE_API

const { Title } = Typography;

export default function ModalMostrarFotoFinalizada({ isOpen, setIsOpen, idOrden }) {
    const [fotoFinalizada, setFotoFinalizada] = useState(null);
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (isOpen && idOrden) {
            setIsLoading(true);

            fetch(`${APIURI}ordenes-trabajo/${idOrden}/foto-finalizada`, {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                    Accept: 'application/json',
                },
            })
                .then((response) => response.json())
                .then((data) => {
                    console.log('Respuesta de la API:', data);
                    if (data.foto_finalizada) {
                        setFotoFinalizada(data.foto_finalizada);
                    } else {
                        notification.warning({
                            message: 'Sin Foto',
                            description: 'No se encontró ninguna foto finalizada para esta orden.',
                        });
                    }
                })
                .catch((error) => {
                    console.error('Error al cargar la foto finalizada:', error);
                    notification.error({
                        message: 'Error',
                        description: 'No se pudo cargar la foto finalizada.',
                    });
                })
                .finally(() => setIsLoading(false));
        }
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
            {/* Spinner de carga */}
            {isLoading ? (
                <div className="flex items-center justify-center mt-10">
                    <Spin size="large" />
                </div>
            ) : fotoFinalizada ? (
                <div className="text-center">
                    <Divider style={{ marginBottom: 16 }} />
                    {/* Muestra la foto finalizada */}
                    <Image
                        src={fotoFinalizada}
                        alt="Foto Finalizada"
                        style={{
                            maxWidth: '100%',
                            maxHeight: '400px',
                            borderRadius: '8px',
                            boxShadow: '0 4px 8px rgba(0, 0, 0, 0.1)',
                        }}
                        preview={true}
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
