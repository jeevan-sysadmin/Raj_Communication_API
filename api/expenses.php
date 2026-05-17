<?php
require_once __DIR__ . '/finance_common.php';

function expense_format_row(array $row): array {
    return [
        'id' => (int)$row['id'],
        'staff_id' => isset($row['staff_id']) ? (int)$row['staff_id'] : null,
        'staff_name' => $row['staff_name'] ?? '',
        'service_type' => $row['service_type'] ?? 'general',
        'expense_type' => $row['expense_type'] ?? 'others',
        'amount' => (float)$row['amount'],
        'description' => $row['description'] ?? '',
        'expense_date' => $row['expense_date'] ?? '',
        'payment_method' => $row['payment_method'] ?? 'cash',
        'receipt_number' => $row['receipt_number'] ?? '',
        'notes' => $row['notes'] ?? '',
        'created_by' => isset($row['created_by']) ? (int)$row['created_by'] : null,
        'created_by_name' => $row['created_by_name'] ?? '',
        'staff_email' => $row['staff_email'] ?? '',
        'created_at' => $row['created_at'] ?? '',
        'updated_at' => $row['updated_at'] ?? '',
    ];
}

function expense_record_by_id(PDO $db, int $id): ?array {
    $query = "SELECT e.*,
                     COALESCE(u.name, e.staff_name) AS staff_name,
                     u.email AS staff_email,
                     COALESCE(creator.name, e.created_by_name) AS created_by_name
              FROM staff_expenses e
              LEFT JOIN users u ON e.staff_id = u.id
              LEFT JOIN users creator ON e.created_by = creator.id
              WHERE e.id = :id
              LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? expense_format_row($row) : null;
}

$db = finance_connection();
$user = finance_user(false);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $filters = finance_query_filters();
    $conditions = ['1=1'];
    $params = [];

    if ($filters['staff_id'] !== null) {
        $conditions[] = 'e.staff_id = :staff_id';
        $params[':staff_id'] = $filters['staff_id'];
    }

    if ($filters['service_type'] !== 'all') {
        $conditions[] = 'e.service_type = :service_type';
        $params[':service_type'] = $filters['service_type'];
    }

    if ($filters['year'] !== null) {
        $conditions[] = 'YEAR(e.expense_date) = :year';
        $params[':year'] = $filters['year'];
    }

    if ($filters['month'] !== null) {
        $conditions[] = 'MONTH(e.expense_date) = :month';
        $params[':month'] = $filters['month'];
    }

    if ($filters['from_date'] !== null) {
        $conditions[] = 'e.expense_date >= :from_date';
        $params[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== null) {
        $conditions[] = 'e.expense_date <= :to_date';
        $params[':to_date'] = $filters['to_date'];
    }

    $query = "SELECT e.*,
                     COALESCE(u.name, e.staff_name) AS staff_name,
                     u.email AS staff_email,
                     COALESCE(creator.name, e.created_by_name) AS created_by_name
              FROM staff_expenses e
              LEFT JOIN users u ON e.staff_id = u.id
              LEFT JOIN users creator ON e.created_by = creator.id
              WHERE " . implode(' AND ', $conditions) . "
              ORDER BY e.expense_date DESC, e.id DESC";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = array_map('expense_format_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
    $total = 0.0;
    $staffIds = [];
    $byType = [];

    foreach ($rows as $row) {
        $total += $row['amount'];
        $byType[$row['expense_type']] = round(($byType[$row['expense_type']] ?? 0) + $row['amount'], 2);
        if ($row['staff_id'] !== null) {
            $staffIds[$row['staff_id']] = true;
        }
    }

    finance_response([
        'success' => true,
        'data' => $rows,
        'summary' => [
            'total' => round($total, 2),
            'count' => count($rows),
            'average' => count($rows) > 0 ? round($total / count($rows), 2) : 0.0,
            'unique_staff' => count($staffIds),
            'by_type' => $byType,
        ],
        'filters' => $filters,
    ]);
}

if ($method === 'POST') {
    $payload = finance_payload();

    $staffId = finance_int_value($payload['staff_id'] ?? null, 'staff_id', true);
    $staff = finance_user_row($db, (int)$staffId);
    if ($staff === null) {
        finance_error('Staff member not found', 404);
    }

    $amount = finance_float_value($payload['amount'] ?? null, 'amount', true);
    $expenseDate = finance_date_value($payload['expense_date'] ?? '', 'expense_date', true);
    $serviceType = finance_service_type($payload['service_type'] ?? 'general');
    $expenseType = finance_clean_text($payload['expense_type'] ?? 'others') ?: 'others';
    $description = finance_clean_text($payload['description'] ?? '');
    if ($description === '') {
        finance_error('description is required', 400);
    }

    $paymentMethod = finance_clean_text($payload['payment_method'] ?? 'cash') ?: 'cash';
    $receiptNumber = finance_clean_text($payload['receipt_number'] ?? '');
    $notes = finance_clean_text($payload['notes'] ?? '');

    $query = "INSERT INTO staff_expenses (
                    staff_id,
                    staff_name,
                    service_type,
                    expense_type,
                    amount,
                    description,
                    expense_date,
                    payment_method,
                    receipt_number,
                    notes,
                    created_by,
                    created_by_name,
                    created_at,
                    updated_at
              ) VALUES (
                    :staff_id,
                    :staff_name,
                    :service_type,
                    :expense_type,
                    :amount,
                    :description,
                    :expense_date,
                    :payment_method,
                    :receipt_number,
                    :notes,
                    :created_by,
                    :created_by_name,
                    NOW(),
                    NOW()
              )";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':staff_id', $staffId, PDO::PARAM_INT);
    $stmt->bindValue(':staff_name', $staff['name'] ?? 'Staff', PDO::PARAM_STR);
    $stmt->bindValue(':service_type', $serviceType, PDO::PARAM_STR);
    $stmt->bindValue(':expense_type', $expenseType, PDO::PARAM_STR);
    $stmt->bindValue(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, PDO::PARAM_STR);
    $stmt->bindValue(':expense_date', $expenseDate, PDO::PARAM_STR);
    $stmt->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
    $stmt->bindValue(':receipt_number', $receiptNumber !== '' ? $receiptNumber : null, $receiptNumber !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':notes', $notes !== '' ? $notes : null, $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':created_by', isset($user['user_id']) ? (int)$user['user_id'] : null, isset($user['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':created_by_name', $user['name'] ?? 'Admin', PDO::PARAM_STR);

    if (!$stmt->execute()) {
        finance_error('Failed to save expense record', 500);
    }

    $record = expense_record_by_id($db, (int)$db->lastInsertId());

    finance_response([
        'success' => true,
        'message' => 'Expense saved successfully',
        'data' => $record,
    ], 201);
}

finance_error('Method not allowed', 405);
