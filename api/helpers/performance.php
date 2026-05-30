<?php

if (!function_exists('initApiResponse')) {
    function initApiResponse(array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']): void
    {
        // Keep payloads smaller over slow ISP links.
        if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
            ob_start('ob_gzhandler');
        }

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=UTF-8');

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit();
        }
    }
}

if (!function_exists('setApiErrorMode')) {
    function setApiErrorMode(bool $debug = false): void
    {
        if ($debug) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            return;
        }

        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
        ini_set('display_errors', '0');
    }
}
