import React from 'react';
import { NavLink } from 'react-router-dom';
import { FaClipboardList, FaChartBar } from 'react-icons/fa';

const Header = () => {
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
        </header>
    );
};

export default Header;
