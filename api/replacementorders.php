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
require_once __DIR__ . '/helpers/jwt_helper.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (empty($authHeader)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "No token provided"]);
    exit();
}

$token = str_replace('Bearer ', '', $authHeader);
$decoded = JWT::decode($token);

if (!$decoded) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit();
}

$id = isset($_GET['id']) ? $_GET['id'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$priority = isset($_GET['priority']) ? trim($_GET['priority']) : '';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

try {
    if ($id) {
        $query = "SELECT so.*,
                         c.full_name as client_name,
                         c.email as client_email,
                         c.phone as client_phone,
                         c.address as client_address,
                         p.product_name,
                         p.brand as product_brand,
                         p.model as product_model,
                         p.specifications as product_specifications,
                         p.serial_number,
                         rp.product_name as replacement_product_name,
                         rp.brand as replacement_product_brand,
                         rp.model as replacement_product_model,
                         rp.serial_number as replacement_serial_number,
                         u.name as staff_name,
                         u.email as staff_email
                  FROM service_orders so
                  LEFT JOIN clients c ON so.client_id = c.id
                  LEFT JOIN products p ON so.product_id = p.id
                  LEFT JOIN products rp ON so.replacement_product_id = rp.id
                  LEFT JOIN users u ON so.staff_id = u.id
                  WHERE so.id = :id AND so.replacement_product_id IS NOT NULL";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Replacement order not found"]);
            exit();
        }

        echo json_encode(["success" => true, "order" => $order]);
        exit();
    }

    $query = "SELECT so.*,
                     c.full_name as client_name,
                     c.phone as client_phone,
                     p.product_name,
                     p.brand as product_brand,
                     p.model as product_model,
                     p.serial_number,
                     rp.product_name as replacement_product_name,
                     rp.brand as replacement_product_brand,
                     rp.model as replacement_product_model,
                     rp.serial_number as replacement_serial_number,
                     u.name as staff_name,
                     u.email as staff_email
              FROM service_orders so
              LEFT JOIN clients c ON so.client_id = c.id
              LEFT JOIN products p ON so.product_id = p.id
              LEFT JOIN products rp ON so.replacement_product_id = rp.id
              LEFT JOIN users u ON so.staff_id = u.id
              WHERE so.replacement_product_id IS NOT NULL";

    $params = [];

    if ($search !== '') {
        $query .= " AND (
            so.order_code LIKE :search OR
            c.full_name LIKE :search OR
            c.phone LIKE :search OR
            p.product_name LIKE :search OR
            rp.product_name LIKE :search OR
            p.serial_number LIKE :search OR
            rp.serial_number LIKE :search OR
            u.name LIKE :search
        )";
        $params[':search'] = "%{$search}%";
    }

    if ($status !== '' && $status !== 'all') {
        $query .= " AND so.status = :status";
        $params[':status'] = $status;
    }

    if ($priority !== '' && $priority !== 'all') {
        $query .= " AND so.priority = :priority";
        $params[':priority'] = $priority;
    }

    if ($start_date !== '' && $end_date !== '') {
        $query .= " AND DATE(so.created_at) BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $start_date;
        $params[':end_date'] = $end_date;
    }

    $query .= " ORDER BY so.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "orders" => $orders,
        "count" => count($orders)
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
