@echo off
chcp 65001 >nul
title Painel da Comissao - Guido Parque
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
  echo.
  echo  PHP nao encontrado neste computador.
  echo  Instale uma vez com o comando abaixo ^(no Prompt de Comando ou PowerShell^):
  echo.
  echo      winget install PHP.PHP.8.3
  echo.
  echo  Depois feche esta janela e abra o iniciar-painel.bat de novo.
  pause
  exit /b
)

rem Pasta do PHP (para carregar as extensoes necessarias mesmo sem php.ini)
for /f "delims=" %%i in ('where php') do (set "PHPEXE=%%i" & goto :found)
:found
for %%i in ("%PHPEXE%") do set "PHPDIR=%%~dpi"
set PHPOPTS=-d extension_dir="%PHPDIR%ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=mbstring -d extension=intl -d extension=curl -d extension=openssl -d extension=zip -d extension=fileinfo

echo Baixando as atualizacoes do site (GitHub)...
git pull --rebase --autostash

rem O banco local e so um cache: e recriado a partir dos arquivos mais recentes
if exist storage\database.sqlite del /q storage\database.sqlite storage\database.sqlite-wal storage\database.sqlite-shm 2>nul

echo.
echo  Painel em http://127.0.0.1:8765/admin/
echo  Site (previa) em http://127.0.0.1:8765/
echo  Para encerrar, feche esta janela.
echo.
start "" "http://127.0.0.1:8765/admin/"
php %PHPOPTS% -S 127.0.0.1:8765
