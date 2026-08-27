// Panel de filtros de Solicitudes de HHEE: estado, rango de fechas y
// departamento. Espejo de components/OrdenTrabajoFilter.jsx: componente
// controlado, sin fetch propio, el padre decide cuándo aplicar los filtros.
import React from 'react';
import { DatePicker, Select } from 'antd';

const { RangePicker } = DatePicker;

const SolicitudHheeFilter = ({
    estados = [],
    departamentos = [],
    estadoSeleccionado = [],
    rangoFechas = [],
    departamentoSeleccionado,
    onChangeEstado,
    onChangeRangoFechas,
    onChangeDepartamento,
}) => {
    return (
        <div className="ot-filter-group" role="group" aria-label="Filtros de solicitudes de horas extras">
            <Select
                mode="multiple"
                allowClear
                placeholder="Estado"
                style={{ minWidth: 220 }}
                value={estadoSeleccionado}
                onChange={onChangeEstado}
                options={estados.map((e) => ({ value: e.value, label: e.label }))}
                maxTagCount="responsive"
            />

            <Select
                allowClear
                placeholder="Departamento"
                style={{ minWidth: 200 }}
                value={departamentoSeleccionado}
                onChange={onChangeDepartamento}
                options={departamentos.map((d) => ({ value: d.id, label: d.nombre }))}
            />

            <RangePicker
                value={rangoFechas}
                onChange={(dates) => onChangeRangoFechas(dates || [])}
                format="DD/MM/YYYY"
                placeholder={['Desde', 'Hasta']}
            />
        </div>
    );
};

export default SolicitudHheeFilter;
