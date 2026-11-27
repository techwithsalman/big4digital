@echo off
setlocal EnableExtensions
title Big4Digital Retail Mart ^& General Store - Windows Desktop Installer
echo ==============================================================================
echo   DIGITALSNOVA RETAIL MART ^& GENERAL STORE
echo   Windows Desktop App Shortcut Installer ^& Standalone POS Launcher
echo ==============================================================================
echo.
set "DN_URL=https://big4digital.com/software/portal/index.html?sector=RETAIL_MART&desktop=1&mode=register"
set "DN_LINK_NAME=Retail Mart POS - Big4Digital POS.lnk"
set "DN_DESC=High-speed Barcode Scanner Checkout, Hold Bill F4, 1-Click Z-Report Closing."
set "DN_PROFILE=%LOCALAPPDATA%\Big4Digital\Big4Digital-Retail-POS"
if not exist "%DN_PROFILE%" mkdir "%DN_PROFILE%" >nul 2>&1
set "DN_BROWSER="
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "DN_BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not defined DN_BROWSER if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "DN_BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not defined DN_BROWSER if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" set "DN_BROWSER=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
if not defined DN_BROWSER if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" set "DN_BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not defined DN_BROWSER if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" set "DN_BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if not defined DN_BROWSER if exist "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" set "DN_BROWSER=%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe"

if not defined DN_BROWSER goto :open_default
set "VBS=%TEMP%\dn_shortcut_%RANDOM%.vbs"
if not defined VBS goto :launch_app
echo [1/2] Creating Desktop ^& Start Menu shortcuts...
(
  echo Set oWS = WScript.CreateObject^("WScript.Shell"^)
  echo Set oEnv = oWS.Environment^("Process"^)
  echo sUrl = oEnv^("DN_URL"^)
  echo sBrowser = oEnv^("DN_BROWSER"^)
  echo sProfile = oEnv^("DN_PROFILE"^)
  echo sLink = oEnv^("DN_LINK_NAME"^)
  echo sDesc = oEnv^("DN_DESC"^)
  echo sDesktop = oWS.SpecialFolders^("Desktop"^)
  echo sPrograms = oWS.SpecialFolders^("Programs"^)
  echo Set oL1 = oWS.CreateShortcut^(sDesktop ^& "\" ^& sLink^)
  echo oL1.TargetPath = sBrowser
  echo oL1.Arguments = "--app=" ^& Chr^(34^) ^& sUrl ^& Chr^(34^) ^& " --user-data-dir=" ^& Chr^(34^) ^& sProfile ^& Chr^(34^) ^& " --start-maximized"
  echo oL1.Description = sDesc
  echo oL1.IconLocation = sBrowser ^& ",0"
  echo oL1.Save
  echo Set oL2 = oWS.CreateShortcut^(sPrograms ^& "\" ^& sLink^)
  echo oL2.TargetPath = sBrowser
  echo oL2.Arguments = "--app=" ^& Chr^(34^) ^& sUrl ^& Chr^(34^) ^& " --user-data-dir=" ^& Chr^(34^) ^& sProfile ^& Chr^(34^) ^& " --start-maximized"
  echo oL2.Description = sDesc
  echo oL2.IconLocation = sBrowser ^& ",0"
  echo oL2.Save
) > "%VBS%"
cscript //nologo "%VBS%" >nul 2>&1
if defined VBS if exist "%VBS%" del /f /q "%VBS%" >nul 2>&1
:launch_app
echo [2/2] Launching Retail Mart POS in standalone Desktop App Mode (Isolated Registration Profile)...
start "" "%DN_BROWSER%" --app="%DN_URL%" --user-data-dir="%DN_PROFILE%" --start-maximized
goto :done
:open_default
echo Chrome or Microsoft Edge not found in standard folders. Opening default browser...
start "" "%DN_URL%"
:done
echo.
echo Shortcut saved to your Desktop. You can close this window safely.
exit /b 0
