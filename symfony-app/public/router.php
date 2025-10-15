<?php

/**
 * Router script for PHP's built-in web server
 * This allows serving static assets properly
 */

// Allow serving static files from public directory
if (preg_match('/\.(?:png|jpg|jpeg|gif|ico|css|js|woff|woff2|ttf|svg|map)$/', $_SERVER["REQUEST_URI"])) {
    return false; // Serve the requested resource as-is
}

// Otherwise, forward to index.php
require __DIR__ . '/index.php';
