@echo off
title Software Team Phone Mirror (scrcpy)
echo ===================================================
echo     Connecting to your Android Phone...
echo     Make sure USB Debugging is ON on your phone.
echo ===================================================

set SCRCPY_DIR=D:\Websites\Internal_Company\Sarekam_app\App\Tools\scrcpy
cd /d "%SCRCPY_DIR%"

echo [1/3] Enabling Port Forwarding for Live App...
adb.exe reverse tcp:8080 tcp:80

echo [2/3] Opening Live Software Team App on Phone...
adb.exe shell am start -a android.intent.action.VIEW -d "http://localhost:8080/software_team/"

echo [3/3] Launching Live Phone Mirror (scrcpy)...
scrcpy.exe --window-title="Software Team ? Live Phone Mirror" --always-on-top

if errorlevel 1 (
    echo.
    echo ---------------------------------------------------
    echo No Android device detected!
    echo Steps to connect:
    echo 1. Connect phone with USB cable.
    echo 2. Enable Developer Options - USB Debugging on phone.
    echo 3. Run this shortcut again!
    echo ---------------------------------------------------
    pause
)
