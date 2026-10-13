@echo off
rem The Display Solution: turn on auto-start for the TV app on a Fire TV (Fire OS 8), or any Android TV without the setting.
rem
rem Android 10 and later let an app come back to the screen by itself only with "Display over other apps", and Fire OS 8 has
rem no screen to allow it, so it is allowed once from a computer on the same Wi-Fi with adb. This also lets the app install
rem its own updates ("Install unknown apps"). Double-click this file; administrator rights are not needed.
rem The TV shows its address in its menu: hold Back for 5 seconds, then "Turn on auto-start (how to)".
rem Every adb call is given nothing to read (<nul): adb shell would otherwise swallow what is typed next.
setlocal EnableExtensions
title The Display Solution - TV auto-start
set "PKG=com.thedisplaysolution.player"
set "HERE=%~dp0"

echo.
echo  The Display Solution - turn on auto-start on a TV
echo  ==================================================
echo.
echo  Before you start, on the TV:
echo    1. Settings / My Fire TV / About: press OK on the TV's name 7 times.
echo    2. Settings / My Fire TV / Developer Options / ADB Debugging: On.
echo  This computer and the TV must be on the same Wi-Fi.
echo.

call :find_adb
if not defined ADB goto :finish
<nul "%ADB%" start-server >nul 2>&1

:next_tv
call :one_tv
echo.
set "MORE="
set /p "MORE= Set up another TV? Type Y and press Enter, or just press Enter to finish: "
if /i "%MORE%"=="Y" goto :next_tv
if /i "%MORE%"=="YES" goto :next_tv

:finish
echo.
pause
exit /b 0


:find_adb
rem adb: on the PATH, in Android Studio's SDK, beside this file, or else Google's own platform-tools, downloaded here once.
set "ADB="
for %%A in (adb.exe) do set "ADB=%%~$PATH:A"
if not defined ADB if exist "%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe" set "ADB=%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe"
if not defined ADB if exist "%HERE%platform-tools\adb.exe" set "ADB=%HERE%platform-tools\adb.exe"
if defined ADB exit /b 0

echo  adb is not on this computer. Downloading Google's platform-tools, about 7 MB, into this folder...
set "DEST=%HERE%"
powershell -NoProfile -Command "$ErrorActionPreference = 'Stop'; [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; $zip = Join-Path $env:TEMP 'tds-platform-tools.zip'; Invoke-WebRequest -UseBasicParsing -Uri 'https://dl.google.com/android/repository/platform-tools-latest-windows.zip' -OutFile $zip; Expand-Archive -Force -Path $zip -DestinationPath $env:DEST; Remove-Item $zip"
if exist "%HERE%platform-tools\adb.exe" set "ADB=%HERE%platform-tools\adb.exe"
if defined ADB exit /b 0
echo.
echo  [PROBLEM] adb could not be downloaded. Check the internet connection and run this file again.
exit /b 1


:one_tv
echo.
set "IP="
set /p "IP= Type the TV's address, for example 192.168.1.50, and press Enter: "
if not defined IP goto :one_tv
set "IP=%IP: =%"
echo %IP%| findstr /r /x "[0-9][0-9.]*[0-9]" >nul
if errorlevel 1 (
    echo  That is not an address like 192.168.1.50. The TV's menu shows it.
    goto :one_tv
)
set "TARGET=%IP%:5555"

<nul "%ADB%" disconnect %TARGET% >nul 2>&1
echo.
echo  Connecting to the TV at %IP% ...
rem A TV that cannot be reached at all is said at once (tried twice: it may have only just joined the Wi-Fi), rather
rem than after two minutes of waiting for an Allow that cannot come.
call :connect
if not defined CONNECTED (
    ping -n 3 127.0.0.1 >nul
    call :connect
)
if not defined CONNECTED (
    echo.
    echo  [PROBLEM] This computer cannot reach a TV at %IP%. Check the address, that the TV is on and on the same
    echo            Wi-Fi as this computer, and that ADB Debugging is On. Then try again.
    goto :done_with_tv
)
echo  On the TV a window asks to allow this computer: tick "Always allow from this computer" and choose OK.

set /a TRIES=0
:wait_for_allow
set "STATE="
for /f "delims=" %%S in ('call "%ADB%" -s %TARGET% get-state 2^>nul ^<nul') do set "STATE=%%S"
if "%STATE%"=="device" goto :allowed
set /a TRIES+=1
if %TRIES% GEQ 60 (
    echo.
    echo  [PROBLEM] The TV did not allow this computer. On the TV choose OK when it asks, then try again.
    goto :done_with_tv
)
rem Gone from the list (the TV turned the window down, or slept): asked again.
if not defined STATE call :connect
<nul set /p "=."
rem Two seconds (ping waits a second between its tries; timeout would refuse to run without a keyboard).
ping -n 3 127.0.0.1 >nul
goto :wait_for_allow

:allowed
echo.
echo  Connected.
<nul "%ADB%" -s %TARGET% shell pm list packages %PKG% | findstr /c:"package:%PKG%" >nul
if errorlevel 1 (
    echo.
    echo  [PROBLEM] The Display Solution app is not installed on this TV. Install it, then run this again.
    goto :done_with_tv
)

<nul "%ADB%" -s %TARGET% shell appops set %PKG% SYSTEM_ALERT_WINDOW allow
<nul "%ADB%" -s %TARGET% shell appops set %PKG% REQUEST_INSTALL_PACKAGES allow

set "FAILED="
<nul "%ADB%" -s %TARGET% shell appops get %PKG% SYSTEM_ALERT_WINDOW | findstr /c:"allow" >nul || set "FAILED=auto-start"
<nul "%ADB%" -s %TARGET% shell appops get %PKG% REQUEST_INSTALL_PACKAGES | findstr /c:"allow" >nul || set "FAILED=%FAILED% updates"
if defined FAILED (
    echo.
    echo  [PROBLEM] The TV did not keep the setting for: %FAILED%. Run this file again.
    goto :done_with_tv
)

<nul "%ADB%" -s %TARGET% shell am start -n %PKG%/.MainActivity >nul 2>&1
echo.
echo  [DONE] Auto-start is on, and the app may install its own updates.
echo         The player now opens by itself after the TV starts.

:done_with_tv
<nul "%ADB%" disconnect %TARGET% >nul 2>&1
echo.
echo  On the TV, turn ADB Debugging Off again: Settings / My Fire TV / Developer Options / ADB Debugging.
exit /b 0


:connect
rem Whether adb could open a line to the TV: "connected to", "already connected to", or an older adb's "failed to
rem authenticate", which means the TV is there and has not allowed this computer yet.
set "CONNECTED="
for /f "delims=" %%L in ('call "%ADB%" connect %TARGET% 2^>^&1 ^<nul') do (
    echo %%L| findstr /i /c:"connected to" /c:"authenticate" >nul && set "CONNECTED=1"
)
exit /b 0
