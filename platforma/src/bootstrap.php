<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/helpers.php';

$cfgFile = getenv('KURSY_CONFIG') ?: APP_ROOT . '/config/config.php';
if (!is_file($cfgFile)) {
    $cfgFile = null;
}
\App\Config::load($cfgFile ? (require $cfgFile) : []);
if (!\App\Config::get('debug', false)) {
    ini_set('display_errors', '0');
}
date_default_timezone_set(\App\Config::get('timezone', 'Europe/Warsaw'));
mb_internal_encoding('UTF-8');
