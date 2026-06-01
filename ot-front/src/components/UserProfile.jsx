import React, { useState, useEffect } from 'react';
import { FaSignOutAlt, FaUser } from 'react-icons/fa';
import { useNavigate } from 'react-router-dom';
import Notificacion from './Notificacion';

const UserProfile = () => {
    const [user, setUser] = useState(null);
    const navigate = useNavigate();

    useEffect(() => {
        // Obtener la informacion del usuario desde localStorage
        const userData = localStorage.getItem('user');

        if (userData) {
            setUser(JSON.parse(userData));
        }
    }, []);

    const handleLogout = () => {
        localStorage.removeItem('user');
        localStorage.removeItem('token');
        navigate('/login');
    };

    if (!user) {
        return <p className="profile-card">Cargando...</p>;
    }

    return (
        <section className="profile-card">
            <div className="profile-user">
                <div className="profile-avatar" aria-hidden="true">
                    <FaUser />
                </div>
                <div className="min-w-0">
                    <p className="profile-name">{user.name}</p>
                    <p className="profile-department">{user.departamento?.nombre}</p>
                </div>
            </div>

            <div className="profile-actions">
                <Notificacion />
                <button
                    className="danger-action"
                    onClick={handleLogout}
                    type="button"
                >
                    <FaSignOutAlt />
                    Cerrar Sesion
                </button>
            </div>
        </section>
    );
};

export default UserProfile;
