<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

function finance_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function finance_error(string $message, int $statusCode = 400, array $extra = []): void {
    finance_response(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), $statusCode);
}

function finance_connection(): PDO {
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $database = new Database();
    $db = $database->getConnection();

    if (!$db instanceof PDO) {
        finance_error('Database connection failed', 500);
    }

    return $db;
}

function finance_request_headers(): array {
    $headers = [];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    }

    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $normalized = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$normalized] = $value;
        }
    }

    $normalizedHeaders = [];
    foreach ($headers as $key => $value) {
        $normalizedHeaders[strtolower($key)] = $value;
    }

    return $normalizedHeaders;
}

function finance_token(): ?string {
    $headers = finance_request_headers();
    $authHeader = $headers['authorization'] ?? '';

    if ($authHeader === '') {
        return null;
    }

    if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function finance_user(bool $adminOnly = true): array {
    static $user = null;

    if (is_array($user)) {
        if ($adminOnly && (($user['role'] ?? '') !== 'admin')) {
            finance_error('Admin access required', 403);
        }

        return $user;
    }

    $token = finance_token();
    if ($token === null) {
        finance_error('Authentication token required', 401);
    }

    $decoded = JWT::decode($token);
    if (!$decoded || !is_array($decoded)) {
        finance_error('Invalid or expired token', 401);
    }

    if ($adminOnly && (($decoded['role'] ?? '') !== 'admin')) {
        finance_error('Admin access required', 403);
    }

    $user = $decoded;
    return $user;
}

function finance_payload(): array {
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false || trim($rawInput) === '') {
        return [];
    }

    $decoded = json_decode($rawInput, true);
    if (!is_array($decoded)) {
        finance_error('Invalid JSON payload', 400);
    }

    return $decoded;
}

function finance_clean_text($value): string {
    return trim((string)$value);
}

function finance_service_type(?string $value, string $default = 'general'): string {
    $value = strtolower(trim((string)$value));
    if ($value === '' || $value === 'all') {
        return $default;
    }

    $value = preg_replace('/[^a-z0-9_\- ]/', '', $value) ?? '';
    $value = str_replace(' ', '_', $value);

    return $value === '' ? $default : $value;
}

function finance_float_value($value, string $fieldName, bool $required = false, float $default = 0.0): float {
    if ($value === null || $value === '') {
        if ($required) {
            finance_error("{$fieldName} is required", 400);
        }

        return round($default, 2);
    }

    if (!is_numeric($value)) {
        finance_error("{$fieldName} must be numeric", 400);
    }

    return round((float)$value, 2);
}

function finance_int_value($value, string $fieldName, bool $required = false): ?int {
    if ($value === null || $value === '') {
        if ($required) {
            finance_error("{$fieldName} is required", 400);
        }

        return null;
    }

    if (!is_numeric($value)) {
        finance_error("{$fieldName} must be numeric", 400);
    }

    return (int)$value;
}

function finance_date_value(?string $value, string $fieldName, bool $required = true): ?string {
    $value = trim((string)$value);

    if ($value === '') {
        if ($required) {
            finance_error("{$fieldName} is required", 400);
        }

        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        finance_error("{$fieldName} must use YYYY-MM-DD format", 400);
    }

    return $value;
}

function finance_month_value(?string $value, string $fieldName = 'month', bool $required = true): ?string {
    $value = trim((string)$value);

    if ($value === '') {
        if ($required) {
            finance_error("{$fieldName} is required", 400);
        }

        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
        finance_error("{$fieldName} must use YYYY-MM format", 400);
    }

    return $value;
}

function finance_user_row(PDO $db, int $userId): ?array {
    $query = "SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function finance_query_filters(): array {
    $serviceTypeInput = finance_clean_text($_GET['service_type'] ?? 'all');

    return [
        'staff_id' => isset($_GET['staff_id']) && $_GET['staff_id'] !== '' ? (int)$_GET['staff_id'] : null,
        'year' => isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null,
        'month' => isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null,
        'service_type' => $serviceTypeInput === '' || strtolower($serviceTypeInput) === 'all'
            ? 'all'
            : finance_service_type($serviceTypeInput),
        'from_date' => finance_date_value($_GET['from_date'] ?? '', 'from_date', false),
        'to_date' => finance_date_value($_GET['to_date'] ?? '', 'to_date', false),
    ];
}
