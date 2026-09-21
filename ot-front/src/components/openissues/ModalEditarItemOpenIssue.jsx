// Modal chico para editar título/detalle/responsable de un item de Open Issue
// (§10.6). Devuelve al padre el detalle COMPLETO del issue que ya trae la
// respuesta de PUT /open-issues/{id}/items/{itemId}: el padre nunca vuelve a
// pedir el show.
import React, { useEffect, useState } from 'react';
import { Modal, Input, Select, message } from 'antd';
import { actualizarItemOpenIssue } from '../../Utils/openIssuesApi';
import { agruparUsuariosPorDepartamento } from '../../Utils/openIssues';

const { TextArea } = Input;

const ModalEditarItemOpenIssue = ({ open, issueId, item, catalogos, onClose, onGuardado }) => {
    const [titulo, setTitulo] = useState('');
    const [detalle, setDetalle] = useState('');
    const [responsableId, setResponsableId] = useState(null);
    const [guardando, setGuardando] = useState(false);

    useEffect(() => {
        if (!open || !item) return;
        setTitulo(item.titulo || '');
        setDetalle(item.detalle || '');
        setResponsableId(item.responsable?.id ?? null);
    }, [open, item]);

    const opcionesResponsable = agruparUsuariosPorDepartamento(catalogos?.usuarios || [], catalogos?.departamentos || []);

    const handleGuardar = async () => {
        if (!titulo.trim()) {
            message.error('El título del item es obligatorio.');
            return;
        }

        setGuardando(true);
        const { ok, data, error } = await actualizarItemOpenIssue(issueId, item.id, {
            titulo: titulo.trim(),
            detalle: detalle.trim() || null,
            responsable_id: responsableId,
        });
        setGuardando(false);

        if (ok) {
            message.success('Item actualizado.');
            onGuardado?.(data);
        } else {
            message.error(error || 'No se pudo actualizar el item.');
        }
    };

    return (
        <Modal
            title="Editar item"
            open={open}
            onCancel={onClose}
            onOk={handleGuardar}
            okText="Guardar"
            cancelText="Cancelar"
            confirmLoading={guardando}
            destroyOnClose
        >
            <div className="form-field">
                <label className="form-label" htmlFor="oi-item-titulo">Título</label>
                <Input
                    id="oi-item-titulo"
                    value={titulo}
                    onChange={(e) => setTitulo(e.target.value)}
                    maxLength={300}
                    showCount
                />
            </div>

            <div className="form-field" style={{ marginTop: 12 }}>
                <label className="form-label" htmlFor="oi-item-detalle">Detalle (opcional)</label>
                <TextArea
                    id="oi-item-detalle"
                    rows={3}
                    maxLength={2000}
                    value={detalle}
                    onChange={(e) => setDetalle(e.target.value)}
                    placeholder="Aclaración adicional del punto (opcional)"
                />
            </div>

            <div className="form-field" style={{ marginTop: 12 }}>
                <label className="form-label" htmlFor="oi-item-responsable">Responsable (opcional)</label>
                <Select
                    id="oi-item-responsable"
                    allowClear
                    showSearch
                    optionFilterProp="label"
                    placeholder="Sin responsable"
                    style={{ width: '100%' }}
                    value={responsableId}
                    onChange={(v) => setResponsableId(v ?? null)}
                    options={opcionesResponsable}
                />
            </div>
        </Modal>
    );
};

export default ModalEditarItemOpenIssue;
