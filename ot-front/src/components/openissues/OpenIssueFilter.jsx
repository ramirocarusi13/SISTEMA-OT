// Panel de filtros de Open Issues: estado, prioridad, depto destino y texto
// libre. Espejo de components/hhee/SolicitudHheeFilter.jsx: componente
// controlado, sin fetch propio, el padre decide cuándo aplicar los filtros.
import React from 'react';
import { Select, Input } from 'antd';
import { SearchOutlined } from '@ant-design/icons';

const OpenIssueFilter = ({
    estados = [],
    prioridades = [],
    departamentos = [],
    estadoSeleccionado = [],
    prioridadSeleccionada,
    departamentoSeleccionado,
    texto = '',
    onChangeEstado,
    onChangePrioridad,
    onChangeDepartamento,
    onChangeTexto,
    onBuscar,
}) => {
    return (
        <div className="ot-filter-group" role="group" aria-label="Filtros de Open Issues">
            <Select
                mode="multiple"
                allowClear
                placeholder="Estado"
                style={{ minWidth: 200 }}
                value={estadoSeleccionado}
                onChange={onChangeEstado}
                options={estados.map((e) => ({ value: e.value, label: e.label }))}
                maxTagCount="responsive"
            />

            <Select
                allowClear
                placeholder="Prioridad"
                style={{ minWidth: 160 }}
                value={prioridadSeleccionada}
                onChange={onChangePrioridad}
                options={prioridades.map((p) => ({ value: p.value, label: p.label }))}
            />

            <Select
                allowClear
                showSearch
                optionFilterProp="label"
                placeholder="Depto destino"
                style={{ minWidth: 200 }}
                value={departamentoSeleccionado}
                onChange={onChangeDepartamento}
                options={departamentos.map((d) => ({ value: d.id, label: d.nombre }))}
            />

            <Input.Search
                allowClear
                placeholder="Buscar por título o descripción"
                style={{ minWidth: 260 }}
                value={texto}
                onChange={(e) => onChangeTexto(e.target.value)}
                onSearch={onBuscar}
                enterButton={<SearchOutlined />}
            />
        </div>
    );
};

export default OpenIssueFilter;
