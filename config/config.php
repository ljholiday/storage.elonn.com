<?php

declare(strict_types=1);

$environment = storage_string_config('APP_ENV', 'local');

return [
    'app' => [
        'environment' => $environment,
        'debug' => storage_bool_config('APP_DEBUG', false),
        'url' => storage_service_url('APP_URL', $environment, 'https://storage.elonn.local', 'https://storage.elonn.com'),
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => storage_string_config('DB_HOST', '127.0.0.1'),
        'port' => storage_int_config('DB_PORT', 3306),
        'name' => storage_database_name($environment, storage_string_config('DB_DATABASE', '')),
        'username' => storage_string_config('DB_USERNAME', storage_string_config('DB_USER', 'elonn_storage')),
        'password' => storage_string_config('DB_PASSWORD', storage_string_config('DB_PASS', '')),
        'charset' => storage_string_config('DB_CHARSET', 'utf8mb4'),
    ],
    'storage' => [
        'resource_path' => storage_path_config('STORAGE_RESOURCE_PATH', BASE_PATH . '/storage/resources'),
    ],
    'service_auth' => [
        'paint.elonn' => storage_string_config('ELONN_PAINT_SERVICE_TOKEN'),
        'messages.elonn' => storage_string_config('ELONN_MESSAGES_SERVICE_TOKEN'),
        'admin.elonn' => storage_string_config('ELONN_ADMIN_SERVICE_TOKEN'),
    ],
];

function storage_string_config(string $key, string $default = ''): string
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    return trim((string) $value);
}

function storage_int_config(string $key, int $default): int
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    return max(1, (int) $value);
}

function storage_bool_config(string $key, bool $default): bool
{
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? $default;
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL);
}

function storage_service_url(string $key, string $environment, string $localDefault, string $productionDefault): string
{
    $default = in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true)
        ? $localDefault
        : $productionDefault;

    return rtrim(storage_string_config($key, $default), '/');
}

function storage_database_name(string $environment, string $fallback): string
{
    if ($fallback !== '') {
        return $fallback;
    }

    return in_array(strtolower($environment), ['local', 'development', 'dev', 'testing'], true)
        ? 'elonn_storage'
        : 'ljholida_elonn_storage';
}

function storage_path_config(string $key, string $default): string
{
    $value = storage_string_config($key, $default);
    if ($value === '') {
        return $default;
    }

    return str_starts_with($value, '/') ? $value : BASE_PATH . '/' . $value;
}
