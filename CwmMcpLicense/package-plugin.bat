@echo off
setlocal EnableExtensions

rem Builds a WordPress plugin zip. The archive root is the plugin folder
rem so WordPress installs it as wp-content/plugins/CwmMcpLicense/.

set "ROOT=%~dp0"
set "PLUGIN_SLUG=CwmMcpLicense"
set "MAIN=%ROOT%cwm-mcp-license.php"

if not exist "%MAIN%" (
    echo Missing plugin file: "%MAIN%"
    goto :fail
)

set "VERSION="
for /f "tokens=2 delims=:" %%V in ('findstr /C:"Version:" "%MAIN%"') do (
    if not defined VERSION set "VERSION=%%V"
)
if not defined VERSION set "VERSION=0.0.0"
for /f "tokens=* delims= " %%T in ("%VERSION%") do set "VERSION=%%T"

set "DIST=%ROOT%..\dist"
if not exist "%DIST%" mkdir "%DIST%"
if errorlevel 1 (
    echo Could not create "%DIST%"
    goto :fail
)

set "STAGE=%TEMP%\cwm-mcp-license-pack-%RANDOM%%RANDOM%"
mkdir "%STAGE%\%PLUGIN_SLUG%"
if errorlevel 1 (
    echo Could not create staging folder "%STAGE%"
    goto :fail
)

rem /XF skips this script and any previous zips. Robocopy uses 0-7 for success.
robocopy "%ROOT%." "%STAGE%\%PLUGIN_SLUG%" /E /NFL /NDL /NJH /NJS /nc /ns /np /XD .git dist .cursor /XF *.bat *.zip >nul
if errorlevel 8 (
    echo Failed to copy plugin files.
    goto :cleanup_fail
)

set "ZIP=%DIST%\cwm-mcp-license-%VERSION%.zip"
if exist "%ZIP%" del /f "%ZIP%"

pushd "%STAGE%"
tar -a -c -f "%ZIP%" "%PLUGIN_SLUG%"
set "TAR_ERR=%ERRORLEVEL%"
popd

if not "%TAR_ERR%"=="0" (
    echo Failed to create zip. tar exit code %TAR_ERR%.
    goto :cleanup_fail
)

rmdir /s /q "%STAGE%" >nul 2>&1
echo Created "%ZIP%"
if /I not "%~1"=="--no-pause" pause
exit /b 0

:cleanup_fail
if exist "%STAGE%" rmdir /s /q "%STAGE%" >nul 2>&1
:fail
if /I not "%~1"=="--no-pause" pause
exit /b 1
