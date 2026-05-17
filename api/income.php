<?php
require_once __DIR__ . '/finance_common.php';

function income_format_row(array $row): array {
    return [
        'id' => (int)$row['id'],
        'service_type' => $row['service_type'] ?? 'general',
        'income_source' => $row['income_source'] ?? 'manual',
        'amount' => (float)$row['amount'],
        'income_date' => $row['income_date'] ?? '',
        'description' => $row['description'] ?? '',
        'payment_method' => $row['payment_method'] ?? 'cash',
        'reference_number' => $row['reference_number'] ?? '',
        'client_id' => isset($row['client_id']) ? (int)$row['client_id'] : null,
        'client_name' => $row['client_name'] ?? '',
        'order_id' => isset($row['order_id']) ? (int)$row['order_id'] : null,
        'order_code' => $row['order_code'] ?? '',
        'notes' => $row['notes'] ?? '',
        'created_by' => isset($row['created_by']) ? (int)$row['created_by'] : null,
        'created_by_name' => $row['created_by_name'] ?? '',
        'created_at' => $row['created_at'] ?? '',
        'updated_at' => $row['updated_at'] ?? '',
    ];
}

function income_record_by_id(PDO $db, int $id): ?array {
    $query = "SELECT i.*,
                     c.full_name AS client_name,
                     so.order_code,
                     COALESCE(u.name, i.created_by_name) AS created_by_name
              FROM income_entries i
              LEFT JOIN clients c ON i.client_id = c.id
              LEFT JOIN service_orders so ON i.order_id = so.id
              LEFT JOIN users u ON i.created_by = u.id
              WHERE i.id = :id
              LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? income_format_row($row) : null;
}

$db = finance_connection();
$user = finance_user(false);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $filters = finance_query_filters();
    $conditions = ['1=1'];
    $params = [];

    if ($filters['service_type'] !== 'all') {
        $conditions[] = 'i.service_type = :service_type';
        $params[':service_type'] = $filters['service_type'];
    }

    if ($filters['year'] !== null) {
        $conditions[] = 'YEAR(i.income_date) = :year';
        $params[':year'] = $filters['year'];
    }

    if ($filters['month'] !== null) {
        $conditions[] = 'MONTH(i.income_date) = :month';
        $params[':month'] = $filters['month'];
    }

    if ($filters['from_date'] !== null) {
        $conditions[] = 'i.income_date >= :from_date';
        $params[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== null) {
        $conditions[] = 'i.income_date <= :to_date';
        $params[':to_date'] = $filters['to_date'];
    }

    $query = "SELECT i.*,
                     c.full_name AS client_name,
                     so.order_code,
                     COALESCE(u.name, i.created_by_name) AS created_by_name
              FROM income_entries i
              LEFT JOIN clients c ON i.client_id = c.id
              LEFT JOIN service_orders so ON i.order_id = so.id
              LEFT JOIN users u ON i.created_by = u.id
              WHERE " . implode(' AND ', $conditions) . "
              ORDER BY i.income_date DESC, i.id DESC";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = array_map('income_format_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
    $total = 0.0;
    $byService = [];

    foreach ($rows as $row) {
        $total += $row['amount'];
        $byService[$row['service_type']] = round(($byService[$row['service_type']] ?? 0) + $row['amount'], 2);
    }

    finance_response([
        'success' => true,
        'data' => $rows,
        'summary' => [
            'total' => round($total, 2),
            'count' => count($rows),
            'average' => count($rows) > 0 ? round($total / count($rows), 2) : 0.0,
            'by_service' => $byService,
        ],
        'filters' => $filters,
    ]);
}

if ($method === 'POST') {
    $payload = finance_payload();

    $serviceType = finance_service_type($payload['service_type'] ?? 'general');
    $incomeSource = finance_clean_text($payload['income_source'] ?? 'manual') ?: 'manual';
    $amount = finance_float_value($payload['amount'] ?? null, 'amount', true);
    $incomeDate = finance_date_value($payload['income_date'] ?? '', 'income_date', true);
    $description = finance_clean_text($payload['description'] ?? '');
    if ($description === '') {
        finance_error('description is required', 400);
    }

    $paymentMethod = finance_clean_text($payload['payment_method'] ?? 'cash') ?: 'cash';
    $referenceNumber = finance_clean_text($payload['reference_number'] ?? '');
    $clientId = finance_int_value($payload['client_id'] ?? null, 'client_id', false);
    $orderId = finance_int_value($payload['order_id'] ?? null, 'order_id', false);
    $notes = finance_clean_text($payload['notes'] ?? '');

    if ($clientId !== null) {
        $clientCheck = $db->prepare("SELECT id FROM clients WHERE id = :id LIMIT 1");
        $clientCheck->bindValue(':id', $clientId, PDO::PARAM_INT);
        $clientCheck->execute();
        if (!$clientCheck->fetch(PDO::FETCH_ASSOC)) {
            finance_error('Client not found', 404);
        }
    }

    if ($orderId !== null) {
        $orderCheck = $db->prepare("SELECT id FROM service_orders WHERE id = :id LIMIT 1");
        $orderCheck->bindValue(':id', $orderId, PDO::PARAM_INT);
        $orderCheck->execute();
        if (!$orderCheck->fetch(PDO::FETCH_ASSOC)) {
            finance_error('Order not found', 404);
        }
    }

    $query = "INSERT INTO income_entries (
                    service_type,
                    income_source,
                    amount,
                    income_date,
                    description,
                    payment_method,
                    reference_number,
                    client_id,
                    order_id,
                    notes,
                    created_by,
                    created_by_name,
                    created_at,
                    updated_at
              ) VALUES (
                    :service_type,
                    :income_source,
                    :amount,
                    :income_date,
                    :description,
                    :payment_method,
                    :reference_number,
                    :client_id,
                    :order_id,
                    :notes,
                    :created_by,
                    :created_by_name,
                    NOW(),
                    NOW()
              )";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':service_type', $serviceType, PDO::PARAM_STR);
    $stmt->bindValue(':income_source', $incomeSource, PDO::PARAM_STR);
    $stmt->bindValue(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindValue(':income_date', $incomeDate, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, PDO::PARAM_STR);
    $stmt->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
    $stmt->bindValue(':reference_number', $referenceNumber !== '' ? $referenceNumber : null, $referenceNumber !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':client_id', $clientId, $clientId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':order_id', $orderId, $orderId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':notes', $notes !== '' ? $notes : null, $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':created_by', isset($user['user_id']) ? (int)$user['user_id'] : null, isset($user['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':created_by_name', $user['name'] ?? 'Admin', PDO::PARAM_STR);

    if (!$stmt->execute()) {
        finance_error('Failed to save income entry', 500);
    }

    $record = income_record_by_id($db, (int)$db->lastInsertId());

    finance_response([
        'success' => true,
        'message' => 'Income saved successfully',
        'data' => $record,
    ], 201);
}

finance_error('Method not allowed', 405);
