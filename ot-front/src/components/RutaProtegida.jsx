import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';

/**
 * Envuelve las rutas que requieren sesión iniciada. Si no hay token en
 * localStorage, redirige a /login en vez de renderizar la pantalla.
 *
 * Ojo: esto es una barrera de NAVEGACIÓN, no de seguridad. Los datos siguen
 * protegidos por el backend (auth:api); esto evita que se llegue a la pantalla
 * escribiendo la URL a mano y se vea el cascarón de la app vacío o roto.
 *
 * Se guarda la ruta a la que se quiso entrar en el state, para poder volver
 * ahí después de loguearse.
 */
const RutaProtegida = ({ children }) => {
    const location = useLocation();
    const token = localStorage.getItem('token');

    if (!token) {
        return <Navigate to="/login" replace state={{ desde: location.pathname }} />;
    }

    return children;
};

export default RutaProtegida;
