<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once dirname(__DIR__) . '/helpers/jwt_helper.php';
require_once dirname(__DIR__) . '/middleware/Auth.php';

const TOKEN_TTL_SECONDS = 180 * 24 * 60 * 60; // 180 days
const REFRESH_GRACE_SECONDS = 30 * 24 * 60 * 60; // allow refresh within 30 days after expiry

function decodeJwtPayload(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]), true);
    if ($decoded === false) {
        return null;
    }

    $payload = json_decode($decoded, true);
    return is_array($payload) ? $payload : null;
}

function encodeRefreshToken(array $payload): string {
    $issuedAt = time();

    return JWT::encode([
        'user_id' => $payload['user_id'] ?? null,
        'email' => $payload['email'] ?? '',
        'name' => $payload['name'] ?? '',
        'role' => $payload['role'] ?? 'user',
        'iat' => $issuedAt,
        'exp' => $issuedAt + TOKEN_TTL_SECONDS,
    ]);
}

$token = Auth::getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication token required',
    ]);
    exit();
}

$verifiedPayload = Auth::verifyToken($token);
if ($verifiedPayload) {
    echo json_encode([
        'success' => true,
        'token' => encodeRefreshToken($verifiedPayload),
        'message' => 'Token refreshed',
    ]);
    exit();
}

$rawPayload = decodeJwtPayload($token);
if (!$rawPayload) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token',
    ]);
    exit();
}

$expiresAt = isset($rawPayload['exp']) ? (int) $rawPayload['exp'] : 0;
if ($expiresAt <= 0 || ($expiresAt + REFRESH_GRACE_SECONDS) < time()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Refresh window expired',
    ]);
    exit();
}

$parts = explode('.', $token);
$expectedSignature = hash_hmac('sha256', $parts[0] . "." . $parts[1], 'sun-computers-secret-key-2025', true);
$providedSignature = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[2]), true);

if ($providedSignature === false || !hash_equals($providedSignature, $expectedSignature)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid token signature',
    ]);
    exit();
}

echo json_encode([
    'success' => true,
    'token' => encodeRefreshToken($rawPayload),
    'message' => 'Token refreshed',
]);
