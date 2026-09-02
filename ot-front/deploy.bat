@echo off
setlocal
cd /d "%~dp0"

REM ===================================================================
REM  Deploy del front de OT (ot-front).
REM
REM  --network ot-network es obligatorio: el default.conf proxea /api/ y
REM  /storage/ a "http://backend", que es el alias de red de api-ot. En
REM  la red "bridge" por defecto Docker no resuelve nombres y nginx se
REM  cae al arrancar en loop con:
REM    [emerg] host not found in upstream "backend"
REM
REM  Levantar SIEMPRE api-ot primero (deploy.bat de ordenes-sar): si el
REM  alias "backend" no existe todavia, nginx no arranca.
REM
REM  El build se chequea antes de tocar el contenedor: con "&" el cmd
REM  encadenaba sin condicion y un build fallido igual borraba el
REM  contenedor sano.
REM ===================================================================

git pull

docker network inspect ot-network >nul 2>nul || docker network create ot-network

docker build . -t ot-front:latest
if errorlevel 1 (
  echo.
  echo ERROR: fallo el build. El contenedor que esta corriendo NO se toco.
  exit /b 1
)

docker stop ot-front >nul 2>nul
docker rm ot-front >nul 2>nul

docker run -d ^
  --name ot-front ^
  --restart unless-stopped ^
  --network ot-network ^
  -p 9050:80 ^
  ot-front:latest
if errorlevel 1 exit /b 1

echo.
echo ot-front desplegado. Probar: http://192.168.8.16:9050/
