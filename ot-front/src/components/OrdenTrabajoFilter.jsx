// Panel de filtros adicionales de Órdenes de Trabajo: prioridad, categoría y
// "solo vencidas" (SPEC-prioridad-reportes.md §7.1). Es un componente controlado:
// no hace fetch propio, recibe el catálogo de categorías/prioridades ya cargado
// por el padre (OrdenTrabajoList) y notifica los cambios vía onChange, que se
// enganchan a los query params que ya acepta OrdenTrabajoController::index()
// (`prioridad[]`, `categoria[]`, `solo_vencidas`).
import React from 'react';
import { Select, Switch } from 'antd';

const OrdenTrabajoFilter = ({
    categorias = [],
    prioridades = [],
    prioridadSeleccionada = [],
    categoriaSeleccionada = [],
    soloVencidas = false,
    onChangePrioridad,
    onChangeCategoria,
    onChangeSoloVencidas,
}) => {
    return (
        <div className="ot-filter-group" role="group" aria-label="Filtros de prioridad y categoría">
            <Select
                mode="multiple"
                allowClear
                placeholder="Prioridad"
                style={{ minWidth: 200 }}
                value={prioridadSeleccionada}
                onChange={onChangePrioridad}
                options={prioridades.map((p) => ({ value: p.value, label: p.label }))}
                maxTagCount="responsive"
            />

            <Select
                mode="multiple"
                allowClear
                placeholder="Categoría"
                style={{ minWidth: 220 }}
                value={categoriaSeleccionada}
                onChange={onChangeCategoria}
                options={categorias.map((c) => ({ value: c.value, label: c.label }))}
                maxTagCount="responsive"
            />

            <label className="ot-solo-vencidas-toggle">
                <Switch
                    checked={soloVencidas}
                    onChange={onChangeSoloVencidas}
                    aria-label="Mostrar solo órdenes vencidas"
                />
                <span>Solo vencidas</span>
            </label>
        </div>
    );
};

export default OrdenTrabajoFilter;
