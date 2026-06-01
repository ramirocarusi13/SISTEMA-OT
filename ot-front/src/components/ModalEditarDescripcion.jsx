import React, { useEffect, useState } from 'react';
import { Modal, Form, Input, Button, notification, Spin } from 'antd';

const APIURI = import.meta.env.VITE_API;

export default function ModalEditarDescripcion({ isOpen, setIsOpen, idOrden, onUpdated }) {
    const [form] = Form.useForm();
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (idOrden) {
            setIsLoading(true);
            fetch(`${APIURI}ordenes-trabajo/${idOrden}/descripciones`, {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                    Accept: 'application/json',
                },
            })
                .then(res => res.json())
                .then(data => {
                    const descripcion = data[0] || {};
                    form.setFieldsValue({
                        descripcion: descripcion.descripcion || '',
                        titulo: descripcion.titulo || '', // si existe
                    });
                    setIsLoading(false);
                })
                .catch(() => {
                    notification.error({
                        message: 'Error',
                        description: 'No se pudo cargar la descripción actual.',
                    });
                    setIsLoading(false);
                });
        }
    }, [idOrden, isOpen]);

    const handleOk = () => {
        form.validateFields().then(values => {
            fetch(`${APIURI}ordenes-trabajo/${idOrden}/descripcion`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
                body: JSON.stringify(values),
            })
                .then(res => {
                    if (!res.ok) throw new Error('Error en el guardado');
                    return res.json();
                })
                .then(() => {
                    notification.success({
                        message: 'Actualizado',
                        description: 'La descripción fue actualizada correctamente.',
                    });
                    setIsOpen(false);
                    onUpdated?.(); // para recargar datos si es necesario
                })
                .catch(() => {
                    notification.error({
                        message: 'Error',
                        description: 'No se pudo actualizar la descripción.',
                    });
                });
        });
    };

    return (
        <Modal
            title={`Editar Descripción - Orden #${idOrden}`}
            open={isOpen}
            onOk={handleOk}
            onCancel={() => setIsOpen(false)}
            okText="Guardar"
        >
            {isLoading ? (
                <div className="text-center my-4">
                    <Spin />
                </div>
            ) : (
                <Form form={form} layout="vertical">
                    <Form.Item label="Título" name="titulo">
                        <Input />
                    </Form.Item>
                    <Form.Item label="Descripción" name="descripcion" rules={[{ required: true, message: 'Ingrese una descripción' }]}>
                        <Input.TextArea rows={4} />
                    </Form.Item>
                </Form>
            )}
        </Modal>
    );
}
