// Alta/edición de un Open Issue. Reusable para crear (issue=null) o editar un
// issue propio no cerrado (issue=objeto ya cargado, ver flags.puede_editar en
// components/openissues/ModalDetalleOpenIssue.jsx). Espejo de
// components/hhee/ModalCrearSolicitudHhee.jsx.
import React, { useEffect, useState } from 'react';
import { Modal, Input, Select, Upload, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import { crearOpenIssue, actualizarOpenIssue, buildOpenIssueFormData } from '../../Utils/openIssuesApi';
import SelectorInvolucrados from './SelectorInvolucrados';

const { TextArea } = Input;

const ModalCrearOpenIssue = ({ open, issue = null, catalogos, onClose, onSuccess }) => {
    const esEdicion = !!issue;

    const [titulo, setTitulo] = useState('');
    const [descripcion, setDescripcion] = useState('');
    const [departamentoDestinoId, setDepartamentoDestinoId] = useState(null);
    const [prioridad, setPrioridad] = useState(catalogos?.prioridad_default || 'media');
    const [userIds, setUserIds] = useState([]);
    const [departamentoIds, setDepartamentoIds] = useState([]);
    const [textoInicial, setTextoInicial] = useState('');
    const [archivo, setArchivo] = useState(null);
    const [guardando, setGuardando] = useState(false);

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
        } else {
            setTitulo('');
            setDescripcion('');
            setDepartamentoDestinoId(null);
            setPrioridad(catalogos?.prioridad_default || 'media');
            setUserIds([]);
            setDepartamentoIds([]);
            setTextoInicial('');
            setArchivo(null);
        }
    }, [open, issue, catalogos]);

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
