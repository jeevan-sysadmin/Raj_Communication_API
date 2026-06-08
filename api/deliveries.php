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

function syncDeliveredProductStatusToDeliveries($conn) {
    $hasProductStatusMapColumn = serviceOrdersHasProductStatusMapColumn($conn);
    $productStatusMapSelect = $hasProductStatusMapColumn ? "so.product_status_map" : "NULL AS product_status_map";

    $ordersQuery = "SELECT so.id, so.order_code, so.client_id, " . $productStatusMapSelect . ", so.created_at,
                           c.full_name AS client_name, c.phone AS client_phone, c.address AS client_address,
                           p.product_name
                    FROM service_orders so
                    LEFT JOIN clients c ON c.id = so.client_id
                    LEFT JOIN products p ON p.id = so.product_id";
    $ordersStmt = $conn->prepare($ordersQuery);
    $ordersStmt->execute();
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    $checkDeliveryStmt = $conn->prepare("SELECT id, status, delivered_date FROM deliveries WHERE order_id = :order_id ORDER BY id DESC LIMIT 1");
    $insertDeliveryStmt = $conn->prepare(
        "INSERT INTO deliveries (
            order_id, delivery_code, delivery_type, address, contact_person, contact_phone,
            scheduled_date, scheduled_time, delivered_date, delivery_person, status, notes, created_at, updated_at
        ) VALUES (
            :order_id, :delivery_code, :delivery_type, :address, :contact_person, :contact_phone,
            :scheduled_date, :scheduled_time, :delivered_date, :delivery_person, :status, :notes, NOW(), NOW()
        )"
    );
    $updateDeliveryStmt = $conn->prepare(
        "UPDATE deliveries
         SET status = 'delivered',
             delivered_date = COALESCE(delivered_date, NOW()),
             updated_at = NOW()
         WHERE id = :id"
    );

    foreach ($orders as $order) {
        if (!hasDeliveredProductStatus($order['product_status_map'] ?? null)) {
            continue;
        }

        $orderId = (int)$order['id'];
        if ($orderId <= 0) {
            continue;
        }

        $checkDeliveryStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $checkDeliveryStmt->execute();
        $existingDelivery = $checkDeliveryStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingDelivery) {
            $normalizedExisting = normalizeFlowStatus($existingDelivery['status'] ?? '');
            if ($normalizedExisting !== 'deliveryed') {
                $updateDeliveryStmt->bindValue(':id', (int)$existingDelivery['id'], PDO::PARAM_INT);
                $updateDeliveryStmt->execute();
            }
            continue;
        }

        $deliveryCode = 'DEL' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
        $scheduledDate = null;
        if (!empty($order['created_at'])) {
            $scheduledDate = date('Y-m-d', strtotime($order['created_at']));
        }

        $insertDeliveryStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $insertDeliveryStmt->bindValue(':delivery_code', $deliveryCode);
        $insertDeliveryStmt->bindValue(':delivery_type', 'inhand');
        $insertDeliveryStmt->bindValue(':address', $order['client_address'] ?: 'Address not specified');
        $insertDeliveryStmt->bindValue(':contact_person', $order['client_name'] ?: 'Customer');
        $insertDeliveryStmt->bindValue(':contact_phone', $order['client_phone'] ?: 'N/A');
        $insertDeliveryStmt->bindValue(':scheduled_date', $scheduledDate, $scheduledDate ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertDeliveryStmt->bindValue(':scheduled_time', '09:00:00');
        $insertDeliveryStmt->bindValue(':delivered_date', date('Y-m-d H:i:s'));
        $insertDeliveryStmt->bindValue(':delivery_person', 'System Auto-assigned');
        $insertDeliveryStmt->bindValue(':status', 'delivered');
        $insertDeliveryStmt->bindValue(
            ':notes',
            'Auto-created from product_status_map deliveryed for order ' . ($order['order_code'] ?: ('#' . $orderId))
                . ' - Product: ' . ($order['product_name'] ?: 'Unknown')
        );
        $insertDeliveryStmt->execute();
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
                             so.product_ids,
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
                      WHERE d.id = :id";

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

        $whereClause = " WHERE 1=1";
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
                         so.product_ids,
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
                  ORDER BY d.scheduled_date DESC, d.scheduled_time DESC";

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
        
        $query = "INSERT INTO deliveries (
            order_id, delivery_code, delivery_type, address,
            contact_person, contact_phone, scheduled_date,
            scheduled_time, delivery_person, status, notes
        ) VALUES (
            :order_id, :delivery_code, :delivery_type, :address,
            :contact_person, :contact_phone, :scheduled_date,
            :scheduled_time, :delivery_person, :status, :notes
        )";
        
        $stmt = $conn->prepare($query);
        
        // Set default status if not provided (use 'scheduled' to match your database enum)
        $status = isset($data['status']) ? $data['status'] : 'scheduled';
        
        $stmt->bindParam(':order_id', $data['order_id'], PDO::PARAM_INT);
        $stmt->bindParam(':delivery_code', $delivery_code);
        $deliveryType = normalizeDeliveryType($data['delivery_type']);
        if (!isValidDeliveryType($deliveryType)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid delivery_type. Allowed: inhand, courier, parcelservice'
            ]);
            return;
        }
        $stmt->bindParam(':delivery_type', $deliveryType);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':contact_person', $data['contact_person']);
        $stmt->bindParam(':contact_phone', $data['contact_phone']);
        $stmt->bindParam(':scheduled_date', $data['scheduled_date']);
        $stmt->bindParam(':scheduled_time', $data['scheduled_time']);
        $stmt->bindParam(':delivery_person', $data['delivery_person'] ?? null);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':notes', $data['notes'] ?? null);
        
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
        $checkQuery = "SELECT id, status FROM deliveries WHERE id = :id";
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
        
        // Build update query dynamically
        $fields = [];
        $params = [':id' => $id];
        
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
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
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
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
            return;
        }
        
        // Check if delivery exists
        $checkQuery = "SELECT id FROM deliveries WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery not found']);
            return;
        }
        
        // Delete delivery
        $query = "DELETE FROM deliveries WHERE id = :id";
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
