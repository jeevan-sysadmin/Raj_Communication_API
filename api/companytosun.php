<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

function formatNullableDate($value, $format = 'd/m/Y') {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '';
    }

    return date($format, strtotime($value));
}

function tableExists($conn, $tableName) {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1");
    $stmt->bindValue(':table', $tableName, PDO::PARAM_STR);
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function columnExists($conn, $tableName, $columnName) {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1");
    $stmt->bindValue(':table', $tableName, PDO::PARAM_STR);
    $stmt->bindValue(':column', $columnName, PDO::PARAM_STR);
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function firstExistingColumn($conn, $tableName, $candidates) {
    foreach ($candidates as $columnName) {
        if (columnExists($conn, $tableName, $columnName)) {
            return $columnName;
        }
    }
    return null;
}

function normalizeIdList($value) {
    if ($value === null) {
        return [];
    }

    if (is_array($value)) {
        $raw = $value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = explode(',', $trimmed);
        }
    } else {
        $raw = [$value];
    }

    $ids = [];
    foreach ($raw as $entry) {
        $id = (int)$entry;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function normalizeStatusValue($status) {
    $value = strtolower(trim((string)$status));
    $value = str_replace(['_', '-', ' '], '', $value);

    if ($value === 'comtoraj' || $value === 'companytoraj') {
        return 'comtoraj';
    }

    return $value;
}

function normalizeProductStatusMap($value) {
    if (is_array($value)) {
        $source = $value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }
        $source = $decoded;
    } else {
        return [];
    }

    $map = [];
    foreach ($source as $productId => $status) {
        $id = (int)$productId;
        if ($id <= 0) {
            continue;
        }
        $map[$id] = normalizeStatusValue($status);
    }

    return $map;
}

function buildLatestClientByProductMap($conn, $productIds) {
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (empty($productIds)) {
        return [];
    }

    $targetSet = array_fill_keys($productIds, true);
    $latestClientByProductId = [];

    $hasSop = tableExists($conn, 'service_order_products')
        && columnExists($conn, 'service_order_products', 'order_id')
        && columnExists($conn, 'service_order_products', 'product_id');

    $sopStatusColumn = null;
    if ($hasSop) {
        $sopStatusColumn = firstExistingColumn($conn, 'service_order_products', ['product_status', 'status', 'flow_status']);
    }

    $query = "SELECT so.id AS order_id,
                     so.order_code,
                     so.product_id,
                     so.product_ids,
                     so.product_status_map,
                     so.created_at,
                     c.full_name AS client_name,
                     c.phone AS client_phone";

    if ($hasSop) {
        $query .= ", sop.product_id AS sop_product_id";
        if ($sopStatusColumn !== null) {
            $query .= ", sop.`{$sopStatusColumn}` AS sop_status";
        }
    }

    $query .= "
              FROM service_orders so
              LEFT JOIN clients c ON so.client_id = c.id";

    if ($hasSop) {
        $query .= "
              LEFT JOIN service_order_products sop ON sop.order_id = so.id";
    }

    $query .= "
              ORDER BY so.created_at DESC, so.id DESC";

    $stmt = $conn->prepare($query);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $matchedProductIds = [];

        $statusMap = normalizeProductStatusMap($row['product_status_map'] ?? []);
        foreach ($statusMap as $productId => $status) {
            if ($status === 'comtoraj' && isset($targetSet[$productId])) {
                $matchedProductIds[] = (int)$productId;
            }
        }

        if ($hasSop && isset($row['sop_product_id'])) {
            $sopProductId = (int)$row['sop_product_id'];
            $sopStatus = normalizeStatusValue($row['sop_status'] ?? '');
            if ($sopProductId > 0 && isset($targetSet[$sopProductId]) && ($sopStatusColumn === null || $sopStatus === 'comtoraj')) {
                $matchedProductIds[] = $sopProductId;
            }
        }

        if (empty($matchedProductIds)) {
            $fallbackIds = normalizeIdList($row['product_ids'] ?? []);
            if (empty($fallbackIds) && !empty($row['product_id'])) {
                $fallbackIds[] = (int)$row['product_id'];
            }

            foreach ($fallbackIds as $productId) {
                if (isset($targetSet[$productId])) {
                    $matchedProductIds[] = (int)$productId;
                }
            }
        }

        if (empty($matchedProductIds)) {
            continue;
        }

        $matchedProductIds = array_values(array_unique(array_filter(array_map('intval', $matchedProductIds))));
        foreach ($matchedProductIds as $productId) {
            if (isset($latestClientByProductId[$productId])) {
                continue;
            }

            $latestClientByProductId[$productId] = [
                'client_name' => (string)($row['client_name'] ?? ''),
                'client_phone' => (string)($row['client_phone'] ?? ''),
                'order_id' => (int)($row['order_id'] ?? 0),
                'order_code' => (string)($row['order_code'] ?? ''),
            ];
        }

        if (count($latestClientByProductId) >= count($productIds)) {
            break;
        }
    }

    return $latestClientByProductId;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        exit();
    }

    // Only include products that are ComToRaj in service order flow.
    $statusNormalizeExpr = "REPLACE(REPLACE(LOWER(TRIM(%s)), '_', ''), ' ', '')";
    $filterParts = [];

    if (tableExists($conn, 'service_order_products') && columnExists($conn, 'service_order_products', 'product_id')) {
        $statusColumn = firstExistingColumn($conn, 'service_order_products', ['product_status', 'status', 'flow_status']);
        if ($statusColumn !== null) {
            $filterParts[] = "EXISTS (\n"
                . "  SELECT 1 FROM service_order_products sop\n"
                . "  WHERE sop.product_id = p.id\n"
                . "    AND " . sprintf($statusNormalizeExpr, "sop.`" . $statusColumn . "`") . " = 'comtoraj'\n"
                . ")";
        }
    }

    if (tableExists($conn, 'service_orders') && columnExists($conn, 'service_orders', 'product_status_map')) {
        $filterParts[] = "EXISTS (\n"
            . "  SELECT 1 FROM service_orders so\n"
            . "  WHERE so.product_status_map IS NOT NULL\n"
            . "    AND " . sprintf($statusNormalizeExpr, "JSON_UNQUOTE(JSON_EXTRACT(so.product_status_map, CONCAT('$.\\\"', p.id, '\\\"')))") . " = 'comtoraj'\n"
            . ")";
    }

    if (count($filterParts) > 0) {
        $comToRajFilter = "(" . implode(" OR ", $filterParts) . ")";
    } else {
        $comToRajFilter = "(1 = 0)";
    }

    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $query = "SELECT p.* FROM products p WHERE p.id = :id AND {$comToRajFilter} LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', (int) $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();

        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "ComToRaj product not found"]);
            exit();
        }

        $clientMap = buildLatestClientByProductMap($conn, [(int)$product['id']]);
        $clientInfo = $clientMap[(int)$product['id']] ?? [
            'client_name' => '',
            'client_phone' => '',
            'order_id' => 0,
            'order_code' => '',
        ];

        $product['purchase_date_formatted'] = formatNullableDate($product['purchase_date']);
        $product['created_at_formatted'] = formatNullableDate($product['created_at'], 'd/m/Y h:i A');
        $product['updated_at_formatted'] = formatNullableDate($product['updated_at'] ?? '', 'd/m/Y h:i A');
        $product['brand'] = $product['brand'] ?? '';
        $product['model'] = $product['model'] ?? '';
        $product['specifications'] = $product['specifications'] ?? '';
        $product['warranty_period'] = $product['warranty_period'] ?? '';
        $product['is_spare_product'] = $product['is_spare_product'] ?? 0;
        $product['client_name'] = $clientInfo['client_name'];
        $product['client_phone'] = $clientInfo['client_phone'];
        $product['order_id'] = $clientInfo['order_id'];
        $product['order_code'] = $clientInfo['order_code'];

        echo json_encode([
            "success" => true,
            "label" => "Company To Raj Products",
            "product" => $product
        ]);
        exit();
    }

    $query = "SELECT p.* FROM products p WHERE {$comToRajFilter}";
    $params = [];

    if (!empty($_GET['search'])) {
        $query .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR p.serial_number LIKE :search OR p.brand LIKE :search OR p.model LIKE :search)";
        $params[':search'] = '%' . trim($_GET['search']) . '%';
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
        $query .= " AND p.status = :status";
        $params[':status'] = trim($_GET['status']);
    }

    if (!empty($_GET['start_date'])) {
        $query .= " AND DATE(p.created_at) >= :start_date";
        $params[':start_date'] = $_GET['start_date'];
    }

    if (!empty($_GET['end_date'])) {
        $query .= " AND DATE(p.created_at) <= :end_date";
        $params[':end_date'] = $_GET['end_date'];
    }

    $query .= " ORDER BY p.created_at DESC";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $products = $stmt->fetchAll();
    $productIds = array_values(array_unique(array_filter(array_map(function ($product) {
        return (int)($product['id'] ?? 0);
    }, $products))));
    $clientMap = buildLatestClientByProductMap($conn, $productIds);

    foreach ($products as &$product) {
        $productId = (int)($product['id'] ?? 0);
        $clientInfo = $clientMap[$productId] ?? [
            'client_name' => '',
            'client_phone' => '',
            'order_id' => 0,
            'order_code' => '',
        ];

        $product['purchase_date_formatted'] = formatNullableDate($product['purchase_date']);
        $product['created_at_formatted'] = formatNullableDate($product['created_at'], 'd/m/Y h:i A');
        $product['updated_at_formatted'] = formatNullableDate($product['updated_at'] ?? '', 'd/m/Y h:i A');
        $product['brand'] = $product['brand'] ?? '';
        $product['model'] = $product['model'] ?? '';
        $product['specifications'] = $product['specifications'] ?? '';
        $product['warranty_period'] = $product['warranty_period'] ?? '';
        $product['is_spare_product'] = $product['is_spare_product'] ?? 0;
        $product['client_name'] = $clientInfo['client_name'];
        $product['client_phone'] = $clientInfo['client_phone'];
        $product['order_id'] = $clientInfo['order_id'];
        $product['order_code'] = $clientInfo['order_code'];
    }
    unset($product);

    echo json_encode([
        "success" => true,
        "label" => "Company To Raj Products",
        "count" => count($products),
        "products" => $products
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}
?>
