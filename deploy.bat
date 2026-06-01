@echo off
setlocal
cd /d "%~dp0"

if not exist "ordenes-sar\.env" (
  echo Falta ordenes-sar\.env. Copia la configuracion del servidor antes de desplegar.
  exit /b 1
)

git pull --ff-only origin main
if errorlevel 1 exit /b 1

docker rm -f api-ot ot-front >nul 2>nul
docker compose up -d --build --remove-orphans
if errorlevel 1 exit /b 1

docker compose ps
