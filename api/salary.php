<?php
require_once __DIR__ . '/finance_common.php';

function salary_format_row(array $row): array {
    return [
        'id' => (int)$row['id'],
        'staff_id' => isset($row['staff_id']) ? (int)$row['staff_id'] : null,
        'staff_name' => $row['staff_name'] ?? '',
        'service_type' => $row['service_type'] ?? 'general',
        'amount' => (float)$row['amount'],
        'bonus' => (float)$row['bonus'],
        'deductions' => (float)$row['deductions'],
        'net_amount' => (float)$row['net_amount'],
        'salary_date' => $row['salary_date'] ?? '',
        'salary_month' => $row['salary_month'] ?? '',
        'payment_method' => $row['payment_method'] ?? 'bank_transfer',
        'transaction_id' => $row['transaction_id'] ?? '',
        'notes' => $row['notes'] ?? '',
        'paid_by' => isset($row['paid_by']) ? (int)$row['paid_by'] : null,
        'paid_by_name' => $row['paid_by_name'] ?? '',
        'staff_email' => $row['staff_email'] ?? '',
        'created_at' => $row['created_at'] ?? '',
        'updated_at' => $row['updated_at'] ?? '',
    ];
}

function salary_record_by_id(PDO $db, int $id): ?array {
    $query = "SELECT s.*,
                     COALESCE(u.name, s.staff_name) AS staff_name,
                     u.email AS staff_email,
                     COALESCE(payer.name, s.paid_by_name) AS paid_by_name
              FROM staff_salaries s
              LEFT JOIN users u ON s.staff_id = u.id
              LEFT JOIN users payer ON s.paid_by = payer.id
              WHERE s.id = :id
              LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? salary_format_row($row) : null;
}

$db = finance_connection();
$user = finance_user(false);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $filters = finance_query_filters();
    $conditions = ['1=1'];
    $params = [];

    if ($filters['staff_id'] !== null) {
        $conditions[] = 's.staff_id = :staff_id';
        $params[':staff_id'] = $filters['staff_id'];
    }

    if ($filters['service_type'] !== 'all') {
        $conditions[] = 's.service_type = :service_type';
        $params[':service_type'] = $filters['service_type'];
    }

    if ($filters['year'] !== null) {
        $conditions[] = 'YEAR(s.salary_date) = :year';
        $params[':year'] = $filters['year'];
    }

    if ($filters['month'] !== null) {
        $conditions[] = 'MONTH(s.salary_date) = :month';
        $params[':month'] = $filters['month'];
    }

    if ($filters['from_date'] !== null) {
        $conditions[] = 's.salary_date >= :from_date';
        $params[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== null) {
        $conditions[] = 's.salary_date <= :to_date';
        $params[':to_date'] = $filters['to_date'];
    }

    $query = "SELECT s.*,
                     COALESCE(u.name, s.staff_name) AS staff_name,
                     u.email AS staff_email,
                     COALESCE(payer.name, s.paid_by_name) AS paid_by_name
              FROM staff_salaries s
              LEFT JOIN users u ON s.staff_id = u.id
              LEFT JOIN users payer ON s.paid_by = payer.id
              WHERE " . implode(' AND ', $conditions) . "
              ORDER BY s.salary_date DESC, s.id DESC";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = array_map('salary_format_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
    $total = 0.0;
    $baseTotal = 0.0;
    $bonusTotal = 0.0;
    $deductionsTotal = 0.0;
    $staffIds = [];

    foreach ($rows as $row) {
        $total += $row['net_amount'];
        $baseTotal += $row['amount'];
        $bonusTotal += $row['bonus'];
        $deductionsTotal += $row['deductions'];
        if ($row['staff_id'] !== null) {
            $staffIds[$row['staff_id']] = true;
        }
    }

    finance_response([
        'success' => true,
        'data' => $rows,
        'summary' => [
            'total' => round($total, 2),
            'base_total' => round($baseTotal, 2),
            'bonus_total' => round($bonusTotal, 2),
            'deductions_total' => round($deductionsTotal, 2),
            'count' => count($rows),
            'average' => count($rows) > 0 ? round($total / count($rows), 2) : 0.0,
            'unique_staff' => count($staffIds),
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
    $bonus = finance_float_value($payload['bonus'] ?? null, 'bonus', false, 0);
    $deductions = finance_float_value($payload['deductions'] ?? null, 'deductions', false, 0);
    $salaryDate = finance_date_value($payload['salary_date'] ?? '', 'salary_date', true);
    $salaryMonth = finance_month_value($payload['salary_month'] ?? substr((string)$salaryDate, 0, 7), 'salary_month', true);
    $serviceType = finance_service_type($payload['service_type'] ?? 'general');
    $paymentMethod = finance_clean_text($payload['payment_method'] ?? 'bank_transfer') ?: 'bank_transfer';
    $transactionId = finance_clean_text($payload['transaction_id'] ?? '');
    $notes = finance_clean_text($payload['notes'] ?? '');
    $netAmount = round($amount + $bonus - $deductions, 2);

    $query = "INSERT INTO staff_salaries (
                    staff_id,
                    staff_name,
                    service_type,
                    amount,
                    bonus,
                    deductions,
                    net_amount,
                    salary_date,
                    salary_month,
                    payment_method,
                    transaction_id,
                    notes,
                    paid_by,
                    paid_by_name,
                    created_at,
                    updated_at
              ) VALUES (
                    :staff_id,
                    :staff_name,
                    :service_type,
                    :amount,
                    :bonus,
                    :deductions,
                    :net_amount,
                    :salary_date,
                    :salary_month,
                    :payment_method,
                    :transaction_id,
                    :notes,
                    :paid_by,
                    :paid_by_name,
                    NOW(),
                    NOW()
              )";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':staff_id', $staffId, PDO::PARAM_INT);
    $stmt->bindValue(':staff_name', $staff['name'] ?? 'Staff', PDO::PARAM_STR);
    $stmt->bindValue(':service_type', $serviceType, PDO::PARAM_STR);
    $stmt->bindValue(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindValue(':bonus', $bonus, PDO::PARAM_STR);
    $stmt->bindValue(':deductions', $deductions, PDO::PARAM_STR);
    $stmt->bindValue(':net_amount', $netAmount, PDO::PARAM_STR);
    $stmt->bindValue(':salary_date', $salaryDate, PDO::PARAM_STR);
    $stmt->bindValue(':salary_month', $salaryMonth, PDO::PARAM_STR);
    $stmt->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
    $stmt->bindValue(':transaction_id', $transactionId !== '' ? $transactionId : null, $transactionId !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':notes', $notes !== '' ? $notes : null, $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':paid_by', isset($user['user_id']) ? (int)$user['user_id'] : null, isset($user['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':paid_by_name', $user['name'] ?? 'Admin', PDO::PARAM_STR);

    if (!$stmt->execute()) {
        finance_error('Failed to save salary record', 500);
    }

    $record = salary_record_by_id($db, (int)$db->lastInsertId());

    finance_response([
        'success' => true,
        'message' => 'Salary saved successfully',
        'data' => $record,
    ], 201);
}

finance_error('Method not allowed', 405);
