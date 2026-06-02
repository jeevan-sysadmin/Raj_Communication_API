<?php
require_once __DIR__ . '/finance_common.php';

function revenue_period_from_query(): array {
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
    $fromDateInput = finance_clean_text($_GET['from_date'] ?? '');
    $toDateInput = finance_clean_text($_GET['to_date'] ?? '');

    if ($fromDateInput !== '' || $toDateInput !== '') {
        if ($fromDateInput === '' || $toDateInput === '') {
            finance_error('Both from_date and to_date are required for a custom range', 400);
        }

        $fromDate = finance_date_value($fromDateInput, 'from_date', true);
        $toDate = finance_date_value($toDateInput, 'to_date', true);
        $fromObject = new DateTime($fromDate);
        $toObject = new DateTime($toDate);

        if ($fromObject > $toObject) {
            finance_error('from_date must be before or equal to to_date', 400);
        }

        $label = $fromObject->format('d M Y') . ' - ' . $toObject->format('d M Y');

        return [
            'year' => $year,
            'month' => $month,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'period_label' => $label,
        ];
    }

    if ($month !== null) {
        $fromObject = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $toObject = clone $fromObject;
        $toObject->modify('last day of this month');

        return [
            'year' => $year,
            'month' => $month,
            'from_date' => $fromObject->format('Y-m-d'),
            'to_date' => $toObject->format('Y-m-d'),
            'period_label' => $fromObject->format('F Y'),
        ];
    }

    $fromObject = new DateTime(sprintf('%04d-01-01', $year));
    $toObject = new DateTime(sprintf('%04d-12-31', $year));

    return [
        'year' => $year,
        'month' => null,
        'from_date' => $fromObject->format('Y-m-d'),
        'to_date' => $toObject->format('Y-m-d'),
        'period_label' => (string)$year,
    ];
}

function revenue_month_keys(string $fromDate, string $toDate): array {
    $keys = [];
    $cursor = new DateTime(substr($fromDate, 0, 7) . '-01');
    $end = new DateTime(substr($toDate, 0, 7) . '-01');

    while ($cursor <= $end) {
        $key = $cursor->format('Y-m');
        $keys[$key] = [
            'month' => $key,
            'label' => $cursor->format('M Y'),
            'income' => 0.0,
            'deposit_amount' => 0.0,
            'expenses' => 0.0,
            'salaries' => 0.0,
            'total_costs' => 0.0,
            'net_profit' => 0.0,
        ];
        $cursor->modify('+1 month');
    }

    return $keys;
}

function revenue_statement(PDO $db, string $query, array $params): PDOStatement {
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
}

function revenue_order_date_conditions(string $fromDate, string $toDate): array {
    $end = new DateTime($toDate . ' 00:00:00');
    $end->modify('+1 day');

    return [
        "so.created_at >= :from_datetime",
        "so.created_at < :to_datetime",
        "LOWER(TRIM(COALESCE(so.payment_status, ''))) = 'paid'",
    ];
}

$db = finance_connection();
finance_user(false);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    finance_error('Method not allowed', 405);
}

$period = revenue_period_from_query();
$serviceTypeInput = finance_clean_text($_GET['service_type'] ?? 'all');
$serviceType = $serviceTypeInput === '' || strtolower($serviceTypeInput) === 'all'
    ? 'all'
    : finance_service_type($serviceTypeInput);

$filters = [
    'year' => $period['year'],
    'month' => $period['month'],
    'service_type' => $serviceType,
    'from_date' => $period['from_date'],
    'to_date' => $period['to_date'],
];

$orderConditions = revenue_order_date_conditions($period['from_date'], $period['to_date']);
$orderParams = [
    ':from_datetime' => $period['from_date'] . ' 00:00:00',
    ':to_datetime' => (new DateTime($period['to_date'] . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s'),
];

if ($serviceType !== 'all') {
    $orderConditions[] = "COALESCE(NULLIF(TRIM(so.service_type), ''), 'general') = :service_type";
    $orderParams[':service_type'] = $serviceType;
}

$expenseConditions = [
    "e.expense_date BETWEEN :from_date AND :to_date",
];
$expenseParams = [
    ':from_date' => $period['from_date'],
    ':to_date' => $period['to_date'],
];

if ($serviceType !== 'all') {
    $expenseConditions[] = "e.service_type = :service_type";
    $expenseParams[':service_type'] = $serviceType;
}

$salaryConditions = [
    "s.salary_date BETWEEN :from_date AND :to_date",
];
$salaryParams = [
    ':from_date' => $period['from_date'],
    ':to_date' => $period['to_date'],
];

if ($serviceType !== 'all') {
    $salaryConditions[] = "s.service_type = :service_type";
    $salaryParams[':service_type'] = $serviceType;
}

$orderSummary = revenue_statement($db, "
    SELECT
        COALESCE(SUM(COALESCE(so.final_cost, 0)), 0) AS total_income,
        COALESCE(SUM(COALESCE(so.final_cost, 0)), 0) AS total_final_cost,
        COALESCE(SUM(COALESCE(so.deposit_amount, 0)), 0) AS total_deposit_amount,
        COUNT(so.id) AS order_count,
        COUNT(DISTINCT so.client_id) AS unique_customers,
        COALESCE(AVG(COALESCE(so.final_cost, 0)), 0) AS average_order_amount
    FROM service_orders so
    WHERE " . implode(' AND ', $orderConditions), $orderParams)->fetch(PDO::FETCH_ASSOC);

$expenseSummary = revenue_statement($db, "
    SELECT COALESCE(SUM(e.amount), 0) AS total_expenses
    FROM staff_expenses e
    WHERE " . implode(' AND ', $expenseConditions), $expenseParams)->fetch(PDO::FETCH_ASSOC);

$salarySummary = revenue_statement($db, "
    SELECT COALESCE(SUM(s.net_amount), 0) AS total_salaries
    FROM staff_salaries s
    WHERE " . implode(' AND ', $salaryConditions), $salaryParams)->fetch(PDO::FETCH_ASSOC);

$serviceOrderIncome = (float)($orderSummary['total_income'] ?? 0);
$totalFinalCost = (float)($orderSummary['total_final_cost'] ?? 0);
$totalDepositAmount = (float)($orderSummary['total_deposit_amount'] ?? 0);
$totalExpenses = (float)($expenseSummary['total_expenses'] ?? 0);
$totalSalaries = (float)($salarySummary['total_salaries'] ?? 0);
$totalIncome = $serviceOrderIncome;
$totalCosts = $totalDepositAmount + $totalExpenses + $totalSalaries;
$netProfit = $totalIncome - $totalCosts;

$serviceRows = [];

$orderServiceRows = revenue_statement($db, "
    SELECT
        COALESCE(NULLIF(TRIM(so.service_type), ''), 'general') AS service_type,
        COALESCE(SUM(COALESCE(so.final_cost, 0)), 0) AS income,
        COALESCE(SUM(COALESCE(so.deposit_amount, 0)), 0) AS deposit_amount_total,
        COUNT(so.id) AS order_count,
        COUNT(DISTINCT so.client_id) AS customer_count
    FROM service_orders so
    WHERE " . implode(' AND ', $orderConditions) . "
    GROUP BY COALESCE(NULLIF(TRIM(so.service_type), ''), 'general')
", $orderParams)->fetchAll(PDO::FETCH_ASSOC);

foreach ($orderServiceRows as $row) {
    $key = $row['service_type'] ?: 'general';
    $serviceRows[$key] = [
        'service_type' => $key,
        'income' => (float)$row['income'],
        'final_cost_total' => (float)$row['income'],
        'deposit_amount_total' => (float)$row['deposit_amount_total'],
        'expenses' => 0.0,
        'salaries' => 0.0,
        'total_costs' => 0.0,
        'net_profit' => 0.0,
        'order_count' => (int)$row['order_count'],
        'customer_count' => (int)$row['customer_count'],
    ];
}

$expenseRows = revenue_statement($db, "
    SELECT
        e.service_type,
        COALESCE(SUM(e.amount), 0) AS expenses
    FROM staff_expenses e
    WHERE " . implode(' AND ', $expenseConditions) . "
    GROUP BY e.service_type
", $expenseParams)->fetchAll(PDO::FETCH_ASSOC);

foreach ($expenseRows as $row) {
    $key = $row['service_type'] ?: 'general';
    if (!isset($serviceRows[$key])) {
        $serviceRows[$key] = [
            'service_type' => $key,
            'income' => 0.0,
            'final_cost_total' => 0.0,
            'deposit_amount_total' => 0.0,
            'expenses' => 0.0,
            'salaries' => 0.0,
            'total_costs' => 0.0,
            'net_profit' => 0.0,
            'order_count' => 0,
            'customer_count' => 0,
        ];
    }
    $serviceRows[$key]['expenses'] = (float)$row['expenses'];
}

$salaryRows = revenue_statement($db, "
    SELECT
        s.service_type,
        COALESCE(SUM(s.net_amount), 0) AS salaries
    FROM staff_salaries s
    WHERE " . implode(' AND ', $salaryConditions) . "
    GROUP BY s.service_type
", $salaryParams)->fetchAll(PDO::FETCH_ASSOC);

foreach ($salaryRows as $row) {
    $key = $row['service_type'] ?: 'general';
    if (!isset($serviceRows[$key])) {
        $serviceRows[$key] = [
            'service_type' => $key,
            'income' => 0.0,
            'final_cost_total' => 0.0,
            'deposit_amount_total' => 0.0,
            'expenses' => 0.0,
            'salaries' => 0.0,
            'total_costs' => 0.0,
            'net_profit' => 0.0,
            'order_count' => 0,
            'customer_count' => 0,
        ];
    }
    $serviceRows[$key]['salaries'] = (float)$row['salaries'];
}

if ($serviceType !== 'all' && !isset($serviceRows[$serviceType])) {
    $serviceRows[$serviceType] = [
        'service_type' => $serviceType,
        'income' => 0.0,
        'final_cost_total' => 0.0,
        'deposit_amount_total' => 0.0,
        'expenses' => 0.0,
        'salaries' => 0.0,
        'total_costs' => 0.0,
        'net_profit' => 0.0,
        'order_count' => 0,
        'customer_count' => 0,
    ];
}

foreach ($serviceRows as $key => $row) {
    $serviceRows[$key]['total_costs'] = round($row['deposit_amount_total'] + $row['expenses'] + $row['salaries'], 2);
    $serviceRows[$key]['net_profit'] = round($row['income'] - $serviceRows[$key]['total_costs'], 2);
    $serviceRows[$key]['income'] = round($row['income'], 2);
    $serviceRows[$key]['final_cost_total'] = round($row['income'], 2);
    $serviceRows[$key]['deposit_amount_total'] = round($row['deposit_amount_total'], 2);
    $serviceRows[$key]['expenses'] = round($row['expenses'], 2);
    $serviceRows[$key]['salaries'] = round($row['salaries'], 2);
}

ksort($serviceRows);
$serviceBreakdown = array_values($serviceRows);

$monthlyData = revenue_month_keys($period['from_date'], $period['to_date']);

$orderMonthlyRows = revenue_statement($db, "
    SELECT
        DATE_FORMAT(so.created_at, '%Y-%m') AS month_key,
        COALESCE(SUM(COALESCE(so.final_cost, 0)), 0) AS income,
        COALESCE(SUM(COALESCE(so.deposit_amount, 0)), 0) AS deposit_amount
    FROM service_orders so
    WHERE " . implode(' AND ', $orderConditions) . "
    GROUP BY DATE_FORMAT(so.created_at, '%Y-%m')
    ORDER BY month_key ASC
", $orderParams)->fetchAll(PDO::FETCH_ASSOC);

foreach ($orderMonthlyRows as $row) {
    if (!isset($monthlyData[$row['month_key']])) {
        continue;
    }
    $monthlyData[$row['month_key']]['income'] += (float)$row['income'];
    $monthlyData[$row['month_key']]['deposit_amount'] += (float)$row['deposit_amount'];
}

$expenseMonthlyRows = revenue_statement($db, "
    SELECT
        DATE_FORMAT(e.expense_date, '%Y-%m') AS month_key,
        COALESCE(SUM(e.amount), 0) AS expenses
    FROM staff_expenses e
    WHERE " . implode(' AND ', $expenseConditions) . "
    GROUP BY DATE_FORMAT(e.expense_date, '%Y-%m')
    ORDER BY month_key ASC
", $expenseParams)->fetchAll(PDO::FETCH_ASSOC);

foreach ($expenseMonthlyRows as $row) {
    if (!isset($monthlyData[$row['month_key']])) {
        continue;
    }
    $monthlyData[$row['month_key']]['expenses'] = (float)$row['expenses'];
}

$salaryMonthlyRows = revenue_statement($db, "
    SELECT
        DATE_FORMAT(s.salary_date, '%Y-%m') AS month_key,
        COALESCE(SUM(s.net_amount), 0) AS salaries
    FROM staff_salaries s
    WHERE " . implode(' AND ', $salaryConditions) . "
    GROUP BY DATE_FORMAT(s.salary_date, '%Y-%m')
    ORDER BY month_key ASC
", $salaryParams)->fetchAll(PDO::FETCH_ASSOC);

foreach ($salaryMonthlyRows as $row) {
    if (!isset($monthlyData[$row['month_key']])) {
        continue;
    }
    $monthlyData[$row['month_key']]['salaries'] = (float)$row['salaries'];
}

foreach ($monthlyData as $key => $row) {
    $monthlyData[$key]['income'] = round($row['income'], 2);
    $monthlyData[$key]['deposit_amount'] = round($row['deposit_amount'], 2);
    $monthlyData[$key]['expenses'] = round($row['expenses'], 2);
    $monthlyData[$key]['salaries'] = round($row['salaries'], 2);
    $monthlyData[$key]['total_costs'] = round($row['deposit_amount'] + $row['expenses'] + $row['salaries'], 2);
    $monthlyData[$key]['net_profit'] = round($row['income'] - $monthlyData[$key]['total_costs'], 2);
}

$topCustomersRows = revenue_statement($db, "
    SELECT
        c.id AS client_id,
        c.full_name AS client_name,
        c.phone,
        COUNT(so.id) AS order_count,
        COALESCE(SUM(COALESCE(so.final_cost, 0)), 0) AS total_paid
    FROM service_orders so
    LEFT JOIN clients c ON so.client_id = c.id
    WHERE " . implode(' AND ', $orderConditions) . "
    GROUP BY c.id, c.full_name, c.phone
    ORDER BY total_paid DESC, client_name ASC
    LIMIT 5
", $orderParams)->fetchAll(PDO::FETCH_ASSOC);

$topCustomers = array_map(static function (array $row): array {
    return [
        'client_id' => isset($row['client_id']) ? (int)$row['client_id'] : 0,
        'client_name' => $row['client_name'] ?? 'Unknown Customer',
        'phone' => $row['phone'] ?? '',
        'order_count' => (int)$row['order_count'],
        'total_paid' => (float)$row['total_paid'],
    ];
}, $topCustomersRows);

finance_response([
    'success' => true,
    'filters' => $filters,
    'summary' => [
        'period_label' => $period['period_label'],
        'date_range' => [
            'from' => $period['from_date'],
            'to' => $period['to_date'],
        ],
        'total_income' => round($totalIncome, 2),
        'total_final_cost' => round($totalFinalCost, 2),
        'total_deposit_amount' => round($totalDepositAmount, 2),
        'payment_income' => round($serviceOrderIncome, 2),
        'manual_income_total' => 0.0,
        'manual_income_count' => 0,
        'total_expenses' => round($totalExpenses, 2),
        'total_salaries' => round($totalSalaries, 2),
        'total_costs' => round($totalCosts, 2),
        'net_profit' => round($netProfit, 2),
        'payment_count' => (int)($orderSummary['order_count'] ?? 0),
        'order_count' => (int)($orderSummary['order_count'] ?? 0),
        'unique_customers' => (int)($orderSummary['unique_customers'] ?? 0),
        'average_payment' => round((float)($orderSummary['average_order_amount'] ?? 0), 2),
        'by_service' => $serviceBreakdown,
    ],
    'monthly_data' => array_values($monthlyData),
    'top_customers' => $topCustomers,
    'recent_income' => [],
    'message' => 'Revenue data loaded successfully',
]);
