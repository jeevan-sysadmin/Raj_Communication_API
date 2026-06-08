<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('html_errors', '0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

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

    // Strictly normalize RajToCom labels only.
    if ($value === 'rajtocom' || $value === 'rajtocompany') {
        return 'rajtocom';
    }

    return $value;
}

function isRajToComClaimType($claimType) {
    $value = strtolower(trim((string)$claimType));
    $value = str_replace(['-', ' '], '_', $value);

    return in_array($value, ['sun_to_company', 'raj_to_com', 'rajtocom', 'rajtocompany'], true);
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
        $map[(string)$id] = normalizeStatusValue($status);
    }

    return $map;
}

function containsRajToComStatus($statusMap) {
    foreach ($statusMap as $status) {
        if ($status === 'rajtocom') {
            return true;
        }
    }
    return false;
}

function rajToComProductIds($statusMap) {
    $ids = [];
    foreach ($statusMap as $productId => $status) {
        if ($status === 'rajtocom') {
            $ids[] = (int)$productId;
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

function parseCompanyIds($row) {
    $ids = [];
    if (array_key_exists('company_ids', $row)) {
        $ids = normalizeIdList($row['company_ids']);
    }
    if (empty($ids) && array_key_exists('company_id', $row)) {
        $single = (int)$row['company_id'];
        if ($single > 0) {
            $ids[] = $single;
        }
    }
    return array_values(array_unique($ids));
}

function loadCompanyNames($db, $allCompanyIds) {
    if (empty($allCompanyIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($allCompanyIds), '?'));
    $stmt = $db->prepare("SELECT id, company_name FROM companies WHERE id IN ($placeholders)");
    $stmt->execute($allCompanyIds);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = (string)$row['company_name'];
    }

    return $map;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    $priority = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
    $startDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
    $endDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';

    $query = "SELECT so.*,
                     c.full_name AS client_name,
                     c.phone AS client_phone,
                     p.product_name,
                     p.brand AS product_brand,
                     p.model AS product_model,
                     p.serial_number,
                     p.claim_type AS primary_claim_type,
                     rp.product_name AS replacement_product_name,
                     u.name AS staff_name
              FROM service_orders so
              LEFT JOIN clients c ON so.client_id = c.id
              LEFT JOIN products p ON so.product_id = p.id
              LEFT JOIN products rp ON so.replacement_product_id = rp.id
              LEFT JOIN users u ON so.staff_id = u.id
              WHERE 1=1";

    $params = [];

    if ($search !== '') {
        $query .= " AND (
            so.order_code LIKE :search OR
            c.full_name LIKE :search OR
            c.phone LIKE :search OR
            p.product_name LIKE :search OR
            rp.product_name LIKE :search
        )";
        $params[':search'] = "%{$search}%";
    }

    if ($status !== '' && strtolower($status) !== 'all') {
        $query .= " AND so.status = :status";
        $params[':status'] = $status;
    }

    if ($priority !== '' && strtolower($priority) !== 'all') {
        $query .= " AND so.priority = :priority";
        $params[':priority'] = $priority;
    }

    if ($startDate !== '' && $endDate !== '') {
        $query .= " AND DATE(so.created_at) BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    }

    $query .= " ORDER BY so.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allOrderProductIds = [];
    foreach ($rows as $row) {
        $primaryIds = normalizeIdList($row['product_ids'] ?? []);
        if (empty($primaryIds) && isset($row['product_id']) && (int)$row['product_id'] > 0) {
            $primaryIds = [(int)$row['product_id']];
        }
        $replacementIds = normalizeIdList($row['replacement_product_ids'] ?? []);
        if (empty($replacementIds) && isset($row['replacement_product_id']) && (int)$row['replacement_product_id'] > 0) {
            $replacementIds = [(int)$row['replacement_product_id']];
        }
        $allOrderProductIds = array_merge($allOrderProductIds, $primaryIds, $replacementIds);
    }
    $allOrderProductIds = array_values(array_unique(array_filter(array_map('intval', $allOrderProductIds))));

    $productClaimTypeMap = [];
    if (!empty($allOrderProductIds)) {
        $placeholders = implode(',', array_fill(0, count($allOrderProductIds), '?'));
        $claimTypeStmt = $db->prepare("SELECT id, claim_type FROM products WHERE id IN ($placeholders)");
        $claimTypeStmt->execute($allOrderProductIds);
        while ($claimRow = $claimTypeStmt->fetch(PDO::FETCH_ASSOC)) {
            $productClaimTypeMap[(int)$claimRow['id']] = (string)($claimRow['claim_type'] ?? '');
        }
    }

    $matchedOrders = [];
    $allCompanyIds = [];
    $allProductIds = [];

    foreach ($rows as $row) {
        $statusMap = normalizeProductStatusMap($row['product_status_map'] ?? []);
        $primaryIds = normalizeIdList($row['product_ids'] ?? []);
        if (empty($primaryIds) && isset($row['product_id']) && (int)$row['product_id'] > 0) {
            $primaryIds = [(int)$row['product_id']];
        }
        $replacementIds = normalizeIdList($row['replacement_product_ids'] ?? []);
        if (empty($replacementIds) && isset($row['replacement_product_id']) && (int)$row['replacement_product_id'] > 0) {
            $replacementIds = [(int)$row['replacement_product_id']];
        }

        $isRajToComByStatusMap = containsRajToComStatus($statusMap);
        $isRajToComByClaimType = isRajToComClaimType($row['primary_claim_type'] ?? '');

        $allIdsForOrder = array_values(array_unique(array_merge($primaryIds, $replacementIds)));
        $rajtocomByIds = [];
        foreach ($allIdsForOrder as $productId) {
            $claimType = $productClaimTypeMap[(int)$productId] ?? '';
            if (isRajToComClaimType($claimType)) {
                $rajtocomByIds[] = (int)$productId;
            }
        }

        if (!$isRajToComByStatusMap && !$isRajToComByClaimType && empty($rajtocomByIds)) {
            continue;
        }

        $companyIds = parseCompanyIds($row);
        $allCompanyIds = array_merge($allCompanyIds, $companyIds);

        $rajtocomIds = rajToComProductIds($statusMap);
        if (!empty($rajtocomIds)) {
            $allProductIds = array_merge($allProductIds, $rajtocomIds);
        } elseif (!empty($rajtocomByIds)) {
            $allProductIds = array_merge($allProductIds, $rajtocomByIds);
        } else {
            $allProductIds = array_merge($allProductIds, $primaryIds);
        }

        $row['order_id'] = (int)$row['id'];
        $row['product_ids'] = $primaryIds;
        $row['replacement_product_ids'] = $replacementIds;
        $row['company_ids'] = $companyIds;
        $row['product_status_map'] = $statusMap;
        $row['rajtocom_product_ids'] = !empty($rajtocomIds) ? $rajtocomIds : $rajtocomByIds;

        $matchedOrders[] = $row;
    }

    $allCompanyIds = array_values(array_unique(array_filter(array_map('intval', $allCompanyIds))));
    $allProductIds = array_values(array_unique(array_filter(array_map('intval', $allProductIds))));
    $companyNameMap = loadCompanyNames($db, $allCompanyIds);

    foreach ($matchedOrders as &$order) {
        $companyNames = [];
        foreach ($order['company_ids'] as $companyId) {
            if (isset($companyNameMap[(int)$companyId])) {
                $companyNames[] = $companyNameMap[(int)$companyId];
            }
        }

        $order['company_names'] = $companyNames;
        $order['company_name'] = !empty($companyNames) ? implode(' || ', $companyNames) : '';
        $order['client_name'] = (string)($order['client_name'] ?? '');
        $order['product_name'] = (string)($order['product_name'] ?? '');
        $order['replacement_product_name'] = (string)($order['replacement_product_name'] ?? '');
        $order['warranty_status'] = (string)($order['warranty_status'] ?? '');
        $order['payment_status'] = (string)($order['payment_status'] ?? 'pending');
        $order['priority'] = (string)($order['priority'] ?? 'low');
    }
    unset($order);

    $latestClientByProductId = [];
    foreach ($matchedOrders as $order) {
        $linkedProductIds = [];
        if (!empty($order['rajtocom_product_ids']) && is_array($order['rajtocom_product_ids'])) {
            $linkedProductIds = array_map('intval', $order['rajtocom_product_ids']);
        } elseif (!empty($order['product_ids']) && is_array($order['product_ids'])) {
            $linkedProductIds = array_map('intval', $order['product_ids']);
        }

        foreach ($linkedProductIds as $productId) {
            if ($productId <= 0) {
                continue;
            }
            if (isset($latestClientByProductId[$productId])) {
                continue;
            }

            $latestClientByProductId[$productId] = [
                'client_name' => (string)($order['client_name'] ?? ''),
                'client_phone' => (string)($order['client_phone'] ?? ''),
                'order_id' => (int)($order['order_id'] ?? 0),
                'order_code' => (string)($order['order_code'] ?? ''),
            ];
        }
    }

    $products = [];
    if (!empty($allProductIds)) {
        $placeholders = implode(',', array_fill(0, count($allProductIds), '?'));
        $productQuery = "SELECT id, product_code, product_name, serial_number, brand, model, category, claim_type, warranty_period, price, status, created_at
                         FROM products
                         WHERE id IN ($placeholders)
                         ORDER BY created_at DESC";
        $productStmt = $db->prepare($productQuery);
        $productStmt->execute($allProductIds);
        $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$product) {
            $productId = (int)($product['id'] ?? 0);
            $clientInfo = $latestClientByProductId[$productId] ?? [
                'client_name' => '',
                'client_phone' => '',
                'order_id' => 0,
                'order_code' => '',
            ];

            $product['client_name'] = $clientInfo['client_name'];
            $product['client_phone'] = $clientInfo['client_phone'];
            $product['order_id'] = $clientInfo['order_id'];
            $product['order_code'] = $clientInfo['order_code'];
        }
        unset($product);
    }

    echo json_encode([
        "success" => true,
        "orders" => $matchedOrders,
        "products" => $products,
        "rajToComClaims" => $products,
        "count" => count($matchedOrders)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to load RajToCom data"
    ]);
}
