@echo off
setlocal EnableExtensions DisableDelayedExpansion
title Aktifkan HansDev Forwarder
set "ADB=%~dp0platform-tools\adb.exe"
set "NO_PAUSE=0"
if "%~1"=="--no-pause" set "NO_PAUSE=1"
set "RESULT=1"
set "STATE_FILE=%TEMP%\hansdev-listener-%RANDOM%-%RANDOM%.tmp"
echo HansDev Forwarder - Aktivasi listener
echo Lokasi ADB: "%ADB%"
echo Hubungkan satu HP, aktifkan USB debugging dan buka kunci layarnya.
if not exist "%ADB%" goto missing_adb
:scan
set "COUNT=0"
set "TOTAL=0"
set "SERIAL="
set "ISSUE="
"%ADB%" devices >"%STATE_FILE%" 2>&1
if errorlevel 1 goto adb_error
for /f "usebackq tokens=1,2" %%A in ("%STATE_FILE%") do (
    if "%%B"=="device" (
        set /a COUNT+=1 >nul
        set /a TOTAL+=1 >nul
        set "SERIAL=%%A"
    )
    if "%%B"=="unauthorized" (
        set /a TOTAL+=1 >nul
        set "ISSUE=unauthorized"
    )
    if "%%B"=="offline" (
        set /a TOTAL+=1 >nul
        set "ISSUE=offline"
    )
)
if %TOTAL% GTR 1 goto multiple
if "%COUNT%"=="1" goto activate
type "%STATE_FILE%"
echo.
echo HP belum siap. Jika unauthorized, tekan Allow / Izinkan di layar HP.
echo Jika offline atau kosong, periksa kabel data dan USB debugging.
if "%NO_PAUSE%"=="1" goto finish
set "ANSWER="
set /p "ANSWER=Tekan Enter untuk periksa ulang, atau ketik Q lalu Enter untuk keluar: "
if /i "%ANSWER%"=="Q" goto finish
goto scan
:activate
"%ADB%" -s "%SERIAL%" shell pm path id.hansdev.forwarder >"%STATE_FILE%" 2>&1
if errorlevel 1 goto adb_error
findstr /b /l /c:"package:" "%STATE_FILE%" >nul
if errorlevel 1 goto missing_app
"%ADB%" -s "%SERIAL%" shell cmd notification allow_listener id.hansdev.forwarder/.ForwardListener >"%STATE_FILE%" 2>&1
if errorlevel 1 goto adb_error
"%ADB%" -s "%SERIAL%" shell settings get secure enabled_notification_listeners >"%STATE_FILE%" 2>&1
if errorlevel 1 goto adb_error
findstr /l /c:"id.hansdev.forwarder/id.hansdev.forwarder.ForwardListener" /c:"id.hansdev.forwarder/.ForwardListener" "%STATE_FILE%" >nul
if errorlevel 1 goto unverified
echo.
echo BERHASIL: izin listener HansDev Forwarder sudah aktif.
"%ADB%" -s "%SERIAL%" shell am start -n id.hansdev.forwarder/.MainActivity >nul 2>&1
if errorlevel 1 echo Buka aplikasi HansDev Forwarder secara manual.
echo Periksa status Listener terhubung. Jika pengiriman dijeda, aktifkan di aplikasi.
set "RESULT=0"
goto finish
:missing_adb
echo GAGAL: ekstrak seluruh paket. Folder platform-tools harus berada di samping script ini.
goto finish
:missing_app
echo GAGAL: HansDev Forwarder belum terpasang pada HP tersebut. Pasang APK dahulu.
goto finish
:multiple
echo GAGAL: ada lebih dari satu perangkat. Sisakan satu HP sebelum melanjutkan.
goto finish
:unverified
echo GAGAL: izin listener belum terverifikasi. Periksa akses notifikasi di HP.
goto finish
:adb_error
echo GAGAL: ADB menolak perintah. Rincian:
if exist "%STATE_FILE%" type "%STATE_FILE%"
:finish
if exist "%STATE_FILE%" del /q "%STATE_FILE%" >nul 2>&1
if "%NO_PAUSE%"=="0" pause
exit /b %RESULT%
