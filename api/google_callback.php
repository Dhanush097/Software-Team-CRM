<?php
/**
 * Direct endpoint for Google OAuth Redirect URI:
 * e.g., http://localhost/software_team/api/google_callback.php
 */
$_GET['action'] = 'google_callback';
require_once __DIR__ . '/auth.php';
