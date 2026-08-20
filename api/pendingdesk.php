<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

function pendingdesk_get_service_order_columns(PDO $db): array {
    $columns = [];
    $stmt = $db->query("SHOW COLUMNS FROM service_orders");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string)($row['Field'] ?? ''));
        if ($name !== '') {
            $columns[$name] = true;
        }
    }
    return $columns;
}

function pendingdesk_has_column(array $columns, string $name): bool {
    return isset($columns[$name]);
}

function pendingdesk_parse_string_list($value): array {
    if ($value === null) return [];

    if (is_array($value)) {
        $raw = $value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') return [];

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = preg_split('/\s*\|\|\s*|\s*,\s*/', $trimmed) ?: [];
        }
    } else {
        $raw = [$value];
    }

    $result = [];
    foreach ($raw as $entry) {
        $text = trim((string)$entry);
        if ($text !== '') $result[] = $text;
    }
    return $result;
}

function pendingdesk_parse_number_list($value): array {
    $numbers = [];
    foreach (pendingdesk_parse_string_list($value) as $entry) {
        $number = (int)$entry;
        if ($number > 0) $numbers[] = $number;
    }
    return array_values(array_unique($numbers));
}

function pendingdesk_parse_record($value): array {
    if (is_array($value)) return $value;
    if (!is_string($value)) return [];

    $trimmed = trim($value);
    if ($trimmed === '') return [];

    $decoded = json_decode($trimmed, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
}

function pendingdesk_normalize_flow_status($value): string {
    $raw = strtolower(trim((string)$value));
    $raw = str_replace(['_', '-', ' '], '', $raw);

    if ($raw === 'rajtocom' || $raw === 'rajtocompany') return 'rajtocom';
    if ($raw === 'comtoraj') return 'comtoraj';
    if ($raw === 'deliveryed' || $raw === 'delivered') return 'deliveryed';
    if ($raw === 'pending') return 'pending';
    return '';
}

function pendingdesk_normalize_flow_status_map($value): array {
    $parsed = pendingdesk_parse_record($value);
    $normalized = [];

    foreach ($parsed as $productId => $status) {
        $id = (int)$productId;
        if ($id <= 0) continue;
        $normalized[(string)$id] = pendingdesk_normalize_flow_status($status);
    }

    return $normalized;
}

function pendingdesk_normalize_repairing_status_map($value): array {
    $parsed = pendingdesk_parse_record($value);
    $normalized = [];

    foreach ($parsed as $productId => $status) {
        $id = (int)$productId;
        if ($id <= 0) continue;

        $raw = strtolower(trim((string)$status));
        if ($raw === 'ready') $normalized[(string)$id] = 'ready';
        elseif ($raw === 'replacement') $normalized[(string)$id] = 'replacement';
        elseif ($raw === 'not_ready' || $raw === 'not ready' || $raw === 'notready') $normalized[(string)$id] = 'not ready';
    }

    return $normalized;
}

function pendingdesk_normalize_company_product_map($value): array {
    $parsed = pendingdesk_parse_record($value);
    $normalized = [];

    foreach ($parsed as $companyId => $productIds) {
        $key = trim((string)$companyId);
        if ($key === '') continue;
        $normalized[$key] = pendingdesk_parse_number_list($productIds);
    }

    return $normalized;
}

function pendingdesk_days_since($createdAt): int {
    $timestamp = strtotime((string)$createdAt);
    if (!$timestamp) return 0;
    $diff = time() - $timestamp;
    return max(0, (int)floor($diff / 86400));
}

function pendingdesk_load_company_names(PDO $db, array $companyIds): array {
    if (empty($companyIds)) return [];

    $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $db->prepare("SELECT id, company_name FROM companies WHERE id IN ($placeholders)");
    $stmt->execute($companyIds);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[(int)$row['id']] = trim((string)$row['company_name']);
    }
    return $map;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $serviceOrderColumns = pendingdesk_get_service_order_columns($db);

    $selectedCompany = trim((string)($_GET['company_name'] ?? ''));
    $startDate = trim((string)($_GET['start_date'] ?? ''));
    $endDate = trim((string)($_GET['end_date'] ?? ''));

    $selectParts = [
        "so.id",
        "so.order_code",
        "so.created_at",
        "so.status",
        pendingdesk_has_column($serviceOrderColumns, 'company_id') ? "so.company_id" : "NULL AS company_id",
        pendingdesk_has_column($serviceOrderColumns, 'company_ids') ? "so.company_ids" : "NULL AS company_ids",
        pendingdesk_has_column($serviceOrderColumns, 'company_name') ? "so.company_name" : "NULL AS company_name",
        pendingdesk_has_column($serviceOrderColumns, 'company_names') ? "so.company_names" : "NULL AS company_names",
        pendingdesk_has_column($serviceOrderColumns, 'company_names_text') ? "so.company_names_text" : "NULL AS company_names_text",
        pendingdesk_has_column($serviceOrderColumns, 'company_product_map') ? "so.company_product_map" : "NULL AS company_product_map",
        pendingdesk_has_column($serviceOrderColumns, 'companies_products') ? "so.companies_products" : "NULL AS companies_products",
        pendingdesk_has_column($serviceOrderColumns, 'company_product_name_map') ? "so.company_product_name_map" : "NULL AS company_product_name_map",
        pendingdesk_has_column($serviceOrderColumns, 'product_id') ? "so.product_id" : "NULL AS product_id",
        pendingdesk_has_column($serviceOrderColumns, 'product_ids') ? "so.product_ids" : "NULL AS product_ids",
        pendingdesk_has_column($serviceOrderColumns, 'product_names') ? "so.product_names" : "NULL AS product_names",
        pendingdesk_has_column($serviceOrderColumns, 'product_models') ? "so.product_models" : "NULL AS product_models",
        pendingdesk_has_column($serviceOrderColumns, 'product_serial_numbers') ? "so.product_serial_numbers" : "NULL AS product_serial_numbers",
        pendingdesk_has_column($serviceOrderColumns, 'product_status_map') ? "so.product_status_map" : "NULL AS product_status_map",
        pendingdesk_has_column($serviceOrderColumns, 'repairing_status_map') ? "so.repairing_status_map" : "NULL AS repairing_status_map",
        pendingdesk_has_column($serviceOrderColumns, 'issue_description') ? "so.issue_description" : "NULL AS issue_description",
        pendingdesk_has_column($serviceOrderColumns, 'issue_description_map') ? "so.issue_description_map" : "NULL AS issue_description_map",
    ];

    $query = "SELECT " . implode(",\n                ", $selectParts) . "
              FROM service_orders so
              WHERE 1=1";

    $params = [];
    if ($startDate !== '' && $endDate !== '') {
        $query .= " AND DATE(so.created_at) BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    }
    $query .= " ORDER BY so.created_at DESC";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allCompanyIds = [];
    foreach ($orders as $order) {
        $allCompanyIds = array_merge(
            $allCompanyIds,
            pendingdesk_parse_number_list($order['company_ids'] ?? null),
            pendingdesk_parse_number_list($order['company_id'] ?? null)
        );
    }
    $allCompanyIds = array_values(array_unique(array_filter(array_map('intval', $allCompanyIds))));
    $companyIdNameMap = pendingdesk_load_company_names($db, $allCompanyIds);

    $rows = [];
    $companyOptions = [];
    $requiredProductIds = [];

    foreach ($orders as $order) {
        $companyIds = pendingdesk_parse_string_list($order['company_ids'] ?? null);
        if (empty($companyIds) && !empty($order['company_id'])) {
            $companyIds[] = trim((string)$order['company_id']);
        }

        $companyNames = pendingdesk_parse_string_list($order['company_names'] ?? null);
        if (empty($companyNames)) {
            $companyNames = pendingdesk_parse_string_list($order['company_names_text'] ?? ($order['company_name'] ?? null));
        }

        $companyProductNameMap = pendingdesk_parse_record($order['company_product_name_map'] ?? null);
        $companyProductMap = pendingdesk_normalize_company_product_map($order['company_product_map'] ?? ($order['companies_products'] ?? null));

        $companyEntries = [];
        if (!empty($companyIds)) {
            foreach ($companyIds as $index => $companyId) {
                $mapped = pendingdesk_parse_record($companyProductNameMap[$companyId] ?? null);
                $fallback = $companyNames[$index] ?? ($companyIdNameMap[(int)$companyId] ?? '');
                $name = trim((string)($mapped['company_name'] ?? $fallback));
                if ($name === '') $name = "Company #{$companyId}";
                $companyEntries[] = ['id' => $companyId, 'name' => $name];
            }
        } else {
            foreach ($companyNames as $name) {
                if (trim($name) !== '') $companyEntries[] = ['id' => '', 'name' => trim($name)];
            }
        }

        foreach ($companyEntries as $companyEntry) {
            $companyOptions[] = $companyEntry['name'];
        }

        if ($selectedCompany === '') continue;

        $matchedEntries = array_values(array_filter($companyEntries, function ($entry) use ($selectedCompany) {
            return strcasecmp($entry['name'], $selectedCompany) === 0;
        }));
        if (empty($matchedEntries)) continue;

        $allProductIds = pendingdesk_parse_number_list($order['product_ids'] ?? null);
        if (empty($allProductIds) && !empty($order['product_id'])) {
            $fallbackProductId = (int)$order['product_id'];
            if ($fallbackProductId > 0) $allProductIds[] = $fallbackProductId;
        }

        $productNames = pendingdesk_parse_string_list($order['product_names'] ?? null);
        $productModels = pendingdesk_parse_string_list($order['product_models'] ?? null);
        $productSerials = pendingdesk_parse_string_list($order['product_serial_numbers'] ?? null);
        $flowStatusMap = pendingdesk_normalize_flow_status_map($order['product_status_map'] ?? null);
        $repairingStatusMap = pendingdesk_normalize_repairing_status_map($order['repairing_status_map'] ?? null);
        $issueDescriptionMap = pendingdesk_parse_record($order['issue_description_map'] ?? null);

        $metaByProductId = [];
        foreach ($allProductIds as $index => $productId) {
            $metaByProductId[(int)$productId] = [
                'product_name' => $productNames[$index] ?? '',
                'model' => $productModels[$index] ?? '',
                'serial' => $productSerials[$index] ?? '',
            ];
        }

        foreach ($matchedEntries as $companyEntry) {
            $scopedProductIds = $allProductIds;
            if ($companyEntry['id'] !== '' && !empty($companyProductMap[$companyEntry['id']])) {
                $scopedProductIds = array_values(array_filter($allProductIds, function ($productId) use ($companyProductMap, $companyEntry) {
                    return in_array((int)$productId, $companyProductMap[$companyEntry['id']], true);
                }));
            } elseif (count($companyEntries) > 1) {
                continue;
            }

            foreach ($scopedProductIds as $productId) {
                $flowStatus = pendingdesk_normalize_flow_status($flowStatusMap[(string)$productId] ?? ($order['status'] ?? ''));
                $repairingStatus = strtolower(trim((string)($repairingStatusMap[(string)$productId] ?? '')));
                if ($flowStatus !== 'rajtocom') continue;
                if ($repairingStatus !== 'not ready') continue;

                $requiredProductIds[] = (int)$productId;
                $issueDescription = trim((string)($issueDescriptionMap[(string)$productId] ?? ''));

                $rows[] = [
                    'key' => $order['id'] . '-' . $productId . '-' . $companyEntry['name'],
                    'orderId' => (int)$order['id'],
                    'company' => $companyEntry['name'],
                    'serviceDate' => (string)$order['created_at'],
                    'productId' => (int)$productId,
                    'productName' => $metaByProductId[(int)$productId]['product_name'] ?? '',
                    'model' => $metaByProductId[(int)$productId]['model'] ?? '',
                    'serial' => $metaByProductId[(int)$productId]['serial'] ?? '',
                    'faultDescription' => $issueDescription !== '' ? $issueDescription : trim((string)($order['issue_description'] ?? '')),
                    'pendingDays' => pendingdesk_days_since($order['created_at'] ?? ''),
                    'flowStatus' => $flowStatus,
                ];
            }
        }
    }

    $requiredProductIds = array_values(array_unique(array_filter(array_map('intval', $requiredProductIds))));
    $productLookup = [];
    if (!empty($requiredProductIds)) {
        $placeholders = implode(',', array_fill(0, count($requiredProductIds), '?'));
        $stmt = $db->prepare("SELECT id, product_name, serial_number, model FROM products WHERE id IN ($placeholders)");
        $stmt->execute($requiredProductIds);
        while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $productLookup[(int)$product['id']] = [
                'product_name' => trim((string)($product['product_name'] ?? '')),
                'serial' => trim((string)($product['serial_number'] ?? '')),
                'model' => trim((string)($product['model'] ?? '')),
            ];
        }
    }

    foreach ($rows as &$row) {
        $productId = (int)$row['productId'];
        $lookup = $productLookup[$productId] ?? null;
        if ($lookup) {
          if (trim((string)$row['productName']) === '') $row['productName'] = $lookup['product_name'];
          if (trim((string)$row['serial']) === '') $row['serial'] = $lookup['serial'];
          if (trim((string)$row['model']) === '') $row['model'] = $lookup['model'];
        }

        if (trim((string)$row['productName']) === '') $row['productName'] = "Product #{$productId}";
        if (trim((string)$row['serial']) === '') $row['serial'] = 'N/A';
        if (trim((string)$row['model']) === '') $row['model'] = 'N/A';
        if (trim((string)$row['faultDescription']) === '') $row['faultDescription'] = 'N/A';
    }
    unset($row);

    usort($rows, function ($a, $b) {
        $dayCompare = (int)$b['pendingDays'] <=> (int)$a['pendingDays'];
        if ($dayCompare !== 0) return $dayCompare;
        return strcmp((string)$b['serviceDate'], (string)$a['serviceDate']);
    });

    $companyOptions = array_values(array_unique(array_filter(array_map('trim', $companyOptions))));
    sort($companyOptions, SORT_NATURAL | SORT_FLAG_CASE);

    echo json_encode([
        'success' => true,
        'companies' => $companyOptions,
        'rows' => $rows,
        'count' => count($rows),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load pending product desk data',
    ]);
}
