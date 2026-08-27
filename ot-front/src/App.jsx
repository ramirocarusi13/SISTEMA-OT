// src/App.js
import React from 'react';
import { BrowserRouter as Router, Route, Routes, Navigate } from 'react-router-dom';
import { ConfigProvider } from 'antd';
import Home from './pages/Home';   // Cambia la ruta según tu estructura
import Login from './pages/LoginPage'; // Cambia la ruta según tu estructura
import Reportes from './pages/Reportes';
import HorasExtras from './pages/HorasExtras';
import RutaProtegida from './components/RutaProtegida';
import './index.css'; // Asegúrate de que este archivo contenga las directivas de Tailwind

const theme = {
  token: {
    colorPrimary: '#0f766e',
    colorInfo: '#2563eb',
    colorSuccess: '#16a34a',
    colorWarning: '#d97706',
    colorError: '#dc2626',
    colorText: '#111827',
    colorTextSecondary: '#64748b',
    colorBorder: '#d9e2ec',
    colorBgLayout: '#f5f7fb',
    colorBgContainer: '#ffffff',
    borderRadius: 10,
    fontFamily: 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
  },
  components: {
    Button: {
      borderRadius: 10,
      controlHeight: 38,
      fontWeight: 700,
      primaryShadow: '0 10px 24px rgba(15, 118, 110, 0.18)',
    },
    DatePicker: {
      borderRadius: 10,
      controlHeight: 38,
    },
    Input: {
      borderRadius: 10,
      controlHeight: 40,
    },
    Modal: {
      borderRadiusLG: 16,
      titleFontSize: 18,
    },
    Select: {
      borderRadius: 10,
      controlHeight: 38,
    },
    Table: {
      borderColor: '#e5ecf3',
      headerBg: '#f8fafc',
      headerColor: '#334155',
      rowHoverBg: '#eefcf9',
    },
    Upload: {
      borderRadiusLG: 14,
    },
  },
};

const App = () => {
  return (
    <ConfigProvider theme={theme}>
      <Router>
        <Routes>
          <Route path="/login" element={<Login />} />

          {/* Rutas que exigen sesión: sin token se redirige a /login */}
          <Route
            path="/home"
            element={(
              <RutaProtegida>
                <Home />
              </RutaProtegida>
            )}
          />
          <Route
            path="/reportes"
            element={(
              <RutaProtegida>
                <Reportes />
              </RutaProtegida>
            )}
          />
          <Route
            path="/horas-extras"
            element={(
              <RutaProtegida>
                <HorasExtras />
              </RutaProtegida>
            )}
          />

          <Route path="/" element={<Navigate to="/login" replace />} />
          {/* Cualquier ruta inexistente cae al login en vez de dejar la pantalla en blanco */}
          <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
      </Router>
    </ConfigProvider>
  );
};

export default App;
