// Alta/edición de un Open Issue. Reusable para crear (issue=null) o editar un
// issue propio no cerrado (issue=objeto ya cargado, ver flags.puede_editar en
// components/openissues/ModalDetalleOpenIssue.jsx). Espejo de
// components/hhee/ModalCrearSolicitudHhee.jsx.
import React, { useEffect, useRef, useState } from 'react';
import { Modal, Input, Select, Upload, message } from 'antd';
import { UploadOutlined, CloseOutlined } from '@ant-design/icons';
import { crearOpenIssue, actualizarOpenIssue, buildOpenIssueFormData } from '../../Utils/openIssuesApi';
import SelectorInvolucrados from './SelectorInvolucrados';

const { TextArea } = Input;

// Fallback si el backend todavia no manda catalogos.max_items (ver OpenIssueController::catalogos).
const MAX_ITEMS_FALLBACK = 50;

const ModalCrearOpenIssue = ({ open, issue = null, catalogos, onClose, onSuccess }) => {
    const maxItems = Number(catalogos?.max_items) || MAX_ITEMS_FALLBACK;
    const esEdicion = !!issue;

    const [titulo, setTitulo] = useState('');
    const [descripcion, setDescripcion] = useState('');
    const [departamentoDestinoId, setDepartamentoDestinoId] = useState(null);
    const [prioridad, setPrioridad] = useState(catalogos?.prioridad_default || 'media');
    const [userIds, setUserIds] = useState([]);
    const [departamentoIds, setDepartamentoIds] = useState([]);
    const [textoInicial, setTextoInicial] = useState('');
    const [archivo, setArchivo] = useState(null);
    const [items, setItems] = useState(['']);
    const [guardando, setGuardando] = useState(false);

    // Refs de los <Input> de items, para poder enfocar la fila siguiente
    // cuando el usuario aprieta Enter (§10.6).
    const itemInputRefs = useRef([]);

    useEffect(() => {
        if (!open) return;

        if (issue) {
            setTitulo(issue.titulo || '');
            setDescripcion(issue.descripcion || '');
            setDepartamentoDestinoId(issue.departamento_destino_id || null);
            setPrioridad(issue.prioridad || catalogos?.prioridad_default || 'media');
            setUserIds([]);
            setDepartamentoIds([]);
            setTextoInicial('');
            setArchivo(null);
            setItems(['']);
        } else {
            setTitulo('');
            setDescripcion('');
            setDepartamentoDestinoId(null);
            setPrioridad(catalogos?.prioridad_default || 'media');
            setUserIds([]);
            setDepartamentoIds([]);
            setTextoInicial('');
            setArchivo(null);
            setItems(['']);
        }
    }, [open, issue, catalogos]);

    const cambiarItem = (index, valor) => {
        setItems((prev) => prev.map((it, i) => (i === index ? valor : it)));
    };

    const agregarFilaItem = () => {
        if (items.length >= maxItems) {
            message.warning(`Máximo ${maxItems} items por issue.`);
            return;
        }
        setItems((prev) => [...prev, '']);
    };

    const quitarFilaItem = (index) => {
        setItems((prev) => (prev.length === 1 ? [''] : prev.filter((_, i) => i !== index)));
    };

    // Enter en una fila: si es la última, agrega la siguiente y le da foco;
    // si no, mueve el foco a la fila de abajo.
    const handleKeyDownItem = (e, index) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (index === items.length - 1) {
            if (items.length >= maxItems) return;
            setItems((prev) => [...prev, '']);
            setTimeout(() => itemInputRefs.current[index + 1]?.focus(), 0);
        } else {
            itemInputRefs.current[index + 1]?.focus();
        }
    };

    const propsUpload = {
        maxCount: 1,
        beforeUpload: () => false,
        fileList: archivo ? [archivo] : [],
        onChange: ({ fileList }) => setArchivo(fileList[0] || null),
        onRemove: () => setArchivo(null),
    };

    const validar = () => {
        if (!titulo.trim()) return 'El título es obligatorio.';
        if (!departamentoDestinoId) return 'Debe seleccionar el departamento destino.';
        return null;
    };

    const enviarFormulario = async () => {
        const errorValidacion = validar();
        if (errorValidacion) {
            message.error(errorValidacion);
            return;
        }

        setGuardando(true);

        // Filas vacías se descartan al enviar (§10.6): solo viajan los
        // títulos con contenido, como { titulo }.
        const itemsParaEnviar = items
            .map((t) => t.trim())
            .filter(Boolean)
            .slice(0, maxItems)
            .map((t) => ({ titulo: t }));

        const resultado = esEdicion
            ? await actualizarOpenIssue(issue.id, {
                titulo: titulo.trim(),
                descripcion: descripcion.trim() || null,
                prioridad,
                departamento_destino_id: departamentoDestinoId,
            })
            : await crearOpenIssue(buildOpenIssueFormData({
                titulo: titulo.trim(),
                descripcion: descripcion.trim() || null,
                departamento_destino_id: departamentoDestinoId,
                prioridad,
                involucrados_ids: userIds,
                departamentos_ids: departamentoIds,
                texto_inicial: textoInicial.trim() || null,
                items: itemsParaEnviar,
            }, archivo?.originFileObj || null));

        setGuardando(false);

        if (resultado.ok) {
            message.success(esEdicion ? 'Issue actualizado.' : 'Issue creado.');
            onSuccess?.(resultado.data);
        } else {
            message.error(resultado.error || 'No se pudo guardar el issue.');
        }
    };

    return (
        <Modal
            title={esEdicion ? `Editar issue N° ${issue.id}` : 'Nuevo issue'}
            open={open}
            onCancel={onClose}
            footer={null}
            width={720}
            destroyOnClose
        >
            <div className="oi-card">
                <div className="hhee-form-grid">
                    <div className="form-field">
                        <label className="form-label" htmlFor="oi-titulo">Título</label>
                        <Input
                            id="oi-titulo"
                            value={titulo}
                            onChange={(e) => setTitulo(e.target.value)}
                            placeholder="Ej.: Funda 47A con hilo suelto en costura lateral"
                            maxLength={200}
                            showCount
                        />
                    </div>

                    <div className="form-field">
                        <label className="form-label" htmlFor="oi-depto-destino">Departamento destino</label>
                        <Select
                            id="oi-depto-destino"
                            placeholder="Seleccione un departamento"
                            value={departamentoDestinoId}
                            onChange={setDepartamentoDestinoId}
                            options={(catalogos?.departamentos || []).map((d) => ({ value: d.id, label: d.nombre }))}
                            style={{ width: '100%' }}
                            showSearch
                            optionFilterProp="label"
                        />
                    </div>

                    <div className="form-field">
                        <label className="form-label" htmlFor="oi-prioridad">Prioridad</label>
                        <Select
                            id="oi-prioridad"
                            value={prioridad}
                            onChange={setPrioridad}
                            options={(catalogos?.prioridades || []).map((p) => ({ value: p.value, label: p.label }))}
                            style={{ width: '100%' }}
                        />
                    </div>
                </div>

                <div className="form-field" style={{ marginTop: 12 }}>
                    <label className="form-label" htmlFor="oi-descripcion">Descripción</label>
                    <TextArea
                        id="oi-descripcion"
                        rows={4}
                        maxLength={4000}
                        value={descripcion}
                        onChange={(e) => setDescripcion(e.target.value)}
                        placeholder="Descripción del issue (opcional)"
                    />
                </div>
            </div>

            {/* Items (§10.6): opcional, solo en el alta. En edición se gestionan
                desde el detalle (ItemsOpenIssue), no acá. */}
            {!esEdicion && (
                <div className="oi-card">
                    <h3 className="hhee-seccion-titulo">Items (opcional)</h3>
                    <p className="oi-item-ayuda">Dividí el trabajo en puntos concretos; cada uno se puede resolver por separado.</p>
                    <div className="oi-item-filas">
                        {items.map((valorItem, index) => (
                            <div className="oi-item-fila" key={`item-${index}`}>
                                <Input
                                    ref={(el) => { itemInputRefs.current[index] = el; }}
                                    value={valorItem}
                                    onChange={(e) => cambiarItem(index, e.target.value)}
                                    onKeyDown={(e) => handleKeyDownItem(e, index)}
                                    placeholder={`Item ${index + 1}`}
                                    maxLength={300}
                                    aria-label={`Título del item ${index + 1}`}
                                />
                                <button
                                    type="button"
                                    className="oi-item-fila__quitar"
                                    onClick={() => quitarFilaItem(index)}
                                    aria-label={`Quitar item ${index + 1}`}
                                >
                                    <CloseOutlined />
                                </button>
                            </div>
                        ))}
                    </div>
                    <button
                        type="button"
                        className="secondary-action"
                        style={{ marginTop: 12 }}
                        onClick={agregarFilaItem}
                        disabled={items.length >= maxItems}
                    >
                        + Agregar item
                    </button>
                </div>
            )}

            {!esEdicion && (
                <div className="oi-card">
                    <h3 className="hhee-seccion-titulo">Involucrados</h3>
                    <SelectorInvolucrados
                        usuarios={catalogos?.usuarios || []}
                        departamentos={catalogos?.departamentos || []}
                        userIds={userIds}
                        departamentoIds={departamentoIds}
                        onChangeUserIds={setUserIds}
                        onChangeDepartamentoIds={setDepartamentoIds}
                    />

                    <div className="form-field" style={{ marginTop: 12 }}>
                        <label className="form-label" htmlFor="oi-texto-inicial">Primera actualización (opcional)</label>
                        <TextArea
                            id="oi-texto-inicial"
                            rows={3}
                            maxLength={4000}
                            value={textoInicial}
                            onChange={(e) => setTextoInicial(e.target.value)}
                            placeholder="Un primer comentario o contexto adicional (opcional)"
                        />
                    </div>

                    <div className="form-field" style={{ marginTop: 12 }}>
                        <label className="form-label">Adjunto (opcional)</label>
                        <Upload {...propsUpload}>
                            <button type="button" className="secondary-action">
                                <UploadOutlined /> Elegir archivo
                            </button>
                        </Upload>
                    </div>
                </div>
            )}

            <div className="modal-actions">
                <button type="button" className="secondary-action" onClick={onClose} disabled={guardando}>
                    Cancelar
                </button>
                <button type="button" className="primary-action" onClick={enviarFormulario} disabled={guardando}>
                    {guardando ? 'Guardando...' : (esEdicion ? 'Guardar cambios' : 'Crear issue')}
                </button>
            </div>
        </Modal>
    );
};

export default ModalCrearOpenIssue;
