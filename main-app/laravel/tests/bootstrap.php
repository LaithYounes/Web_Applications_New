<?php

// Laravel checks server variables before environment variables. Keep both aligned
// with PHPUnit's forced values, even when Compose supplied conflicting settings.
foreach ([
    'APP_ENV', 'APP_KEY', 'APP_MAINTENANCE_DRIVER', 'BCRYPT_ROUNDS',
    'BROADCAST_CONNECTION', 'CACHE_STORE', 'DB_CONNECTION', 'DB_DATABASE',
    'DB_URL', 'MAIL_MAILER', 'QUEUE_CONNECTION', 'ORDER_PUSH_DRIVER',
    'FIREBASE_PROJECT_ID', 'FIREBASE_CREDENTIALS', 'SESSION_DRIVER',
    'PULSE_ENABLED', 'TELESCOPE_ENABLED', 'NIGHTWATCH_ENABLED', 'JWT_SECRET',
] as $name) {
    $_SERVER[$name] = $_ENV[$name];
    putenv($name.'='.$_ENV[$name]);
}

// An unpredictable, nonexistent path prevents loading any runtime config cache.
$configCache = sys_get_temp_dir().'/codex-phpunit-config-'.bin2hex(random_bytes(16)).'.php';
$_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'] = $configCache;
putenv('APP_CONFIG_CACHE='.$configCache);

require dirname(__DIR__).'/vendor/autoload.php';
