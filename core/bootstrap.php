<?php
declare(strict_types=1);
/**
 * Core bootstrap — shared by the public site, the admin panel and the installer.
 * The panel package (core/ + admin/) is self-contained and only needs this folder.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

foreach (['helpers', 'db', 'security', 'i18n', 'markdown', 'upload', 'totp', 'migrate', 'sites', 'backup'] as $f) require __DIR__ . '/' . $f . '.php';

// Errors: never shown to visitors; written to data/php-error.log (set 'debug' => true in config.php to display).
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/data/php-error.log');
ini_set('display_errors', cfg('debug') ? '1' : '0');
error_reporting(E_ALL);
