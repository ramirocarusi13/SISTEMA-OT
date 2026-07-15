import React, { useState } from 'react';
import { Modal, Upload, Button, notification } from 'antd';
import { InboxOutlined } from '@ant-design/icons';
import { getItem } from '../storage/UserAsyncStorage';

const { Dragger } = Upload;

const APIURI = import.meta.env.VITE_API


const ModalAgregarArchivos = ({ isOpen, setIsOpen, ordenId, onUploadSuccess }) => {
    const [archivos, setArchivos] = useState([]);
    const [isUploading, setIsUploading] = useState(false);

    const propsUpload = {
        multiple: true,
        fileList: archivos,
        beforeUpload: () => false, // Prevenir la subida automática; el archivo se agrega vía onChange
        onChange: ({ fileList: nuevaLista }) => {
            setArchivos(nuevaLista);
        },
    };

    const handleUpload = async () => {
        if (archivos.length === 0) {
            notification.warning({
                message: 'Archivos Requeridos',
                description: 'Debe seleccionar al menos un archivo antes de subir.',
            });
            return;
        }

        setIsUploading(true);
        const formData = new FormData();
        archivos.forEach((item) => {
            formData.append('archivos[]', item.originFileObj || item); // Usar el mismo campo `archivos[]`
        });

        try {
            const token = await getItem();
            const response = await fetch(`${APIURI}ordenes-trabajo/${ordenId}/agregar-archivos`, {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${token}`,
                },
                body: formData,
            });

            if (response.ok) {
                notification.success({
                    message: 'Archivos Subidos',
                    description: 'Los archivos se agregaron correctamente.',
                });
                setArchivos([]); // Limpiar los archivos seleccionados
                onUploadSuccess(); // Actualizar la vista
                setIsOpen(false); // Cerrar el modal
            } else {
                let description = 'Hubo un error al subir los archivos.';
                try {
                    const errorData = await response.json();
                    if (errorData?.message) {
                        description = errorData.message;
                    } else if (errorData?.errors) {
                        description = Object.values(errorData.errors).flat().join(' ');
                    }
                } catch (parseError) {
                    // Se mantiene el mensaje genérico si la respuesta no trae JSON.
                }
                notification.error({
                    message: 'Error al Subir',
                    description,
                });
                // Limpiamos los archivos para que el próximo intento no reenvíe el que falló
                setArchivos([]);
            }
        } catch (error) {
            console.error('Error al subir archivos:', error);
            notification.error({
                message: 'Error',
                description: 'No se pudo conectar al servidor.',
            });
            setArchivos([]);
        } finally {
            setIsUploading(false);
        }
    };

    return (
        <>
            {isOpen && (
                <Modal
                    title="Agregar Archivos"
                    open={isOpen}
                    onCancel={() => {
                        setArchivos([]); // Limpiar archivos al cancelar
                        setIsOpen(false);
                    }}
                    footer={null}
                >
                    <Dragger {...propsUpload}>
                        <p className="ant-upload-drag-icon">
                            <InboxOutlined />
                        </p>
                        <p className="ant-upload-text">Haz clic o arrastra los archivos aquí para subirlos.</p>
                        <p className="ant-upload-hint">
                            Puedes subir múltiples archivos simultáneamente sin reemplazar los existentes.
                        </p>
                    </Dragger>

                    <div className="modal-actions">
                        <Button
                            type="default"
                            onClick={() => {
                                setArchivos([]); // Limpiar archivos al cancelar
                                setIsOpen(false);
                            }}
                        >
                            Cancelar
                        </Button>
                        <Button type="primary" onClick={handleUpload} disabled={isUploading || archivos.length === 0}>
                            {isUploading ? 'Subiendo...' : 'Subir Archivos'}
                        </Button>
                    </div>
                </Modal>
            )}
        </>
    );
};

export default ModalAgregarArchivos;
