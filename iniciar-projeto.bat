@echo off
setlocal EnableExtensions EnableDelayedExpansion

:: ============================================================
:: CONFIGURACAO GLOBAL
:: ============================================================

set "TERMINAL_WINDOW=ProjetoExpense"

set "PROJECT_ROOT=%~dp0"
if "%PROJECT_ROOT:~-1%"=="\" set "PROJECT_ROOT=%PROJECT_ROOT:~0,-1%"

set "BACKEND_DIR=%PROJECT_ROOT%\backend"
set "FRONTEND_DIR=%PROJECT_ROOT%\frontend"

set "PORT_BACKEND=8000"
set "PORT_FRONTEND=3000"
set "PORT_MYSQL=3306"
set "PORT_ADMINER=8081"

:: ============================================================
:: RELANCAR O SCRIPT DENTRO DO WINDOWS TERMINAL
:: ============================================================

if /I not "%~1"=="IN_TERMINAL" (

    where wt >nul 2>&1

    if errorlevel 1 (
        echo [ERRO] Windows Terminal nao encontrado.
        pause
        exit /b 1
    )

    wt -w "%TERMINAL_WINDOW%" ^
        new-tab ^
        --title "Menu - Expense" ^
        -d "%PROJECT_ROOT%" ^
        cmd.exe /k call "%~f0" IN_TERMINAL

    exit /b 0
)

title Menu - Expense


:: ============================================================
:: VALIDACOES INICIAIS
:: ============================================================

call :validate_environment

if errorlevel 1 (
    echo.
    pause
    exit /b 1
)


:: ============================================================
:: MENU PRINCIPAL
:: ============================================================

:menu

cls

echo ============================================================
echo               AMBIENTE LOCAL - CONTROLE DE DESPESAS
echo ============================================================
echo.
echo  1 - Iniciar tudo (Docker + Backend + Frontend)
echo  2 - Abrir no Chrome
echo  3 - Ver status
echo  4 - Reiniciar tudo
echo  5 - Parar tudo
echo.
echo  6 - Iniciar Docker
echo  7 - Iniciar Backend
echo  8 - Iniciar Frontend
echo.
echo  9 - Parar Docker
echo 10 - Parar Backend
echo 11 - Parar Frontend
echo.
echo  0 - Sair
echo.
echo ============================================================
echo.

set /p OPTION="Escolha uma opcao: "

if "%OPTION%"=="1" goto start_all
if "%OPTION%"=="2" goto open_browser
if "%OPTION%"=="3" goto status
if "%OPTION%"=="4" goto restart_all
if "%OPTION%"=="5" goto stop_all

if "%OPTION%"=="6" goto start_docker_menu
if "%OPTION%"=="7" goto start_backend_menu
if "%OPTION%"=="8" goto start_frontend_menu

if "%OPTION%"=="9" goto stop_docker_menu
if "%OPTION%"=="10" goto stop_backend_menu
if "%OPTION%"=="11" goto stop_frontend_menu

if "%OPTION%"=="0" goto end

echo.
echo [ERRO] Opcao invalida.
timeout /t 2 /nobreak >nul
goto menu


:: ============================================================
:: INICIAR TUDO
:: ============================================================

:start_all

cls

echo ============================================================
echo                    INICIAR TUDO
echo ============================================================
echo.

call :start_docker
call :start_backend
call :start_frontend

echo.
echo ============================================================
echo Ambiente iniciado.
echo ============================================================
echo.

pause
goto menu


:: ============================================================
:: REINICIAR TUDO
:: ============================================================

:restart_all

cls

echo ============================================================
echo                    REINICIAR TUDO
echo ============================================================
echo.

call :stop_frontend
call :stop_backend
call :stop_docker

echo.
echo Aguardando encerramento...
timeout /t 2 /nobreak >nul

echo.
echo Iniciando novamente...
echo.

call :start_docker
call :start_backend
call :start_frontend

echo.
echo [OK] Ambiente reiniciado.
echo.

pause
goto menu


:: ============================================================
:: PARAR TUDO
:: ============================================================

:stop_all

cls

echo ============================================================
echo                      PARAR TUDO
echo ============================================================
echo.

call :stop_frontend
call :stop_backend
call :stop_docker

echo.
echo [OK] Operacao concluida.
echo.

pause
goto menu


:: ============================================================
:: STATUS
:: ============================================================

:status

cls

echo ============================================================
echo                 STATUS DOS SERVICOS
echo ============================================================
echo.

call :show_docker_status
call :show_status "Backend (Laravel)" "%PORT_BACKEND%" "php.exe"
call :show_status "Frontend (Vite)" "%PORT_FRONTEND%" "node.exe"

echo.
echo ============================================================
echo URLs
echo ============================================================
echo.
echo Frontend: http://localhost:%PORT_FRONTEND%
echo Backend:  http://localhost:%PORT_BACKEND%
echo Adminer:  http://localhost:%PORT_ADMINER%
echo MySQL:    localhost:%PORT_MYSQL%
echo.

pause
goto menu


:: ============================================================
:: ABRIR CHROME
:: ============================================================

:open_browser

cls

echo ============================================================
echo                     GOOGLE CHROME
echo ============================================================
echo.

call :find_chrome

if errorlevel 1 (
    echo [ERRO] Google Chrome nao encontrado.
    echo.
    pause
    goto menu
)

echo Abrindo nova janela do Chrome...
echo.

start "" "%CHROME%" --new-window ^
    "http://localhost:%PORT_FRONTEND%" ^
    "http://localhost:%PORT_ADMINER%"

echo [OK] Chrome iniciado com 2 abas.
echo.

pause
goto menu


:: ============================================================
:: OPCOES INDIVIDUAIS DO MENU (INICIAR)
:: ============================================================

:start_docker_menu

cls
call :start_docker
pause
goto menu


:start_backend_menu

cls
call :start_backend
pause
goto menu


:start_frontend_menu

cls
call :start_frontend
pause
goto menu


:: ============================================================
:: OPCOES INDIVIDUAIS DO MENU (PARAR)
:: ============================================================

:stop_docker_menu

cls
call :stop_docker
pause
goto menu


:stop_backend_menu

cls
call :stop_backend
pause
goto menu


:stop_frontend_menu

cls
call :stop_frontend
pause
goto menu


:: ============================================================
:: FUNCAO - VALIDAR AMBIENTE
:: ============================================================

:validate_environment

where php >nul 2>&1

if errorlevel 1 (
    echo [ERRO] PHP nao encontrado no PATH.
    exit /b 1
)

where npm >nul 2>&1

if errorlevel 1 (
    echo [ERRO] npm nao encontrado no PATH.
    exit /b 1
)

where docker >nul 2>&1

if errorlevel 1 (
    echo [ERRO] Docker nao encontrado no PATH.
    exit /b 1
)

where wt >nul 2>&1

if errorlevel 1 (
    echo [ERRO] Windows Terminal nao encontrado.
    exit /b 1
)

call :check_dir "Backend" "%BACKEND_DIR%"
if errorlevel 1 exit /b 1

call :check_dir "Frontend" "%FRONTEND_DIR%"
if errorlevel 1 exit /b 1

if not exist "%PROJECT_ROOT%\docker-compose.yml" (
    echo.
    echo [ERRO] docker-compose.yml nao encontrado em:
    echo        %PROJECT_ROOT%
    echo.
    exit /b 1
)

exit /b 0


:: ============================================================
:: FUNCAO - VALIDAR DIRETORIO
:: ============================================================

:check_dir

if not exist "%~2" (
    echo.
    echo [ERRO] Diretorio nao encontrado para %~1:
    echo        %~2
    echo.
    exit /b 1
)

exit /b 0


:: ============================================================
:: FUNCAO - VERIFICAR SE PORTA ESTA EM LISTENING
:: ============================================================

:is_port_running

netstat -ano | findstr /R /C:":%~1 .*LISTENING" >nul 2>&1

if errorlevel 1 (
    exit /b 1
)

exit /b 0


:: ============================================================
:: FUNCAO - PEGAR PID PELA PORTA
:: ============================================================

:get_pid_by_port

set "FOUND_PID="

for /f "tokens=5" %%P in (
    'netstat -ano ^| findstr /R /C:":%~1 .*LISTENING"'
) do (
    set "FOUND_PID=%%P"
)

exit /b 0


:: ============================================================
:: FUNCAO - VALIDAR NOME DO PROCESSO PELO PID
:: ============================================================

:is_process

set "PROCESS_NAME="

for /f "tokens=1" %%P in (
    'tasklist /FI "PID eq %~1" /FO CSV /NH 2^>nul'
) do (
    set "PROCESS_NAME=%%~P"
)

if /I "!PROCESS_NAME!"=="%~2" (
    exit /b 0
)

exit /b 1


:: ============================================================
:: FUNCAO - INICIAR DOCKER (MYSQL + ADMINER)
:: ============================================================

:start_docker

call :is_port_running "%PORT_MYSQL%"

if not errorlevel 1 (
    echo [RODANDO] Docker
    echo           MySQL ja esta escutando na porta %PORT_MYSQL%.
    echo.
    exit /b 0
)

echo [INICIANDO] Docker
echo             MySQL: %PORT_MYSQL% / Adminer: %PORT_ADMINER%
echo.

wt -w "%TERMINAL_WINDOW%" ^
    new-tab ^
    --title "Docker" ^
    -d "%PROJECT_ROOT%" ^
    cmd.exe /k "docker compose up -d && docker compose logs -f"

timeout /t 3 /nobreak >nul

call :is_port_running "%PORT_MYSQL%"

if not errorlevel 1 (
    echo [OK] Docker iniciado.
    echo.
) else (
    echo [AVISO] Docker solicitado, mas o MySQL ainda nao respondeu na porta.
    echo         Confira a aba "Docker" no Windows Terminal.
    echo.
)

exit /b 0


:: ============================================================
:: FUNCAO - PARAR DOCKER (MYSQL + ADMINER)
:: ============================================================

:stop_docker

call :is_port_running "%PORT_MYSQL%"

if errorlevel 1 (
    echo [PARADO] Docker
    echo          Nenhum container escutando na porta %PORT_MYSQL%.
    echo.
    exit /b 0
)

echo [PARANDO] Docker
echo.

pushd "%PROJECT_ROOT%"
docker compose stop
popd

if errorlevel 1 (
    echo [ERRO] Nao foi possivel parar os containers.
) else (
    echo [OK] Containers parados (dados preservados).
)

echo.

exit /b 0


:: ============================================================
:: FUNCAO - STATUS DO DOCKER
:: ============================================================

:show_docker_status

call :is_port_running "%PORT_MYSQL%"
set "MYSQL_UP=%errorlevel%"

call :is_port_running "%PORT_ADMINER%"
set "ADMINER_UP=%errorlevel%"

set "DOCKER_STATE=parado"
if "%MYSQL_UP%"=="0" set "DOCKER_STATE=parcial"
if "%MYSQL_UP%"=="0" if "%ADMINER_UP%"=="0" set "DOCKER_STATE=online"

if "%DOCKER_STATE%"=="online" (
    echo [ONLINE ] Docker
    echo           MySQL  : %PORT_MYSQL%
    echo           Adminer: %PORT_ADMINER%
) else if "%DOCKER_STATE%"=="parcial" (
    echo [PARCIAL] Docker
    echo           MySQL  : %PORT_MYSQL% ^(online^)
    echo           Adminer: %PORT_ADMINER% ^(parado^)
) else (
    echo [PARADO ] Docker
    echo           MySQL  : %PORT_MYSQL%
    echo           Adminer: %PORT_ADMINER%
)

echo.

exit /b 0


:: ============================================================
:: FUNCAO - INICIAR BACKEND (LARAVEL)
:: ============================================================

:start_backend

call :is_port_running "%PORT_BACKEND%"

if not errorlevel 1 (

    call :get_pid_by_port "%PORT_BACKEND%"

    echo [RODANDO] Backend
    echo           Porta: %PORT_BACKEND%
    echo           PID  : !FOUND_PID!
    echo.

    exit /b 0
)

echo [INICIANDO] Backend
echo             Porta: %PORT_BACKEND%
echo.

wt -w "%TERMINAL_WINDOW%" ^
    new-tab ^
    --title "Backend :%PORT_BACKEND%" ^
    -d "%BACKEND_DIR%" ^
    cmd.exe /k "php artisan serve --port=%PORT_BACKEND%"

timeout /t 2 /nobreak >nul

call :get_pid_by_port "%PORT_BACKEND%"

if defined FOUND_PID (
    echo [OK] Backend iniciado.
    echo      PID: !FOUND_PID!
    echo.
) else (
    echo [AVISO] Servidor solicitado, mas o PID ainda nao foi identificado.
    echo.
)

exit /b 0


:: ============================================================
:: FUNCAO - PARAR BACKEND (LARAVEL)
:: ============================================================

:stop_backend

call :is_port_running "%PORT_BACKEND%"

if errorlevel 1 (
    echo [PARADO] Backend
    echo          Nenhum processo escutando na porta %PORT_BACKEND%.
    echo.
    exit /b 0
)

call :get_pid_by_port "%PORT_BACKEND%"

if not defined FOUND_PID (
    echo [ERRO] Nao foi possivel localizar o PID do Backend.
    echo.
    exit /b 1
)

call :is_process "!FOUND_PID!" "php.exe"

if errorlevel 1 (
    echo [BLOQUEADO] Backend
    echo.
    echo A porta %PORT_BACKEND% esta sendo utilizada pelo PID !FOUND_PID!,
    echo mas o processo nao e php.exe.
    echo.
    echo Por seguranca, o launcher nao encerrara esse processo.
    echo.
    exit /b 1
)

echo [PARANDO] Backend
echo           Porta: %PORT_BACKEND%
echo           PID  : !FOUND_PID!
echo.

taskkill /PID !FOUND_PID! /F >nul 2>&1

if errorlevel 1 (
    echo [ERRO] Nao foi possivel encerrar o processo.
) else (
    echo [OK] Backend encerrado.
)

echo.

exit /b 0


:: ============================================================
:: FUNCAO - INICIAR FRONTEND (VITE)
:: ============================================================

:start_frontend

call :is_port_running "%PORT_FRONTEND%"

if not errorlevel 1 (

    call :get_pid_by_port "%PORT_FRONTEND%"

    echo [RODANDO] Frontend
    echo           Porta: %PORT_FRONTEND%
    echo           PID  : !FOUND_PID!
    echo.

    exit /b 0
)

echo [INICIANDO] Frontend
echo             Porta: %PORT_FRONTEND%
echo.

wt -w "%TERMINAL_WINDOW%" ^
    new-tab ^
    --title "Frontend :%PORT_FRONTEND%" ^
    -d "%FRONTEND_DIR%" ^
    cmd.exe /k "npm run dev"

timeout /t 2 /nobreak >nul

call :get_pid_by_port "%PORT_FRONTEND%"

if defined FOUND_PID (
    echo [OK] Frontend iniciado.
    echo      PID: !FOUND_PID!
    echo.
) else (
    echo [AVISO] Servidor solicitado, mas o PID ainda nao foi identificado.
    echo.
)

exit /b 0


:: ============================================================
:: FUNCAO - PARAR FRONTEND (VITE)
:: ============================================================

:stop_frontend

call :is_port_running "%PORT_FRONTEND%"

if errorlevel 1 (
    echo [PARADO] Frontend
    echo          Nenhum processo escutando na porta %PORT_FRONTEND%.
    echo.
    exit /b 0
)

call :get_pid_by_port "%PORT_FRONTEND%"

if not defined FOUND_PID (
    echo [ERRO] Nao foi possivel localizar o PID do Frontend.
    echo.
    exit /b 1
)

call :is_process "!FOUND_PID!" "node.exe"

if errorlevel 1 (
    echo [BLOQUEADO] Frontend
    echo.
    echo A porta %PORT_FRONTEND% esta sendo utilizada pelo PID !FOUND_PID!,
    echo mas o processo nao e node.exe.
    echo.
    echo Por seguranca, o launcher nao encerrara esse processo.
    echo.
    exit /b 1
)

echo [PARANDO] Frontend
echo           Porta: %PORT_FRONTEND%
echo           PID  : !FOUND_PID!
echo.

taskkill /PID !FOUND_PID! /F >nul 2>&1

if errorlevel 1 (
    echo [ERRO] Nao foi possivel encerrar o processo.
) else (
    echo [OK] Frontend encerrado.
)

echo.

exit /b 0


:: ============================================================
:: FUNCAO - STATUS GENERICO (BACKEND / FRONTEND)
:: ============================================================

:show_status

set "SVC_NAME=%~1"
set "SVC_PORT=%~2"
set "SVC_PROCESS=%~3"

call :is_port_running "!SVC_PORT!"

if errorlevel 1 (

    echo [PARADO ] !SVC_NAME!
    echo           Porta: !SVC_PORT!

) else (

    call :get_pid_by_port "!SVC_PORT!"
    call :is_process "!FOUND_PID!" "!SVC_PROCESS!"

    if not errorlevel 1 (

        echo [ONLINE ] !SVC_NAME!
        echo           Porta: !SVC_PORT!
        echo           PID  : !FOUND_PID!

    ) else (

        echo [OCUPADA] !SVC_NAME!
        echo           Porta: !SVC_PORT!
        echo           PID  : !FOUND_PID!
        echo           Tipo : Outro processo

    )

)

echo.

exit /b 0


:: ============================================================
:: FUNCAO - LOCALIZAR CHROME
:: ============================================================

:find_chrome

set "CHROME="

where chrome >nul 2>&1

if not errorlevel 1 (

    for /f "delims=" %%C in ('where chrome') do (
        if not defined CHROME set "CHROME=%%C"
    )

    exit /b 0
)

if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
    exit /b 0
)

if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
    set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
    exit /b 0
)

if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" (
    set "CHROME=%LocalAppData%\Google\Chrome\Application\chrome.exe"
    exit /b 0
)

exit /b 1


:: ============================================================
:: SAIR
:: ============================================================

:end

cls

echo ============================================================
echo               AMBIENTE LOCAL - CONTROLE DE DESPESAS
echo ============================================================
echo.
echo Encerrando o menu...
echo.
echo Os servicos continuarao executando nas outras abas.
echo.

endlocal
exit /b 0
