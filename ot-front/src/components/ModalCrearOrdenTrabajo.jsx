import React, { useState, useEffect, useMemo } from 'react';
import { getItem } from '../storage/UserAsyncStorage';
import { Modal, Button, notification, Upload, Spin, Select, Checkbox, Alert, Skeleton } from 'antd';
import { InboxOutlined } from '@ant-design/icons';
import { fetchCatalogosOT } from '../Utils/otApi';
import { calcularPrioridadCliente, getPrioridadInfo } from '../Utils/prioridad';

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

    // Catálogo de categorías/prioridades/SLA (§1 y §7.1) y selección del creador.
    const [categorias, setCategorias] = useState([]);
    const [prioridades, setPrioridades] = useState([]);
    const [slaHoras, setSlaHoras] = useState({});
    const [categoria, setCategoria] = useState(null);
    const [esSeguridad, setEsSeguridad] = useState(false);
    const [catalogosLoading, setCatalogosLoading] = useState(false);
    const [catalogosError, setCatalogosError] = useState(null);

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
                notification.error({
                    message: 'Error',
                    description: 'No se pudo obtener la lista de usuarios de mantenimiento.',
                });
            }
        };

        const cargarCatalogos = async () => {
            setCatalogosLoading(true);
            setCatalogosError(null);
            const { ok, data, error } = await fetchCatalogosOT();
            if (ok) {
                setCategorias(data.categorias || []);
                setPrioridades(data.prioridades || []);
                setSlaHoras(data.sla_horas || {});
            } else {
                setCatalogosError(error || 'No se pudieron cargar las categorías.');
            }
            setCatalogosLoading(false);
        };

        if (isOpen) {
            fetchUsuariosMantenimiento();
            cargarCatalogos();
        }
    }, [isOpen]);

    // Prioridad resultante en vivo, según el mapeo que sirve el catálogo del backend
    // (§7.1: el creador no la elige directamente).
    const prioridadCalculada = useMemo(
        () => (categoria ? calcularPrioridadCliente(categoria, esSeguridad, categorias) : null),
        [categoria, esSeguridad, categorias]
    );
    const prioridadInfo = getPrioridadInfo(prioridades, prioridadCalculada);
    const horasSla = prioridadCalculada ? slaHoras[prioridadCalculada] : null;

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (!categoria) {
            notification.error({
                message: 'Error de validación',
                description: 'Debe seleccionar una categoría para poder crear la orden.',
            });
            return;
        }

        setLoading(true); // Activar el spinner

        const formData = new FormData();
        formData.append('titulo', titulo);
        formData.append('descripcion', descripcion);
        formData.append('usuario_id', 1);
        formData.append('usuario_mantenimiento_id', usuarioMantenimientoId);
        formData.append('estado', 'creada');
        formData.append('categoria', categoria);
        formData.append('es_seguridad', esSeguridad ? '1' : '0');

        archivos.forEach((item) => {
            formData.append('archivos[]', item.originFileObj || item);
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
                setCategoria(null);
                setEsSeguridad(false);
            } else if (response.status === 403) {
                notification.error({
                    message: 'Error',
                    description: 'No tenés permisos para realizar esta acción.',
                });
                setArchivos([]);
            } else {
                // Intentamos mostrar el mensaje real del backend (ej. validación 422 de archivos)
                let description = 'Hubo un error al crear la orden. Inténtalo de nuevo.';
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
                    message: 'Error',
                    description,
                });
                // Limpiamos los archivos para que el próximo intento no reenvíe el archivo que falló
                setArchivos([]);
            }
        } catch (error) {
            console.error('Error:', error);
            notification.error({
                message: 'Error de conexión',
                description: 'No se pudo conectar al servidor. Inténtelo nuevamente.',
            });
            // También limpiamos los archivos ante un fallo de red, para permitir reintentar sin recargar
            setArchivos([]);
        } finally {
            setLoading(false); // Desactivar el spinner
        }
    };

    const handleCancelar = () => {
        setIsOpen(false);
        setTitulo('');
        setDescripcion('');
        setArchivos([]);
        setUsuarioMantenimientoId('');
        setCategoria(null);
        setEsSeguridad(false);
    };

    const propsUpload = {
        multiple: true,
        fileList: archivos,
        beforeUpload: () => false, // Evita el auto-upload; el archivo se agrega vía onChange
        onChange: ({ fileList: nuevaLista }) => {
            setArchivos(nuevaLista);
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
                                <label className="form-label" htmlFor="ot-categoria">Categoría</label>
                                {catalogosLoading ? (
                                    <Skeleton.Input active size="large" block />
                                ) : catalogosError ? (
                                    <Alert type="error" showIcon message={catalogosError} />
                                ) : (
                                    <Select
                                        id="ot-categoria"
                                        placeholder="Seleccione una categoría"
                                        value={categoria}
                                        onChange={(value) => setCategoria(value)}
                                        options={categorias.map((cat) => ({ value: cat.value, label: cat.label }))}
                                        style={{ width: '100%' }}
                                    />
                                )}
                            </div>

                            <div className="form-field">
                                <Checkbox
                                    checked={esSeguridad}
                                    onChange={(e) => setEsSeguridad(e.target.checked)}
                                >
                                    Involucra seguridad (persona o instalación en riesgo)
                                </Checkbox>
                            </div>

                            {prioridadCalculada && (
                                <Alert
                                    className="ot-prioridad-preview"
                                    showIcon
                                    type={
                                        prioridadCalculada === 'critica' ? 'error'
                                            : prioridadCalculada === 'alta' ? 'warning'
                                            : 'info'
                                    }
                                    message={
                                        <span>
                                            Prioridad asignada: <strong>{(prioridadInfo?.label || prioridadCalculada).toUpperCase()}</strong>
                                            {horasSla ? ` — objetivo de respuesta ${horasSla} h` : ''}
                                        </span>
                                    }
                                />
                            )}

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
                                    onClick={handleCancelar}
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
