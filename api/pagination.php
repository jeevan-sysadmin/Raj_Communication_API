<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
class Database {
    private $host = "localhost";
    private $db_name = "sun_computers";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            error_log("Connection error: " . $e->getMessage());
        }
        return $this->conn;
    }
}

// Simple auth check (for demo purposes)
function checkAuth() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
        // For demo, accept any token
        return !empty($token);
    }
    return false;
}

// Handle paginated data
function getPaginatedData($conn, $table, $page = 1, $limit = 25, $filters = []) {
    $offset = ($page - 1) * $limit;
    
    // Build where clause
    $whereClause = " WHERE 1=1";
    $params = [];
    
    foreach ($filters as $key => $value) {
        if (!empty($value) && $value !== 'all') {
            $whereClause .= " AND $key = :$key";
            $params[":$key"] = $value;
        }
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM $table" . $whereClause;
    $countStmt = $conn->prepare($countQuery);
    $countStmt->execute($params);
    $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total = $totalResult['total'] ?? 0;
    
    // Get paginated data
    $query = "SELECT * FROM $table" . $whereClause . " LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($query);
    
    // Bind parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'data' => $data,
        'total' => (int)$total,
        'page' => (int)$page,
        'limit' => (int)$limit,
        'total_pages' => ceil($total / $limit)
    ];
}

// Main execution
try {
    if (!checkAuth()) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Unauthorized"]);
        exit();
    }
    
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    $table = $_GET['table'] ?? 'service_orders';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
    
    // Validate table name
    $allowedTables = ['service_orders', 'clients', 'products', 'deliveries', 'payments'];
    if (!in_array($table, $allowedTables)) {
        $table = 'service_orders';
    }
    
    // Get filters from query parameters
    $filters = [];
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $filters['status'] = $_GET['status'];
    }
    if (isset($_GET['payment_status']) && $_GET['payment_status'] !== '') {
        $filters['payment_status'] = $_GET['payment_status'];
    }
    if (isset($_GET['priority']) && $_GET['priority'] !== '') {
        $filters['priority'] = $_GET['priority'];
    }
    
    // Get paginated data
    $result = getPaginatedData($conn, $table, $page, $limit, $filters);
    
    echo json_encode([
        "success" => true,
        ...$result
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>