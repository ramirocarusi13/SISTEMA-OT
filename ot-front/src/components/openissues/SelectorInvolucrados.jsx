// Selector de involucrados de un Open Issue: personas sueltas (Select
// múltiple buscable, agrupado por departamento) + "agregar todo el
// departamento", que VUELCA a cada integrante del depto como una persona
// más de la lista (chips individuales, removibles de a uno). Por eso al
// backend siempre viajan user_ids concretos: `departamentoIds` queda vacío
// y se mantiene solo por compatibilidad de props con los modales que lo usan
// (ModalCrearOpenIssue y el botón "+ Involucrar" de ModalDetalleOpenIssue).
// Si se mandara departamento_ids, el backend volvería a agregar a quien el
// usuario sacó a mano de la lista.
import React from 'react';
import { Select, message } from 'antd';
import { CloseOutlined } from '@ant-design/icons';
import { agruparUsuariosPorDepartamento, iniciales } from '../../Utils/openIssues';

// Los ids pueden llegar como número o como string según cómo los serialice
// el driver (sqlsrv devuelve int, pero no se asume): se comparan normalizados.
const mismoId = (a, b) => Number(a) === Number(b);

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
    const estaExcluido = (id) => excluirUserIds.some((eid) => mismoId(eid, id));
    const usuariosSeleccionables = usuarios.filter((u) => !estaExcluido(u.id));
    const opcionesPersonas = agruparUsuariosPorDepartamento(usuariosSeleccionables, departamentos);
    const opcionesDeptos = departamentos.map((d) => ({ value: d.id, label: d.nombre }));

    const nombrePorUserId = new Map(usuarios.map((u) => [Number(u.id), u.name]));

    const quitarUserId = (id) => onChangeUserIds(userIds.filter((uid) => !mismoId(uid, id)));

    // Expande el departamento elegido a sus integrantes y los suma a la
    // lista de personas (sin duplicar, sin los excluidos). El Select de
    // departamentos no retiene valor: es una acción, no un filtro.
    const agregarDepartamento = (deptoId) => {
        if (deptoId === undefined || deptoId === null) return;
        const depto = departamentos.find((d) => mismoId(d.id, deptoId));
        const nombreDepto = depto?.nombre || `Depto #${deptoId}`;

        const idsDelDepto = usuariosSeleccionables
            .filter((u) => mismoId(u.departamento_id, deptoId))
            .map((u) => u.id);

        if (idsDelDepto.length === 0) {
            message.warning(`${nombreDepto} no tiene personas cargadas para agregar.`);
            return;
        }

        const yaEstan = new Set(userIds.map(Number));
        const nuevos = idsDelDepto.filter((id) => !yaEstan.has(Number(id)));

        if (nuevos.length === 0) {
            message.info(`Todas las personas de ${nombreDepto} ya están en la lista.`);
            return;
        }

        onChangeUserIds([...userIds, ...nuevos]);
        message.success(`Se agregaron ${nuevos.length} persona${nuevos.length === 1 ? '' : 's'} de ${nombreDepto}.`);

        if (departamentoIds.length > 0 && onChangeDepartamentoIds) {
            onChangeDepartamentoIds([]);
        }
    };

    const total = userIds.length;

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
                    showSearch
                    optionFilterProp="label"
                    placeholder="Elegí un departamento para sumar a todas sus personas..."
                    style={{ width: '100%' }}
                    value={null}
                    onChange={agregarDepartamento}
                    options={opcionesDeptos}
                    disabled={disabled}
                />
            </div>

            {userIds.length > 0 && (
                <div className="oi-chips" style={{ marginTop: 12 }}>
                    {userIds.map((id) => {
                        const nombre = nombrePorUserId.get(Number(id)) || `Usuario #${id}`;
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
                </div>
            )}

            <p className="oi-selector-resumen">
                {total === 0
                    ? 'No se agregará ningún involucrado nuevo.'
                    : `Se agregarán ${total} persona${total === 1 ? '' : 's'}.`}
            </p>
        </div>
    );
};

export default SelectorInvolucrados;
