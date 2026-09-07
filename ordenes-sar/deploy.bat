@echo off
setlocal
cd /d "%~dp0"

REM ===================================================================
REM  Deploy del backend de OT (api-ot).
REM
REM  Tres cosas que este script TIENE que hacer y que antes faltaban;
REM  sin ellas el sistema queda roto despues de cada deploy:
REM
REM  1) --network ot-network --network-alias backend
REM     En la red "bridge" por defecto Docker NO resuelve nombres de
REM     contenedor. El nginx de ot-front proxea a "http://backend", no
REM     lo encontraba y moria en loop con:
REM       [emerg] host not found in upstream "backend"
REM
REM  2) --env-file .env
REM     El .env esta en .dockerignore, asi que NO viaja dentro de la
REM     imagen. Sin esto Laravel arranca sin APP_KEY y todo login
REM     revienta con MissingAppKeyException (500 "server error").
REM     Verificado: mod_php lee las variables del contenedor, no hace
REM     falta montar el archivo.
REM
REM  3) El build se chequea ANTES de tocar el contenedor.
REM     Con "&" el cmd encadena sin condicion: si el build fallaba,
REM     igual borraba el contenedor sano y lo levantaba con la imagen
REM     vieja.
REM
REM  El volumen es ot_files (NO sistema_ot_archivos, que es lo que dice
REM  el docker-compose.yml): ahi estan los adjuntos reales del sistema.
REM ===================================================================

if not exist ".env" (
  echo ERROR: falta ordenes-sar\.env. Copia la configuracion del servidor.
  exit /b 1
)

git pull

docker network inspect ot-network >nul 2>nul || docker network create ot-network

docker build . -t api-ot:latest
if errorlevel 1 (
  echo.
  echo ERROR: fallo el build. El contenedor que esta corriendo NO se toco.
  echo Revisa las lineas de arriba para ver el motivo del error.
  pause
  exit /b 1
)

docker stop api-ot >nul 2>nul
docker rm api-ot >nul 2>nul

docker run -d ^
  --name api-ot ^
  --restart unless-stopped ^
  --network ot-network --network-alias backend ^
  --add-host host.docker.internal:host-gateway ^
  --env-file .env ^
  -v ot_files:/var/www/html/public/storage/archivos ^
  -p 8585:80 ^
  api-ot:latest
if errorlevel 1 (
  echo.
  echo ERROR: fallo el docker run. Revisa las lineas de arriba.
  pause
  exit /b 1
)

echo.
echo api-ot desplegado. Probar: http://192.168.8.16:8585/api/
pause
