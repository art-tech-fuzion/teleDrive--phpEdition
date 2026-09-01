<?php
/**
 * TeleDrive Application Entry Point & View Router
 */

define('TELEDRIVE_INIT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Auth.php';

// Route to Dashboard if logged in, otherwise show Login
if (Auth::check()) {
    require_once __DIR__ . '/templates/frontend/app.php';
} else {
    require_once __DIR__ . '/templates/backend/login.php';
}
