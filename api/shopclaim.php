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

    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $query = "SELECT * FROM products WHERE id = :id AND claim_type = 'shop_claim' LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':id', (int) $_GET['id'], PDO::PARAM_INT);
        $stmt->execute();

        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Shop claim product not found"]);
            exit();
        }

        $product['purchase_date_formatted'] = formatNullableDate($product['purchase_date']);
        $product['created_at_formatted'] = formatNullableDate($product['created_at'], 'd/m/Y h:i A');
        $product['updated_at_formatted'] = formatNullableDate($product['updated_at'] ?? '', 'd/m/Y h:i A');
        $product['brand'] = $product['brand'] ?? '';
        $product['model'] = $product['model'] ?? '';
        $product['specifications'] = $product['specifications'] ?? '';
        $product['warranty_period'] = $product['warranty_period'] ?? '';
        $product['is_spare_product'] = $product['is_spare_product'] ?? 0;

        echo json_encode(["success" => true, "product" => $product]);
        exit();
    }

    $query = "SELECT * FROM products WHERE claim_type = 'shop_claim'";
    $params = [];

    if (!empty($_GET['search'])) {
        $query .= " AND (product_name LIKE :search OR product_code LIKE :search OR serial_number LIKE :search OR brand LIKE :search OR model LIKE :search)";
        $params[':search'] = '%' . trim($_GET['search']) . '%';
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
        $query .= " AND status = :status";
        $params[':status'] = trim($_GET['status']);
    }

    if (!empty($_GET['start_date'])) {
        $query .= " AND DATE(created_at) >= :start_date";
        $params[':start_date'] = $_GET['start_date'];
    }

    if (!empty($_GET['end_date'])) {
        $query .= " AND DATE(created_at) <= :end_date";
        $params[':end_date'] = $_GET['end_date'];
    }

    $query .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    $products = $stmt->fetchAll();

    foreach ($products as &$product) {
        $product['purchase_date_formatted'] = formatNullableDate($product['purchase_date']);
        $product['created_at_formatted'] = formatNullableDate($product['created_at'], 'd/m/Y h:i A');
        $product['updated_at_formatted'] = formatNullableDate($product['updated_at'] ?? '', 'd/m/Y h:i A');
        $product['brand'] = $product['brand'] ?? '';
        $product['model'] = $product['model'] ?? '';
        $product['specifications'] = $product['specifications'] ?? '';
        $product['warranty_period'] = $product['warranty_period'] ?? '';
        $product['is_spare_product'] = $product['is_spare_product'] ?? 0;
    }

    echo json_encode([
        "success" => true,
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
