import React, { useEffect, useState } from 'react';
import { getItem } from '../storage/UserAsyncStorage';
import { FaBell } from 'react-icons/fa';
import { Modal } from 'antd';

const APIURI = import.meta.env.VITE_API

export default function Notificacion() {
    const [notificaciones, setNotificaciones] = useState([]);
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [unreadCount, setUnreadCount] = useState(0);

    useEffect(() => {
        const interval = setInterval(() => {
            FetchData();
        }, 120000);
        return () => clearInterval(interval);
    }, []);

    useEffect(() => {
        FetchData();
    }, []);

    const FetchData = async () => {
        const token = await getItem();

        const data = await fetch(`${APIURI}notificaciones`, {
            headers: {
                Authorization: `Bearer ${token}`,
                'Accept': 'application/json'
            },
        });
        const res = await data.json();

        setNotificaciones(res);

        const unreadNotifications = res.filter(n => !n.leido);
        setUnreadCount(unreadNotifications.length);
    };

    const handleBellClick = async () => {
        await markAllAsRead();
        setIsModalVisible(true);
    };

    const markAllAsRead = async () => {
        const token = await getItem();

        await fetch(`${APIURI}notificaciones/marcar-todas-leidas`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
                'Accept': 'application/json'
            },
        });

        setNotificaciones((prev) => prev.map((n) => ({ ...n, leido: true })));
        setUnreadCount(0);
    };

    const handleModalClose = () => {
        setIsModalVisible(false);
    };

    return (
        <div>
            <button className="notification-button" onClick={handleBellClick} type="button" aria-label="Notificaciones">
                <FaBell size={18} />
                {unreadCount > 0 && (
                    <span className="notification-badge">
                        {unreadCount}
                    </span>
                )}
            </button>

            <Modal
                title="Notificaciones"
                open={isModalVisible}
                onCancel={handleModalClose}
                footer={null}
            >
                <div className="notification-list">
                    {notificaciones.length === 0 ? (
                        <p>No hay notificaciones.</p>
                    ) : (
                        notificaciones.map((notificacion) => (
                            <div
                                key={notificacion.id}
                                className={`notification-item ${notificacion.leido ? 'is-read' : ''}`}
                            >
                                <p>{notificacion.detalle}</p>
                                <small>{new Date(notificacion.created_at).toLocaleString()}</small>
                            </div>
                        ))
                    )}
                </div>
            </Modal>
        </div>
    );
}
