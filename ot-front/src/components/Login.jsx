import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

const APIURI = import.meta.env.VITE_API

const Login = () => {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    /*     const [successMessage, setSuccessMessage] = useState(''); */
    const navigate = useNavigate();

    // useEffect para borrar el token al cargar el componente Login
    useEffect(() => {
        localStorage.removeItem('token');
        localStorage.removeItem('user');
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        /* setSuccessMessage(''); */

        try {
            const response = await fetch(`${APIURI}login`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ email, password }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Error en el inicio de sesion');
            }

            /* setSuccessMessage('Inicio de sesion exitoso'); */

            localStorage.setItem('token', data.access_token);
            localStorage.setItem('user', JSON.stringify(data.user));

            setTimeout(() => {
                navigate('/home');
            }, 200);

        } catch (error) {
            setError(error.message);
        }
    };

    return (
        <div className="login-screen">
            <section className="login-brand" aria-label="Sistema OT">
                <img src="/LOGO-LETRAS.png" alt="Logo" className="login-brand__logo" />
                <div className="login-brand__title">
                    <p className="login-brand__eyebrow">SAR</p>
                    <h1>Sistema OT</h1>
                    <p>Ordenes de Trabajo</p>
                </div>
            </section>

            <section className="login-card-wrap">
                <div className="login-card">
                    <img src="/LOGO-LETRAS.png" alt="Logo" className="login-card__logo" />
                    <h2>Bienvenido</h2>
                    <p className="login-card__subtitle">Ordenes de Trabajo</p>
                    {error && <p className="login-error" role="alert">{error}</p>}
                    {/* successMessage &&*/  <p className="text-green-500 text-center mb-4">{/* {successMessage} */}</p>}
                    <form onSubmit={handleSubmit} className="login-form">
                        <div className="form-field">
                            <label className="form-label" htmlFor="email">Correo Electronico</label>
                            <input
                                type="email"
                                id="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                className="form-input"
                                placeholder="Ingresa tu correo electronico"
                            />
                        </div>
                        <div className="form-field">
                            <label className="form-label" htmlFor="password">Contrasena</label>
                            <input
                                type="password"
                                id="password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                className="form-input"
                                placeholder="Ingresa tu contrasena"
                            />
                        </div>
                        <button
                            type="submit"
                            className="primary-action w-full"
                        >
                            Iniciar Sesion
                        </button>
                    </form>
                </div>
            </section>
        </div>
    );
};

export default Login;
