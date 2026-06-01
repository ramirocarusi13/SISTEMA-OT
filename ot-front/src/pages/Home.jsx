import React from 'react';
import Header from '../components/Header';
import OrdenTrabajoList from '../components/OrdenTrabajoList';
import UserProfile from '../components/UserProfile'; // Importa el componente UserProfile

const Home = () => {
    return (
        <div className="app-shell">
            <main className="page-container">
                <UserProfile /> {/* Muestra el perfil del usuario en la parte superior */}
                <Header />
                <OrdenTrabajoList />
            </main>
        </div>
    );
};

export default Home;
