import React, { useState, useEffect } from 'react';
import { getItem } from '../storage/UserAsyncStorage';
import { Modal, Button, notification, Upload, Spin } from 'antd';
import { InboxOutlined } from '@ant-design/icons';

const APIURI = import.meta.env.VITE_API

const { Dragger } = Upload;

const ModalCrearOrdenTrabajo = ({ isOpen, setIsOpen, onCreateSuccess }) => {
    const [titulo, setTitulo] = useState('');
    const [descripcion, setDescripcion] = useState('');
    const [archivos, setArchivos] = useState([]);
    const [usuariosMantenimiento, setUsuariosMantenimiento] = useState([]);
    const [usuarioMantenimientoId, setUsuarioMantenimientoId] = useState('');
    const [subiendoArchivos, setSubiendoArchivos] = useState(false);
    const [loading, setLoading] = useState(false); // Estado para el spinner

    useEffect(() => {
        const fetchUsuariosMantenimiento = async () => {
            const token = await getItem();

            try {
                const response = await fetch(`${APIURI}usuarios-mantenimiento`, {
                    headers: {
                        Authorization: `Bearer ${token}`,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                setUsuariosMantenimiento(data);
            } catch (error) {
                console.error('Error al obtener usuarios de mantenimiento:', error);
            }
        };

        if (isOpen) {
            fetchUsuariosMantenimiento();
        }
    }, [isOpen]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true); // Activar el spinner

        const formData = new FormData();
        formData.append('titulo', titulo);
        formData.append('descripcion', descripcion);
        formData.append('usuario_id', 1);
        formData.append('usuario_mantenimiento_id', usuarioMantenimientoId);
        formData.append('estado', 'creada');

        archivos.forEach((archivo) => {
            formData.append('archivos[]', archivo);
        });

        try {
            const token = await getItem();

            const response = await fetch(`${APIURI}ordenes-trabajo`, {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${token}`,
                    'Accept': 'application/json',
                },
                body: formData,
            });

            if (response.ok) {
                notification.success({
                    message: 'Orden Creada con Éxito',
                    description: 'La orden ha sido creada con éxito.',
                });
                setIsOpen(false);
                onCreateSuccess();
                setTitulo('');
                setDescripcion('');
                setArchivos([]);
                setUsuarioMantenimientoId('');
            } else {
                notification.error({
                    message: 'Error',
                    description: 'Hubo un error al crear la orden. Inténtalo de nuevo.',
                });
            }
        } catch (error) {
            console.error('Error:', error);
        } finally {
            setLoading(false); // Desactivar el spinner
        }
    };

    const propsUpload = {
        multiple: true,
        beforeUpload: (file) => {
            setArchivos(prevArchivos => [...prevArchivos, file]);
            return false;
        },
        onRemove: (file) => {
            setArchivos(prevArchivos => prevArchivos.filter(item => item !== file));
        },
    };

    return (
        <>
            {isOpen && (
                <div className="ot-modal-backdrop">
                    <div className="ot-modal-card">
                        <h2 className="ot-modal-title">Crear Orden de Trabajo</h2>

                        <form onSubmit={handleSubmit} className="login-form">
                            <div className="form-field">
                                <label className="form-label">Título</label>
                                <input
                                    type="text"
                                    className="form-input"
                                    value={titulo}
                                    onChange={(e) => setTitulo(e.target.value)}
                                    placeholder="Ingrese el título"
                                    required
                                />
                            </div>

                            <div className="form-field">
                                <label className="form-label">Descripción</label>
                                <textarea
                                    className="form-textarea"
                                    rows="3"
                                    value={descripcion}
                                    onChange={(e) => setDescripcion(e.target.value)}
                                    placeholder="Ingrese la descripción"
                                    required
                                />
                            </div>

                            <div className="form-field">
                                <label className="form-label">Subir Archivos</label>
                                <Dragger {...propsUpload} disabled={subiendoArchivos} className="rounded-lg mt-2">
                                    <p className="ant-upload-drag-icon">
                                        <InboxOutlined />
                                    </p>
                                    <p className="ant-upload-text">
                                        Haz clic o arrastra los archivos aquí para subirlos.
                                    </p>
                                    <p className="ant-upload-hint">
                                        Puedes subir múltiples archivos simultáneamente.
                                    </p>
                                </Dragger>
                            </div>

                            <div className="modal-actions">
                                <button
                                    type="button"
                                    className="secondary-action"
                                    onClick={() => setIsOpen(false)}
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    className="primary-action"
                                    disabled={loading}
                                >
                                    {loading ? (
                                        <Spin
                                            size="small"
                                            indicator={
                                                <div className="w-4 h-4 border-2 border-t-transparent border-white rounded-full animate-spin"></div>
                                            }
                                        />
                                    ) : (
                                        'Crear Orden'
                                    )}
                                </button>


                            </div>
                        </form>
                    </div>
                </div>
            )}
        </>
    );
};

export default ModalCrearOrdenTrabajo;
