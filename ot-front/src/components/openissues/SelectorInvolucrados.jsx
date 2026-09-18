// Selector de involucrados de un Open Issue: personas sueltas (Select
// múltiple buscable, agrupado por departamento) + "agregar todo el
// departamento" (que vuelca a chips por persona removibles, más chips de
// depto). La expansión real de "todo el departamento" la hace SIEMPRE el
// backend (App\Support\OpenIssueFlujo::insertarInvolucrados); acá el conteo
// es solo informativo. Lo usan ModalCrearOpenIssue (alta) y el botón
// "+ Involucrar" de ModalDetalleOpenIssue.
import React from 'react';
import { Select } from 'antd';
import { CloseOutlined } from '@ant-design/icons';
import { agruparUsuariosPorDepartamento, iniciales } from '../../Utils/openIssues';

const SelectorInvolucrados = ({
    usuarios = [],
    departamentos = [],
    userIds = [],
    departamentoIds = [],
    onChangeUserIds,
    onChangeDepartamentoIds,
    excluirUserIds = [],
    disabled = false,
}) => {
    const usuariosSeleccionables = usuarios.filter((u) => !excluirUserIds.includes(u.id));
    const opcionesPersonas = agruparUsuariosPorDepartamento(usuariosSeleccionables, departamentos);
    const opcionesDeptos = departamentos.map((d) => ({ value: d.id, label: d.nombre }));

    const nombrePorUserId = new Map(usuarios.map((u) => [u.id, u.name]));
    const nombrePorDeptoId = new Map(departamentos.map((d) => [d.id, d.nombre]));
    const cantidadPorDeptoId = (deptoId) => usuarios.filter((u) => u.departamento_id === deptoId).length;

    const quitarUserId = (id) => onChangeUserIds(userIds.filter((uid) => uid !== id));
    const quitarDepartamentoId = (id) => onChangeDepartamentoIds(departamentoIds.filter((did) => did !== id));

    // Total estimado de personas distintas (unión de userIds con los users de
    // los deptos elegidos, sin duplicados ni los de excluirUserIds). Solo informativo.
    const idsUsuariosDeDeptos = usuarios
        .filter((u) => departamentoIds.includes(u.departamento_id))
        .map((u) => u.id);
    const totalEstimado = new Set(
        [...userIds, ...idsUsuariosDeDeptos].filter((id) => !excluirUserIds.includes(id))
    ).size;

    return (
        <div className="oi-selector-involucrados">
            <div className="form-field">
                <label className="form-label" htmlFor="oi-selector-personas">Personas</label>
                <Select
                    id="oi-selector-personas"
                    mode="multiple"
                    showSearch
                    allowClear
                    optionFilterProp="label"
                    placeholder="Buscar personas..."
                    style={{ width: '100%' }}
                    value={userIds}
                    onChange={onChangeUserIds}
                    options={opcionesPersonas}
                    disabled={disabled}
                />
            </div>

            <div className="form-field" style={{ marginTop: 12 }}>
                <label className="form-label" htmlFor="oi-selector-deptos">Agregar todo el departamento</label>
                <Select
                    id="oi-selector-deptos"
                    mode="multiple"
                    allowClear
                    placeholder="Agregar todo el departamento..."
                    style={{ width: '100%' }}
                    value={departamentoIds}
                    onChange={onChangeDepartamentoIds}
                    options={opcionesDeptos}
                    disabled={disabled}
                />
            </div>

            {(userIds.length > 0 || departamentoIds.length > 0) && (
                <div className="oi-chips" style={{ marginTop: 12 }}>
                    {userIds.map((id) => {
                        const nombre = nombrePorUserId.get(id) || `Usuario #${id}`;
                        return (
                            <span className="oi-chip" key={`user-${id}`}>
                                <span className="oi-chip__avatar">{iniciales(nombre)}</span>
                                {nombre}
                                <button
                                    type="button"
                                    className="oi-chip__quitar"
                                    onClick={() => quitarUserId(id)}
                                    disabled={disabled}
                                    aria-label={`Quitar a ${nombre}`}
                                >
                                    <CloseOutlined />
                                </button>
                            </span>
                        );
                    })}
                    {departamentoIds.map((id) => {
                        const nombre = nombrePorDeptoId.get(id) || `Depto #${id}`;
                        const cantidad = cantidadPorDeptoId(id);
                        return (
                            <span className="oi-chip oi-chip--depto" key={`depto-${id}`}>
                                {`Todo ${nombre} (${cantidad} persona${cantidad === 1 ? '' : 's'})`}
                                <button
                                    type="button"
                                    className="oi-chip__quitar"
                                    onClick={() => quitarDepartamentoId(id)}
                                    disabled={disabled}
                                    aria-label={`Quitar todo el departamento ${nombre}`}
                                >
                                    <CloseOutlined />
                                </button>
                            </span>
                        );
                    })}
                </div>
            )}

            <p className="oi-selector-resumen">
                {totalEstimado === 0
                    ? 'No se agregará ningún involucrado nuevo.'
                    : `Se agregarán aproximadamente ${totalEstimado} persona${totalEstimado === 1 ? '' : 's'} (el backend calcula el total exacto).`}
            </p>
        </div>
    );
};

export default SelectorInvolucrados;
