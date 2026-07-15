import React, { useState } from "react";
import { Upload, Input, notification, Button, Spin } from "antd";
import { InboxOutlined } from "@ant-design/icons";

const APIURI = import.meta.env.VITE_API

const { Dragger } = Upload;

const ModalFinalizarOrdenTrabajo = ({ isOpen, setIsOpen, ordenId, onFinalizarSuccess }) => {
    const [tiempo, setTiempo] = useState("");
    const [mensaje, setMensaje] = useState("");
    const [archivo, setArchivo] = useState(null);
    const [fileList, setFileList] = useState([]);
    const [loading, setLoading] = useState(false); // Estado de carga

    // Manejo de carga de archivo (controlado, solo se permite 1 foto)
    const propsUpload = {
        fileList,
        maxCount: 1,
        beforeUpload: () => false, // Evita el auto-upload
        onChange: ({ fileList: nuevaLista }) => {
            const lista = nuevaLista.slice(-1); // Solo se permite un archivo
            setFileList(lista);
            setArchivo(lista[0]?.originFileObj || null);
        },
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        // Validar que el campo "tiempo" esté completo
        if (!tiempo) {
            notification.error({
                message: "Error de validación",
                description: "Debe completar el tiempo para finalizar la orden.",
            });
            return;
        }

        // Validar que la observación (mensaje de finalización) esté completa
        if (!mensaje.trim()) {
            notification.error({
                message: "Error de validación",
                description: "Debe ingresar una observación para finalizar la orden.",
            });
            return;
        }

        const formData = new FormData();
        formData.append("_method", "PUT"); // Forzar método PUT
        formData.append("estado", "finalizada");
        formData.append("horas_ot", tiempo);
        formData.append("mensaje_finalizacion", mensaje);

        // Solo agregamos el archivo si existe
        if (archivo) {
            formData.append("foto_finalizada", archivo);
        }

        try {
            setLoading(true); // Activar loading
            const token = localStorage.getItem("token");
            const response = await fetch(`${APIURI}ordenes-trabajo/${ordenId}/estado`, {
                method: "POST", // Aquí usamos POST
                headers: {
                    Authorization: `Bearer ${token}`,
                },
                body: formData,
            });

            const result = await response.json();

            if (response.ok) {
                notification.success({
                    message: "Orden Finalizada",
                    description: "La orden ha sido finalizada con éxito.",
                });
                setIsOpen(false);
                onFinalizarSuccess();
                // Resetear formulario
                setTiempo("");
                setMensaje("");
                setArchivo(null);
                setFileList([]);
            } else {
                notification.error({
                    message: "Error del servidor",
                    description: result.message || "Hubo un problema al finalizar la orden.",
                });
                // Limpiamos la foto para que el próximo intento no reenvíe la que falló
                setArchivo(null);
                setFileList([]);
            }
        } catch (error) {
            notification.error({
                message: "Error de conexión",
                description: "No se pudo conectar al servidor. Inténtelo nuevamente.",
            });
            setArchivo(null);
            setFileList([]);
        } finally {
            setLoading(false); // Desactivar loading después de la respuesta
        }
    };

    const handleCancelar = () => {
        setIsOpen(false);
        setTiempo("");
        setMensaje("");
        setArchivo(null);
        setFileList([]);
    };

    return (
        <>
            {isOpen && (
                <div className="ot-modal-backdrop">
                    <div className="ot-modal-card">
                        <h2 className="ot-modal-title">Finalizar Orden de Trabajo</h2>

                        <form onSubmit={handleSubmit} className="login-form">
                            {/* Input Tiempo */}
                            <div className="form-field">
                                <label className="form-label">Tiempo (horas):</label>
                                <Input
                                    type="number"
                                    value={tiempo}
                                    onChange={(e) => setTiempo(e.target.value)}
                                    placeholder="Ingrese las horas trabajadas"
                                    required
                                />
                            </div>

                            {/* Input Mensaje */}
                            <div className="form-field">
                                <label className="form-label">Observación (obligatoria):</label>
                                <Input.TextArea
                                    rows={3}
                                    value={mensaje}
                                    onChange={(e) => setMensaje(e.target.value)}
                                    placeholder="Escriba una observación sobre la finalización"
                                    required
                                />
                            </div>

                            {/* Subir Archivo */}
                            <div className="form-field">
                                <label className="form-label">Subir Foto:</label>
                                <Dragger {...propsUpload} className="mt-2">
                                    <p className="ant-upload-drag-icon">
                                        <InboxOutlined />
                                    </p>
                                    <p className="ant-upload-text">Haz clic o arrastra una foto aquí para subirla</p>
                                    <p className="ant-upload-hint">Solo se permite subir una foto.</p>
                                </Dragger>
                            </div>

                            {/* Botones */}
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
                                    disabled={loading} // Deshabilitar el botón cuando está cargando
                                >
                                    {loading ? (
                                        <Spin size="small" indicator={
                                            <div className="w-4 h-4 border-2 border-t-transparent border-white rounded-full animate-spin"></div>
                                        } /> // Mostrar el spinner de carga
                                    ) : (
                                        "Finalizar Orden"
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

export default ModalFinalizarOrdenTrabajo;
