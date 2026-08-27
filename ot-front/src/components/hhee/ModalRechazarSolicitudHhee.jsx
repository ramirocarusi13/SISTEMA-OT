// Modal de rechazo de una solicitud de HHEE (nivel 1 o nivel final, según los
// flags que ya vienen resueltos del backend). Pide un motivo obligatorio.
import React, { useEffect, useState } from 'react';
import { Modal, Input, message } from 'antd';
import { rechazarSolicitudHhee } from '../../Utils/hheeApi';

const { TextArea } = Input;

const ModalRechazarSolicitudHhee = ({ open, solicitudId, onClose, onSuccess }) => {
    const [motivo, setMotivo] = useState('');
    const [enviando, setEnviando] = useState(false);

    useEffect(() => {
        if (open) {
            setMotivo('');
        }
    }, [open]);

    const handleRechazar = async () => {
        if (!motivo.trim()) {
            message.error('Debe ingresar el motivo del rechazo.');
            return;
        }

        setEnviando(true);
        const { ok, error } = await rechazarSolicitudHhee(solicitudId, { motivo: motivo.trim() });
        setEnviando(false);

        if (ok) {
            message.success('Solicitud rechazada.');
            onSuccess?.();
            onClose();
        } else {
            message.error(error || 'No se pudo rechazar la solicitud.');
        }
    };

    return (
        <Modal
            title={`Rechazar solicitud N° ${solicitudId}`}
            open={open}
            onCancel={onClose}
            onOk={handleRechazar}
            okText="Rechazar"
            cancelText="Cancelar"
            okButtonProps={{ danger: true, loading: enviando }}
            destroyOnClose
        >
            <div className="form-field">
                <label className="form-label" htmlFor="hhee-motivo-rechazo">Motivo del rechazo</label>
                <TextArea
                    id="hhee-motivo-rechazo"
                    rows={4}
                    maxLength={1000}
                    showCount
                    value={motivo}
                    onChange={(e) => setMotivo(e.target.value)}
                    placeholder="Explique el motivo del rechazo"
                />
            </div>
        </Modal>
    );
};

export default ModalRechazarSolicitudHhee;
