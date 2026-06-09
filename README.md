# SISTEMA-OT

Monorepo para el sistema de ordenes de trabajo.

- `ot-front`: frontend React/Vite servido con Nginx.
- `ordenes-sar`: backend Laravel servido con Apache/PHP.

## Deploy Docker

El deploy unificado actualiza el repo, reconstruye y reinicia frontend y backend juntos.

Antes de desplegar, el servidor debe tener `ordenes-sar/.env` con la configuracion real de la base y correo. Ese archivo no se versiona.

```bat
deploy.bat
```

Servicios publicados:

- Frontend: `http://localhost:9050`
- Backend API directa: `http://localhost:8585/api`

El frontend proxya `/api` hacia el backend dentro de Docker, por eso no necesita apuntar a una IP fija.
