import React, { useEffect, useState } from 'react';
import { Modal, Button, notification, Spin, Image, Upload, Divider, Typography } from 'antd';
import { InboxOutlined } from '@ant-design/icons';

const APIURI = import.meta.env.VITE_API

const { Dragger } = Upload;
const { Title } = Typography;

const iconMap = {
    pdf: '/iconpdf.png',
    word: '/iconword.png',
    excel: '/iconexcel.png',
    image: '/icongaleria.png',
    /* default: '/file.png', */
};

export default function ModalDescripcion({ isOpen, setIsOpen, idOrden, usuarioLogueado }) {

    const [descripciones, setDescripciones] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [subiendoArchivos, setSubiendoArchivos] = useState(false);


    const [usuarioCreadorId, setUsuarioCreadorId] = useState(null);

    useEffect(() => {
        if (idOrden) {
            setIsLoading(true);

            fetch(`${APIURI}ordenes-trabajo/${idOrden}/descripciones`, {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                    Accept: 'application/json',
                },
            })
                .then((response) => response.json())
                .then((data) => {
                    setDescripciones(data);
                    if (data.length > 0) setUsuarioCreadorId(data[0].usuario_creador_id);
                    setIsLoading(false);
                })
                .catch((error) => {
                    console.error('Error fetching data:', error);
                    setIsLoading(false);
                    notification.error({
                        message: 'Error',
                        description: 'No se pudieron cargar las descripciones.',
                    });
                });
        }
    }, [isOpen]);


    const handleOk = () => setIsOpen(false);
    const handleCancel = () => setIsOpen(false);

    const getFileIcon = (archivo) => {
        const extension = archivo.split('.').pop().toLowerCase();

        if (extension === 'pdf') return iconMap.pdf;
        if (extension === 'doc' || extension === 'docx') return iconMap.word;
        if (extension === 'xls' || extension === 'xlsx') return iconMap.excel;
        if (['jpg', 'jpeg', 'png', 'jfif'].includes(extension)) return iconMap.image;

        return iconMap.default;
    };

    const fotos = descripciones.filter((desc) =>
        desc.archivo &&
        ['jpg', 'jpeg', 'png'].includes(desc.archivo.split('.').pop().toLowerCase())
    );

    const archivos = descripciones.filter(
        (desc) =>
            desc.archivo &&
            !['jpg', 'jpeg', 'png'].includes(desc.archivo.split('.').pop().toLowerCase())
    );


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
                        {fotos.map((desc, index) => (
                            <Image
                                key={index}
                                src={desc.archivo}
                                width={150}
                                height={150}
                                style={{
                                    objectFit: 'cover',
                                    borderRadius: '8px',
                                    boxShadow: '0 4px 8px rgba(0, 0, 0, 0.1)',
                                    padding: '2px'
                                }}
                                preview={{ visible: true }}
                            />
                        ))}
                    </Image.PreviewGroup>
                </div>
            )}


            {archivos.length > 0 && (
                <div className="mt-4">
                    <Title level={4} style={{ fontSize: '18px', padding: 0, marginBottom: 4 }}>Archivos</Title>
                    <Divider style={{ marginBottom: 8 }} />
                    {archivos.map((desc) => (
                        <div key={desc.id} className="mb-3 flex items-center">
                            <img
                                src={getFileIcon(desc.archivo)}
                                alt="Archivo"
                                style={{ width: 24, height: 24, marginRight: 8 }}
                            />
                            <Button
                                type="link"
                                onClick={() => {
                                    const archivo = desc.archivo;
                                    const extension = archivo.split('.').pop().toLowerCase();

                                    if (['jpg', 'jpeg', 'png'].includes(extension)) {
                                        const link = document.createElement('a');
                                        link.href = archivo;
                                        link.download = archivo.split('/').pop();
                                        link.click();
                                    } else if (extension === 'pdf') {
                                        window.open(archivo, '_blank'); // Abrir PDF en nueva pestaña
                                    } else {
                                        window.open(archivo, '_blank');
                                    }
                                }}
                                style={{ fontSize: '14px' }}
                            >
                                {desc.archivo.split('/').pop()}
                            </Button>
                        </div>
                    ))}
                </div>
            )}
            {usuarioLogueado?.id === usuarioCreadorId && (
                <Button
                    type="primary"
                    className="mt-4"
                    onClick={() => setEditarOpen(true)}
                >
                    Editar Descripción
                </Button>
            )}

        </Modal>
    );
}
