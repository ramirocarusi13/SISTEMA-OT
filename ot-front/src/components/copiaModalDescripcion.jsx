import React, { useEffect, useState } from 'react';
import { Modal, Input, Button, notification, Image, Spin } from 'antd';


const APIURI = import.meta.env.VITE_API

export default function ModalDescripcion({ isOpen, setIsOpen, idOrden }) {
    const [descripciones, setDescripciones] = useState([]);
    const [nuevaDescripcion, setNuevaDescripcion] = useState('');
    const [lightboxOpen, setLightboxOpen] = useState(false);
    const [currentImageIndex, setCurrentImageIndex] = useState(0);
    const [isLoading, setIsLoading] = useState(false)

    useEffect(() => {
        if (idOrden) {
            setIsLoading(true)
            fetch(`${APIURI}ordenes-trabajo/${idOrden}/descripciones`, {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                    'Accept': 'application/json',

                },
            })
                .then(response => response.json())
                .then(data => {
                    setDescripciones(data);
                    setIsLoading(false)
                })
                .catch(error => {
                    console.error('Error fetching data:', error)
                    setIsLoading(false)

                });
        }
    }, [idOrden]);

    const agregarDescripcion = async () => {
        if (!nuevaDescripcion.trim()) {
            notification.error({
                message: 'Error',
                description: 'La descripción no puede estar vacía.',
            });
            return;
        }

        const response = await fetch(`${APIURI}ordenes-trabajo/${idOrden}/descripciones`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${localStorage.getItem('token')}`,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ descripcion: nuevaDescripcion }),
        });

        if (response.ok) {
            const nuevaDesc = await response.json();
            setDescripciones((prev) => [...prev, nuevaDesc]);
            setNuevaDescripcion('');
        } else {
            notification.error({
                message: 'Error',
                description: 'Error al agregar la descripción. Por favor, inténtelo de nuevo.',
            });
        }
    };

    const handleOk = () => {
        setIsOpen(false);
    };

    const handleCancel = () => {
        setIsOpen(false);
    };

    // Agrupamos por orden y tomamos las fotos
    const descripcionUnica = descripciones.length > 0 ? descripciones[0] : null;
    const fotos = descripciones.map(desc => desc.foto).filter(foto => foto);

    const openLightbox = (index) => {
        setCurrentImageIndex(index);
        setLightboxOpen(true);
    };

    return (
        <Modal
            title={`Descripciones de la Orden #${idOrden}`}
            open={isOpen}
            onOk={handleOk}
            onCancel={handleCancel}
        >
            {/* <div className="mb-4">
                <Input.TextArea
                    value={nuevaDescripcion}
                    onChange={(e) => setNuevaDescripcion(e.target.value)}
                    placeholder="Ingrese una nueva descripción"
                    rows={4}
                />
                <Button type="primary" onClick={agregarDescripcion} style={{ marginTop: '10px' }}>
                    Agregar Descripción
                </Button>
            </div> */}

            {descripcionUnica && (
                <div>
                    {/* <h3>{descripcionUnica.titulo || 'Sin título'}</h3> */}
                    <p>{descripcionUnica.descripcion || 'No hay descripción disponible.'}</p>
                </div>
            )}

            {isLoading && <div className='flex items-center justify-center mt-10'>
                <Spin /></div>}
            <div className="flex gap-2 mt-4">


                <Image.PreviewGroup>
                    {(fotos.length > 0 && !isLoading) && (

                        fotos.map((foto, index) => (
                            <Image
                                key={index}
                                width={200}
                                src={foto}
                            />
                        ))
                    )}
                </Image.PreviewGroup>
            </div>

            {/* {lightboxOpen && (
                <Lightbox
                    mainSrc={fotos[currentImageIndex]}
                    nextSrc={fotos[(currentImageIndex + 1) % fotos.length]}
                    prevSrc={fotos[(currentImageIndex + fotos.length - 1) % fotos.length]}
                    onCloseRequest={() => setLightboxOpen(false)}
                    onMovePrevRequest={() =>
                        setCurrentImageIndex((currentImageIndex + fotos.length - 1) % fotos.length)
                    }
                    onMoveNextRequest={() =>
                        setCurrentImageIndex((currentImageIndex + 1) % fotos.length)
                    }
                />
            )} */}
        </Modal>
    );
}
