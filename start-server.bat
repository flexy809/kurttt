@echo off
echo WolfTeam2D v3 - Emotes, Weapons, Battle Pass, Crates
cd /d %~dp0
if not exist vendor (
    echo Installing dependencies...
    composer install --no-dev
)
if not exist data mkdir data
echo Server starting on ws://localhost:8080
echo Open frontend/index.html in browser
php backend/server.php
pause
