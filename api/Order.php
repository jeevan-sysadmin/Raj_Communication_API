<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendBootstrapJsonError($message, $status = 500) {
    if (!headers_sent()) {
        http_response_code($status);
        header("Content-Type: application/json; charset=UTF-8");
    }
    echo json_encode(["success" => false, "message" => $message]);
    exit();
}

function requireFirstAvailable($paths, $label) {
    foreach ($paths as $path) {
        if (is_string($path) && is_file($path)) {
            require_once $path;
            return;
        }
    }
    sendBootstrapJsonError("Server configuration error: missing {$label}", 500);
}

set_exception_handler(function ($exception) {
    error_log("Order API uncaught exception: " . $exception->getMessage());
    sendBootstrapJsonError("Server error", 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) return;
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");
    }
    echo json_encode(["success" => false, "message" => "Server error"]);
});

requireFirstAvailable([
    __DIR__ . '/config/database.php',
    dirname(__DIR__) . '/api_sync/config/database.php',
    dirname(__DIR__) . '/config/database.php'
], 'database.php');

requireFirstAvailable([
    __DIR__ . '/helpers/jwt_helper.php',
    dirname(__DIR__) . '/api_sync/helpers/jwt_helper.php',
    dirname(__DIR__) . '/helpers/jwt_helper.php'
], 'jwt_helper.php');

requireFirstAvailable([
    __DIR__ . '/helpers/performance.php',
    dirname(__DIR__) . '/api_sync/helpers/performance.php',
    dirname(__DIR__) . '/helpers/performance.php'
], 'performance.php');

apiEnableCompression();

if (!class_exists('Database')) {
    sendBootstrapJsonError('Server configuration error: Database class not found', 500);
}

if (!class_exists('JWT') || !method_exists('JWT', 'decode')) {
    sendBootstrapJsonError('Server configuration error: JWT helper is invalid', 500);
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

function getBearerToken() {
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (!is_array($headers)) $headers = [];
    }
    $authHeader = '';
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    } elseif (function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        if (isset($apacheHeaders['Authorization'])) {
            $authHeader = $apacheHeaders['Authorization'];
        } elseif (isset($apacheHeaders['authorization'])) {
            $authHeader = $apacheHeaders['authorization'];
        }
    }
    if ($authHeader === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if ($authHeader === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ($authHeader && preg_match('/Bearer\\s(\\S+)/', $authHeader, $matches)) {
        return $matches[1];
    }
    return null;
}

$token = getBearerToken();
if (empty($token)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "No token provided"]);
    exit();
}
$decoded = JWT::decode($token);
if (!$decoded) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit();
}

$user_id = $decoded['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

function normalizePaymentStatus($status) {
    if ($status === null || $status === '') return 'pending';
    $status = strtolower(trim($status));
    if ($status === 'partial' || $status === 'partially paid' || $status === 'partially_paid') return 'partially_paid';
    $allowed = ['pending', 'partially_paid', 'paid', 'refunded'];
    return in_array($status, $allowed, true) ? $status : 'pending';
}

function normalizeDateOrNull($value) {
    if ($value === null) return null;
    $trimmed = trim((string)$value);
    if ($trimmed === '' || $trimmed === '0000-00-00') return null;
    return $trimmed;
}

function normalizeRating($value) {
    if ($value === null || $value === '') return null;
    $rating = (int)$value;
    if ($rating < 1 || $rating > 5) return null;
    return $rating;
}

function normalizeProductFlowStatus($status) {
    $normalized = strtolower(trim((string)$status));
    if ($normalized === 'ready') return 'ready';
    if ($normalized === 'replacement') return 'replacement';
    if ($normalized === 'not_ready' || $normalized === 'not ready' || $normalized === 'notready') return 'not ready';
    if ($normalized === 'rajtocom') return 'rajtocom';
    if ($normalized === 'comtoraj') return 'comtoraj';
    if ($normalized === 'deliveryed' || $normalized === 'delivered') return 'deliveryed';
    return 'pending';
}

function normalizeProductStatusMap($value) {
    if ($value === null || $value === '') return [];
    $parsed = $value;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $parsed = $decoded;
        } else {
            return [];
        }
    }
    if (!is_array($parsed)) return [];
    $normalized = [];
    foreach ($parsed as $productId => $status) {
        $pid = (int)$productId;
        if ($pid <= 0) continue;
        $normalized[(string)$pid] = normalizeProductFlowStatus($status);
    }
    return $normalized;
}

function normalizeStatusDateOrNull($value) {
    if ($value === null) return null;
    $trimmed = trim((string)$value);
    if (
        $trimmed === '' ||
        strtolower($trimmed) === 'null' ||
        $trimmed === '0000-00-00' ||
        $trimmed === '0000-00-00 00:00:00'
    ) {
        return null;
    }
    // Normalize Unix timestamps and parseable date strings to a consistent DB/API format.
    if (preg_match('/^\d{10}$/', $trimmed)) {
        return date('Y-m-d H:i:s', (int)$trimmed);
    }
    if (preg_match('/^\d{13}$/', $trimmed)) {
        return date('Y-m-d H:i:s', (int)floor(((int)$trimmed) / 1000));
    }
    $ts = strtotime($trimmed);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

function normalizeProductStatusDatesMap($value) {
    if ($value === null || $value === '') return [];
    $parsed = $value;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $parsed = $decoded;
        } else {
            return [];
        }
    }
    if (!is_array($parsed)) return [];
    $allowedStatuses = ['pending', 'rajtocom', 'comtoraj', 'deliveryed'];
    $normalized = [];
    foreach ($parsed as $productId => $statusDates) {
        $pid = (int)$productId;
        if ($pid <= 0) continue;
        // Accept both object form and JSON-string form for each product row.
        if (is_string($statusDates)) {
            $decodedRow = json_decode($statusDates, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $statusDates = $decodedRow;
            }
        }
        if (!is_array($statusDates)) continue;
        $row = [];
        foreach ($allowedStatuses as $statusKey) {
            $row[$statusKey] = array_key_exists($statusKey, $statusDates)
                ? normalizeStatusDateOrNull($statusDates[$statusKey])
                : null;
        }
        $normalized[(string)$pid] = $row;
    }
    return $normalized;
}

/**
 * CRITICAL FIX: This function preserves ALL historical timestamps
 * When status changes from rajtocom -> comtoraj -> deliveryed,
 * ALL previous timestamps are kept and never lost
 */
function buildProductStatusDatesMap($productIds, $statusMap, $existingDatesMap = [], $incomingDatesMap = [], $existingStatusMap = []) {
    $allowedStatuses = ['pending', 'rajtocom', 'comtoraj', 'deliveryed'];
    $now = date('Y-m-d H:i:s');
    $result = [];

    foreach ($productIds as $pidRaw) {
        $pid = (int)$pidRaw;
        if ($pid <= 0) continue;
        $key = (string)$pid;

        // Start with existing dates from database (PRESERVE ALL)
        $existingRow = (isset($existingDatesMap[$key]) && is_array($existingDatesMap[$key])) 
            ? $existingDatesMap[$key] 
            : [];
        
        $incomingRow = (isset($incomingDatesMap[$key]) && is_array($incomingDatesMap[$key])) 
            ? $incomingDatesMap[$key] 
            : [];

        // Initialize row with INCOMING values first so explicit API payload wins.
        // Existing DB values are used as fallback only when incoming is missing/null.
        $row = [];
        foreach ($allowedStatuses as $statusKey) {
            if (array_key_exists($statusKey, $incomingRow) && $incomingRow[$statusKey] !== null) {
                $row[$statusKey] = normalizeStatusDateOrNull($incomingRow[$statusKey]);
            }
            elseif (array_key_exists($statusKey, $existingRow) && $existingRow[$statusKey] !== null) {
                $row[$statusKey] = normalizeStatusDateOrNull($existingRow[$statusKey]);
            }
            else {
                $row[$statusKey] = null;
            }
        }

        // Set pending date if not set (only from created_at or now)
        if ($row['pending'] === null) {
            $row['pending'] = $now;
        }

        $newStatus = isset($statusMap[$key]) ? normalizeProductFlowStatus($statusMap[$key]) : 'pending';
        $oldStatus = isset($existingStatusMap[$key]) ? normalizeProductFlowStatus($existingStatusMap[$key]) : null;

        // Auto-fill only when that status date is still missing.
        if (in_array($newStatus, $allowedStatuses, true) && !array_key_exists($newStatus, $incomingRow)) {
            // Only set the timestamp for the NEW status if it doesn't exist OR status actually changed
            if ($row[$newStatus] === null || ($oldStatus !== $newStatus && $oldStatus !== null)) {
                $row[$newStatus] = $now;
            }
        }

        // HARD GUARANTEE:
        // Never lose previously stored timestamps.
        // If a status already had a non-null value in DB, keep it when new value is null.
        foreach ($allowedStatuses as $statusKey) {
            if (
                array_key_exists($statusKey, $existingRow) &&
                $existingRow[$statusKey] !== null &&
                (!array_key_exists($statusKey, $row) || $row[$statusKey] === null)
            ) {
                $row[$statusKey] = normalizeStatusDateOrNull($existingRow[$statusKey]);
            }
        }

        $result[$key] = $row;
    }

    return $result;
}

function buildStrictStatusDatesFromProductIds($productIds, $existingDatesMap = []) {
    $now = date('Y-m-d H:i:s');
    $result = [];
    foreach ($productIds as $pidRaw) {
        $pid = (int)$pidRaw;
        if ($pid <= 0) continue;
        $key = (string)$pid;
        $existing = (isset($existingDatesMap[$key]) && is_array($existingDatesMap[$key])) ? $existingDatesMap[$key] : [];
        $result[$key] = [
            'pending' => isset($existing['pending']) && $existing['pending'] !== null ? $existing['pending'] : $now,
            'rajtocom' => isset($existing['rajtocom']) ? normalizeStatusDateOrNull($existing['rajtocom']) : null,
            'comtoraj' => isset($existing['comtoraj']) ? normalizeStatusDateOrNull($existing['comtoraj']) : null,
            'deliveryed' => isset($existing['deliveryed']) ? normalizeStatusDateOrNull($existing['deliveryed']) : null,
        ];
    }
    return $result;
}

function ensureNonEmptyProductStatusDatesMap($productIds, $statusMap, $datesMap) {
    $safeProductIds = normalizeIdList($productIds);
    if (empty($safeProductIds)) {
        return [];
    }
    $safeStatusMap = is_array($statusMap) ? $statusMap : [];
    $safeDatesMap = is_array($datesMap) ? $datesMap : [];
    $rebuilt = buildProductStatusDatesMap($safeProductIds, $safeStatusMap, $safeDatesMap, [], []);
    if (empty($rebuilt)) {
        $rebuilt = buildStrictStatusDatesFromProductIds($safeProductIds, $safeDatesMap);
    }
    return $rebuilt;
}

function buildResponseProductStatusDatesMap($productIds, $statusMap, $datesMap, $createdAt, $updatedAt) {
    $allowedStatuses = ['pending', 'rajtocom', 'comtoraj', 'deliveryed'];
    $normalizedStatusMap = is_array($statusMap) ? $statusMap : [];
    $normalizedDatesMap = is_array($datesMap) ? $datesMap : [];
    $result = [];

    foreach ($productIds as $pidRaw) {
        $pid = (int)$pidRaw;
        if ($pid <= 0) continue;
        $key = (string)$pid;
        $status = isset($normalizedStatusMap[$key]) ? normalizeProductFlowStatus($normalizedStatusMap[$key]) : 'pending';
        $source = (isset($normalizedDatesMap[$key]) && is_array($normalizedDatesMap[$key])) ? $normalizedDatesMap[$key] : [];

        $row = [];
        foreach ($allowedStatuses as $s) {
            $row[$s] = isset($source[$s]) ? normalizeStatusDateOrNull($source[$s]) : null;
        }

        if ($row['pending'] === null) {
            $row['pending'] = normalizeStatusDateOrNull($createdAt) ?: normalizeStatusDateOrNull($updatedAt);
        }
        
        // For the current status, ensure it has a timestamp
        if ($status !== 'pending' && in_array($status, $allowedStatuses, true) && $row[$status] === null) {
            $row[$status] = normalizeStatusDateOrNull($updatedAt);
        }

        $result[$key] = $row;
    }

    return $result;
}

function normalizeRepairingStatusMapForResponse($value) {
    $map = normalizeRepairingStatusMap($value);
    return !empty($map) ? $map : (object)[];
}

function normalizeRepairingStatus($status) {
    $normalized = strtolower(trim((string)$status));
    if ($normalized === 'ready') return 'ready';
    if ($normalized === 'replacement') return 'replacement';
    if ($normalized === 'not_ready' || $normalized === 'not ready' || $normalized === 'notready') return 'not ready';
    return 'not ready';
}

function normalizeRepairingStatusMap($value) {
    if ($value === null || $value === '') return [];
    $parsed = $value;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $parsed = $decoded;
        } else {
            return [];
        }
    }
    if (!is_array($parsed)) return [];
    $normalized = [];
    foreach ($parsed as $productId => $status) {
        $pid = (int)$productId;
        if ($pid <= 0) continue;
        $normalized[(string)$pid] = normalizeRepairingStatus($status);
    }
    return $normalized;
}

function normalizeIssueDescriptionMap($value) {
    if ($value === null || $value === '') return [];
    $parsed = $value;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $parsed = $decoded;
        } else {
            return [];
        }
    }
    if (!is_array($parsed)) return [];
    $normalized = [];
    foreach ($parsed as $productId => $issueText) {
        $pid = (int)$productId;
        if ($pid <= 0) continue;
        $normalized[(string)$pid] = trim((string)$issueText);
    }
    return $normalized;
}

function isPreviewModeRequest($input) {
    if (!is_array($input)) return false;
    if (isset($_GET['preview']) && ($_GET['preview'] === '1' || strtolower((string)$_GET['preview']) === 'true')) {
        return true;
    }
    if (!array_key_exists('preview', $input)) return false;
    $value = $input['preview'];
    if (is_bool($value)) return $value;
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes'], true);
}

function normalizeHandoverType($value) {
    $normalized = strtolower(trim((string)$value));
    $allowed = ['in_hand', 'courier', 'parcel_service'];
    return in_array($normalized, $allowed, true) ? $normalized : null;
}

function normalizeHandoverTypeMap($value) {
    if ($value === null || $value === '') return [];
    $parsed = $value;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $parsed = $decoded;
        } else {
            return [];
        }
    }
    if (!is_array($parsed)) return [];
    $normalized = [];
    foreach ($parsed as $productId => $handoverType) {
        $pid = (int)$productId;
        if ($pid <= 0) continue;
        $normalizedType = normalizeHandoverType($handoverType);
        if ($normalizedType === null) continue;
        $normalized[(string)$pid] = $normalizedType;
    }
    return $normalized;
}

function fetchTableColumns($db, $table, $forceRefresh = false) {
    static $cache = [];
    if (!$forceRefresh && isset($cache[$table])) return $cache[$table];
    if (!$forceRefresh) {
        $remembered = apiLoadRememberedSchema($table);
        if (!empty($remembered)) {
            $cache[$table] = $remembered;
            return $remembered;
        }
    }
    $columns = [];
    try {
        $stmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
        $stmt->bindValue(':table', $table);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['COLUMN_NAME'])) $columns[$row['COLUMN_NAME']] = true;
        }
    } catch (Exception $e) {
        $columns = [];
    }
    if (empty($columns)) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['Field'])) $columns[$row['Field']] = true;
            }
        } catch (Exception $e) {}
    }
    $cache[$table] = $columns;
    apiRememberSchema($table, $columns);
    return $columns;
}

function ensureServiceOrderHandoverColumns($db, $orderColumns) {
    if (!apiShouldAutoMigrateSchema()) return $orderColumns;
    $added = false;
    if (!isset($orderColumns['handover_type'])) {
        try {
            $db->exec("ALTER TABLE service_orders ADD COLUMN handover_type VARCHAR(50) NULL AFTER product_status_map");
            $added = true;
        } catch (Exception $e) {}
    }
    if (!isset($orderColumns['handover_type_map'])) {
        try {
            $db->exec("ALTER TABLE service_orders ADD COLUMN handover_type_map JSON NULL AFTER handover_type");
            $added = true;
        } catch (Exception $e) {}
    }
    return $added ? fetchTableColumns($db, 'service_orders', true) : $orderColumns;
}

function ensureServiceOrderRepairingStatusColumn($db, $orderColumns) {
    if (!apiShouldAutoMigrateSchema()) return $orderColumns;
    if (!isset($orderColumns['repairing_status_map'])) {
        try {
            $db->exec("ALTER TABLE service_orders ADD COLUMN repairing_status_map LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}' CHECK (json_valid(repairing_status_map)) AFTER product_status_map");
            return fetchTableColumns($db, 'service_orders', true);
        } catch (Exception $e) {
            return fetchTableColumns($db, 'service_orders', true);
        }
    }
    return $orderColumns;
}

function ensureServiceOrderProductStatusDatesColumn($db, $orderColumns) {
    if (!apiShouldAutoMigrateSchema()) return $orderColumns;
    $added = false;
    if (!isset($orderColumns['product_status_dates_map'])) {
        try {
            $db->exec("ALTER TABLE service_orders ADD COLUMN product_status_dates_map LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}' CHECK (json_valid(product_status_dates_map)) AFTER product_status_map");
            $added = true;
        } catch (Exception $e) {}
    }
    foreach (['pending_dates', 'rajtocom_dates', 'comtoraj_dates', 'deliveryed_dates'] as $statusDatesColumn) {
        if (!isset($orderColumns[$statusDatesColumn])) {
            try {
                $db->exec("ALTER TABLE service_orders ADD COLUMN {$statusDatesColumn} LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}' CHECK (json_valid({$statusDatesColumn}))");
                $added = true;
            } catch (Exception $e) {}
        }
    }
    return $added ? fetchTableColumns($db, 'service_orders', true) : $orderColumns;
}

function ensureServiceOrderIssueDescriptionMapColumn($db, $orderColumns) {
    if (!apiShouldAutoMigrateSchema()) return $orderColumns;
    if (!isset($orderColumns['issue_description_map'])) {
        try {
            $db->exec("ALTER TABLE service_orders ADD COLUMN issue_description_map LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}' CHECK (json_valid(issue_description_map)) AFTER repairing_status_map");
            return fetchTableColumns($db, 'service_orders', true);
        } catch (Exception $e) {
            return fetchTableColumns($db, 'service_orders', true);
        }
    }
    return $orderColumns;
}

function ensureLegacyIssueDescriptionColumnIsOptional($db, $orderColumns) {
    if (!apiShouldAutoMigrateSchema()) return $orderColumns;
    if (!isset($orderColumns['issue_description'])) return $orderColumns;
    try {
        // Legacy compatibility: old schemas may still require issue_description.
        $db->exec("ALTER TABLE service_orders MODIFY COLUMN issue_description TEXT NULL DEFAULT NULL");
        return fetchTableColumns($db, 'service_orders', true);
    } catch (Exception $e) {
        return $orderColumns;
    }
}

function splitProductStatusDatesByStatus($datesMap, $productIds = []) {
    $allowedStatuses = ['pending', 'rajtocom', 'comtoraj', 'deliveryed'];
    $normalizedDatesMap = is_array($datesMap) ? $datesMap : [];
    $keys = [];
    foreach ($productIds as $productId) {
        $pid = (int)$productId;
        if ($pid > 0) $keys[] = (string)$pid;
    }
    if (empty($keys)) $keys = array_keys($normalizedDatesMap);

    $result = [];
    foreach ($allowedStatuses as $status) {
        $result[$status] = [];
    }

    foreach ($keys as $key) {
        $row = (isset($normalizedDatesMap[$key]) && is_array($normalizedDatesMap[$key])) ? $normalizedDatesMap[$key] : [];
        foreach ($allowedStatuses as $status) {
            $result[$status][$key] = isset($row[$status]) ? normalizeStatusDateOrNull($row[$status]) : null;
        }
    }
    return $result;
}

function fetchOrderPaidTotal($db, $order_id) {
    $query = "SELECT COALESCE(SUM(amount), 0) as total_paid
              FROM payments
              WHERE order_id = :order_id
              AND payment_status IN ('completed', 'paid')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':order_id', $order_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (float)($result['total_paid'] ?? 0);
}

function normalizeIdList($value) {
    if ($value === null) return [];
    $list = [];
    if (is_array($value)) {
        $list = $value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') return [];
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $list = $decoded;
        } else {
            $list = explode(',', $trimmed);
        }
    } else {
        $list = [$value];
    }
    $ids = [];
    foreach ($list as $entry) {
        $id = (int)$entry;
        if ($id > 0) $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function normalizeCompanyProductMap($value) {
    if ($value === null) return [];
    $raw = $value;
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') return [];
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $raw = $decoded;
        } else {
            return [];
        }
    }
    if (!is_array($raw)) return [];
    $map = [];
    foreach ($raw as $companyId => $productIds) {
        $normalizedCompanyId = (int)$companyId;
        if ($normalizedCompanyId <= 0) continue;
        $map[(string)$normalizedCompanyId] = normalizeIdList($productIds);
    }
    return $map;
}

function flattenCompanyProductMap($companyIds, $companyProductMap) {
    $flat = [];
    foreach ($companyIds as $companyId) {
        $key = (string)((int)$companyId);
        if (!isset($companyProductMap[$key]) || !is_array($companyProductMap[$key])) continue;
        foreach ($companyProductMap[$key] as $productId) {
            $id = (int)$productId;
            if ($id > 0) $flat[] = $id;
        }
    }
    return array_values(array_unique($flat));
}

function fetchProductNamesByIds($db, $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, product_name FROM products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = $row['product_name'];
    }
    return $map;
}

function fetchCompanyNamesByIds($db, $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $db->prepare("SELECT id, company_name FROM companies WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    } catch (Exception $e) {
        return [];
    }
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = $row['company_name'];
    }
    return $map;
}

function buildNamesFromIds($ids, $nameMap) {
    $names = [];
    foreach ($ids as $id) {
        if (isset($nameMap[$id])) $names[] = $nameMap[$id];
    }
    return $names;
}

function createPayment($db, $order_id, $order_code, $amount, $payment_type, $staff_id, $notes = '', $payment_method = 'cash', $payment_status = 'completed', $meta = []) {
    if ((float)$amount <= 0) return true;
    $payment_code = 'PAY-' . $order_code . '-' . strtoupper($payment_type) . '-' . time();
    $paymentColumns = fetchTableColumns($db, 'payments');
    $hasSchemaInfo = !empty($paymentColumns);
    $data = [
        'payment_code' => $payment_code,
        'order_id' => $order_id,
        'amount' => $amount,
        'payment_method' => $payment_method,
        'payment_status' => $payment_status,
        'notes' => $notes,
        'created_by' => $staff_id
    ];
    $optional = ['estimated_cost', 'final_cost', 'deposit_amount', 'transaction_id'];
    foreach ($optional as $key) {
        if (array_key_exists($key, $meta)) $data[$key] = $meta[$key];
    }
    $columns = [];
    $placeholders = [];
    $params = [];
    foreach ($data as $column => $value) {
        if ($hasSchemaInfo && !isset($paymentColumns[$column])) continue;
        $columns[] = $column;
        $placeholders[] = ':' . $column;
        $params[':' . $column] = $value;
    }
    if ($hasSchemaInfo) {
        if (isset($paymentColumns['created_at'])) {
            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';
        }
        if (isset($paymentColumns['updated_at'])) {
            $columns[] = 'updated_at';
            $placeholders[] = 'NOW()';
        }
    } else {
        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';
        $columns[] = 'updated_at';
        $placeholders[] = 'NOW()';
    }
    if (empty($columns)) return false;
    $query = "INSERT INTO payments (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    return $stmt->execute();
}

function updateOrderPaymentStatus($db, $order_id, $final_cost, $current_status = null) {
    if ($current_status === null) {
        $statusStmt = $db->prepare("SELECT payment_status FROM service_orders WHERE id = :order_id");
        $statusStmt->bindParam(':order_id', $order_id);
        $statusStmt->execute();
        $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);
        $current_status = $statusRow ? $statusRow['payment_status'] : null;
    }
    $current_status = $current_status ? normalizePaymentStatus($current_status) : null;
    if ($current_status === 'refunded') {
        $payment_status = 'refunded';
    } elseif ((float)$final_cost <= 0) {
        $payment_status = 'paid';
    } else {
        $total_paid = fetchOrderPaidTotal($db, $order_id);
        if ($total_paid >= (float)$final_cost) {
            $payment_status = 'paid';
        } elseif ($total_paid > 0 && $total_paid < (float)$final_cost) {
            $payment_status = 'partially_paid';
        } else {
            $payment_status = 'pending';
        }
    }
    $updateQuery = "UPDATE service_orders SET payment_status = :payment_status, updated_at = NOW() WHERE id = :order_id";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':payment_status', $payment_status);
    $updateStmt->bindParam(':order_id', $order_id);
    return $updateStmt->execute();
}

function ensureOrderPayments($db, $order_id, $order_code, $final_cost, $deposit_amount, $desired_status, $staff_id, $payment_method, $notes, $meta = []) {
    $desired_status = normalizePaymentStatus($desired_status);
    if ($desired_status === 'pending' || $desired_status === 'refunded') return;
    $final_cost = (float)$final_cost;
    $deposit_amount = (float)$deposit_amount;
    if ($final_cost <= 0) return;
    $total_paid = fetchOrderPaidTotal($db, $order_id);
    if ($desired_status === 'paid') {
        $remaining = $final_cost - $total_paid;
        if ($remaining > 0) {
            createPayment($db, $order_id, $order_code, $remaining, 'FINAL', $staff_id, $notes, $payment_method, 'completed', $meta);
        }
        return;
    }
    if ($desired_status === 'partially_paid') {
        $target = min(max($deposit_amount, 0), $final_cost);
        $missing = $target - $total_paid;
        if ($missing > 0) {
            createPayment($db, $order_id, $order_code, $missing, 'DEPOSIT', $staff_id, $notes, $payment_method, 'completed', $meta);
        }
    }
}

function syncOrderProducts($db, $order_id, $product_ids, $is_replacement) {
    $deleteStmt = $db->prepare("DELETE FROM service_order_products WHERE order_id = :order_id AND is_replacement = :is_replacement");
    $deleteStmt->bindValue(':order_id', $order_id, PDO::PARAM_INT);
    $deleteStmt->bindValue(':is_replacement', $is_replacement ? 1 : 0, PDO::PARAM_INT);
    $deleteStmt->execute();
    if (empty($product_ids)) return;
    $insertStmt = $db->prepare("INSERT INTO service_order_products (order_id, product_id, is_replacement, sort_order, created_at)
                                VALUES (:order_id, :product_id, :is_replacement, :sort_order, NOW())");
    foreach ($product_ids as $index => $product_id) {
        $insertStmt->bindValue(':order_id', $order_id, PDO::PARAM_INT);
        $insertStmt->bindValue(':product_id', (int)$product_id, PDO::PARAM_INT);
        $insertStmt->bindValue(':is_replacement', $is_replacement ? 1 : 0, PDO::PARAM_INT);
        $insertStmt->bindValue(':sort_order', (int)$index, PDO::PARAM_INT);
        $insertStmt->execute();
    }
}

function fetchOrderProductsMap($db, $order_ids) {
    $map = [];
    if (empty($order_ids)) return $map;
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $query = "SELECT sop.order_id, sop.is_replacement,
                     GROUP_CONCAT(sop.product_id ORDER BY sop.sort_order SEPARATOR ',') AS product_ids,
                     GROUP_CONCAT(p.product_name ORDER BY sop.sort_order SEPARATOR '||') AS product_names
              FROM service_order_products sop
              JOIN products p ON sop.product_id = p.id
              WHERE sop.order_id IN ($placeholders)
              GROUP BY sop.order_id, sop.is_replacement";
    $stmt = $db->prepare($query);
    $stmt->execute($order_ids);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $order_id = (int)$row['order_id'];
        $bucket = ((int)$row['is_replacement'] === 1) ? 'replacement' : 'primary';
        $ids = $row['product_ids'] !== null && $row['product_ids'] !== ''
            ? array_map('intval', explode(',', $row['product_ids']))
            : [];
        $names = $row['product_names'] !== null && $row['product_names'] !== ''
            ? explode('||', $row['product_names'])
            : [];
        $map[$order_id][$bucket] = ['ids' => $ids, 'names' => $names];
    }
    return $map;
}

function getOrderWithRelations($db, $order_id) {
    $query = "SELECT so.*,
                     c.full_name as client_name,
                     c.email as client_email,
                     c.phone as client_phone,
                     c.address as client_address,
                     p.product_name,
                     p.brand as product_brand,
                     p.model as product_model,
                     p.specifications as product_specifications,
                     p.serial_number,
                     rp.product_name as replacement_product_name,
                     rp.brand as replacement_product_brand,
                     rp.model as replacement_product_model,
                     rp.serial_number as replacement_serial_number,
                     u.name as staff_name,
                     u.email as staff_email
              FROM service_orders so
              LEFT JOIN clients c ON so.client_id = c.id
              LEFT JOIN products p ON so.product_id = p.id
              LEFT JOIN products rp ON so.replacement_product_id = rp.id
              LEFT JOIN users u ON so.staff_id = u.id
              WHERE so.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $order_id);
    $stmt->execute();
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return null;
    $orderColumns = fetchTableColumns($db, 'service_orders');
    $companyIds = [];
    if (!empty($orderColumns) && isset($orderColumns['company_ids']) && array_key_exists('company_ids', $order)) {
        $companyIds = normalizeIdList($order['company_ids']);
    }
    if (empty($companyIds) && !empty($orderColumns) && isset($orderColumns['company_id'])) {
        $companyId = isset($order['company_id']) ? (int)$order['company_id'] : 0;
        if ($companyId > 0) $companyIds = [$companyId];
    }
    $companyNamesMap = !empty($companyIds) ? fetchCompanyNamesByIds($db, $companyIds) : [];
    $companyIds = array_values(array_filter($companyIds, function ($companyId) use ($companyNamesMap) {
        return isset($companyNamesMap[(int)$companyId]);
    }));
    $companyNames = buildNamesFromIds($companyIds, $companyNamesMap);
    $order['company_ids'] = $companyIds;
    $order['company_names'] = $companyNames;
    $order['company_id'] = !empty($companyIds) ? (int)$companyIds[0] : null;
    $order['company_name'] = !empty($companyNames) ? implode(' || ', $companyNames) : '';
    $stored_primary_ids = array_key_exists('product_ids', $order) ? normalizeIdList($order['product_ids']) : [];
    $stored_replacement_ids = array_key_exists('replacement_product_ids', $order) ? normalizeIdList($order['replacement_product_ids']) : [];
    $stored_names_map = [];
    if (!empty($stored_primary_ids) || !empty($stored_replacement_ids)) {
        $stored_names_map = fetchProductNamesByIds($db, array_merge($stored_primary_ids, $stored_replacement_ids));
    }
    $needs_map = empty($stored_primary_ids) || empty($stored_replacement_ids);
    $map = $needs_map ? fetchOrderProductsMap($db, [(int)$order_id]) : [];
    $primary = $map[$order_id]['primary'] ?? null;
    $replacement = $map[$order_id]['replacement'] ?? null;
    if (!empty($stored_primary_ids)) {
        $order['product_ids'] = $stored_primary_ids;
        $order['product_names'] = buildNamesFromIds($stored_primary_ids, $stored_names_map);
    } elseif ($primary) {
        $order['product_ids'] = $primary['ids'];
        $order['product_names'] = $primary['names'];
    } else {
        $primary_id = isset($order['product_id']) ? (int)$order['product_id'] : 0;
        $order['product_ids'] = $primary_id > 0 ? [$primary_id] : [];
        $order['product_names'] = !empty($order['product_name']) ? [$order['product_name']] : [];
    }
    if (!empty($stored_replacement_ids)) {
        $order['replacement_product_ids'] = $stored_replacement_ids;
        $order['replacement_product_names'] = buildNamesFromIds($stored_replacement_ids, $stored_names_map);
    } elseif ($replacement) {
        $order['replacement_product_ids'] = $replacement['ids'];
        $order['replacement_product_names'] = $replacement['names'];
    } else {
        $replacement_id = isset($order['replacement_product_id']) ? (int)$order['replacement_product_id'] : 0;
        $order['replacement_product_ids'] = $replacement_id > 0 ? [$replacement_id] : [];
        $order['replacement_product_names'] = !empty($order['replacement_product_name']) ? [$order['replacement_product_name']] : [];
    }
    $companyProductMap = [];
    if (!empty($orderColumns) && isset($orderColumns['company_product_map']) && array_key_exists('company_product_map', $order)) {
        $companyProductMap = normalizeCompanyProductMap($order['company_product_map']);
    }
    if (empty($companyProductMap) && !empty($companyIds) && !empty($order['product_ids'])) {
        $companyProductMap[(string)$companyIds[0]] = $order['product_ids'];
    }
    $normalizedCompanyProductMap = [];
    foreach ($companyIds as $companyId) {
        $key = (string)$companyId;
        $normalizedCompanyProductMap[$key] = isset($companyProductMap[$key]) ? normalizeIdList($companyProductMap[$key]) : [];
    }
    $flatProductIds = flattenCompanyProductMap($companyIds, $normalizedCompanyProductMap);
    if (!empty($flatProductIds)) {
        $order['product_ids'] = $flatProductIds;
        $order['product_names'] = buildNamesFromIds($flatProductIds, $stored_names_map);
    }
    $order['company_product_map'] = $normalizedCompanyProductMap;
    if (!empty($orderColumns) && isset($orderColumns['product_status_map']) && array_key_exists('product_status_map', $order)) {
        $order['product_status_map'] = normalizeProductStatusMap($order['product_status_map']);
    }
    if (!empty($orderColumns) && isset($orderColumns['product_status_dates_map']) && array_key_exists('product_status_dates_map', $order)) {
        $order['product_status_dates_map'] = normalizeProductStatusDatesMap($order['product_status_dates_map']);
    }
    $order['product_status_dates_map'] = buildResponseProductStatusDatesMap(
        normalizeIdList($order['product_ids'] ?? ($order['product_id'] ?? null)),
        is_array($order['product_status_map'] ?? null) ? $order['product_status_map'] : [],
        is_array($order['product_status_dates_map'] ?? null) ? $order['product_status_dates_map'] : [],
        $order['created_at'] ?? null,
        $order['updated_at'] ?? null
    );
    if (empty($order['product_status_map'])) $order['product_status_map'] = (object)[];
    if (empty($order['product_status_dates_map'])) $order['product_status_dates_map'] = (object)[];
    $splitStatusDates = splitProductStatusDatesByStatus(
        is_array($order['product_status_dates_map']) ? $order['product_status_dates_map'] : [],
        normalizeIdList($order['product_ids'] ?? ($order['product_id'] ?? null))
    );
    $order['pending_dates'] = !empty($splitStatusDates['pending']) ? $splitStatusDates['pending'] : (object)[];
    $order['rajtocom_dates'] = !empty($splitStatusDates['rajtocom']) ? $splitStatusDates['rajtocom'] : (object)[];
    $order['comtoraj_dates'] = !empty($splitStatusDates['comtoraj']) ? $splitStatusDates['comtoraj'] : (object)[];
    $order['deliveryed_dates'] = !empty($splitStatusDates['deliveryed']) ? $splitStatusDates['deliveryed'] : (object)[];
    $order['repairing_status_map'] = normalizeRepairingStatusMapForResponse($order['repairing_status_map'] ?? null);
    return $order;
}

try {
    switch ($method) {
        case 'GET':
            if (apiServeCachedJson('orders', 5)) {
                exit();
            }
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';
            $priority = isset($_GET['priority']) ? trim($_GET['priority']) : '';
            $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
            $end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

            if ($id) {
                $order = getOrderWithRelations($db, $id);
                if (!$order) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Order not found"]);
                    exit();
                }
                $paymentsQuery = "SELECT * FROM payments WHERE order_id = :order_id ORDER BY created_at ASC";
                $paymentsStmt = $db->prepare($paymentsQuery);
                $paymentsStmt->bindParam(':order_id', $id);
                $paymentsStmt->execute();
                $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
                $total_paid = 0;
                foreach ($payments as $payment) {
                    if (in_array($payment['payment_status'], ['completed', 'paid'], true)) {
                        $total_paid += (float)$payment['amount'];
                    }
                }
                $final_cost = (float)($order['final_cost'] ?: 0);
                $balance = $final_cost - $total_paid;
                $response = json_encode([
                    "success" => true,
                    "order" => $order,
                    "payments" => $payments,
                    "payment_summary" => [
                        "total_paid" => $total_paid,
                        "final_cost" => $final_cost,
                        "deposit_amount" => (float)($order['deposit_amount'] ?: 0),
                        "balance" => $balance
                    ]
                ]);
                apiCacheJsonResponse('orders', $response);
                echo $response;
                exit();
            }

            $query = "SELECT so.*,
                             c.full_name as client_name,
                             c.phone as client_phone,
                             p.product_name,
                             p.brand as product_brand,
                             p.model as product_model,
                             p.serial_number,
                             rp.product_name as replacement_product_name,
                             u.name as staff_name,
                             u.email as staff_email
                      FROM service_orders so
                      LEFT JOIN clients c ON so.client_id = c.id
                      LEFT JOIN products p ON so.product_id = p.id
                      LEFT JOIN products rp ON so.replacement_product_id = rp.id
                      LEFT JOIN users u ON so.staff_id = u.id
                      WHERE 1=1";
            $params = [];
            if ($search !== '') {
                $query .= " AND (so.order_code LIKE :search OR c.full_name LIKE :search OR c.phone LIKE :search OR 
                         p.product_name LIKE :search OR rp.product_name LIKE :search OR p.serial_number LIKE :search OR 
                         p.product_code LIKE :search OR u.name LIKE :search)";
                $params[':search'] = "%{$search}%";
            }
            if ($priority !== '' && $priority !== 'all') {
                $query .= " AND so.priority = :priority";
                $params[':priority'] = $priority;
            }
            if ($start_date !== '' && $end_date !== '') {
                $query .= " AND DATE(so.created_at) BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $start_date;
                $params[':end_date'] = $end_date;
            }
            $query .= " ORDER BY so.created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($orders)) {
                $orderIds = array_map('intval', array_column($orders, 'id'));
                $productMap = fetchOrderProductsMap($db, $orderIds);
                $stored_primary_by_order = [];
                $stored_replacement_by_order = [];
                $stored_ids = [];
                foreach ($orders as $order) {
                    $orderId = (int)$order['id'];
                    if (array_key_exists('product_ids', $order)) {
                        $ids = normalizeIdList($order['product_ids']);
                        if (!empty($ids)) {
                            $stored_primary_by_order[$orderId] = $ids;
                            $stored_ids = array_merge($stored_ids, $ids);
                        }
                    }
                    if (array_key_exists('replacement_product_ids', $order)) {
                        $ids = normalizeIdList($order['replacement_product_ids']);
                        if (!empty($ids)) {
                            $stored_replacement_by_order[$orderId] = $ids;
                            $stored_ids = array_merge($stored_ids, $ids);
                        }
                    }
                }
                $stored_names_map = !empty($stored_ids) ? fetchProductNamesByIds($db, $stored_ids) : [];
                $company_ids = [];
                $order_columns = fetchTableColumns($db, 'service_orders');
                $has_company_ids_column = !empty($order_columns) && isset($order_columns['company_ids']);
                $has_company_id_column = !empty($order_columns) && isset($order_columns['company_id']);
                $has_company_product_map_column = !empty($order_columns) && isset($order_columns['company_product_map']);
                $has_product_status_map_column = !empty($order_columns) && isset($order_columns['product_status_map']);
                $has_product_status_dates_map_column = !empty($order_columns) && isset($order_columns['product_status_dates_map']);
                foreach ($orders as $order) {
                    if ($has_company_ids_column && array_key_exists('company_ids', $order)) {
                        $company_ids = array_merge($company_ids, normalizeIdList($order['company_ids']));
                    }
                    if ($has_company_id_column && array_key_exists('company_id', $order)) {
                        $companyId = (int)$order['company_id'];
                        if ($companyId > 0) $company_ids[] = $companyId;
                    }
                }
                $company_names_map = !empty($company_ids) ? fetchCompanyNamesByIds($db, $company_ids) : [];
                foreach ($orders as &$order) {
                    $orderId = (int)$order['id'];
                    $primary = $productMap[$orderId]['primary'] ?? null;
                    $replacement = $productMap[$orderId]['replacement'] ?? null;
                    $primary_json = $stored_primary_by_order[$orderId] ?? [];
                    $replacement_json = $stored_replacement_by_order[$orderId] ?? [];
                    if (!empty($primary_json)) {
                        $order['product_ids'] = $primary_json;
                        $order['product_names'] = buildNamesFromIds($primary_json, $stored_names_map);
                    } elseif ($primary) {
                        $order['product_ids'] = $primary['ids'];
                        $order['product_names'] = $primary['names'];
                    } else {
                        $primary_id = isset($order['product_id']) ? (int)$order['product_id'] : 0;
                        $order['product_ids'] = $primary_id > 0 ? [$primary_id] : [];
                        $order['product_names'] = !empty($order['product_name']) ? [$order['product_name']] : [];
                    }
                    if (!empty($replacement_json)) {
                        $order['replacement_product_ids'] = $replacement_json;
                        $order['replacement_product_names'] = buildNamesFromIds($replacement_json, $stored_names_map);
                    } elseif ($replacement) {
                        $order['replacement_product_ids'] = $replacement['ids'];
                        $order['replacement_product_names'] = $replacement['names'];
                    } else {
                        $replacement_id = isset($order['replacement_product_id']) ? (int)$order['replacement_product_id'] : 0;
                        $order['replacement_product_ids'] = $replacement_id > 0 ? [$replacement_id] : [];
                        $order['replacement_product_names'] = !empty($order['replacement_product_name']) ? [$order['replacement_product_name']] : [];
                    }
                    $orderCompanyIds = [];
                    if ($has_company_ids_column && array_key_exists('company_ids', $order)) {
                        $orderCompanyIds = normalizeIdList($order['company_ids']);
                    }
                    if (empty($orderCompanyIds) && $has_company_id_column && array_key_exists('company_id', $order)) {
                        $companyId = (int)$order['company_id'];
                        if ($companyId > 0) $orderCompanyIds = [$companyId];
                    }
                    $orderCompanyIds = array_values(array_filter($orderCompanyIds, function ($companyId) use ($company_names_map) {
                        return isset($company_names_map[(int)$companyId]);
                    }));
                    $orderCompanyNames = buildNamesFromIds($orderCompanyIds, $company_names_map);
                    $order['company_ids'] = $orderCompanyIds;
                    $order['company_names'] = $orderCompanyNames;
                    $order['company_id'] = !empty($orderCompanyIds) ? (int)$orderCompanyIds[0] : null;
                    $order['company_name'] = !empty($orderCompanyNames) ? implode(' || ', $orderCompanyNames) : '';
                    $companyProductMap = [];
                    if ($has_company_product_map_column && array_key_exists('company_product_map', $order)) {
                        $companyProductMap = normalizeCompanyProductMap($order['company_product_map']);
                    }
                    if (empty($companyProductMap) && !empty($orderCompanyIds) && !empty($order['product_ids'])) {
                        $companyProductMap[(string)$orderCompanyIds[0]] = $order['product_ids'];
                    }
                    $normalizedCompanyProductMap = [];
                    foreach ($orderCompanyIds as $companyId) {
                        $key = (string)$companyId;
                        $normalizedCompanyProductMap[$key] = isset($companyProductMap[$key]) ? normalizeIdList($companyProductMap[$key]) : [];
                    }
                    $flatProductIds = flattenCompanyProductMap($orderCompanyIds, $normalizedCompanyProductMap);
                    if (!empty($flatProductIds)) {
                        $order['product_ids'] = $flatProductIds;
                        $order['product_names'] = buildNamesFromIds($flatProductIds, $stored_names_map);
                    }
                    $order['company_product_map'] = $normalizedCompanyProductMap;
                    if ($has_product_status_map_column && array_key_exists('product_status_map', $order)) {
                        $order['product_status_map'] = normalizeProductStatusMap($order['product_status_map']);
                    }
                    if ($has_product_status_dates_map_column && array_key_exists('product_status_dates_map', $order)) {
                        $order['product_status_dates_map'] = normalizeProductStatusDatesMap($order['product_status_dates_map']);
                    }
                    $order['product_status_dates_map'] = buildResponseProductStatusDatesMap(
                        normalizeIdList($order['product_ids'] ?? ($order['product_id'] ?? null)),
                        is_array($order['product_status_map'] ?? null) ? $order['product_status_map'] : [],
                        is_array($order['product_status_dates_map'] ?? null) ? $order['product_status_dates_map'] : [],
                        $order['created_at'] ?? null,
                        $order['updated_at'] ?? null
                    );
                    if (empty($order['product_status_map'])) $order['product_status_map'] = (object)[];
                    if (empty($order['product_status_dates_map'])) $order['product_status_dates_map'] = (object)[];
                    $splitStatusDates = splitProductStatusDatesByStatus(
                        is_array($order['product_status_dates_map']) ? $order['product_status_dates_map'] : [],
                        normalizeIdList($order['product_ids'] ?? ($order['product_id'] ?? null))
                    );
                    $order['pending_dates'] = !empty($splitStatusDates['pending']) ? $splitStatusDates['pending'] : (object)[];
                    $order['rajtocom_dates'] = !empty($splitStatusDates['rajtocom']) ? $splitStatusDates['rajtocom'] : (object)[];
                    $order['comtoraj_dates'] = !empty($splitStatusDates['comtoraj']) ? $splitStatusDates['comtoraj'] : (object)[];
                    $order['deliveryed_dates'] = !empty($splitStatusDates['deliveryed']) ? $splitStatusDates['deliveryed'] : (object)[];
                    $order['repairing_status_map'] = normalizeRepairingStatusMapForResponse($order['repairing_status_map'] ?? null);
                }
                unset($order);
            }
            $response = json_encode(["success" => true, "orders" => $orders, "count" => count($orders)]);
            apiCacheJsonResponse('orders', $response);
            echo $response;
            break;

        case 'POST':
            $input = json_decode(file_get_contents("php://input"), true);
            if (!$input) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid input"]);
                exit();
            }
            $required_fields = ['client_id'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "Missing required field: {$field}"]);
                    exit();
                }
            }
            $product_ids = normalizeIdList($input['product_ids'] ?? ($input['product_id'] ?? null));
            if (empty($product_ids)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Missing required field: product_ids"]);
                exit();
            }
            $replacement_product_ids = normalizeIdList($input['replacement_product_ids'] ?? ($input['replacement_product_id'] ?? null));
            $product_ids_json = json_encode($product_ids);
            $replacement_product_ids_json = !empty($replacement_product_ids) ? json_encode($replacement_product_ids) : null;
            $db->beginTransaction();
            try {
                $order_code = 'ORD' . date('Ymd') . strtoupper(substr(uniqid(), 7, 6));
                $staff_id = isset($input['staff_id']) && $input['staff_id'] !== '' ? (int)$input['staff_id'] : null;
                $primary_product_id = $product_ids[0];
                $primary_replacement_product_id = !empty($replacement_product_ids) ? (int)$replacement_product_ids[0] : null;
                $estimated_cost = isset($input['estimated_cost']) && $input['estimated_cost'] !== '' ? (float)$input['estimated_cost'] : 0.00;
                $final_cost = isset($input['final_cost']) && $input['final_cost'] !== '' ? (float)$input['final_cost'] : $estimated_cost;
                $deposit_amount = isset($input['deposit_amount']) && $input['deposit_amount'] !== '' ? (float)$input['deposit_amount'] : 0.00;
                $service_type = isset($input['service_type']) && trim((string)$input['service_type']) !== '' ? trim((string)$input['service_type']) : 'general';
                $diagnosis_notes = isset($input['diagnosis_notes']) ? trim((string)$input['diagnosis_notes']) : null;
                $repair_notes = isset($input['repair_notes']) ? trim((string)$input['repair_notes']) : null;
                $actual_delivery_date = normalizeDateOrNull($input['actual_delivery_date'] ?? null);
                $rating = normalizeRating($input['rating'] ?? null);
                $warranty_status = isset($input['warranty_status']) ? $input['warranty_status'] : 'out_of_warranty';
                $payment_status = normalizePaymentStatus(isset($input['payment_status']) ? $input['payment_status'] : 'pending');
                $status = isset($input['status']) ? $input['status'] : 'pending';
                $priority = isset($input['priority']) ? $input['priority'] : 'medium';
                $estimated_delivery_date = normalizeDateOrNull($input['estimated_delivery_date'] ?? null);
                $notes = isset($input['notes']) ? $input['notes'] : '';
                $payment_method = isset($input['payment_method']) && trim((string)$input['payment_method']) !== '' ? trim((string)$input['payment_method']) : 'cash';
                $transaction_id = isset($input['transaction_id']) && trim((string)$input['transaction_id']) !== '' ? trim((string)$input['transaction_id']) : null;
                $payment_notes = isset($input['payment_notes']) ? trim((string)$input['payment_notes']) : '';
                $orderColumns = fetchTableColumns($db, 'service_orders');
                $orderColumns = ensureServiceOrderHandoverColumns($db, $orderColumns);
                $orderColumns = ensureServiceOrderRepairingStatusColumn($db, $orderColumns);
                $orderColumns = ensureServiceOrderProductStatusDatesColumn($db, $orderColumns);
                $orderColumns = ensureServiceOrderIssueDescriptionMapColumn($db, $orderColumns);
                $orderColumns = ensureLegacyIssueDescriptionColumnIsOptional($db, $orderColumns);
                $hasOrderColumns = !empty($orderColumns);
                $hasCompanyIdColumn = $hasOrderColumns && isset($orderColumns['company_id']);
                $hasCompanyIdsColumn = $hasOrderColumns && isset($orderColumns['company_ids']);
                $hasCompaniesProductsColumn = $hasOrderColumns && isset($orderColumns['companies_products']);
                $hasCompanyProductMapColumn = $hasOrderColumns && isset($orderColumns['company_product_map']);
                $hasProductStatusMapColumn = $hasOrderColumns && isset($orderColumns['product_status_map']);
                $hasProductStatusDatesMapColumn = $hasOrderColumns && isset($orderColumns['product_status_dates_map']);
                $hasLegacyIssueDescriptionColumn = $hasOrderColumns && isset($orderColumns['issue_description']);
                $hasPendingDatesColumn = $hasOrderColumns && isset($orderColumns['pending_dates']);
                $hasRajtocomDatesColumn = $hasOrderColumns && isset($orderColumns['rajtocom_dates']);
                $hasComtorajDatesColumn = $hasOrderColumns && isset($orderColumns['comtoraj_dates']);
                $hasDeliveryedDatesColumn = $hasOrderColumns && isset($orderColumns['deliveryed_dates']);
                $hasRepairingStatusMapColumn = $hasOrderColumns && isset($orderColumns['repairing_status_map']);
                $hasIssueDescriptionMapColumn = $hasOrderColumns && isset($orderColumns['issue_description_map']);
                $hasHandoverTypeColumn = $hasOrderColumns && isset($orderColumns['handover_type']);
                $hasHandoverTypeMapColumn = $hasOrderColumns && isset($orderColumns['handover_type_map']);
                $company_ids = normalizeIdList($input['company_ids'] ?? ($input['company_id'] ?? null));
                $companyNamesMap = !empty($company_ids) ? fetchCompanyNamesByIds($db, $company_ids) : [];
                $company_ids = array_values(array_filter($company_ids, function ($companyId) use ($companyNamesMap) {
                    return isset($companyNamesMap[(int)$companyId]);
                }));
                $company_id = !empty($company_ids) ? (int)$company_ids[0] : null;
                $company_product_map = normalizeCompanyProductMap($input['company_product_map'] ?? ($input['companies_products'] ?? null));
                $incoming_product_status_map = normalizeProductStatusMap($input['product_status_map'] ?? null);
                $incoming_product_status_dates_map = normalizeProductStatusDatesMap($input['product_status_dates_map'] ?? null);
                $incoming_repairing_status_map = normalizeRepairingStatusMap($input['repairing_status_map'] ?? null);
                $incoming_issue_description_map = normalizeIssueDescriptionMap($input['issue_description_map'] ?? null);
                $normalized_company_product_map = [];
                if (!empty($company_ids)) {
                    if (empty($company_product_map)) {
                        $normalized_company_product_map[(string)$company_ids[0]] = $product_ids;
                    } else {
                        foreach ($company_ids as $companyId) {
                            $key = (string)$companyId;
                            $normalized_company_product_map[$key] = isset($company_product_map[$key]) ? normalizeIdList($company_product_map[$key]) : [];
                        }
                    }
                    $flat_from_map = flattenCompanyProductMap($company_ids, $normalized_company_product_map);
                    if (empty($flat_from_map) && !empty($product_ids)) {
                        $normalized_company_product_map[(string)$company_ids[0]] = $product_ids;
                        $flat_from_map = $product_ids;
                    } elseif (!empty($product_ids)) {
                        $missing_product_ids = array_values(array_diff($product_ids, $flat_from_map));
                        if (!empty($missing_product_ids)) {
                            $firstKey = (string)$company_ids[0];
                            $normalized_company_product_map[$firstKey] = array_values(array_unique(array_merge(
                                $normalized_company_product_map[$firstKey] ?? [],
                                $missing_product_ids
                            )));
                            $flat_from_map = flattenCompanyProductMap($company_ids, $normalized_company_product_map);
                        }
                    }
                    if (!empty($flat_from_map)) $product_ids = $flat_from_map;
                } else {
                    $normalized_company_product_map = [];
                }
                if (empty($product_ids)) throw new Exception("At least one product is required");
                $primary_product_id = $product_ids[0];
                $product_ids_json = json_encode($product_ids);
                $company_ids_json = !empty($company_ids) ? json_encode($company_ids) : null;
                $companies_products_json = !empty($normalized_company_product_map) ? json_encode($normalized_company_product_map) : null;
                $company_product_map_json = !empty($normalized_company_product_map) ? json_encode($normalized_company_product_map) : null;
                $normalized_product_status_map = [];
                foreach ($product_ids as $product_id) {
                    $key = (string)$product_id;
                    $normalized_product_status_map[$key] = isset($incoming_product_status_map[$key])
                        ? normalizeProductFlowStatus($incoming_product_status_map[$key])
                        : 'pending';
                }
                $product_status_map_json = !empty($normalized_product_status_map) ? json_encode($normalized_product_status_map) : null;
                
                // Create dates map for new order with progressive status timestamps only.
                $normalized_product_status_dates_map = buildProductStatusDatesMap(
                    $product_ids,
                    $normalized_product_status_map,
                    [],
                    $incoming_product_status_dates_map,
                    []
                );
                if (empty($normalized_product_status_dates_map) && !empty($product_ids)) {
                    $normalized_product_status_dates_map = buildStrictStatusDatesFromProductIds($product_ids, []);
                }
                $normalized_product_status_dates_map = ensureNonEmptyProductStatusDatesMap(
                    $product_ids,
                    $normalized_product_status_map,
                    $normalized_product_status_dates_map
                );
                $product_status_dates_map_json = !empty($normalized_product_status_dates_map) ? json_encode($normalized_product_status_dates_map) : null;
                if ($hasProductStatusDatesMapColumn && ($product_status_dates_map_json === null || $product_status_dates_map_json === '{}' || $product_status_dates_map_json === 'null')) {
                    $normalized_product_status_dates_map = buildStrictStatusDatesFromProductIds($product_ids, []);
                    $product_status_dates_map_json = json_encode($normalized_product_status_dates_map);
                }
                $splitStatusDates = splitProductStatusDatesByStatus($normalized_product_status_dates_map, $product_ids);
                $pending_dates_json = json_encode($splitStatusDates['pending']);
                $rajtocom_dates_json = json_encode($splitStatusDates['rajtocom']);
                $comtoraj_dates_json = json_encode($splitStatusDates['comtoraj']);
                $deliveryed_dates_json = json_encode($splitStatusDates['deliveryed']);
                
                $normalized_repairing_status_map = [];
                foreach ($product_ids as $product_id) {
                    $key = (string)$product_id;
                    $normalized_repairing_status_map[$key] = isset($incoming_repairing_status_map[$key])
                        ? normalizeRepairingStatus($incoming_repairing_status_map[$key])
                        : 'not ready';
                }
                $repairing_status_map_json = !empty($normalized_repairing_status_map) ? json_encode($normalized_repairing_status_map) : '{}';
                $normalized_issue_description_map = [];
                foreach ($product_ids as $product_id) {
                    $key = (string)$product_id;
                    $normalized_issue_description_map[$key] = isset($incoming_issue_description_map[$key])
                        ? trim((string)$incoming_issue_description_map[$key])
                        : '';
                }
                $issue_description_map_json = !empty($normalized_issue_description_map) ? json_encode($normalized_issue_description_map) : '{}';
                $legacy_issue_description_value = 'N/A';
                foreach ($normalized_issue_description_map as $issueText) {
                    $candidate = trim((string)$issueText);
                    if ($candidate !== '') {
                        $legacy_issue_description_value = $candidate;
                        break;
                    }
                }
                $incoming_handover_type_map = normalizeHandoverTypeMap($input['handover_type_map'] ?? null);
                $normalized_handover_type_map = [];
                foreach ($product_ids as $product_id) {
                    $key = (string)$product_id;
                    if (isset($incoming_handover_type_map[$key])) {
                        $normalized_handover_type_map[$key] = $incoming_handover_type_map[$key];
                    }
                }
                $handover_type_map_json = !empty($normalized_handover_type_map) ? json_encode($normalized_handover_type_map) : null;
                $handover_type = normalizeHandoverType($input['handover_type'] ?? null);
                if ($handover_type === null && !empty($normalized_handover_type_map)) {
                    $handover_type = reset($normalized_handover_type_map);
                }
                if (isPreviewModeRequest($input)) {
                    $db->rollBack();
                    echo json_encode(["success" => true, "preview" => true, "message" => "Preview generated successfully", "order_preview" => [
                        "client_id" => (int)$input['client_id'],
                        "company_id" => $company_id,
                        "company_ids" => $company_ids,
                        "product_id" => $primary_product_id,
                        "product_ids" => $product_ids,
                        "product_status_map" => $normalized_product_status_map,
                        "product_status_dates_map" => $normalized_product_status_dates_map,
                        "replacement_product_id" => $primary_replacement_product_id,
                        "replacement_product_ids" => $replacement_product_ids,
                        "repairing_status_map" => $normalized_repairing_status_map,
                        "estimated_cost" => $estimated_cost,
                        "final_cost" => $final_cost,
                        "deposit_amount" => $deposit_amount,
                        "payment_status" => $payment_status,
                        "priority" => $priority,
                        "estimated_delivery_date" => $estimated_delivery_date
                    ]]);
                    exit();
                }
                $orderAssignments = [
                    'order_code = :order_code',
                    'client_id = :client_id',
                    'product_id = :product_id',
                    'product_ids = :product_ids',
                    'replacement_product_id = :replacement_product_id',
                    'replacement_product_ids = :replacement_product_ids',
                    'staff_id = :staff_id',
                                        'warranty_status = :warranty_status',
                    'estimated_cost = :estimated_cost',
                    'final_cost = :final_cost',
                    'deposit_amount = :deposit_amount',
                    'payment_status = :payment_status',
                    'estimated_delivery_date = :estimated_delivery_date',
                    'notes = :notes',
                    'created_at = NOW()',
                    'updated_at = NOW()'
                ];
                if ($hasOrderColumns && isset($orderColumns['service_type'])) $orderAssignments[] = 'service_type = :service_type';
                if ($hasOrderColumns && isset($orderColumns['diagnosis_notes'])) $orderAssignments[] = 'diagnosis_notes = :diagnosis_notes';
                if ($hasOrderColumns && isset($orderColumns['repair_notes'])) $orderAssignments[] = 'repair_notes = :repair_notes';
                if ($hasOrderColumns && isset($orderColumns['actual_delivery_date'])) $orderAssignments[] = 'actual_delivery_date = :actual_delivery_date';
                if ($hasOrderColumns && isset($orderColumns['rating'])) $orderAssignments[] = 'rating = :rating';
                if ($hasOrderColumns && isset($orderColumns['priority'])) $orderAssignments[] = 'priority = :priority';
                if ($hasCompanyIdColumn) $orderAssignments[] = 'company_id = :company_id';
                if ($hasCompanyIdsColumn) $orderAssignments[] = 'company_ids = :company_ids';
                if ($hasCompaniesProductsColumn) $orderAssignments[] = 'companies_products = :companies_products';
                if ($hasCompanyProductMapColumn) $orderAssignments[] = 'company_product_map = :company_product_map';
                if ($hasLegacyIssueDescriptionColumn) $orderAssignments[] = 'issue_description = :legacy_issue_description';
                if ($hasProductStatusMapColumn) $orderAssignments[] = 'product_status_map = :product_status_map';
                if ($hasProductStatusDatesMapColumn) $orderAssignments[] = 'product_status_dates_map = :product_status_dates_map';
                if ($hasPendingDatesColumn) $orderAssignments[] = 'pending_dates = :pending_dates';
                if ($hasRajtocomDatesColumn) $orderAssignments[] = 'rajtocom_dates = :rajtocom_dates';
                if ($hasComtorajDatesColumn) $orderAssignments[] = 'comtoraj_dates = :comtoraj_dates';
                if ($hasDeliveryedDatesColumn) $orderAssignments[] = 'deliveryed_dates = :deliveryed_dates';
                if ($hasRepairingStatusMapColumn) $orderAssignments[] = 'repairing_status_map = :repairing_status_map';
                if ($hasIssueDescriptionMapColumn) $orderAssignments[] = 'issue_description_map = :issue_description_map';
                if ($hasHandoverTypeColumn) $orderAssignments[] = 'handover_type = :handover_type';
                if ($hasHandoverTypeMapColumn) $orderAssignments[] = 'handover_type_map = :handover_type_map';
                $query = "INSERT INTO service_orders SET " . implode(', ', $orderAssignments);
                $stmt = $db->prepare($query);
                $stmt->bindParam(':order_code', $order_code);
                $stmt->bindParam(':client_id', $input['client_id']);
                $stmt->bindValue(':product_id', $primary_product_id, PDO::PARAM_INT);
                $stmt->bindValue(':product_ids', $product_ids_json, PDO::PARAM_STR);
                $stmt->bindValue(':replacement_product_id', $primary_replacement_product_id, is_null($primary_replacement_product_id) ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':replacement_product_ids', $replacement_product_ids_json, is_null($replacement_product_ids_json) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':staff_id', $staff_id, is_null($staff_id) ? PDO::PARAM_NULL : PDO::PARAM_INT);
                if ($hasOrderColumns && isset($orderColumns['service_type'])) $stmt->bindParam(':service_type', $service_type);
                $stmt->bindParam(':warranty_status', $warranty_status);
                $stmt->bindParam(':estimated_cost', $estimated_cost);
                $stmt->bindParam(':final_cost', $final_cost);
                $stmt->bindParam(':deposit_amount', $deposit_amount);
                $stmt->bindParam(':payment_status', $payment_status);
                $stmt->bindValue(':estimated_delivery_date', $estimated_delivery_date, is_null($estimated_delivery_date) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasOrderColumns && isset($orderColumns['priority'])) $stmt->bindParam(':priority', $priority);
                $stmt->bindParam(':notes', $notes);
                if ($hasOrderColumns && isset($orderColumns['diagnosis_notes'])) $stmt->bindValue(':diagnosis_notes', $diagnosis_notes, $diagnosis_notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasOrderColumns && isset($orderColumns['repair_notes'])) $stmt->bindValue(':repair_notes', $repair_notes, $repair_notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasOrderColumns && isset($orderColumns['actual_delivery_date'])) $stmt->bindValue(':actual_delivery_date', $actual_delivery_date, $actual_delivery_date === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasOrderColumns && isset($orderColumns['rating'])) $stmt->bindValue(':rating', $rating, $rating === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                if ($hasCompanyIdColumn) $stmt->bindValue(':company_id', $company_id, $company_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                if ($hasCompanyIdsColumn) $stmt->bindValue(':company_ids', $company_ids_json, $company_ids_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasCompaniesProductsColumn) $stmt->bindValue(':companies_products', $companies_products_json, $companies_products_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasCompanyProductMapColumn) $stmt->bindValue(':company_product_map', $company_product_map_json, $company_product_map_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasLegacyIssueDescriptionColumn) $stmt->bindValue(':legacy_issue_description', $legacy_issue_description_value, PDO::PARAM_STR);
                if ($hasProductStatusMapColumn) $stmt->bindValue(':product_status_map', $product_status_map_json, $product_status_map_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasProductStatusDatesMapColumn) {
                    if ($product_status_dates_map_json === null || $product_status_dates_map_json === '{}' || $product_status_dates_map_json === 'null') {
                        $fallbackDatesMap = buildStrictStatusDatesFromProductIds($product_ids, []);
                        $product_status_dates_map_json = json_encode($fallbackDatesMap);
                    }
                    $stmt->bindValue(':product_status_dates_map', $product_status_dates_map_json, PDO::PARAM_STR);
                }
                if ($hasPendingDatesColumn) $stmt->bindValue(':pending_dates', $pending_dates_json, $pending_dates_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasRajtocomDatesColumn) $stmt->bindValue(':rajtocom_dates', $rajtocom_dates_json, $rajtocom_dates_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasComtorajDatesColumn) $stmt->bindValue(':comtoraj_dates', $comtoraj_dates_json, $comtoraj_dates_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasDeliveryedDatesColumn) $stmt->bindValue(':deliveryed_dates', $deliveryed_dates_json, $deliveryed_dates_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasRepairingStatusMapColumn) $stmt->bindValue(':repairing_status_map', $repairing_status_map_json, $repairing_status_map_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasIssueDescriptionMapColumn) $stmt->bindValue(':issue_description_map', $issue_description_map_json, $issue_description_map_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasHandoverTypeColumn) $stmt->bindValue(':handover_type', $handover_type, $handover_type === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if ($hasHandoverTypeMapColumn) $stmt->bindValue(':handover_type_map', $handover_type_map_json, $handover_type_map_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                if (!$stmt->execute()) throw new Exception("Failed to create order");
                $order_id = $db->lastInsertId();
                try {
                    $fixStmt = $db->prepare("SELECT product_ids, product_id, product_status_dates_map FROM service_orders WHERE id = :id");
                    $fixStmt->bindValue(':id', $order_id, PDO::PARAM_INT);
                    $fixStmt->execute();
                    $fixRow = $fixStmt->fetch(PDO::FETCH_ASSOC);
                    $fixProductIds = normalizeIdList($fixRow['product_ids'] ?? ($fixRow['product_id'] ?? null));
                    if (empty($fixProductIds)) {
                        $fixProductIds = $product_ids;
                    }
                    $fixDatesMap = normalizeProductStatusDatesMap($fixRow['product_status_dates_map'] ?? null);
                    if (empty($fixDatesMap) && !empty($normalized_product_status_dates_map)) {
                        $fixDatesMap = $normalized_product_status_dates_map;
                    }
                    if (!empty($fixProductIds)) {
                        $rebuiltDates = !empty($fixDatesMap)
                            ? buildProductStatusDatesMap($fixProductIds, $normalized_product_status_map, $fixDatesMap, [], [])
                            : buildStrictStatusDatesFromProductIds($fixProductIds, []);
                        if (!empty($rebuiltDates)) {
                            $fixSplitDates = splitProductStatusDatesByStatus($rebuiltDates, $fixProductIds);
                            $fixSet = ['product_status_dates_map = :dates_map'];
                            if ($hasPendingDatesColumn) $fixSet[] = 'pending_dates = :pending_dates';
                            if ($hasRajtocomDatesColumn) $fixSet[] = 'rajtocom_dates = :rajtocom_dates';
                            if ($hasComtorajDatesColumn) $fixSet[] = 'comtoraj_dates = :comtoraj_dates';
                            if ($hasDeliveryedDatesColumn) $fixSet[] = 'deliveryed_dates = :deliveryed_dates';

                            $fixUpdate = $db->prepare("UPDATE service_orders SET " . implode(', ', $fixSet) . " WHERE id = :id");
                            $fixUpdate->bindValue(':dates_map', json_encode($rebuiltDates), PDO::PARAM_STR);
                            if ($hasPendingDatesColumn) $fixUpdate->bindValue(':pending_dates', json_encode($fixSplitDates['pending']), PDO::PARAM_STR);
                            if ($hasRajtocomDatesColumn) $fixUpdate->bindValue(':rajtocom_dates', json_encode($fixSplitDates['rajtocom']), PDO::PARAM_STR);
                            if ($hasComtorajDatesColumn) $fixUpdate->bindValue(':comtoraj_dates', json_encode($fixSplitDates['comtoraj']), PDO::PARAM_STR);
                            if ($hasDeliveryedDatesColumn) $fixUpdate->bindValue(':deliveryed_dates', json_encode($fixSplitDates['deliveryed']), PDO::PARAM_STR);
                            $fixUpdate->bindValue(':id', $order_id, PDO::PARAM_INT);
                            $fixUpdate->execute();
                        }
                    }
                } catch (Exception $e) {}
                try {
                    $repairingStmt = $db->prepare("UPDATE service_orders SET repairing_status_map = :repairing_status_map WHERE id = :id");
                    $repairingStmt->bindValue(':repairing_status_map', $repairing_status_map_json, $repairing_status_map_json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $repairingStmt->bindValue(':id', $order_id, PDO::PARAM_INT);
                    $repairingStmt->execute();
                } catch (Exception $e) {}
                syncOrderProducts($db, $order_id, $product_ids, false);
                syncOrderProducts($db, $order_id, $replacement_product_ids, true);
                $paymentMeta = ['estimated_cost' => $estimated_cost, 'final_cost' => $final_cost, 'deposit_amount' => $deposit_amount];
                if ($transaction_id !== null) $paymentMeta['transaction_id'] = $transaction_id;
                if ($deposit_amount > 0) {
                    $depositNote = $payment_notes !== '' ? $payment_notes : 'Initial deposit for service order';
                    if (!createPayment($db, $order_id, $order_code, $deposit_amount, 'DEPOSIT', $user_id, $depositNote, $payment_method, 'completed', $paymentMeta)) {
                        throw new Exception("Failed to create deposit payment");
                    }
                }
                if ($payment_status === 'paid' && $final_cost > $deposit_amount) {
                    $remaining_amount = $final_cost - $deposit_amount;
                    if ($remaining_amount > 0) {
                        $finalNote = $payment_notes !== '' ? $payment_notes : 'Final payment for service order';
                        if (!createPayment($db, $order_id, $order_code, $remaining_amount, 'FINAL', $user_id, $finalNote, $payment_method, 'completed', $paymentMeta)) {
                            throw new Exception("Failed to create final payment");
                        }
                    }
                }
                updateOrderPaymentStatus($db, $order_id, $final_cost, $payment_status);
                $order = getOrderWithRelations($db, $order_id);
                $paymentsStmt = $db->prepare("SELECT * FROM payments WHERE order_id = :order_id ORDER BY created_at ASC");
                $paymentsStmt->bindParam(':order_id', $order_id);
                $paymentsStmt->execute();
                $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
                $total_paid = 0;
                foreach ($payments as $payment) {
                    if (in_array($payment['payment_status'], ['completed', 'paid'], true)) {
                        $total_paid += (float)$payment['amount'];
                    }
                }
                $db->commit();
                echo json_encode([
                    "success" => true,
                    "message" => "Order created successfully",
                    "order_id" => $order_id,
                    "order_code" => $order_code,
                    "order" => $order,
                    "payments" => $payments,
                    "payment_summary" => [
                        "total_paid" => $total_paid,
                        "final_cost" => $final_cost,
                        "deposit_amount" => $deposit_amount,
                        "balance" => $final_cost - $total_paid
                    ]
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Failed to create order", "error" => $e->getMessage()]);
            }
            break;

        case 'PUT':
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Order ID required"]);
                exit();
            }
            $input = json_decode(file_get_contents("php://input"), true);
            if (!$input) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid input"]);
                exit();
            }
            $fields = [];
            $params = [':id' => $id];
            $update_product_ids = null;
            $update_replacement_product_ids = null;
            $orderColumns = fetchTableColumns($db, 'service_orders');
            $orderColumns = ensureServiceOrderHandoverColumns($db, $orderColumns);
            $orderColumns = ensureServiceOrderRepairingStatusColumn($db, $orderColumns);
            $orderColumns = ensureServiceOrderProductStatusDatesColumn($db, $orderColumns);
            $orderColumns = ensureServiceOrderIssueDescriptionMapColumn($db, $orderColumns);
            $orderColumns = ensureLegacyIssueDescriptionColumnIsOptional($db, $orderColumns);
            $hasOrderColumns = !empty($orderColumns);
            if (isset($input['client_id']) && $input['client_id'] !== '') {
                $fields[] = 'client_id = :client_id';
                $params[':client_id'] = $input['client_id'];
            }
            if (array_key_exists('product_ids', $input) || array_key_exists('product_id', $input)) {
                $product_ids = normalizeIdList($input['product_ids'] ?? ($input['product_id'] ?? null));
                if (empty($product_ids)) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "At least one product is required"]);
                    exit();
                }
                $fields[] = 'product_id = :product_id';
                $fields[] = 'product_ids = :product_ids';
                $params[':product_id'] = $product_ids[0];
                $params[':product_ids'] = json_encode($product_ids);
                $update_product_ids = $product_ids;
            }
            if (array_key_exists('replacement_product_ids', $input) || array_key_exists('replacement_product_id', $input)) {
                $replacement_product_ids = normalizeIdList($input['replacement_product_ids'] ?? ($input['replacement_product_id'] ?? null));
                $fields[] = 'replacement_product_id = :replacement_product_id';
                $fields[] = 'replacement_product_ids = :replacement_product_ids';
                $params[':replacement_product_id'] = !empty($replacement_product_ids) ? $replacement_product_ids[0] : null;
                $params[':replacement_product_ids'] = !empty($replacement_product_ids) ? json_encode($replacement_product_ids) : null;
                $update_replacement_product_ids = $replacement_product_ids;
            }
            if (array_key_exists('staff_id', $input)) {
                $fields[] = 'staff_id = :staff_id';
                $params[':staff_id'] = ($input['staff_id'] === '' || $input['staff_id'] === null) ? null : $input['staff_id'];
            }
            $hasCompanyIdColumn = $hasOrderColumns && isset($orderColumns['company_id']);
            $hasCompanyIdsColumn = $hasOrderColumns && isset($orderColumns['company_ids']);
            $hasCompaniesProductsColumn = $hasOrderColumns && isset($orderColumns['companies_products']);
            $hasCompanyProductMapColumn = $hasOrderColumns && isset($orderColumns['company_product_map']);
            $hasProductStatusMapColumn = $hasOrderColumns && isset($orderColumns['product_status_map']);
            $hasProductStatusDatesMapColumn = $hasOrderColumns && isset($orderColumns['product_status_dates_map']);
            $hasPendingDatesColumn = $hasOrderColumns && isset($orderColumns['pending_dates']);
            $hasRajtocomDatesColumn = $hasOrderColumns && isset($orderColumns['rajtocom_dates']);
            $hasComtorajDatesColumn = $hasOrderColumns && isset($orderColumns['comtoraj_dates']);
            $hasDeliveryedDatesColumn = $hasOrderColumns && isset($orderColumns['deliveryed_dates']);
            $hasRepairingStatusMapColumn = $hasOrderColumns && isset($orderColumns['repairing_status_map']);
            $hasLegacyIssueDescriptionColumn = $hasOrderColumns && isset($orderColumns['issue_description']);
            $hasIssueDescriptionMapColumn = $hasOrderColumns && isset($orderColumns['issue_description_map']);
            $hasHandoverTypeColumn = $hasOrderColumns && isset($orderColumns['handover_type']);
            $hasHandoverTypeMapColumn = $hasOrderColumns && isset($orderColumns['handover_type_map']);
            
            // Fetch existing order data for date preservation
            $existingOrderData = null;
            try {
                $existingStmt = $db->prepare("SELECT product_ids, product_id, product_status_map, product_status_dates_map, pending_dates, rajtocom_dates, comtoraj_dates, deliveryed_dates FROM service_orders WHERE id = :id");
                $existingStmt->bindValue(':id', $id, PDO::PARAM_INT);
                $existingStmt->execute();
                $existingOrderData = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Exception $e) {
                $existingOrderData = null;
            }
            
            $companyIdsProvided = array_key_exists('company_ids', $input) || array_key_exists('company_id', $input);
            $companyProductMapProvided = array_key_exists('company_product_map', $input) || array_key_exists('companies_products', $input);
            if ($hasCompanyIdColumn || $hasCompanyIdsColumn || $hasCompanyProductMapColumn) {
                $company_ids = [];
                if ($companyIdsProvided) {
                    $company_ids = normalizeIdList($input['company_ids'] ?? ($input['company_id'] ?? null));
                }
                $company_product_map = $companyProductMapProvided
                    ? normalizeCompanyProductMap($input['company_product_map'] ?? ($input['companies_products'] ?? null))
                    : [];
                if (!$companyIdsProvided && $companyProductMapProvided) {
                    $company_ids = normalizeIdList(array_keys($company_product_map));
                }
                if (!empty($company_ids)) {
                    $companyNamesMap = fetchCompanyNamesByIds($db, $company_ids);
                    $company_ids = array_values(array_filter($company_ids, function ($companyId) use ($companyNamesMap) {
                        return isset($companyNamesMap[(int)$companyId]);
                    }));
                }
                if (($companyIdsProvided || $companyProductMapProvided) && !empty($company_ids)) {
                    $normalized_company_product_map = [];
                    foreach ($company_ids as $companyId) {
                        $key = (string)$companyId;
                        $normalized_company_product_map[$key] = isset($company_product_map[$key]) ? normalizeIdList($company_product_map[$key]) : [];
                    }
                    $source_product_ids = [];
                    if (array_key_exists('product_ids', $input) || array_key_exists('product_id', $input)) {
                        $source_product_ids = normalizeIdList($input['product_ids'] ?? ($input['product_id'] ?? null));
                    }
                    if ($companyProductMapProvided) {
                        $flat_from_map = flattenCompanyProductMap($company_ids, $normalized_company_product_map);
                        if (!empty($source_product_ids)) {
                            $missing_product_ids = array_values(array_diff($source_product_ids, $flat_from_map));
                            if (!empty($missing_product_ids)) {
                                $firstKey = (string)$company_ids[0];
                                $normalized_company_product_map[$firstKey] = array_values(array_unique(array_merge(
                                    $normalized_company_product_map[$firstKey] ?? [],
                                    $missing_product_ids
                                )));
                                $flat_from_map = flattenCompanyProductMap($company_ids, $normalized_company_product_map);
                            }
                        }
                        if (!empty($flat_from_map)) {
                            $fields[] = 'product_id = :product_id';
                            $fields[] = 'product_ids = :product_ids';
                            $params[':product_id'] = $flat_from_map[0];
                            $params[':product_ids'] = json_encode($flat_from_map);
                            $update_product_ids = $flat_from_map;
                        }
                    } elseif ($companyIdsProvided && !empty($source_product_ids)) {
                        $firstKey = (string)$company_ids[0];
                        $normalized_company_product_map[$firstKey] = $source_product_ids;
                    }
                    if ($hasCompanyIdColumn) {
                        $fields[] = 'company_id = :company_id';
                        $params[':company_id'] = !empty($company_ids) ? (int)$company_ids[0] : null;
                    }
                    if ($hasCompanyIdsColumn) {
                        $fields[] = 'company_ids = :company_ids';
                        $params[':company_ids'] = !empty($company_ids) ? json_encode($company_ids) : null;
                    }
                    if ($hasCompanyProductMapColumn) {
                        $fields[] = 'company_product_map = :company_product_map';
                        $params[':company_product_map'] = !empty($normalized_company_product_map) ? json_encode($normalized_company_product_map) : null;
                    }
                    if ($hasCompaniesProductsColumn) {
                        $fields[] = 'companies_products = :companies_products';
                        $params[':companies_products'] = !empty($normalized_company_product_map) ? json_encode($normalized_company_product_map) : null;
                    }
                } elseif ($companyIdsProvided || $companyProductMapProvided) {
                    if ($hasCompanyIdColumn) {
                        $fields[] = 'company_id = :company_id';
                        $params[':company_id'] = null;
                    }
                    if ($hasCompanyIdsColumn) {
                        $fields[] = 'company_ids = :company_ids';
                        $params[':company_ids'] = null;
                    }
                    if ($hasCompanyProductMapColumn) {
                        $fields[] = 'company_product_map = :company_product_map';
                        $params[':company_product_map'] = null;
                    }
                    if ($hasCompaniesProductsColumn) {
                        $fields[] = 'companies_products = :companies_products';
                        $params[':companies_products'] = null;
                    }
                }
            }
            if ($hasOrderColumns && isset($orderColumns['service_type']) && isset($input['service_type'])) {
                $fields[] = 'service_type = :service_type';
                $params[':service_type'] = trim((string)$input['service_type']) !== '' ? trim((string)$input['service_type']) : 'general';
            }
            if ($hasOrderColumns && isset($orderColumns['diagnosis_notes']) && array_key_exists('diagnosis_notes', $input)) {
                $fields[] = 'diagnosis_notes = :diagnosis_notes';
                $params[':diagnosis_notes'] = trim((string)$input['diagnosis_notes']);
            }
            if ($hasOrderColumns && isset($orderColumns['repair_notes']) && array_key_exists('repair_notes', $input)) {
                $fields[] = 'repair_notes = :repair_notes';
                $params[':repair_notes'] = trim((string)$input['repair_notes']);
            }
            if (isset($input['warranty_status'])) {
                $fields[] = 'warranty_status = :warranty_status';
                $params[':warranty_status'] = $input['warranty_status'];
            }
            if (array_key_exists('estimated_cost', $input)) {
                $fields[] = 'estimated_cost = :estimated_cost';
                $params[':estimated_cost'] = ($input['estimated_cost'] === '' || $input['estimated_cost'] === null) ? 0 : $input['estimated_cost'];
            }
            if (isset($input['final_cost']) && $input['final_cost'] !== '') {
                $fields[] = 'final_cost = :final_cost';
                $params[':final_cost'] = $input['final_cost'];
            }
            if (isset($input['deposit_amount']) && $input['deposit_amount'] !== '') {
                $fields[] = 'deposit_amount = :deposit_amount';
                $params[':deposit_amount'] = $input['deposit_amount'];
            }
            if (isset($input['payment_status'])) {
                $fields[] = 'payment_status = :payment_status';
                $params[':payment_status'] = normalizePaymentStatus($input['payment_status']);
            }
            if (array_key_exists('estimated_delivery_date', $input)) {
                $fields[] = 'estimated_delivery_date = :estimated_delivery_date';
                $params[':estimated_delivery_date'] = normalizeDateOrNull($input['estimated_delivery_date']);
            }
            if ($hasOrderColumns && isset($orderColumns['actual_delivery_date']) && array_key_exists('actual_delivery_date', $input)) {
                $fields[] = 'actual_delivery_date = :actual_delivery_date';
                $params[':actual_delivery_date'] = normalizeDateOrNull($input['actual_delivery_date']);
            }
            if ($hasOrderColumns && isset($orderColumns['rating']) && array_key_exists('rating', $input)) {
                $fields[] = 'rating = :rating';
                $params[':rating'] = normalizeRating($input['rating']);
            }
            if ($hasOrderColumns && isset($orderColumns['priority']) && isset($input['priority'])) {
                $fields[] = 'priority = :priority';
                $params[':priority'] = $input['priority'];
            }
            if (isset($input['notes'])) {
                $fields[] = 'notes = :notes';
                $params[':notes'] = $input['notes'];
            }
            
            // CRITICAL FIX: Handle product_status_map update with FULL date preservation
            if ($hasProductStatusMapColumn && array_key_exists('product_status_map', $input)) {
                $newStatusMap = normalizeProductStatusMap($input['product_status_map']);
                
                // Normalize for current product IDs
                if (is_array($update_product_ids) && !empty($update_product_ids)) {
                    $normalizedStatusMap = [];
                    foreach ($update_product_ids as $productId) {
                        $key = (string)$productId;
                        $normalizedStatusMap[$key] = isset($newStatusMap[$key]) ? normalizeProductFlowStatus($newStatusMap[$key]) : 'pending';
                    }
                    $newStatusMap = $normalizedStatusMap;
                }
                
                $fields[] = 'product_status_map = :product_status_map';
                $params[':product_status_map'] = !empty($newStatusMap) ? json_encode($newStatusMap) : null;
                
                // CRITICAL: Build dates map while preserving ALL existing dates
                if ($hasProductStatusDatesMapColumn) {
                    // Get target product IDs
                    $targetProductIds = [];
                    if (is_array($update_product_ids) && !empty($update_product_ids)) {
                        $targetProductIds = $update_product_ids;
                    } elseif ($existingOrderData) {
                        $targetProductIds = normalizeIdList($existingOrderData['product_ids'] ?? ($existingOrderData['product_id'] ?? null));
                    }
                    
                    // Get existing status map for old status comparison
                    $existingStatusMap = $existingOrderData
                        ? normalizeProductStatusMap($existingOrderData['product_status_map'] ?? null)
                        : [];
                    
                    // Get existing dates map (THIS PRESERVES ALL HISTORICAL TIMESTAMPS)
                    $existingDatesMap = $existingOrderData
                        ? normalizeProductStatusDatesMap($existingOrderData['product_status_dates_map'] ?? null)
                        : [];
                    
                    // Get incoming dates map from request
                    $incomingDatesMap = normalizeProductStatusDatesMap($input['product_status_dates_map'] ?? null);
                    
                    // Build target product IDs list
                    $targetDateProductIds = !empty($targetProductIds) ? $targetProductIds : array_map('intval', array_keys($newStatusMap));
                    if (empty($targetDateProductIds)) {
                        $targetDateProductIds = array_map('intval', array_keys($existingDatesMap));
                    }
                    
                    // Build dates map that PRESERVES ALL EXISTING TIMESTAMPS
                    $computedDatesMap = buildProductStatusDatesMap(
                        $targetDateProductIds,
                        $newStatusMap,
                        $existingDatesMap,
                        $incomingDatesMap,
                        $existingStatusMap
                    );
                    
                    $fields[] = 'product_status_dates_map = :product_status_dates_map';
                    $computedDatesMap = ensureNonEmptyProductStatusDatesMap($targetDateProductIds, $newStatusMap, $computedDatesMap);
                    $params[':product_status_dates_map'] = !empty($computedDatesMap) ? json_encode($computedDatesMap) : null;
                    $splitStatusDates = splitProductStatusDatesByStatus($computedDatesMap, $targetDateProductIds);
                    if ($hasPendingDatesColumn) {
                        $fields[] = 'pending_dates = :pending_dates';
                        $params[':pending_dates'] = json_encode($splitStatusDates['pending']);
                    }
                    if ($hasRajtocomDatesColumn) {
                        $fields[] = 'rajtocom_dates = :rajtocom_dates';
                        $params[':rajtocom_dates'] = json_encode($splitStatusDates['rajtocom']);
                    }
                    if ($hasComtorajDatesColumn) {
                        $fields[] = 'comtoraj_dates = :comtoraj_dates';
                        $params[':comtoraj_dates'] = json_encode($splitStatusDates['comtoraj']);
                    }
                    if ($hasDeliveryedDatesColumn) {
                        $fields[] = 'deliveryed_dates = :deliveryed_dates';
                        $params[':deliveryed_dates'] = json_encode($splitStatusDates['deliveryed']);
                    }
                }
            }
            
            // Handle standalone product_status_dates_map update
            if ($hasProductStatusDatesMapColumn && !array_key_exists('product_status_map', $input) && array_key_exists('product_status_dates_map', $input)) {
                $existingStatusMap = $existingOrderData
                    ? normalizeProductStatusMap($existingOrderData['product_status_map'] ?? null)
                    : [];
                $existingDatesMap = $existingOrderData
                    ? normalizeProductStatusDatesMap($existingOrderData['product_status_dates_map'] ?? null)
                    : [];
                $incomingDatesMap = normalizeProductStatusDatesMap($input['product_status_dates_map'] ?? null);
                $targetProductIds = [];
                if (is_array($update_product_ids) && !empty($update_product_ids)) {
                    $targetProductIds = $update_product_ids;
                } elseif ($existingOrderData) {
                    $targetProductIds = normalizeIdList($existingOrderData['product_ids'] ?? ($existingOrderData['product_id'] ?? null));
                }
                if (empty($targetProductIds)) {
                    $targetProductIds = array_map('intval', array_keys($incomingDatesMap));
                }
                
                // PRESERVE ALL EXISTING TIMESTAMPS when merging
                $computedDatesMap = buildProductStatusDatesMap(
                    $targetProductIds,
                    $existingStatusMap,
                    $existingDatesMap,
                    $incomingDatesMap,
                    $existingStatusMap
                );
                $fields[] = 'product_status_dates_map = :product_status_dates_map';
                $computedDatesMap = ensureNonEmptyProductStatusDatesMap($targetProductIds, $existingStatusMap, $computedDatesMap);
                $params[':product_status_dates_map'] = !empty($computedDatesMap) ? json_encode($computedDatesMap) : null;
                $splitStatusDates = splitProductStatusDatesByStatus($computedDatesMap, $targetProductIds);
                if ($hasPendingDatesColumn) {
                    $fields[] = 'pending_dates = :pending_dates';
                    $params[':pending_dates'] = json_encode($splitStatusDates['pending']);
                }
                if ($hasRajtocomDatesColumn) {
                    $fields[] = 'rajtocom_dates = :rajtocom_dates';
                    $params[':rajtocom_dates'] = json_encode($splitStatusDates['rajtocom']);
                }
                if ($hasComtorajDatesColumn) {
                    $fields[] = 'comtoraj_dates = :comtoraj_dates';
                    $params[':comtoraj_dates'] = json_encode($splitStatusDates['comtoraj']);
                }
                if ($hasDeliveryedDatesColumn) {
                    $fields[] = 'deliveryed_dates = :deliveryed_dates';
                    $params[':deliveryed_dates'] = json_encode($splitStatusDates['deliveryed']);
                }
            }

            // Final safety: never leave product_status_dates_map as {}
            if ($hasProductStatusDatesMapColumn) {
                $currentDatesParam = isset($params[':product_status_dates_map']) ? (string)$params[':product_status_dates_map'] : '';
                if ($currentDatesParam === '' || $currentDatesParam === '{}' || $currentDatesParam === 'null') {
                    $safeStatusMap = [];
                    if (isset($params[':product_status_map']) && $params[':product_status_map']) {
                        $safeStatusMap = normalizeProductStatusMap($params[':product_status_map']);
                    } elseif ($existingOrderData) {
                        $safeStatusMap = normalizeProductStatusMap($existingOrderData['product_status_map'] ?? null);
                    }

                    $safeExistingDatesMap = $existingOrderData
                        ? normalizeProductStatusDatesMap($existingOrderData['product_status_dates_map'] ?? null)
                        : [];

                    $safeProductIds = [];
                    if (is_array($update_product_ids) && !empty($update_product_ids)) {
                        $safeProductIds = $update_product_ids;
                    } elseif ($existingOrderData) {
                        $safeProductIds = normalizeIdList($existingOrderData['product_ids'] ?? ($existingOrderData['product_id'] ?? null));
                    }
                    if (empty($safeProductIds)) {
                        $safeProductIds = array_map('intval', array_keys($safeStatusMap));
                    }

                    if (!empty($safeProductIds)) {
                        $safeDatesMap = buildStrictStatusDatesFromProductIds($safeProductIds, $safeExistingDatesMap);
                        $safeDatesMap = ensureNonEmptyProductStatusDatesMap($safeProductIds, $safeStatusMap, $safeDatesMap);
                        if (!empty($safeDatesMap)) {
                            if (!in_array('product_status_dates_map = :product_status_dates_map', $fields, true)) {
                                $fields[] = 'product_status_dates_map = :product_status_dates_map';
                            }
                            $params[':product_status_dates_map'] = json_encode($safeDatesMap);
                        }
                    }
                }
            }
            
            // Handle repairing_status_map
            $normalizedRepairingStatusMapJson = null;
            if ($hasRepairingStatusMapColumn && array_key_exists('repairing_status_map', $input)) {
                $statusMap = normalizeRepairingStatusMap($input['repairing_status_map']);
                $targetProductIds = [];
                if (is_array($update_product_ids) && !empty($update_product_ids)) {
                    $targetProductIds = $update_product_ids;
                } else {
                    try {
                        $existingProductStmt = $db->prepare("SELECT product_ids, product_id FROM service_orders WHERE id = :id");
                        $existingProductStmt->bindValue(':id', $id, PDO::PARAM_INT);
                        $existingProductStmt->execute();
                        $existingProductRow = $existingProductStmt->fetch(PDO::FETCH_ASSOC);
                        if ($existingProductRow) {
                            $targetProductIds = normalizeIdList($existingProductRow['product_ids'] ?? ($existingProductRow['product_id'] ?? null));
                        }
                    } catch (Exception $e) {
                        $targetProductIds = [];
                    }
                }
                if (!empty($targetProductIds)) {
                    $normalizedStatusMap = [];
                    foreach ($targetProductIds as $productId) {
                        $key = (string)$productId;
                        $normalizedStatusMap[$key] = isset($statusMap[$key]) ? normalizeRepairingStatus($statusMap[$key]) : 'not ready';
                    }
                    $statusMap = $normalizedStatusMap;
                } elseif (!empty($statusMap)) {
                    $preservedStatusMap = [];
                    foreach ($statusMap as $productId => $statusValue) {
                        $key = trim((string)$productId);
                        if ($key === '') continue;
                        $preservedStatusMap[$key] = normalizeRepairingStatus($statusValue);
                    }
                    $statusMap = $preservedStatusMap;
                }
                $fields[] = 'repairing_status_map = :repairing_status_map';
                $normalizedRepairingStatusMapJson = !empty($statusMap) ? json_encode($statusMap) : '{}';
                $params[':repairing_status_map'] = $normalizedRepairingStatusMapJson;
            }

            if ($hasIssueDescriptionMapColumn && array_key_exists('issue_description_map', $input)) {
                $issueMap = normalizeIssueDescriptionMap($input['issue_description_map']);
                $targetProductIds = [];
                if (is_array($update_product_ids) && !empty($update_product_ids)) {
                    $targetProductIds = $update_product_ids;
                } else {
                    try {
                        $existingProductStmt = $db->prepare("SELECT product_ids, product_id FROM service_orders WHERE id = :id");
                        $existingProductStmt->bindValue(':id', $id, PDO::PARAM_INT);
                        $existingProductStmt->execute();
                        $existingProductRow = $existingProductStmt->fetch(PDO::FETCH_ASSOC);
                        if ($existingProductRow) {
                            $targetProductIds = normalizeIdList($existingProductRow['product_ids'] ?? ($existingProductRow['product_id'] ?? null));
                        }
                    } catch (Exception $e) {
                        $targetProductIds = [];
                    }
                }
                if (!empty($targetProductIds)) {
                    $normalizedIssueMap = [];
                    foreach ($targetProductIds as $productId) {
                        $key = (string)$productId;
                        $normalizedIssueMap[$key] = isset($issueMap[$key]) ? trim((string)$issueMap[$key]) : '';
                    }
                    $issueMap = $normalizedIssueMap;
                } elseif (!empty($issueMap)) {
                    $preservedIssueMap = [];
                    foreach ($issueMap as $productId => $issueText) {
                        $key = trim((string)$productId);
                        if ($key === '') continue;
                        $preservedIssueMap[$key] = trim((string)$issueText);
                    }
                    $issueMap = $preservedIssueMap;
                }
                $fields[] = 'issue_description_map = :issue_description_map';
                $params[':issue_description_map'] = !empty($issueMap) ? json_encode($issueMap) : '{}';
                if ($hasLegacyIssueDescriptionColumn) {
                    $legacyIssue = 'N/A';
                    foreach ($issueMap as $issueText) {
                        $candidate = trim((string)$issueText);
                        if ($candidate !== '') {
                            $legacyIssue = $candidate;
                            break;
                        }
                    }
                    $fields[] = 'issue_description = :legacy_issue_description';
                    $params[':legacy_issue_description'] = $legacyIssue;
                }
            }
            
            if ($hasHandoverTypeMapColumn && array_key_exists('handover_type_map', $input)) {
                $handoverTypeMap = normalizeHandoverTypeMap($input['handover_type_map']);
                if (is_array($update_product_ids) && !empty($update_product_ids)) {
                    $normalizedHandoverTypeMap = [];
                    foreach ($update_product_ids as $productId) {
                        $key = (string)$productId;
                        if (isset($handoverTypeMap[$key])) {
                            $normalizedHandoverTypeMap[$key] = $handoverTypeMap[$key];
                        }
                    }
                    $handoverTypeMap = $normalizedHandoverTypeMap;
                }
                $fields[] = 'handover_type_map = :handover_type_map';
                $params[':handover_type_map'] = !empty($handoverTypeMap) ? json_encode($handoverTypeMap) : null;
            }
            if ($hasHandoverTypeColumn) {
                if (array_key_exists('handover_type', $input)) {
                    $handover_type = normalizeHandoverType($input['handover_type']);
                    if ($handover_type === null && $hasHandoverTypeMapColumn && array_key_exists('handover_type_map', $input)) {
                        $handoverTypeMap = normalizeHandoverTypeMap($input['handover_type_map']);
                        $handover_type = !empty($handoverTypeMap) ? reset($handoverTypeMap) : null;
                    }
                    $fields[] = 'handover_type = :handover_type';
                    $params[':handover_type'] = $handover_type;
                }
            }
            $fields[] = 'updated_at = NOW()';
            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "No fields to update"]);
                exit();
            }
            if (isPreviewModeRequest($input)) {
                echo json_encode(["success" => true, "preview" => true, "message" => "Preview generated successfully", "update_preview" => ["id" => (int)$id, "fields" => $fields, "values" => $params]]);
                exit();
            }
            $query = "UPDATE service_orders SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $db->prepare($query);
            $db->beginTransaction();
            try {
                if (!$stmt->execute($params)) throw new Exception("Failed to update order");
                if (!is_null($update_product_ids)) syncOrderProducts($db, (int)$id, $update_product_ids, false);
                if (!is_null($update_replacement_product_ids)) syncOrderProducts($db, (int)$id, $update_replacement_product_ids, true);
                if (array_key_exists('repairing_status_map', $input)) {
                    $forcedRepairingStatusMap = $normalizedRepairingStatusMapJson;
                    if ($forcedRepairingStatusMap === null) {
                        $forcedStatusMap = normalizeRepairingStatusMap($input['repairing_status_map']);
                        if (is_array($update_product_ids) && !empty($update_product_ids)) {
                            $filteredForcedStatusMap = [];
                            foreach ($update_product_ids as $productId) {
                                $key = (string)$productId;
                                $filteredForcedStatusMap[$key] = isset($forcedStatusMap[$key]) ? normalizeRepairingStatus($forcedStatusMap[$key]) : 'not ready';
                            }
                            $forcedStatusMap = $filteredForcedStatusMap;
                        }
                        $forcedRepairingStatusMap = !empty($forcedStatusMap) ? json_encode($forcedStatusMap) : '{}';
                    }
                    try {
                        $repairingStmt = $db->prepare("UPDATE service_orders SET repairing_status_map = :repairing_status_map WHERE id = :id");
                        $repairingStmt->bindValue(':repairing_status_map', $forcedRepairingStatusMap ?? '{}', PDO::PARAM_STR);
                        $repairingStmt->bindValue(':id', $id, PDO::PARAM_INT);
                        $repairingStmt->execute();
                    } catch (Exception $e) {}
                }
                $summaryStmt = $db->prepare("SELECT order_code, final_cost, deposit_amount, payment_status, estimated_cost FROM service_orders WHERE id = :id");
                $summaryStmt->bindValue(':id', $id, PDO::PARAM_INT);
                $summaryStmt->execute();
                $orderSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
                if ($orderSummary) {
                    $desiredStatus = isset($input['payment_status']) ? normalizePaymentStatus($input['payment_status']) : normalizePaymentStatus($orderSummary['payment_status'] ?? 'pending');
                    $payment_method = isset($input['payment_method']) && trim((string)$input['payment_method']) !== '' ? trim((string)$input['payment_method']) : 'cash';
                    $transaction_id = isset($input['transaction_id']) && trim((string)$input['transaction_id']) !== '' ? trim((string)$input['transaction_id']) : null;
                    $payment_notes = isset($input['payment_notes']) && trim((string)$input['payment_notes']) !== '' ? trim((string)$input['payment_notes']) : 'Payment update for service order';
                    $paymentMeta = [
                        'estimated_cost' => (float)($orderSummary['estimated_cost'] ?? 0),
                        'final_cost' => (float)($orderSummary['final_cost'] ?? 0),
                        'deposit_amount' => (float)($orderSummary['deposit_amount'] ?? 0)
                    ];
                    if ($transaction_id !== null) $paymentMeta['transaction_id'] = $transaction_id;
                    ensureOrderPayments($db, (int)$id, $orderSummary['order_code'], (float)($orderSummary['final_cost'] ?? 0), (float)($orderSummary['deposit_amount'] ?? 0), $desiredStatus, $user_id, $payment_method, $payment_notes, $paymentMeta);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(["success" => false, "message" => $e->getMessage()]);
                exit();
            }
            $order = getOrderWithRelations($db, $id);
            if ($order) {
                $currentStatus = isset($input['payment_status']) ? normalizePaymentStatus($input['payment_status']) : normalizePaymentStatus($order['payment_status'] ?? 'pending');
                updateOrderPaymentStatus($db, $id, (float)($order['final_cost'] ?: 0), $currentStatus);
            }
            echo json_encode(["success" => true, "message" => "Order updated successfully", "order" => getOrderWithRelations($db, $id)]);
            break;

        case 'DELETE':
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Order ID required"]);
                exit();
            }
            $db->beginTransaction();
            try {
                $deletePaymentsStmt = $db->prepare("DELETE FROM payments WHERE order_id = :order_id");
                $deletePaymentsStmt->bindParam(':order_id', $id);
                $deletePaymentsStmt->execute();
                $deleteProductsStmt = $db->prepare("DELETE FROM service_order_products WHERE order_id = :order_id");
                $deleteProductsStmt->bindParam(':order_id', $id);
                $deleteProductsStmt->execute();
                $stmt = $db->prepare("DELETE FROM service_orders WHERE id = :id");
                $stmt->bindParam(':id', $id);
                if (!$stmt->execute()) throw new Exception("Failed to delete order");
                $db->commit();
                echo json_encode(["success" => true, "message" => "Order and related payments deleted successfully"]);
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Failed to delete order", "error" => $e->getMessage()]);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(["success" => false, "message" => "Method not allowed"]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error", "error" => $e->getMessage()]);
}
?>

