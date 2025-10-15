@echo off
echo ================================
echo   MAESTRO Dashboard - Symfony 7.3
echo ================================
echo.
echo Compilation des assets...
"C:\wamp64-v3\bin\php\php8.2.26\php.exe" bin/console asset-map:compile
echo.
echo Demarrage du serveur de developpement...
echo.
echo URL: http://localhost:8000
echo.
echo Appuyez sur Ctrl+C pour arreter le serveur
echo.
"C:\wamp64-v3\bin\php\php8.2.26\php.exe" -S localhost:8000 -t public public/router.php
