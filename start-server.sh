#!/bin/bash
echo "╔══════════════════════════════════════╗"
echo "║     WolfTeam2D v3 - Game Server      ║"
echo "║  Emotes · Weapons · BP · Crates · FX ║"
echo "╚══════════════════════════════════════╝"
cd "$(dirname "$0")"
if [ ! -d vendor ]; then
    echo "[*] Installing PHP dependencies..."
    composer install --no-dev 2>&1 || { echo "ERROR: composer not found. Install from https://getcomposer.org"; exit 1; }
fi
mkdir -p data
echo "[*] Starting WebSocket server on ws://localhost:8080"
echo "[*] Open frontend/index.html in your browser"
echo ""
php backend/server.php
