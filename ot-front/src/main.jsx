import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import 'antd/dist/reset.css'
import './index.css'
import App from './App.jsx'
import { instalarInterceptor401 } from './Utils/sesion'

// Antes de montar la app: cualquier 401 de la API (token vencido) manda al
// login recordando la ruta, también para los fetch directos de los
// componentes viejos que no pasan por apiFetch.
instalarInterceptor401()

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
