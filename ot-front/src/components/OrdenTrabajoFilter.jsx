// OrdenTrabajoFilter.js
import React, { useState } from 'react';

const OrdenTrabajoFilter = ({ onFilter }) => {
    const [departamentoId, setDepartamentoId] = useState('');
    const [fechaInicio, setFechaInicio] = useState('');
    const [fechaFin, setFechaFin] = useState('');

    const handleSubmit = (e) => {
        e.preventDefault();
        onFilter({ departamento_id: departamentoId, fecha_inicio: fechaInicio, fecha_fin: fechaFin });
    };

    return (
        <form onSubmit={handleSubmit} style={{ marginBottom: '20px' }}>
            <div>
                <label>Departamento:</label>
                <input
                    type="text"
                    placeholder="ID del Departamento"
                    value={departamentoId}
                    onChange={(e) => setDepartamentoId(e.target.value)}
                />
            </div>
            <div>
                <label>Fecha Inicio:</label>
                <input
                    type="date"
                    value={fechaInicio}
                    onChange={(e) => setFechaInicio(e.target.value)}
                />
            </div>
            <div>
                <label>Fecha Fin:</label>
                <input
                    type="date"
                    value={fechaFin}
                    onChange={(e) => setFechaFin(e.target.value)}
                />
            </div>
            <button type="submit">Aplicar Filtros</button>
        </form>
    );
};

export default OrdenTrabajoFilter;
