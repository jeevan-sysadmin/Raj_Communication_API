<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

function notifications_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
    exit();
}

function notifications_auth_user(): array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader || !preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        notifications_error('Unauthorized', 401);
    }

    $decoded = JWT::decode(trim($matches[1]));
    if (!$decoded || !is_array($decoded)) {
        notifications_error('Invalid or expired token', 401);
    }

    return $decoded;
}

function build_overdue_notifications(PDO $db, array $user, int $limit): array {
    $role = strtolower((string)($user['role'] ?? 'user'));
    $conditions = [
        "so.status IN ('pending', 'scheduled', 'process', 'ready')",
        "DATE(so.created_at) <= DATE_SUB(CURDATE(), INTERVAL 2 DAY)",
    ];
    $params = [];

    if ($role !== 'admin') {
        $conditions[] = 'so.staff_id = :staff_id';
        $params[':staff_id'] = (int)($user['user_id'] ?? 0);
    }

    $query = "SELECT
                so.id,
                so.order_code,
                so.status,
                so.created_at,
                so.estimated_delivery_date,
                COALESCE(c.full_name, 'Unknown Customer') AS client_name,
                COALESCE(c.phone, '') AS client_phone,
                COALESCE(u.name, 'Not Assigned') AS staff_name,
                TIMESTAMPDIFF(DAY, DATE(so.created_at), CURDATE()) AS pending_days
              FROM service_orders so
              LEFT JOIN clients c ON so.client_id = c.id
              LEFT JOIN users u ON so.staff_id = u.id
              WHERE " . implode(' AND ', $conditions) . "
              ORDER BY so.created_at ASC
              LIMIT :limit";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $createdAt = date('Y-m-d H:i:s');
    $notifications = [];

    foreach ($rows as $row) {
        $days = max(2, (int)($row['pending_days'] ?? 2));
        $orderCode = $row['order_code'] ?? ('ORD-' . $row['id']);
        $clientName = $row['client_name'] ?? 'Unknown Customer';
        $statusLabel = ucfirst(str_replace('_', ' ', (string)($row['status'] ?? 'pending')));

        $notifications[] = [
            'id' => 900000 + (int)$row['id'],
            'user_id' => (int)($user['user_id'] ?? 0),
            'title' => 'Open Order Reminder',
            'message' => $role === 'admin'
                ? "Order {$orderCode} for {$clientName} has been open for {$days} days and is still {$statusLabel}. Keep following up until it is completed."
                : "Your order {$orderCode} for {$clientName} has been open for {$days} days and is still {$statusLabel}. Please follow up until it is completed.",
            'type' => 'order',
            'is_read' => 0,
            'link' => '/orders',
            'created_at' => $createdAt,
        ];
    }

    return $notifications;
}

try {
    $user = notifications_auth_user();
    $database = new Database();
    $db = $database->getConnection();

    if (!$db instanceof PDO) {
        throw new Exception('Database connection failed');
    }

    $userId = (int)($user['user_id'] ?? 0);
    $isRead = isset($_GET['is_read']) ? (int)$_GET['is_read'] : null;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;

    if (isset($_GET['mark_read']) && (int)$_GET['mark_read'] === 1) {
        $updateQuery = "UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $updateStmt->execute();
    }

    $query = "SELECT
                id,
                user_id,
                title,
                message,
                type,
                is_read,
                link,
                created_at
              FROM notifications
              WHERE user_id = :user_id";
    $params = [':user_id' => $userId];

    if ($isRead !== null) {
        $query .= " AND is_read = :is_read";
        $params[':is_read'] = $isRead;
    }

    $query .= " ORDER BY created_at DESC LIMIT :limit";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $storedNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $generatedNotifications = $isRead === 1 ? [] : build_overdue_notifications($db, $user, $limit);

    $notifications = array_merge($generatedNotifications, $storedNotifications);

    usort($notifications, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    if (count($notifications) > $limit) {
        $notifications = array_slice($notifications, 0, $limit);
    }

    $unreadCount = 0;
    foreach ($notifications as $notification) {
        if (empty($notification['is_read'])) {
            $unreadCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
        'total' => count($notifications),
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}
