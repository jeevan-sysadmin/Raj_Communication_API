<?php

function apiEnableCompression() {
    static $enabled = false;
    if ($enabled || headers_sent()) {
        return;
    }

    $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (strpos($acceptEncoding, 'gzip') !== false && function_exists('ob_gzhandler') && !ini_get('zlib.output_compression')) {
        ob_start('ob_gzhandler');
    }

    $enabled = true;
}

function apiGetCacheDir() {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'raj_communication_api_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function apiGetAuthCacheFragment() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $auth = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
        }
    }

    return $auth === '' ? 'guest' : sha1($auth);
}

function apiBuildCacheFile($namespace) {
    $uri = $_SERVER['REQUEST_URI'] ?? $namespace;
    $key = sha1($namespace . '|' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . '|' . $uri . '|' . apiGetAuthCacheFragment());
    return apiGetCacheDir() . DIRECTORY_SEPARATOR . $key . '.json';
}

function apiServeCachedJson($namespace, $ttlSeconds) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $ttlSeconds <= 0) {
        return false;
    }

    $cacheFile = apiBuildCacheFile($namespace);
    if (!is_file($cacheFile)) {
        return false;
    }

    $payload = @file_get_contents($cacheFile);
    if ($payload === false) {
        return false;
    }

    $cached = json_decode($payload, true);
    if (!is_array($cached) || !isset($cached['created_at'], $cached['body'])) {
        return false;
    }

    if ((time() - (int)$cached['created_at']) > $ttlSeconds) {
        @unlink($cacheFile);
        return false;
    }

    header('X-API-Cache: HIT');
    echo $cached['body'];
    return true;
}

function apiCacheJsonResponse($namespace, $body, $statusCode = 200) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $statusCode !== 200 || $body === '') {
        return;
    }

    $cacheFile = apiBuildCacheFile($namespace);
    $payload = json_encode([
        'created_at' => time(),
        'body' => $body,
    ]);

    if ($payload !== false) {
        @file_put_contents($cacheFile, $payload, LOCK_EX);
    }
}

function apiRememberSchema($table, array $columns) {
    if (empty($columns)) {
        return;
    }

    $file = apiGetCacheDir() . DIRECTORY_SEPARATOR . 'schema_' . preg_replace('/[^a-z0-9_]+/i', '_', $table) . '.json';
    $payload = json_encode([
        'created_at' => time(),
        'columns' => array_keys($columns),
    ]);

    if ($payload !== false) {
        @file_put_contents($file, $payload, LOCK_EX);
    }
}

function apiLoadRememberedSchema($table, $maxAgeSeconds = 86400) {
    $file = apiGetCacheDir() . DIRECTORY_SEPARATOR . 'schema_' . preg_replace('/[^a-z0-9_]+/i', '_', $table) . '.json';
    if (!is_file($file)) {
        return [];
    }

    $payload = @file_get_contents($file);
    if ($payload === false) {
        return [];
    }

    $cached = json_decode($payload, true);
    if (!is_array($cached) || !isset($cached['created_at'], $cached['columns']) || !is_array($cached['columns'])) {
        return [];
    }

    if ((time() - (int)$cached['created_at']) > $maxAgeSeconds) {
        return [];
    }

    $columns = [];
    foreach ($cached['columns'] as $column) {
        $columns[$column] = true;
    }

    return $columns;
}

function apiShouldAutoMigrateSchema() {
    $value = getenv('RAJ_API_AUTO_MIGRATE_SCHEMA');
    if ($value === false || $value === null || $value === '') {
        return false;
    }

    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}
