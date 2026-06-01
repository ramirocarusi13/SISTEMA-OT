import React, { useState } from "react";
import { Modal, Input, Button, notification } from "antd";
import { getItem } from "../storage/UserAsyncStorage";

const ModalFinalizarOrdenPendiente = ({ isOpen, setIsOpen, ordenId, onFinalizarSuccess }) => {
    const [mensajeFinalizacion, setMensajeFinalizacion] = useState("");
    const [loading, setLoading] = useState(false);

    const finalizarOrden = async () => {
        if (!mensajeFinalizacion.trim()) {
            notification.error({
                message: "Error",
                description: "Debes ingresar un motivo de finalización.",
            });
            return;
        }

        setLoading(true);
        try {
            const token = await getItem();
            const response = await fetch(`${import.meta.env.VITE_API}ordenes-trabajo/${ordenId}/finalizar`, {
                method: "PUT",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    mensaje_finalizacion: mensajeFinalizacion
                }),
            });

            const result = await response.json();

            if (response.ok) {
                notification.success({
                    message: "Orden Finalizada",
                    description: "La orden ha sido finalizada correctamente.",
                });
                onFinalizarSuccess(); // 🔄 Refresca la lista de órdenes
                setIsOpen(false); // 🔹 Cierra el modal
                setMensajeFinalizacion(""); // 🔹 Limpia el mensaje de finalización
            } else {
                notification.error({
                    message: "Error",
                    description: result.error || "No se pudo finalizar la orden.",
                });
            }
        } catch (error) {
            notification.error({
                message: "Error",
                description: error.message || "Hubo un problema al finalizar la orden.",
            });
        }
        setLoading(false);
    };

    return (
        <Modal
            title="Finalizar Orden de Trabajo"
            open={isOpen}
            onCancel={() => {
                setIsOpen(false);
                setMensajeFinalizacion(""); // 🔹 Limpia el campo cuando se cierra
            }}
            footer={[
                <Button key="cancel" onClick={() => {
                    setIsOpen(false);
                    setMensajeFinalizacion("");
                }}>
                    Cancelar
                </Button>,
                <Button key="submit" type="primary" loading={loading} onClick={finalizarOrden}>
                    Finalizar Orden
                </Button>,
            ]}
        >
            <p>Por favor, ingrese el motivo de finalización:</p>
            <Input.TextArea
                value={mensajeFinalizacion}
                onChange={(e) => setMensajeFinalizacion(e.target.value)}
                placeholder="Motivo de finalización..."
                rows={3}
            />
        </Modal>
    );
};

export default ModalFinalizarOrdenPendiente;
