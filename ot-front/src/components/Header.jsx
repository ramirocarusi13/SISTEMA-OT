import React, { useCallback, useEffect, useState } from 'react';
import { NavLink } from 'react-router-dom';
import { Badge } from 'antd';
import { FaClipboardList, FaChartBar, FaRegClock, FaTasks } from 'react-icons/fa';
import { fetchPendientesHhee } from '../Utils/hheeApi';
import { fetchPendientesOpenIssues } from '../Utils/openIssuesApi';

// Intervalo de polling del badge de HHEE (mismo patrón que la campana de
// notificaciones, ver components/Notificacion.jsx).
const INTERVALO_POLLING_MS = 120000;

const Header = () => {
    const [pendientesHhee, setPendientesHhee] = useState(0);
    const [pendientesOpenIssues, setPendientesOpenIssues] = useState(0);

    const cargarPendientesHhee = useCallback(async () => {
        // Pide solo el total (?solo_total=1): si el backend todavía no lo
        // soporta, igual responde {total, solicitudes} completo y acá se
        // toma solo el número, sin bajar de más ni romper si el día de
        // mañana el backend deja de mandar las filas.
        const { ok, data } = await fetchPendientesHhee(true);
        if (ok) {
            const total = typeof data?.total === 'number' ? data.total : (data?.solicitudes?.length || 0);
            setPendientesHhee(total);
        }
    }, []);

    const cargarPendientesOpenIssues = useCallback(async () => {
        const { ok, data } = await fetchPendientesOpenIssues(true);
        if (ok) {
            const total = typeof data?.total === 'number' ? data.total : (data?.issues?.length || 0);
            setPendientesOpenIssues(total);
        }
    }, []);

    useEffect(() => {
        cargarPendientesHhee();
        const interval = setInterval(cargarPendientesHhee, INTERVALO_POLLING_MS);

        // Permite refrescar el badge al instante desde cualquier acción del
        // módulo HHEE (aprobar/rechazar/enviar/etc.) sin esperar el polling.
        window.addEventListener('hhee:actualizado', cargarPendientesHhee);

        return () => {
            clearInterval(interval);
            window.removeEventListener('hhee:actualizado', cargarPendientesHhee);
        };
    }, [cargarPendientesHhee]);

    // useEffect PROPIO, independiente del de HHEE (ese no se toca).
    useEffect(() => {
        cargarPendientesOpenIssues();
        const interval = setInterval(cargarPendientesOpenIssues, INTERVALO_POLLING_MS);
        window.addEventListener('openissues:actualizado', cargarPendientesOpenIssues);

        return () => {
            clearInterval(interval);
            window.removeEventListener('openissues:actualizado', cargarPendientesOpenIssues);
        };
    }, [cargarPendientesOpenIssues]);

    return (
        <header className="ot-header-nav">
            <NavLink
                to="/home"
                className={({ isActive }) => `ot-header-link ${isActive ? 'is-active' : ''}`}
            >
                <FaClipboardList />
                Órdenes de Trabajo
            </NavLink>
            <NavLink
                to="/reportes"
                className={({ isActive }) => `ot-header-link ${isActive ? 'is-active' : ''}`}
            >
                <FaChartBar />
                Reportes
            </NavLink>
            <NavLink
                to="/horas-extras"
                className={({ isActive }) => `ot-header-link ${isActive ? 'is-active' : ''}`}
            >
                <Badge count={pendientesHhee} size="small" offset={[6, -2]} overflowCount={99}>
                    <span className="ot-header-link__label">
                        <FaRegClock />
                        Horas Extras
                    </span>
                </Badge>
            </NavLink>
            <NavLink
                to="/open-issues"
                className={({ isActive }) => `ot-header-link ${isActive ? 'is-active' : ''}`}
            >
                <Badge count={pendientesOpenIssues} size="small" offset={[6, -2]} overflowCount={99}>
                    <span className="ot-header-link__label">
                        <FaTasks />
                        Open Issues
                    </span>
                </Badge>
            </NavLink>
        </header>
    );
};

export default Header;
