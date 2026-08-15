<?php
/**
 * Standalone Plugin Download Script
 * Bypasses WordPress and Apache content negotiation
 *
 * Usage: /wp-content/plugins/peanut-license-server/download.php?plugin=peanut-suite
 */

// Security: Load WordPress and require authentication
if (!defined('ABSPATH')) {
    // Find WordPress root (go up from plugin directory)
    $wp_root = dirname(dirname(dirname(dirname(__FILE__))));
    require_once $wp_root . '/wp-load.php';
}

// Require user to be logged in
if (!is_user_logged_in()) {
    wp_die('Unauthorized access.', 'Unauthorized', array('response' => 403));
}

// Require manage_options capability
if (!current_user_can('manage_options')) {
    wp_die('Insufficient permissions to download plugins.', 'Forbidden', array('response' => 403));
}

// Prevent any output
error_reporting(0);
ini_set('display_errors', 0);

// Valid plugins
$valid_plugins = ['peanut-suite', 'formflow', 'peanut-booker'];

// Get requested plugin
$plugin = isset($_GET['plugin']) ? preg_replace('/[^a-z0-9-]/', '', $_GET['plugin']) : 'peanut-suite';

if (!in_array($plugin, $valid_plugins, true)) {
    http_response_code(400);
    die('Invalid plugin');
}

// Define upload base (WordPress already loaded)
$upload_base = WP_CONTENT_DIR . '/uploads/' . $plugin . '/';
$releases_dir = dirname(__FILE__) . '/releases/';

$file = null;

// Check uploads directory first
if (is_dir($upload_base)) {
    // Check for exact name
    if (file_exists($upload_base . $plugin . '.zip')) {
        $file = $upload_base . $plugin . '.zip';
    } else {
        // Check for versioned files
        $files = glob($upload_base . $plugin . '-*.zip');
        if (!empty($files)) {
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $file = $files[0];
        }
    }
}

// Check releases directory as fallback
if (!$file && file_exists($releases_dir . $plugin . '.zip')) {
    $file = $releases_dir . $plugin . '.zip';
}

if (!$file || !file_exists($file)) {
    http_response_code(404);
    die('File not found');
}

if (!peanut_is_path_within_roots($file, [$upload_base, $releases_dir])) {
    http_response_code(403);
    die('Access denied');
}

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Get file info
$filename = basename($file);
$filesize = filesize($file);

// Send headers - be explicit about everything
header('HTTP/1.1 200 OK');
header('Content-Type: application/octet-stream');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $filesize);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

// Flush headers
flush();

// Output file
readfile($file);
exit;
