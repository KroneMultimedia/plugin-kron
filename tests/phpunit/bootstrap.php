<?php
/**
 * PHPUnit bootstrap file for Kron plugin
 */

// Basic test environment setup
define('KRN_HOST_API', 'test-api.krone.at');
define('WP_HOME', 'test-www.krone.at');
define('KRN_HOST_MOBIL', 'test-mobil.krone.at');
define('KRN_IS_TESTING', 1);

// Load Composer autoloader
$composerAutoload = dirname(dirname(__DIR__)) . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Basic WordPress constants and functions for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

// Mock basic WordPress functions that might be needed
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}
