<?php

/**
 * WolfTeam2D - 2D Side-Scroll Multiplayer Game Server
 * Entry point: php backend/server.php
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Ensure we're running from project root
$projectRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . basename(__DIR__);
chdir(dirname(__FILE__) . '/..');

require_once __DIR__ . '/bootstrap.php';
