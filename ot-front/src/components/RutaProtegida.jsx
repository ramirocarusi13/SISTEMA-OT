import React, { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { Spin } from 'antd';
import { guardarRetorno, verificarSesion } from '../Utils/sesion';

/**
 * Envuelve las rutas que requieren sesión iniciada.
 *
 *  1) Sin token en localStorage: va a /login.
 *  2) Con token: se VERIFICA contra el backend (GET /user) antes de mostrar la
 *     pantalla. Un token vencido o revocado devuelve 401, y el interceptor
 *     global (Utils/sesion.js) limpia la sesión y redirige al login. Antes solo
 *     se miraba que el token existiera, y con uno vencido se entraba a una
 *     pantalla vacía o rota.
 *
 * En los dos casos se guarda la ruta completa a la que se quiso entrar
 * (pathname + querystring, p.ej. /open-issues?issue=18 del link de un mail)
 * para volver ahí después de loguearse (ver components/Login.jsx).
 *
 * Ojo: esto es una barrera de NAVEGACIÓN, no de seguridad. Los datos siguen
 * protegidos por el backend (auth:api).
 */
const RutaProtegida = ({ children }) => {
    const location = useLocation();
    const token = localStorage.getItem('token');
    const rutaCompleta = `${location.pathname}${location.search}${location.hash}`;

    // 'verificando' | 'ok' | 'vencida'
    const [estado, setEstado] = useState(token ? 'verificando' : 'vencida');

    useEffect(() => {
        let cancelado = false;

        if (!token) {
            setEstado('vencida');
            return undefined;
        }

        verificarSesion(token).then((resultado) => {
            if (cancelado) return;
            // 'sin_red' deja pasar: si el servidor está caído, cada pantalla
            // muestra su propio error en vez de expulsar al usuario.
            setEstado(resultado === 'vencida' ? 'vencida' : 'ok');
        });

        return () => {
            cancelado = true;
        };
    }, [token]);

    if (estado === 'vencida') {
        guardarRetorno(rutaCompleta);
        return <Navigate to="/login" replace state={{ desde: rutaCompleta }} />;
    }

    if (estado === 'verificando') {
        return (
            <div
                style={{ minHeight: '100vh', display: 'grid', placeItems: 'center' }}
                role="status"
                aria-label="Verificando sesión"
            >
                <Spin size="large" />
            </div>
        );
    }

    return children;
};

export default RutaProtegida;
