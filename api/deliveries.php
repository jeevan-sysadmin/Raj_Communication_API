<?php
// Set headers for CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error reporting for development
error_reporting(E_ALL);
// Keep API responses valid JSON (log errors, don't print HTML notices/warnings)
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config/database.php';

// Simple Auth class
class Auth {
    
    // Get bearer token from Authorization header
    public function getBearerToken() {
        $headers = null;
        
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    // Verify token - For development, accept any token. For production, implement proper validation
    public function verifyToken($token) {
        // For development purposes, accept all tokens
        // In production, implement proper JWT validation here
        return !empty($token);
    }
}

// Initialize database and auth
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        throw new Exception("Could not connect to database");
    }
    
    $auth = new Auth();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Initialization error',
        'error' => $e->getMessage()
    ]);
    exit();
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Verify token for all requests except OPTIONS
$token = $auth->getBearerToken();
if (!$token || !$auth->verifyToken($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid or missing token']);
    exit();
}

// Route the request
switch ($method) {
    case 'GET':
        getDeliveries($conn);
        break;
    case 'POST':
        createDelivery($conn);
        break;
    case 'PUT':
        updateDelivery($conn);
        break;
    case 'DELETE':
        deleteDelivery($conn);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

function normalizeFlowStatus($value) {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === 'deliveryed' || $normalized === 'delivered') {
        return 'deliveryed';
    }
    if ($normalized === 'rajtocom') {
        return 'rajtocom';
    }
    if ($normalized === 'comtoraj') {
        return 'comtoraj';
    }
    return 'pending';
}

function normalizeDeliveryType($value) {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === 'inhand' || $normalized === 'courier' || $normalized === 'parcelservice') {
        return $normalized;
    }
    // Backward-compatibility mapping
    if ($normalized === 'pickup' || $normalized === 'in_hand') {
        return 'inhand';
    }
    if ($normalized === 'delivery' || $normalized === 'parcel_service') {
        return 'parcelservice';
    }
    return 'inhand';
}

function isValidDeliveryType($value) {
    return in_array((string)$value, ['inhand', 'courier', 'parcelservice'], true);
}

function normalizeDeliveryTypeRowsInDatabase($conn) {
    try {
        $conn->exec("
            UPDATE deliveries
            SET delivery_type = 'inhand'
            WHERE delivery_type IS NULL
               OR delivery_type = ''
               OR delivery_type = 'in_hand'
               OR delivery_type = 'pickup'
        ");

        $conn->exec("
            UPDATE deliveries
            SET delivery_type = 'parcelservice'
            WHERE delivery_type = 'parcel_service'
               OR delivery_type = 'delivery'
               OR delivery_type = 'home_delivery'
        ");
    } catch (Exception $e) {
        // Do not block API if cleanup fails; requests can still proceed.
        error_log('Delivery type normalization failed: ' . $e->getMessage());
    }
}

function hasDeliveredProductStatus($statusMapRaw) {
    if ($statusMapRaw === null || $statusMapRaw === '') {
        return false;
    }

    $statusMap = $statusMapRaw;
    if (is_string($statusMapRaw)) {
        $decoded = json_decode($statusMapRaw, true);
        if (!is_array($decoded)) {
            return false;
        }
        $statusMap = $decoded;
    }

    if (!is_array($statusMap)) {
        return false;
    }

    foreach ($statusMap as $status) {
        if (normalizeFlowStatus($status) === 'deliveryed') {
            return true;
        }
    }

    return false;
}

function serviceOrdersHasProductStatusMapColumn($conn) {
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    try {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'service_orders'
              AND COLUMN_NAME = 'product_status_map'
            LIMIT 1
        ");
        $stmt->execute();
        $hasColumn = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        $hasColumn = false;
    }

    return $hasColumn;
}

function serviceOrdersHasColumn($conn, $columnName) {
    static $cache = [];
    $key = strtolower((string)$columnName);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'service_orders'
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $stmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
        $stmt->execute();
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function deliveriesHasColumn($conn, $columnName) {
    static $cache = [];
    $key = strtolower((string)$columnName);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'deliveries'
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $stmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
        $stmt->execute();
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function ensureDeliveriesSchemaColumns($conn) {
    try {
        if (!deliveriesHasColumn($conn, 'product_id')) {
            $conn->exec("ALTER TABLE deliveries ADD COLUMN product_id INT(11) NULL AFTER notes");
        }
        if (!deliveriesHasColumn($conn, 'product_ids')) {
            $conn->exec("ALTER TABLE deliveries ADD COLUMN product_ids LONGTEXT NULL AFTER product_id");
        }
        if (!deliveriesHasColumn($conn, 'serial_numbers')) {
            $conn->exec("ALTER TABLE deliveries ADD COLUMN serial_numbers LONGTEXT NULL AFTER product_ids");
        }
        if (!deliveriesHasColumn($conn, 'serial_number')) {
            $conn->exec("ALTER TABLE deliveries ADD COLUMN serial_number VARCHAR(255) NULL AFTER serial_numbers");
        }
        if (!deliveriesHasColumn($conn, 'delivery_type_map')) {
            $conn->exec("ALTER TABLE deliveries ADD COLUMN delivery_type_map LONGTEXT NULL AFTER serial_number");
        }
        if (!deliveriesHasColumn($conn, 'is_deleted')) {
            $conn->exec("ALTER TABLE deliveries ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_type_map");
        }
    } catch (Exception $e) {
        error_log('ensureDeliveriesSchemaColumns failed: ' . $e->getMessage());
    }
}

function parseJsonArraySafe($value) {
    if (is_array($value)) {
        return array_values($value);
    }
    if (!is_string($value)) {
        return [];
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return [];
    }
    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return array_values($decoded);
    }
    return array_values(array_filter(array_map('trim', explode(',', $trimmed)), function ($entry) {
        return $entry !== '';
    }));
}

function parseJsonObjectSafe($value) {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return [];
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return [];
    }
    $decoded = json_decode($trimmed, true);
    return is_array($decoded) ? $decoded : [];
}

function extractDeliveredProductIds($statusMapRaw) {
    $statusMap = parseJsonObjectSafe($statusMapRaw);
    $delivered = [];
    foreach ($statusMap as $productId => $status) {
        $pid = (int)$productId;
        if ($pid > 0 && normalizeFlowStatus($status) === 'deliveryed') {
            $delivered[] = $pid;
        }
    }
    return array_values(array_unique($delivered));
}

function generateUniqueDeliveryCode($conn) {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = 'DEL' . date('YmdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $checkStmt = $conn->prepare("SELECT id FROM deliveries WHERE delivery_code = :delivery_code LIMIT 1");
        $checkStmt->bindValue(':delivery_code', $candidate, PDO::PARAM_STR);
        $checkStmt->execute();
        if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
            return $candidate;
        }
        usleep(20000);
    }
    return 'DEL' . date('YmdHis') . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
}

function syncDeliveredProductStatusToDeliveries($conn) {
    ensureDeliveriesSchemaColumns($conn);

    $hasProductStatusMapColumn = serviceOrdersHasProductStatusMapColumn($conn);
    $hasProductIdsColumn = serviceOrdersHasColumn($conn, 'product_ids');
    $hasHandoverTypeColumn = serviceOrdersHasColumn($conn, 'handover_type');
    $hasHandoverTypeMapColumn = serviceOrdersHasColumn($conn, 'handover_type_map');
    $hasProductSerialNumbersColumn = serviceOrdersHasColumn($conn, 'product_serial_numbers');

    $productStatusMapSelect = $hasProductStatusMapColumn ? "so.product_status_map" : "NULL AS product_status_map";
    $productIdsSelect = $hasProductIdsColumn ? "so.product_ids" : "NULL AS product_ids";
    $handoverTypeSelect = $hasHandoverTypeColumn ? "so.handover_type" : "NULL AS handover_type";
    $handoverTypeMapSelect = $hasHandoverTypeMapColumn ? "so.handover_type_map" : "NULL AS handover_type_map";
    $productSerialNumbersSelect = $hasProductSerialNumbersColumn ? "so.product_serial_numbers" : "NULL AS product_serial_numbers";

    $ordersQuery = "SELECT so.id, so.order_code, so.client_id, so.product_id, " . $productStatusMapSelect . ",
                           " . $productIdsSelect . ", " . $handoverTypeSelect . ", " . $handoverTypeMapSelect . ",
                           " . $productSerialNumbersSelect . ", so.created_at,
                           c.full_name AS client_name, c.phone AS client_phone, c.address AS client_address
                    FROM service_orders so
                    LEFT JOIN clients c ON c.id = so.client_id";
    $ordersStmt = $conn->prepare($ordersQuery);
    $ordersStmt->execute();
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    $existingDeliveriesStmt = $conn->prepare("
        SELECT id, status, delivered_date, product_id, product_ids, serial_numbers, delivery_type_map, delivery_code, created_at, is_deleted
        FROM deliveries
        WHERE order_id = :order_id
        ORDER BY id DESC
    ");
    $deleteDuplicateStmt = $conn->prepare("DELETE FROM deliveries WHERE id = :id");
    $insertDeliveryStmt = $conn->prepare(
        "INSERT INTO deliveries (
            order_id, serial_number, delivery_type_map, delivery_code, delivery_type, address, contact_person, contact_phone,
            scheduled_date, scheduled_time, delivered_date, delivery_person, status, notes, product_id, product_ids, serial_numbers, created_at, updated_at
        ) VALUES (
            :order_id, :serial_number, :delivery_type_map, :delivery_code, :delivery_type, :address, :contact_person, :contact_phone,
            :scheduled_date, :scheduled_time, :delivered_date, :delivery_person, :status, :notes, :product_id, :product_ids, :serial_numbers, NOW(), NOW()
        )"
    );
    $updateDeliveryStmt = $conn->prepare(
        "UPDATE deliveries
         SET status = 'delivered',
             serial_number = :serial_number,
             delivery_type = :delivery_type,
             delivery_type_map = :delivery_type_map,
             address = :address,
             contact_person = :contact_person,
             contact_phone = :contact_phone,
             product_id = :product_id,
             product_ids = :product_ids,
             serial_numbers = :serial_numbers,
             notes = :notes,
             delivered_date = COALESCE(delivered_date, NOW()),
             updated_at = NOW()
         WHERE id = :id"
    );

    foreach ($orders as $order) {
        $deliveredProductIds = extractDeliveredProductIds($order['product_status_map'] ?? null);
        if (empty($deliveredProductIds)) {
            continue;
        }
        sort($deliveredProductIds);

        $orderId = (int)$order['id'];
        if ($orderId <= 0) {
            continue;
        }

        $productIds = parseJsonArraySafe($order['product_ids'] ?? ($order['product_id'] ?? null));
        if (empty($productIds) && !empty($order['product_id'])) {
            $productIds = [(int)$order['product_id']];
        }
        $serials = parseJsonArraySafe($order['product_serial_numbers'] ?? '');
        $serialByProductId = [];
        foreach ($productIds as $index => $pid) {
            $safePid = (int)$pid;
            if ($safePid > 0) {
                $serialByProductId[$safePid] = trim((string)($serials[$index] ?? ''));
            }
        }

        $handoverMap = parseJsonObjectSafe($order['handover_type_map'] ?? null);
        $fallbackDeliveryType = normalizeDeliveryType($order['handover_type'] ?? 'inhand');
        $scheduledDate = null;
        if (!empty($order['created_at'])) {
            $scheduledDate = date('Y-m-d', strtotime($order['created_at']));
        }

        $productInfo = [];
        if (!empty($deliveredProductIds)) {
            $placeholders = implode(',', array_fill(0, count($deliveredProductIds), '?'));
            $productStmt = $conn->prepare("SELECT id, product_name, serial_number FROM products WHERE id IN ($placeholders)");
            $productStmt->execute($deliveredProductIds);
            while ($productRow = $productStmt->fetch(PDO::FETCH_ASSOC)) {
                $productInfo[(int)$productRow['id']] = $productRow;
            }
        }

        $aggregateDeliveryTypeMap = [];
        $aggregateSerials = [];
        $aggregateNames = [];
        foreach ($deliveredProductIds as $productId) {
            $deliveryType = isset($handoverMap[(string)$productId])
                ? normalizeDeliveryType($handoverMap[(string)$productId])
                : $fallbackDeliveryType;
            $aggregateDeliveryTypeMap[(string)$productId] = $deliveryType;

            $serialNumber = isset($serialByProductId[$productId]) && $serialByProductId[$productId] !== ''
                ? $serialByProductId[$productId]
                : trim((string)($productInfo[$productId]['serial_number'] ?? ''));
            if ($serialNumber !== '') {
                $aggregateSerials[] = $serialNumber;
            }

            $aggregateNames[] = trim((string)($productInfo[$productId]['product_name'] ?? ("Product #{$productId}")));
        }

        $primaryProductId = (int)$deliveredProductIds[0];
        $primaryDeliveryType = $aggregateDeliveryTypeMap[(string)$primaryProductId] ?? $fallbackDeliveryType;
        $primarySerialNumber = $aggregateSerials[0] ?? null;
        $deliveryTypeMapJson = json_encode($aggregateDeliveryTypeMap);
        $productIdsJson = json_encode(array_values($deliveredProductIds));
        $serialNumbersJson = !empty($aggregateSerials) ? json_encode(array_values($aggregateSerials)) : null;
        $notes = 'Auto-created from delivered products for order '
            . ($order['order_code'] ?: ('#' . $orderId))
            . ' - Products: ' . implode(', ', $aggregateNames);

        $existingDeliveriesStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $existingDeliveriesStmt->execute();
        $existingRows = $existingDeliveriesStmt->fetchAll(PDO::FETCH_ASSOC);

        $primaryRow = null;
        $duplicateRowIds = [];
        $hasDeletedRow = false;
        foreach ($existingRows as $index => $existingRow) {
            if ((int)($existingRow['is_deleted'] ?? 0) === 1) {
                $hasDeletedRow = true;
            }
            if ($index === 0) {
                $primaryRow = $existingRow;
            } else {
                $duplicateRowIds[] = (int)$existingRow['id'];
            }
        }

        if ($hasDeletedRow) {
            continue;
        }

        if ($primaryRow) {
            $existingDeliveryTypeMap = parseJsonObjectSafe($primaryRow['delivery_type_map'] ?? null);
            $existingProductIds = parseJsonArraySafe($primaryRow['product_ids'] ?? null);
            $existingSerials = parseJsonArraySafe($primaryRow['serial_numbers'] ?? null);
            $existingSerialByProductId = [];
            foreach ($existingProductIds as $existingIndex => $existingProductId) {
                $safeExistingProductId = (int)$existingProductId;
                if ($safeExistingProductId > 0) {
                    $existingSerialByProductId[$safeExistingProductId] = trim((string)($existingSerials[$existingIndex] ?? ''));
                }
            }

            $preservedDeliveryTypeMap = [];
            $preservedSerials = [];
            foreach ($deliveredProductIds as $productId) {
                $existingType = isset($existingDeliveryTypeMap[(string)$productId])
                    ? normalizeDeliveryType($existingDeliveryTypeMap[(string)$productId])
                    : '';
                $resolvedType = isValidDeliveryType($existingType)
                    ? $existingType
                    : ($aggregateDeliveryTypeMap[(string)$productId] ?? $fallbackDeliveryType);
                $preservedDeliveryTypeMap[(string)$productId] = $resolvedType;

                $resolvedSerial = trim((string)($existingSerialByProductId[$productId] ?? ''));
                if ($resolvedSerial === '') {
                    $resolvedSerial = isset($serialByProductId[$productId]) && $serialByProductId[$productId] !== ''
                        ? $serialByProductId[$productId]
                        : trim((string)($productInfo[$productId]['serial_number'] ?? ''));
                }
                if ($resolvedSerial !== '') {
                    $preservedSerials[] = $resolvedSerial;
                }
            }

            $primaryDeliveryType = $preservedDeliveryTypeMap[(string)$primaryProductId] ?? $primaryDeliveryType;
            $primarySerialNumber = $preservedSerials[0] ?? $primarySerialNumber;
            $deliveryTypeMapJson = json_encode($preservedDeliveryTypeMap);
            $serialNumbersJson = !empty($preservedSerials) ? json_encode(array_values($preservedSerials)) : $serialNumbersJson;

            $updateDeliveryStmt->bindValue(':id', (int)$primaryRow['id'], PDO::PARAM_INT);
            $updateDeliveryStmt->bindValue(':serial_number', $primarySerialNumber, $primarySerialNumber !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $updateDeliveryStmt->bindValue(':delivery_type', $primaryDeliveryType, PDO::PARAM_STR);
            $updateDeliveryStmt->bindValue(':delivery_type_map', $deliveryTypeMapJson, PDO::PARAM_STR);
            $updateDeliveryStmt->bindValue(':address', $order['client_address'] ?: 'Address not specified', PDO::PARAM_STR);
            $updateDeliveryStmt->bindValue(':contact_person', $order['client_name'] ?: 'Customer', PDO::PARAM_STR);
            $updateDeliveryStmt->bindValue(':contact_phone', $order['client_phone'] ?: 'N/A', PDO::PARAM_STR);
            $updateDeliveryStmt->bindValue(':product_id', $primaryProductId, PDO::PARAM_INT);
            $updateDeliveryStmt->bindValue(':product_ids', $productIdsJson, PDO::PARAM_STR);
            $updateDeliveryStmt->bindValue(':serial_numbers', $serialNumbersJson, $serialNumbersJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $updateDeliveryStmt->bindValue(':notes', $notes, PDO::PARAM_STR);
            $updateDeliveryStmt->execute();
        } else {
            $insertDeliveryStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $insertDeliveryStmt->bindValue(':serial_number', $primarySerialNumber, $primarySerialNumber !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertDeliveryStmt->bindValue(':delivery_type_map', $deliveryTypeMapJson, PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':delivery_code', generateUniqueDeliveryCode($conn), PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':delivery_type', $primaryDeliveryType, PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':address', $order['client_address'] ?: 'Address not specified', PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':contact_person', $order['client_name'] ?: 'Customer', PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':contact_phone', $order['client_phone'] ?: 'N/A', PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':scheduled_date', $scheduledDate, $scheduledDate ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertDeliveryStmt->bindValue(':scheduled_time', '09:00:00', PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':delivered_date', date('Y-m-d H:i:s'), PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':delivery_person', 'System Auto-assigned', PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':status', 'delivered', PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':notes', $notes, PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':product_id', $primaryProductId, PDO::PARAM_INT);
            $insertDeliveryStmt->bindValue(':product_ids', $productIdsJson, PDO::PARAM_STR);
            $insertDeliveryStmt->bindValue(':serial_numbers', $serialNumbersJson, $serialNumbersJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertDeliveryStmt->execute();
        }

        foreach ($duplicateRowIds as $duplicateId) {
            if ($duplicateId > 0) {
                $deleteDuplicateStmt->bindValue(':id', $duplicateId, PDO::PARAM_INT);
                $deleteDuplicateStmt->execute();
            }
        }
    }
}

function deliveredDeliveryWhereClause($alias = 'd') {
    return "(
        LOWER(TRIM(COALESCE({$alias}.status, ''))) IN ('delivered', 'deliveryed')
        OR (
            {$alias}.delivered_date IS NOT NULL
            AND {$alias}.delivered_date <> ''
            AND {$alias}.delivered_date <> '0000-00-00 00:00:00'
        )
    )";
}

/**
 * Get all deliveries
 */
function getDeliveries($conn) {
    try {
        normalizeDeliveryTypeRowsInDatabase($conn);
        syncDeliveredProductStatusToDeliveries($conn);

        $parseNameList = function ($value): array {
            if (is_array($value)) {
                return array_values(array_filter(array_map(function ($entry) {
                    return trim((string)$entry);
                }, $value), function ($entry) {
                    return $entry !== '';
                }));
            }
            if (!is_string($value)) return [];
            $trimmed = trim($value);
            if ($trimmed === '') return [];
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values(array_filter(array_map(function ($entry) {
                    return trim((string)$entry);
                }, $decoded), function ($entry) {
                    return $entry !== '';
                }));
            }
            $parts = preg_split('/\|\||,/', $trimmed);
            return array_values(array_filter(array_map(function ($entry) {
                return trim((string)$entry);
            }, $parts ?: []), function ($entry) {
                return $entry !== '';
            }));
        };

        $hasProductNamesColumn = serviceOrdersHasColumn($conn, 'product_names');
        $hasProductSerialNumbersColumn = serviceOrdersHasColumn($conn, 'product_serial_numbers');
        $productNamesSelect = $hasProductNamesColumn ? "so.product_names" : "NULL AS product_names";
        $productSerialsSelect = $hasProductSerialNumbersColumn ? "so.product_serial_numbers" : "NULL AS product_serial_numbers";

        if (isset($_GET['id']) && !empty($_GET['id'])) {
            $query = "SELECT d.*,
                             COALESCE(NULLIF(d.delivery_type, ''), 'inhand') AS delivery_type,
                             o.order_code, 
                             so.product_ids AS order_product_ids,
                             {$productNamesSelect},
                             {$productSerialsSelect},
                             so.product_status_map,
                             so.handover_type_map,
                             c.full_name as client_name, 
                             c.phone as client_phone,
                             c.email as client_email,
                             c.address as client_address,
                             p.product_name,
                             p.brand AS product_brand,
                             p.model AS product_model,
                             p.serial_number AS product_serial_number
                      FROM deliveries d 
                      LEFT JOIN service_orders o ON d.order_id = o.id 
                      LEFT JOIN service_orders so ON d.order_id = so.id
                      LEFT JOIN clients c ON o.client_id = c.id
                      LEFT JOIN products p ON p.id = COALESCE(d.product_id, so.product_id)
                      WHERE d.id = :id
                        AND COALESCE(d.is_deleted, 0) = 0";

            $stmt = $conn->prepare($query);
            $stmt->bindValue(':id', (int)$_GET['id'], PDO::PARAM_INT);
            $stmt->execute();
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($delivery) {
                $delivery['delivery_type'] = normalizeDeliveryType($delivery['delivery_type'] ?? '');
                if (empty($delivery['product_serial_number']) && !empty($delivery['product_name'])) {
                    $names = $parseNameList($delivery['product_names'] ?? null);
                    $serials = $parseNameList($delivery['product_serial_numbers'] ?? null);
                    $target = strtolower(trim((string)$delivery['product_name']));
                    if (!empty($names) && !empty($serials)) {
                        $found = false;
                        foreach ($names as $index => $name) {
                            if (strtolower(trim((string)$name)) === $target) {
                                $delivery['product_serial_number'] = $serials[$index] ?? '';
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            foreach ($names as $index => $name) {
                                $normalizedName = strtolower(trim((string)$name));
                                if ($normalizedName !== '' && (strpos($normalizedName, $target) !== false || strpos($target, $normalizedName) !== false)) {
                                    $delivery['product_serial_number'] = $serials[$index] ?? '';
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found && !empty($serials[0])) {
                            $delivery['product_serial_number'] = $serials[0];
                        }
                    }
                }
                echo json_encode([
                    'success' => true,
                    'delivery' => $delivery
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Delivery not found'
                ]);
            }
            return;
        }

        $whereClause = " WHERE COALESCE(d.is_deleted, 0) = 0";
        $params = [];

        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $requestedStatus = strtolower(trim((string)$_GET['status']));
            if ($requestedStatus === 'delivered' || $requestedStatus === 'deliveryed') {
                $whereClause .= " AND " . deliveredDeliveryWhereClause('d');
            } else {
                $whereClause .= " AND LOWER(TRIM(COALESCE(d.status, ''))) = :status";
                $params[':status'] = $requestedStatus;
            }
        }
        if (isset($_GET['delivery_person']) && $_GET['delivery_person'] !== '') {
            $whereClause .= " AND d.delivery_person = :delivery_person";
            $params[':delivery_person'] = $_GET['delivery_person'];
        }
        if (isset($_GET['start_date']) && $_GET['start_date'] !== '') {
            $whereClause .= " AND d.scheduled_date >= :start_date";
            $params[':start_date'] = $_GET['start_date'];
        }
        if (isset($_GET['end_date']) && $_GET['end_date'] !== '') {
            $whereClause .= " AND d.scheduled_date <= :end_date";
            $params[':end_date'] = $_GET['end_date'];
        }
        if (isset($_GET['delivery_code']) && $_GET['delivery_code'] !== '') {
            $whereClause .= " AND d.delivery_code LIKE :delivery_code";
            $params[':delivery_code'] = '%' . $_GET['delivery_code'] . '%';
        }
        if (isset($_GET['order_code']) && $_GET['order_code'] !== '') {
            $whereClause .= " AND o.order_code LIKE :order_code";
            $params[':order_code'] = '%' . $_GET['order_code'] . '%';
        }
        if (isset($_GET['client_name']) && $_GET['client_name'] !== '') {
            $whereClause .= " AND c.full_name LIKE :client_name";
            $params[':client_name'] = '%' . $_GET['client_name'] . '%';
        }

        $query = "SELECT d.*,
                         COALESCE(NULLIF(d.delivery_type, ''), 'inhand') AS delivery_type,
                         o.order_code, 
                         so.product_ids AS order_product_ids,
                         {$productNamesSelect},
                         {$productSerialsSelect},
                         so.product_status_map,
                         so.handover_type_map,
                         c.full_name as client_name, 
                         c.phone as client_phone,
                         c.email as client_email,
                         c.address as client_address,
                         p.product_name,
                         p.brand AS product_brand,
                         p.model AS product_model,
                         p.serial_number AS product_serial_number
                  FROM deliveries d 
                  LEFT JOIN service_orders o ON d.order_id = o.id 
                  LEFT JOIN service_orders so ON d.order_id = so.id
                  LEFT JOIN clients c ON o.client_id = c.id
                  LEFT JOIN products p ON p.id = COALESCE(d.product_id, so.product_id)
                  {$whereClause}
                  ORDER BY COALESCE(d.delivered_date, d.updated_at, d.created_at, TIMESTAMP(d.scheduled_date, d.scheduled_time)) DESC, d.id DESC";

        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deliveries as &$delivery) {
            $delivery['delivery_type'] = normalizeDeliveryType($delivery['delivery_type'] ?? '');
            if (!empty($delivery['scheduled_date'])) {
                $delivery['scheduled_date_formatted'] = date('d/m/Y', strtotime($delivery['scheduled_date']));
            }
            if (!empty($delivery['delivered_date'])) {
                $delivery['delivered_date_formatted'] = date('d/m/Y H:i', strtotime($delivery['delivered_date']));
            }
            if (!empty($delivery['created_at'])) {
                $delivery['created_at_formatted'] = date('d/m/Y H:i', strtotime($delivery['created_at']));
            }
            if (!empty($delivery['updated_at'])) {
                $delivery['updated_at_formatted'] = date('d/m/Y H:i', strtotime($delivery['updated_at']));
            }
            if (!empty($delivery['scheduled_time'])) {
                $delivery['scheduled_time_formatted'] = date('h:i A', strtotime($delivery['scheduled_time']));
            }

            if (empty($delivery['product_serial_number']) && !empty($delivery['product_name'])) {
                $names = $parseNameList($delivery['product_names'] ?? null);
                $serials = $parseNameList($delivery['product_serial_numbers'] ?? null);
                $target = strtolower(trim((string)$delivery['product_name']));
                if (!empty($names) && !empty($serials)) {
                    $found = false;
                    foreach ($names as $index => $name) {
                        if (strtolower(trim((string)$name)) === $target) {
                            $delivery['product_serial_number'] = $serials[$index] ?? '';
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        foreach ($names as $index => $name) {
                            $normalizedName = strtolower(trim((string)$name));
                            if ($normalizedName !== '' && (strpos($normalizedName, $target) !== false || strpos($target, $normalizedName) !== false)) {
                                $delivery['product_serial_number'] = $serials[$index] ?? '';
                                $found = true;
                                break;
                            }
                        }
                    }
                    if (!$found && !empty($serials[0])) {
                        $delivery['product_serial_number'] = $serials[0];
                    }
                }
            }
        }
        unset($delivery);

        echo json_encode([
            'success' => true,
            'count' => count($deliveries),
            'deliveries' => $deliveries
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error',
            'error' => $e->getMessage()
        ]);
    }
}
/**
 * Create a new delivery
 */
function createDelivery($conn) {
    try {
        ensureDeliveriesSchemaColumns($conn);
        $data = json_decode(file_get_contents("php://input"), true);
        
        // If no JSON data, check form data
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string)$_SERVER['CONTENT_TYPE']) : '';
        if (empty($data) && strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            $data = $_POST;
        }
        
        if (empty($data)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No data provided'
            ]);
            return;
        }
        
        // Validate required fields
        $required = ['order_id', 'delivery_type', 'address', 'contact_person', 'contact_phone', 'scheduled_date', 'scheduled_time'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Missing required field: {$field}"
                ]);
                return;
            }
        }
        
        // Check if order exists
        $checkOrderQuery = "SELECT id FROM service_orders WHERE id = :order_id";
        $checkOrderStmt = $conn->prepare($checkOrderQuery);
        $checkOrderStmt->bindParam(':order_id', $data['order_id'], PDO::PARAM_INT);
        $checkOrderStmt->execute();
        
        if ($checkOrderStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Order not found'
            ]);
            return;
        }
        
        // Generate delivery code
        $delivery_code = 'DEL' . date('Ymd') . strtoupper(substr(uniqid(), -6));
        
        $deliveryProductId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
        if ($deliveryProductId <= 0) {
            $orderProductStmt = $conn->prepare("SELECT product_id FROM service_orders WHERE id = :order_id LIMIT 1");
            $orderProductStmt->bindParam(':order_id', $data['order_id'], PDO::PARAM_INT);
            $orderProductStmt->execute();
            $orderProductRow = $orderProductStmt->fetch(PDO::FETCH_ASSOC);
            $deliveryProductId = (int)($orderProductRow['product_id'] ?? 0);
        }
        $deliveryType = normalizeDeliveryType($data['delivery_type']);
        if (!isValidDeliveryType($deliveryType)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid delivery_type. Allowed: inhand, courier, parcelservice'
            ]);
            return;
        }
        $deliveryTypeMapJson = $deliveryProductId > 0 ? json_encode([(string)$deliveryProductId => $deliveryType]) : '{}';
        $productIdsJson = $deliveryProductId > 0 ? json_encode([$deliveryProductId]) : null;

        $query = "INSERT INTO deliveries (
            order_id, delivery_type_map, delivery_code, delivery_type, address,
            contact_person, contact_phone, scheduled_date,
            scheduled_time, delivery_person, status, notes, product_id, product_ids
        ) VALUES (
            :order_id, :delivery_type_map, :delivery_code, :delivery_type, :address,
            :contact_person, :contact_phone, :scheduled_date,
            :scheduled_time, :delivery_person, :status, :notes, :product_id, :product_ids
        )";
        
        $stmt = $conn->prepare($query);
        
        // Set default status if not provided (use 'scheduled' to match your database enum)
        $status = isset($data['status']) ? $data['status'] : 'scheduled';
        
        $stmt->bindParam(':order_id', $data['order_id'], PDO::PARAM_INT);
        $stmt->bindValue(':delivery_type_map', $deliveryTypeMapJson, PDO::PARAM_STR);
        $stmt->bindParam(':delivery_code', $delivery_code);
        $stmt->bindParam(':delivery_type', $deliveryType);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':contact_person', $data['contact_person']);
        $stmt->bindParam(':contact_phone', $data['contact_phone']);
        $stmt->bindParam(':scheduled_date', $data['scheduled_date']);
        $stmt->bindParam(':scheduled_time', $data['scheduled_time']);
        $stmt->bindParam(':delivery_person', $data['delivery_person'] ?? null);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':notes', $data['notes'] ?? null);
        $stmt->bindValue(':product_id', $deliveryProductId > 0 ? $deliveryProductId : null, $deliveryProductId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':product_ids', $productIdsJson, $productIdsJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        
        if ($stmt->execute()) {
            $delivery_id = $conn->lastInsertId();
            
            // Fetch the created delivery with client info
            $fetchQuery = "SELECT d.*, 
                                  o.order_code, 
                                  c.full_name as client_name, 
                                  c.phone as client_phone
                           FROM deliveries d 
                           LEFT JOIN service_orders o ON d.order_id = o.id 
                           LEFT JOIN clients c ON o.client_id = c.id
                           WHERE d.id = :id";
            $fetchStmt = $conn->prepare($fetchQuery);
            $fetchStmt->bindParam(':id', $delivery_id, PDO::PARAM_INT);
            $fetchStmt->execute();
            $delivery = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            if ($delivery) {
                $delivery['delivery_type'] = normalizeDeliveryType($delivery['delivery_type'] ?? '');
            }
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Delivery scheduled successfully',
                'delivery_id' => $delivery_id,
                'delivery_code' => $delivery_code,
                'delivery' => $delivery
            ]);
        } else {
            throw new Exception('Failed to execute query');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to schedule delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Update an existing delivery
 */
function updateDelivery($conn) {
    try {
        ensureDeliveriesSchemaColumns($conn);
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $data = [];
        }

        // Get delivery ID from URL parameter first, then request body
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        if (!$id && isset($data['id'])) {
            $id = $data['id'];
        }

        if (!$id || !is_numeric($id)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
            return;
        }

        // If no JSON data, check form data
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string)$_SERVER['CONTENT_TYPE']) : '';
        if (empty($data) && strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            $data = $_POST;
        }
        
        // Check if delivery exists
        $checkQuery = "SELECT id, status, product_id, product_ids, delivery_type_map, order_id FROM deliveries WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $id = (int)$id;
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery not found']);
            return;
        }
        
        $currentDelivery = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $currentProductId = (int)($currentDelivery['product_id'] ?? 0);
        $currentOrderId = (int)($currentDelivery['order_id'] ?? 0);
        if ($currentProductId <= 0 && !empty($currentDelivery['order_id'])) {
            $orderProductStmt = $conn->prepare("SELECT product_id FROM service_orders WHERE id = :order_id LIMIT 1");
            $orderProductStmt->bindValue(':order_id', (int)$currentDelivery['order_id'], PDO::PARAM_INT);
            $orderProductStmt->execute();
            $orderProductRow = $orderProductStmt->fetch(PDO::FETCH_ASSOC);
            $currentProductId = (int)($orderProductRow['product_id'] ?? 0);
        }

        $resolveSerialNumbersForProductIds = function ($orderId, $productIds) use ($conn) {
            $normalizedIds = array_values(array_filter(array_map('intval', is_array($productIds) ? $productIds : []), function ($id) {
                return $id > 0;
            }));
            if ((int)$orderId <= 0 || empty($normalizedIds)) {
                return [];
            }

            $serialByProductId = [];
            try {
                $orderStmt = $conn->prepare("SELECT product_ids, product_serial_numbers FROM service_orders WHERE id = :order_id LIMIT 1");
                $orderStmt->bindValue(':order_id', (int)$orderId, PDO::PARAM_INT);
                $orderStmt->execute();
                $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
                if ($orderRow) {
                    $orderProductIds = parseJsonArraySafe($orderRow['product_ids'] ?? null);
                    $orderSerials = parseJsonArraySafe($orderRow['product_serial_numbers'] ?? null);
                    foreach ($orderProductIds as $index => $productId) {
                        $safeProductId = (int)$productId;
                        if ($safeProductId > 0) {
                            $serialByProductId[$safeProductId] = trim((string)($orderSerials[$index] ?? ''));
                        }
                    }
                }
            } catch (Exception $e) {
                // fallback to products table below
            }

            $missingIds = array_values(array_filter($normalizedIds, function ($productId) use ($serialByProductId) {
                return !isset($serialByProductId[$productId]) || $serialByProductId[$productId] === '';
            }));

            if (!empty($missingIds)) {
                $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
                $productStmt = $conn->prepare("SELECT id, serial_number FROM products WHERE id IN ($placeholders)");
                $productStmt->execute($missingIds);
                while ($productRow = $productStmt->fetch(PDO::FETCH_ASSOC)) {
                    $safeProductId = (int)($productRow['id'] ?? 0);
                    if ($safeProductId > 0) {
                        $serialByProductId[$safeProductId] = trim((string)($productRow['serial_number'] ?? ''));
                    }
                }
            }

            $serials = [];
            foreach ($normalizedIds as $productId) {
                $serial = trim((string)($serialByProductId[$productId] ?? ''));
                if ($serial !== '') {
                    $serials[] = $serial;
                }
            }

            return $serials;
        };
        
        // Build update query dynamically
        $fields = [];
        $params = [':id' => $id];
        $setField = function ($field, $paramKey, $value) use (&$fields, &$params) {
            $assignment = "{$field} = {$paramKey}";
            $fields = array_values(array_filter($fields, function ($entry) use ($field) {
                return strpos($entry, "{$field} = ") !== 0;
            }));
            $fields[] = $assignment;
            $params[$paramKey] = $value;
        };
        
        // Define allowed fields to update
        $allowed_fields = [
            'order_id', 'delivery_type', 'address', 'contact_person', 
            'contact_phone', 'scheduled_date', 'scheduled_time', 
            'delivery_person', 'status', 'notes'
        ];
        
        // If marking as delivered, add delivered_date (timestamp)
        if (isset($data['status']) && $data['status'] === 'delivered' && $currentDelivery['status'] !== 'delivered') {
            $allowed_fields[] = 'delivered_date';
            $data['delivered_date'] = date('Y-m-d H:i:s');
        }
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'delivery_type') {
                    $data[$field] = normalizeDeliveryType($data[$field]);
                    if (!isValidDeliveryType($data[$field])) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Invalid delivery_type. Allowed: inhand, courier, parcelservice'
                        ]);
                        return;
                    }
                }
                $setField($field, ":{$field}", $data[$field]);
            }
        }

        if (isset($data['delivery_type_map']) && is_array($data['delivery_type_map'])) {
            $normalizedMap = [];
            foreach ($data['delivery_type_map'] as $productId => $deliveryType) {
                $normalizedProductId = (int)$productId;
                if ($normalizedProductId <= 0) {
                    continue;
                }
                $normalizedType = normalizeDeliveryType($deliveryType);
                if (!isValidDeliveryType($normalizedType)) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid delivery_type_map. Allowed: inhand, courier, parcelservice'
                    ]);
                    return;
                }
                $normalizedMap[(string)$normalizedProductId] = $normalizedType;
            }

            if (!empty($normalizedMap)) {
                $productIds = array_values(array_map('intval', array_keys($normalizedMap)));
                $serialNumbers = $resolveSerialNumbersForProductIds($currentOrderId, $productIds);
                $setField('delivery_type_map', ':delivery_type_map', json_encode($normalizedMap));
                $setField('product_id', ':product_id', (int)$productIds[0]);
                $setField('product_ids', ':product_ids', json_encode($productIds));
                $setField('delivery_type', ':delivery_type', reset($normalizedMap));
                $setField('serial_numbers', ':serial_numbers', !empty($serialNumbers) ? json_encode($serialNumbers) : null);
                $setField('serial_number', ':serial_number', !empty($serialNumbers) ? $serialNumbers[0] : null);

                if ($currentOrderId > 0 && serviceOrdersHasColumn($conn, 'handover_type_map')) {
                    $existingOrderMap = [];
                    if (serviceOrdersHasColumn($conn, 'handover_type_map')) {
                        $orderMapStmt = $conn->prepare("SELECT handover_type_map FROM service_orders WHERE id = :order_id LIMIT 1");
                        $orderMapStmt->bindValue(':order_id', $currentOrderId, PDO::PARAM_INT);
                        $orderMapStmt->execute();
                        $orderMapRow = $orderMapStmt->fetch(PDO::FETCH_ASSOC);
                        $existingOrderMap = parseJsonObjectSafe($orderMapRow['handover_type_map'] ?? null);
                    }
                    foreach ($normalizedMap as $productId => $deliveryType) {
                        $existingOrderMap[(string)$productId] = $deliveryType;
                    }
                    $updateOrderFields = [];
                    $updateOrderParams = [':order_id' => $currentOrderId, ':handover_type_map' => json_encode($existingOrderMap)];
                    $updateOrderFields[] = "handover_type_map = :handover_type_map";
                    if (serviceOrdersHasColumn($conn, 'handover_type')) {
                        $updateOrderFields[] = "handover_type = :handover_type";
                        $updateOrderParams[':handover_type'] = reset($normalizedMap);
                    }
                    $updateOrderStmt = $conn->prepare("UPDATE service_orders SET " . implode(', ', $updateOrderFields) . " WHERE id = :order_id");
                    foreach ($updateOrderParams as $key => $value) {
                        $updateOrderStmt->bindValue($key, $value);
                    }
                    $updateOrderStmt->execute();
                }
            }
        } elseif (isset($params[':delivery_type']) && $currentProductId > 0) {
            $existingProductIds = parseJsonArraySafe($currentDelivery['product_ids'] ?? null);
            if (empty($existingProductIds)) {
                $existingProductIds = [$currentProductId];
            }
            $normalizedMap = [];
            foreach ($existingProductIds as $productId) {
                $normalizedProductId = (int)$productId;
                if ($normalizedProductId > 0) {
                    $normalizedMap[(string)$normalizedProductId] = $params[':delivery_type'];
                }
            }
            $serialNumbers = $resolveSerialNumbersForProductIds($currentOrderId, $existingProductIds);
            $setField('delivery_type_map', ':delivery_type_map', json_encode($normalizedMap));
            $setField('product_id', ':product_id', (int)$existingProductIds[0]);
            $setField('product_ids', ':product_ids', json_encode(array_values(array_map('intval', $existingProductIds))));
            $setField('serial_numbers', ':serial_numbers', !empty($serialNumbers) ? json_encode($serialNumbers) : null);
            $setField('serial_number', ':serial_number', !empty($serialNumbers) ? $serialNumbers[0] : null);
        }
        
        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            return;
        }
        
        $query = "UPDATE deliveries SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        // Bind parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            // Fetch updated delivery with client info
            $fetchQuery = "SELECT d.*, 
                                  o.order_code, 
                                  c.full_name as client_name, 
                                  c.phone as client_phone
                           FROM deliveries d 
                           LEFT JOIN service_orders o ON d.order_id = o.id 
                           LEFT JOIN clients c ON o.client_id = c.id
                           WHERE d.id = :id";
            $fetchStmt = $conn->prepare($fetchQuery);
            $fetchStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $fetchStmt->execute();
            $delivery = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            if ($delivery) {
                $delivery['delivery_type'] = normalizeDeliveryType($delivery['delivery_type'] ?? '');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Delivery updated successfully',
                'delivery' => $delivery
            ]);
        } else {
            throw new Exception('Failed to execute update');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Delete a delivery
 */
function deleteDelivery($conn) {
    try {
        ensureDeliveriesSchemaColumns($conn);
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
            return;
        }
        
        // Check if delivery exists
        $checkQuery = "SELECT id FROM deliveries WHERE id = :id AND COALESCE(is_deleted, 0) = 0";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery not found']);
            return;
        }
        
        // Soft delete delivery so delivery sync does not recreate it automatically.
        $query = "UPDATE deliveries SET is_deleted = 1, updated_at = NOW() WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Delivery deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete delivery');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
?>
