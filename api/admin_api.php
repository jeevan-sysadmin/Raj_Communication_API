<?php
// C:\xampp\htdocs\raj_communication\api\admin_api.php

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);

// Include required files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

class AdminAPI {
    private $conn;
    private $user;
    private $tableColumnCache = [];

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        
        if (!$this->conn) {
            $this->sendError("Database connection failed", 500);
            exit();
        }
        
        // Verify authentication
        $this->verifyAuth();
    }
    
    private function verifyAuth() {
        $token = $this->getBearerToken();
        
        if (!$token) {
            $this->sendError("Authentication token required", 401);
            exit();
        }
        
        $payload = JWT::decode($token);
        
        if (!$payload) {
            $this->sendError("Invalid or expired token", 401);
            exit();
        }
        
        $this->user = $payload;
        
        // Check if user is admin
        if ($this->user['role'] !== 'admin') {
            $this->sendError("Admin access required", 403);
            exit();
        }
    }

    private function normalizeIdList($value): array {
        if ($value === null) {
            return [];
        }

        $list = [];

        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $list = $decoded;
            } else {
                $list = explode(',', $trimmed);
            }
        } else {
            $list = [$value];
        }

        $ids = [];
        foreach ($list as $entry) {
            $id = (int)$entry;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeIssueDescriptionMap($value): array {
        if ($value === null || $value === '') {
            return [];
        }
        $parsed = $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsed = $decoded;
            } else {
                return [];
            }
        }
        if (!is_array($parsed)) {
            return [];
        }
        $normalized = [];
        foreach ($parsed as $productId => $issueText) {
            $pid = (int)$productId;
            if ($pid <= 0) {
                continue;
            }
            $normalized[(string)$pid] = trim((string)$issueText);
        }
        return $normalized;
    }

    private function encodeJsonObject($value): ?string {
        if ($value === null) return null;
        $parsed = $value;
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') return null;
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsed = $decoded;
            } else {
                return null;
            }
        }
        if (!is_array($parsed)) return null;
        return json_encode($parsed);
    }

    private function updateServiceOrderExtendedFields(int $orderId, array $data, array $productIds): void {
        $columns = $this->getTableColumns('service_orders');
        if (empty($columns)) return;

        $sets = [];
        $params = [':id' => $orderId];

        if (isset($columns['company_ids']) && array_key_exists('company_ids', $data)) {
            $companyIds = $this->normalizeIdList($data['company_ids']);
            $sets[] = "company_ids = :company_ids";
            $params[':company_ids'] = !empty($companyIds) ? json_encode($companyIds) : null;
        }
        if (isset($columns['company_product_map']) && array_key_exists('company_product_map', $data)) {
            $json = $this->encodeJsonObject($data['company_product_map']);
            $sets[] = "company_product_map = :company_product_map";
            $params[':company_product_map'] = $json;
        }
        if (isset($columns['companies_products']) && array_key_exists('companies_products', $data)) {
            $json = $this->encodeJsonObject($data['companies_products']);
            $sets[] = "companies_products = :companies_products";
            $params[':companies_products'] = $json;
        }
        if (isset($columns['product_status_map']) && array_key_exists('product_status_map', $data)) {
            $json = $this->encodeJsonObject($data['product_status_map']);
            $sets[] = "product_status_map = :product_status_map";
            $params[':product_status_map'] = $json;
        }
        if (isset($columns['product_status_dates_map']) && (array_key_exists('product_status_dates_map', $data) || array_key_exists('product_status_map', $data))) {
            $allowedStatuses = ['pending', 'rajtocom', 'comtoraj', 'deliveryed'];
            $now = date('Y-m-d H:i:s');

            $normalizeStatus = static function ($status) use ($allowedStatuses): string {
                $raw = strtolower(trim((string)$status));
                if ($raw === 'delivered') $raw = 'deliveryed';
                return in_array($raw, $allowedStatuses, true) ? $raw : 'pending';
            };

            $decodeObject = static function ($value): array {
                if ($value === null || $value === '') return [];
                if (is_array($value)) return $value;
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
                }
                return [];
            };

            $statusMap = $decodeObject($data['product_status_map'] ?? null);
            $incomingDatesMap = $decodeObject($data['product_status_dates_map'] ?? null);

            $existingDatesMap = [];
            try {
                $existingStmt = $this->conn->prepare("SELECT product_status_dates_map FROM service_orders WHERE id = :id LIMIT 1");
                $existingStmt->bindValue(':id', $orderId, PDO::PARAM_INT);
                $existingStmt->execute();
                $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
                $existingDatesMap = $decodeObject($existingRow['product_status_dates_map'] ?? null);
            } catch (Exception $e) {
                $existingDatesMap = [];
            }

            $targetIds = !empty($productIds)
                ? $productIds
                : array_values(array_unique(array_merge(
                    array_map('intval', array_keys($statusMap)),
                    array_map('intval', array_keys($incomingDatesMap)),
                    array_map('intval', array_keys($existingDatesMap))
                )));

            $finalDatesMap = [];
            foreach ($targetIds as $pid) {
                $key = (string)$pid;
                $row = [];
                foreach ($allowedStatuses as $statusKey) {
                    $incoming = $incomingDatesMap[$key][$statusKey] ?? null;
                    $existing = $existingDatesMap[$key][$statusKey] ?? null;
                    $row[$statusKey] = ($incoming !== null && trim((string)$incoming) !== '')
                        ? (string)$incoming
                        : (($existing !== null && trim((string)$existing) !== '') ? (string)$existing : null);
                }

                if ($row['pending'] === null) {
                    $row['pending'] = $now;
                }

                $currentStatus = $normalizeStatus($statusMap[$key] ?? 'pending');
                if ($row[$currentStatus] === null) {
                    $row[$currentStatus] = $now;
                }

                $finalDatesMap[$key] = $row;
            }

            $sets[] = "product_status_dates_map = :product_status_dates_map";
            $params[':product_status_dates_map'] = json_encode($finalDatesMap);
        }
        if (isset($columns['repairing_status_map']) && array_key_exists('repairing_status_map', $data)) {
            $json = $this->encodeJsonObject($data['repairing_status_map']);
            $sets[] = "repairing_status_map = :repairing_status_map";
            $params[':repairing_status_map'] = $json;
        }
        if (isset($columns['issue_description_map']) && array_key_exists('issue_description_map', $data)) {
            $incomingIssueMap = $this->normalizeIssueDescriptionMap($data['issue_description_map']);
            $normalizedIssueMap = [];
            foreach ($productIds as $pid) {
                $key = (string)$pid;
                $normalizedIssueMap[$key] = isset($incomingIssueMap[$key]) ? trim((string)$incomingIssueMap[$key]) : '';
            }
            $sets[] = "issue_description_map = :issue_description_map";
            $params[':issue_description_map'] = json_encode($normalizedIssueMap);
        }
        if (isset($columns['handover_type_map']) && array_key_exists('handover_type_map', $data)) {
            $json = $this->encodeJsonObject($data['handover_type_map']);
            $sets[] = "handover_type_map = :handover_type_map";
            $params[':handover_type_map'] = $json;
        }

        if (isset($columns['updated_at'])) {
            $sets[] = "updated_at = NOW()";
        }
        if (empty($sets)) return;

        $sql = "UPDATE service_orders SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $key => $value) {
            if ($value === null) $stmt->bindValue($key, null, PDO::PARAM_NULL);
            else $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
    }

    private function normalizeProductPayload(array $row): array {
        if ((!isset($row['product_name']) || trim((string)$row['product_name']) === '')) {
            $aliasKeys = ['productName', 'name'];
            foreach ($aliasKeys as $aliasKey) {
                if (isset($row[$aliasKey]) && trim((string)$row[$aliasKey]) !== '') {
                    $row['product_name'] = trim((string)$row[$aliasKey]);
                    break;
                }
            }
        }

        if ((!isset($row['stock_quantity']) || $row['stock_quantity'] === '' || $row['stock_quantity'] === null)) {
            $stockAliasKeys = ['stockQuantity', 'quantity', 'qty'];
            foreach ($stockAliasKeys as $aliasKey) {
                if (array_key_exists($aliasKey, $row) && $row[$aliasKey] !== '' && $row[$aliasKey] !== null) {
                    $row['stock_quantity'] = $row[$aliasKey];
                    break;
                }
            }
        }

        return $row;
    }

    private function syncOrderProducts(int $orderId, array $productIds, bool $isReplacement): void {
        $deleteStmt = $this->conn->prepare("DELETE FROM service_order_products WHERE order_id = :order_id AND is_replacement = :is_replacement");
        $deleteStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $deleteStmt->bindValue(':is_replacement', $isReplacement ? 1 : 0, PDO::PARAM_INT);
        $deleteStmt->execute();

        if (empty($productIds)) {
            return;
        }

        $insertStmt = $this->conn->prepare("INSERT INTO service_order_products (order_id, product_id, is_replacement, sort_order, created_at)
                                            VALUES (:order_id, :product_id, :is_replacement, :sort_order, NOW())");

        foreach ($productIds as $index => $productId) {
            $insertStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $insertStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
            $insertStmt->bindValue(':is_replacement', $isReplacement ? 1 : 0, PDO::PARAM_INT);
            $insertStmt->bindValue(':sort_order', (int)$index, PDO::PARAM_INT);
            $insertStmt->execute();
        }
    }

    private function fetchOrderProductsMap(array $orderIds): array {
        if (empty($orderIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $query = "SELECT sop.order_id,
                         sop.is_replacement,
                         GROUP_CONCAT(sop.product_id ORDER BY sop.sort_order SEPARATOR ',') AS product_ids,
                         GROUP_CONCAT(p.product_name ORDER BY sop.sort_order SEPARATOR '||') AS product_names
                  FROM service_order_products sop
                  JOIN products p ON sop.product_id = p.id
                  WHERE sop.order_id IN ($placeholders)
                  GROUP BY sop.order_id, sop.is_replacement";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($orderIds);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orderId = (int)$row['order_id'];
            $bucket = ((int)$row['is_replacement'] === 1) ? 'replacement' : 'primary';
            $ids = $row['product_ids'] !== null && $row['product_ids'] !== ''
                ? array_map('intval', explode(',', $row['product_ids']))
                : [];
            $names = $row['product_names'] !== null && $row['product_names'] !== ''
                ? explode('||', $row['product_names'])
                : [];
            $map[$orderId][$bucket] = [
                'ids' => $ids,
                'names' => $names
            ];
        }

        return $map;
    }

    private function fetchProductNamesByIds(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));

        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare("SELECT id, product_name FROM products WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int)$row['id']] = $row['product_name'];
        }

        return $map;
    }

    private function buildNamesFromIds(array $ids, array $nameMap): array {
        $names = [];
        foreach ($ids as $id) {
            if (isset($nameMap[$id])) {
                $names[] = $nameMap[$id];
            }
        }
        return $names;
    }

    private function getTableColumns(string $table): array {
        if (isset($this->tableColumnCache[$table])) {
            return $this->tableColumnCache[$table];
        }

        $columns = [];

        try {
            $query = "SELECT COLUMN_NAME
                      FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = :table";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':table', $table, PDO::PARAM_STR);
            $stmt->execute();

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['COLUMN_NAME'])) {
                    $columns[$row['COLUMN_NAME']] = true;
                }
            }
        } catch (Exception $e) {
            $columns = [];
        }

        $this->tableColumnCache[$table] = $columns;
        return $columns;
    }

    private function tableHasColumn(string $table, string $column): bool {
        $columns = $this->getTableColumns($table);
        return isset($columns[$column]);
    }

    private function normalizeExistingCompanyId($value): ?int {
        $companyId = (int)$value;
        if ($companyId <= 0) {
            return null;
        }

        $stmt = $this->conn->prepare("SELECT id FROM companies WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $companyId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ? $companyId : null;
    }
    
    private function getBearerToken() {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (!is_array($headers)) {
                $headers = [];
            }
        }

        $authHeader = '';
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
        } elseif (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            if (isset($apacheHeaders['Authorization'])) {
                $authHeader = $apacheHeaders['Authorization'];
            } elseif (isset($apacheHeaders['authorization'])) {
                $authHeader = $apacheHeaders['authorization'];
            }
        }

        if ($authHeader === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if ($authHeader === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        
        switch ($action) {
            case 'dashboard_stats':
                $this->getDashboardStats();
                break;
                
            case 'get_users':
                $this->getUsers();
                break;
            case 'create_user':
                $this->createUser();
                break;
            case 'update_user':
                $this->updateUser();
                break;
            case 'delete_user':
                $this->deleteUser();
                break;
                
            case 'get_orders':
                $this->getOrders();
                break;
            case 'create_order':
                $this->createOrder();
                break;
            case 'update_order':
                $this->updateOrder();
                break;
            case 'delete_order':
                $this->deleteOrder();
                break;
                
            case 'get_clients':
                $this->getClients();
                break;
            case 'create_client':
                $this->createClient();
                break;
            case 'update_client':
                $this->updateClient();
                break;
            case 'delete_client':
                $this->deleteClient();
                break;
                
            case 'get_products':
                $this->getProducts();
                break;
            case 'create_product':
                $this->createProduct();
                break;
            case 'update_product':
                $this->updateProduct();
                break;
            case 'delete_product':
                $this->deleteProduct();
                break;
                
            case 'get_deliveries':
                $this->getDeliveries();
                break;
            case 'update_delivery':
                $this->updateDelivery();
                break;
            case 'delete_delivery':
                $this->deleteDelivery();
                break;
                
            case 'staff_performance':
                $this->getStaffPerformance();
                break;
                
            case 'analytics':
                $this->getAnalytics();
                break;
                
            case 'notifications':
                $this->getRealtimeNotifications();
                break;
                
            case 'reset_password':
                $this->resetPassword();
                break;

            case 'backup_database':
                $this->backupDatabase();
                break;

            case 'get_backup_history':
                $this->getBackupHistory();
                break;
                 
            default:
                $this->sendError("Invalid action", 400);
                break;
        }
    }
    
    private function getDashboardStats() {
        try {
            $stats = [];
            
            // Total users
            $query = "SELECT COUNT(*) as total_users FROM users";
            $stmt = $this->conn->query($query);
            $stats['total_users'] = (int)$stmt->fetchColumn();
            
            // Total clients
            $query = "SELECT COUNT(*) as total_clients FROM clients";
            $stmt = $this->conn->query($query);
            $stats['total_clients'] = (int)$stmt->fetchColumn();
            
            // Total orders
            $query = "SELECT COUNT(*) as total_orders FROM service_orders";
            $stmt = $this->conn->query($query);
            $stats['total_orders'] = (int)$stmt->fetchColumn();
            
            // Total products
            $query = "SELECT COUNT(*) as total_products FROM products";
            $stmt = $this->conn->query($query);
            $stats['total_products'] = (int)$stmt->fetchColumn();
            
            // Active staff (users with role 'user' who are active)
            $query = "SELECT COUNT(*) as active_staff FROM users WHERE role <> 'admin' AND is_active = 1";
            $stmt = $this->conn->query($query);
            $stats['active_staff'] = (int)$stmt->fetchColumn();
            
            // Pending orders (ONLY orders with status 'pending')
            $query = "SELECT COUNT(*) as pending_orders FROM service_orders WHERE status = 'pending'";
            $stmt = $this->conn->query($query);
            $stats['pending_orders'] = (int)$stmt->fetchColumn();
            
            // Active orders (orders that are in progress but not pending, completed, delivered or cancelled)
            $query = "SELECT COUNT(*) as active_orders FROM service_orders WHERE status IN ('scheduled', 'process', 'ready')";
            $stmt = $this->conn->query($query);
            $stats['active_orders'] = (int)$stmt->fetchColumn();
            
            // Completed orders
            $query = "SELECT COUNT(*) as completed_orders FROM service_orders WHERE status = 'completed'";
            $stmt = $this->conn->query($query);
            $stats['completed_orders'] = (int)$stmt->fetchColumn();
            
            // Delivered orders from real delivery records.
            $query = "SELECT COUNT(*) as delivered_orders
                     FROM deliveries
                     WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('delivered', 'deliveryed')
                        OR (
                            delivered_date IS NOT NULL
                            AND delivered_date <> ''
                            AND delivered_date <> '0000-00-00 00:00:00'
                        )";
            $stmt = $this->conn->query($query);
            $stats['delivered_orders'] = (int)$stmt->fetchColumn();
            
            // Total service margin from service orders (final_cost - deposit_amount)
            $query = "SELECT COALESCE(SUM(COALESCE(final_cost, 0) - COALESCE(deposit_amount, 0)), 0) as total_revenue
                     FROM service_orders
                     WHERE payment_status <> 'refunded'";
            $stmt = $this->conn->query($query);
            $stats['total_revenue'] = (float)$stmt->fetchColumn();
            
            // Today's orders
            $query = "SELECT COUNT(*) as today_orders FROM service_orders WHERE DATE(created_at) = CURDATE()";
            $stmt = $this->conn->query($query);
            $stats['today_orders'] = (int)$stmt->fetchColumn();
            
            // Today's service margin from service orders (final_cost - deposit_amount)
            $query = "SELECT COALESCE(SUM(COALESCE(final_cost, 0) - COALESCE(deposit_amount, 0)), 0) as today_revenue
                     FROM service_orders
                     WHERE DATE(created_at) = CURDATE()
                     AND payment_status <> 'refunded'";
            $stmt = $this->conn->query($query);
            $stats['today_revenue'] = (float)$stmt->fetchColumn();
            
            // Active products
            $query = "SELECT COUNT(*) as active_products FROM products WHERE status = 'active'";
            $stmt = $this->conn->query($query);
            $stats['active_products'] = (int)$stmt->fetchColumn();
            
            // Low stock products
            // Some deployments use a products table without stock columns, so only query
            // low-stock counts when both columns exist.
            $stockColumnQuery = "SELECT COUNT(*) 
                               FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = 'products'
                               AND COLUMN_NAME IN ('stock_quantity', 'min_stock_level')";
            $stmt = $this->conn->query($stockColumnQuery);
            $stockColumnCount = (int)$stmt->fetchColumn();

            if ($stockColumnCount === 2) {
                $query = "SELECT COUNT(*) as low_stock_products FROM products 
                         WHERE stock_quantity <= min_stock_level AND status = 'active'";
                $stmt = $this->conn->query($query);
                $stats['low_stock_products'] = (int)$stmt->fetchColumn();
            } else {
                $stats['low_stock_products'] = 0;
            }
            
            // Average order value
            $query = "SELECT COALESCE(AVG(final_cost), 0) as avg_order_value 
                     FROM service_orders 
                     WHERE final_cost > 0";
            $stmt = $this->conn->query($query);
            $stats['avg_order_value'] = (float)$stmt->fetchColumn();
            
            $this->sendSuccess([
                'stats' => $stats
            ]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get dashboard stats: " . $e->getMessage(), 500);
        }
    }
    
    private function getUsers() {
        try {
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $role = isset($_GET['role']) ? $_GET['role'] : '';
            
            $query = "SELECT id, name, email, phone, role, is_active, last_login, created_at, 
                     profile_image, department
                     FROM users WHERE 1=1";
            
            $params = [];
            $types = [];
            
            if (!empty($search)) {
                $query .= " AND (name LIKE :search OR email LIKE :search OR phone LIKE :search)";
                $params[':search'] = "%$search%";
                $types[':search'] = PDO::PARAM_STR;
            }
            
            if (!empty($role) && $role !== 'all') {
                $query .= " AND role = :role";
                $params[':role'] = $role;
                $types[':role'] = PDO::PARAM_STR;
            }
            
            $query .= " ORDER BY created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, $types[$key] ?? PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendSuccess(['users' => $users]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get users: " . $e->getMessage(), 500);
        }
    }
    
    private function createUser() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            // Validate input
            if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
                $this->sendError("Name, email, and password are required", 400);
                return;
            }
            
            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->sendError("Invalid email format", 400);
                return;
            }
            
            // Check if email exists
            $checkQuery = "SELECT id FROM users WHERE email = :email";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':email', $data['email'], PDO::PARAM_STR);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                $this->sendError("Email already exists", 400);
                return;
            }
            
            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
            
            // Set role to 'user' by default
            $role = isset($data['role']) ? $data['role'] : 'user';
            $phone = isset($data['phone']) ? $data['phone'] : '';
            $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
            $department = isset($data['department']) ? $data['department'] : 'general';
            $profile_image = isset($data['profile_image']) ? $data['profile_image'] : null;
            
            $query = "INSERT INTO users (name, email, password, phone, role, is_active, department, profile_image, created_at) 
                     VALUES (:name, :email, :password, :phone, :role, :is_active, :department, :profile_image, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
            $stmt->bindValue(':email', $data['email'], PDO::PARAM_STR);
            $stmt->bindValue(':password', $hashedPassword, PDO::PARAM_STR);
            $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
            $stmt->bindValue(':role', $role, PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $is_active, PDO::PARAM_INT);
            $stmt->bindValue(':department', $department, PDO::PARAM_STR);
            $stmt->bindValue(':profile_image', $profile_image, $profile_image ? PDO::PARAM_STR : PDO::PARAM_NULL);
            
            if ($stmt->execute()) {
                $user_id = $this->conn->lastInsertId();
                
                $this->sendSuccess([
                    'message' => 'User created successfully',
                    'user_id' => $user_id
                ]);
            } else {
                $this->sendError("Failed to create user", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to create user: " . $e->getMessage(), 500);
        }
    }
    
    private function updateUser() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data['id'])) {
                $this->sendError("User ID is required", 400);
                return;
            }
            
            // Check if user exists
            $checkQuery = "SELECT id FROM users WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendError("User not found", 404);
                return;
            }
            
            $query = "UPDATE users SET name = :name, email = :email, phone = :phone, 
                     role = :role, is_active = :is_active, department = :department,
                     profile_image = :profile_image, updated_at = NOW() 
                     WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
            $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
            $stmt->bindValue(':email', $data['email'], PDO::PARAM_STR);
            $stmt->bindValue(':phone', isset($data['phone']) ? $data['phone'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':role', $data['role'], PDO::PARAM_STR);
            $stmt->bindValue(':is_active', isset($data['is_active']) ? (int)$data['is_active'] : 1, PDO::PARAM_INT);
            $stmt->bindValue(':department', isset($data['department']) ? $data['department'] : 'general', PDO::PARAM_STR);
            $stmt->bindValue(':profile_image', isset($data['profile_image']) ? $data['profile_image'] : null, 
                           isset($data['profile_image']) ? PDO::PARAM_STR : PDO::PARAM_NULL);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'User updated successfully']);
            } else {
                $this->sendError("Failed to update user", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to update user: " . $e->getMessage(), 500);
        }
    }
    
    private function deleteUser() {
        try {
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$id) {
                $this->sendError("User ID is required", 400);
                return;
            }
            
            // Don't allow deleting admin users
            $checkQuery = "SELECT role FROM users WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendError("User not found", 404);
                return;
            }
            
            $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user['role'] === 'admin') {
                $this->sendError("Cannot delete admin users", 400);
                return;
            }
            
            $query = "DELETE FROM users WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'User deleted successfully']);
            } else {
                $this->sendError("Failed to delete user", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to delete user: " . $e->getMessage(), 500);
        }
    }
    
    private function getOrders() {
        try {
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
            $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
            $exclude_delivered = isset($_GET['exclude_delivered']) ? $_GET['exclude_delivered'] : false;
            $serviceOrdersHasCompanyId = $this->tableHasColumn('service_orders', 'company_id');
            $companySelect = $serviceOrdersHasCompanyId ? ", co.company_name as company_name" : ", '' as company_name";
            $companyJoin = $serviceOrdersHasCompanyId ? " LEFT JOIN companies co ON o.company_id = co.id " : "";
            
            $query = "SELECT o.*, c.full_name as client_name, c.phone as client_phone, c.email as client_email,
                     c.address as client_address, p.product_name, p.brand, p.model, rp.product_name as replacement_product_name, u.name as staff_name
                     {$companySelect}
                     FROM service_orders o 
                     LEFT JOIN clients c ON o.client_id = c.id 
                     LEFT JOIN products p ON o.product_id = p.id 
                     LEFT JOIN products rp ON o.replacement_product_id = rp.id
                     LEFT JOIN users u ON o.staff_id = u.id
                     {$companyJoin}
                     WHERE 1=1";
            
            $params = [];
            $types = [];
            
            if (!empty($search)) {
                $query .= " AND (o.order_code LIKE :search OR c.full_name LIKE :search 
                           OR p.product_name LIKE :search OR rp.product_name LIKE :search OR u.name LIKE :search";
                if ($serviceOrdersHasCompanyId) {
                    $query .= " OR co.company_name LIKE :search";
                }
                $query .= ")";
                $params[':search'] = "%$search%";
                $types[':search'] = PDO::PARAM_STR;
            }
            
            if (!empty($status) && $status !== 'all') {
                $query .= " AND o.status = :status";
                $params[':status'] = $status;
                $types[':status'] = PDO::PARAM_STR;
            }
            
            if ($exclude_delivered) {
                $query .= " AND o.status != 'delivered'";
            }
            
            if (!empty($date_from)) {
                $query .= " AND DATE(o.created_at) >= :date_from";
                $params[':date_from'] = $date_from;
                $types[':date_from'] = PDO::PARAM_STR;
            }
            
            if (!empty($date_to)) {
                $query .= " AND DATE(o.created_at) <= :date_to";
                $params[':date_to'] = $date_to;
                $types[':date_to'] = PDO::PARAM_STR;
            }
            
            $query .= " ORDER BY o.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, $types[$key] ?? PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($orders)) {
                $orderIds = array_map('intval', array_column($orders, 'id'));
                $productMap = $this->fetchOrderProductsMap($orderIds);

                $stored_primary_by_order = [];
                $stored_replacement_by_order = [];
                $stored_ids = [];

                foreach ($orders as $order) {
                    $orderId = (int)$order['id'];

                    if (array_key_exists('product_ids', $order)) {
                        $ids = $this->normalizeIdList($order['product_ids']);
                        if (!empty($ids)) {
                            $stored_primary_by_order[$orderId] = $ids;
                            $stored_ids = array_merge($stored_ids, $ids);
                        }
                    }

                    if (array_key_exists('replacement_product_ids', $order)) {
                        $ids = $this->normalizeIdList($order['replacement_product_ids']);
                        if (!empty($ids)) {
                            $stored_replacement_by_order[$orderId] = $ids;
                            $stored_ids = array_merge($stored_ids, $ids);
                        }
                    }
                }

                $stored_names_map = !empty($stored_ids) ? $this->fetchProductNamesByIds($stored_ids) : [];

                foreach ($orders as &$order) {
                    $orderId = (int)$order['id'];
                    $primary = $productMap[$orderId]['primary'] ?? null;
                    $replacement = $productMap[$orderId]['replacement'] ?? null;
                    $primary_json = $stored_primary_by_order[$orderId] ?? [];
                    $replacement_json = $stored_replacement_by_order[$orderId] ?? [];

                    if (!empty($primary_json)) {
                        $order['product_ids'] = $primary_json;
                        $order['product_names'] = $this->buildNamesFromIds($primary_json, $stored_names_map);
                    } elseif ($primary) {
                        $order['product_ids'] = $primary['ids'];
                        $order['product_names'] = $primary['names'];
                    } else {
                        $primaryId = isset($order['product_id']) ? (int)$order['product_id'] : 0;
                        $order['product_ids'] = $primaryId > 0 ? [$primaryId] : [];
                        $order['product_names'] = !empty($order['product_name']) ? [$order['product_name']] : [];
                    }

                    if (!empty($replacement_json)) {
                        $order['replacement_product_ids'] = $replacement_json;
                        $order['replacement_product_names'] = $this->buildNamesFromIds($replacement_json, $stored_names_map);
                    } elseif ($replacement) {
                        $order['replacement_product_ids'] = $replacement['ids'];
                        $order['replacement_product_names'] = $replacement['names'];
                    } else {
                        $replacementId = isset($order['replacement_product_id']) ? (int)$order['replacement_product_id'] : 0;
                        $order['replacement_product_ids'] = $replacementId > 0 ? [$replacementId] : [];
                        $order['replacement_product_names'] = !empty($order['replacement_product_name']) ? [$order['replacement_product_name']] : [];
                    }
                }
                unset($order);
            }
            
            $this->sendSuccess(['orders' => $orders]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get orders: " . $e->getMessage(), 500);
        }
    }
    
    private function createOrder() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            // Validate required fields
            $required = ['client_name', 'client_phone'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->sendError("$field is required", 400);
                    return;
                }
            }
            
            // Start transaction
            $this->conn->beginTransaction();
            
            try {
                // First, check if client exists or create new
                $clientQuery = "SELECT id FROM clients WHERE phone = :phone LIMIT 1";
                $clientStmt = $this->conn->prepare($clientQuery);
                $clientStmt->bindValue(':phone', $data['client_phone'], PDO::PARAM_STR);
                $clientStmt->execute();
                
                $client_id = isset($data['client_id']) && !empty($data['client_id']) ? (int)$data['client_id'] : null;
                if ($client_id) {
                    $existingClientQuery = "SELECT id FROM clients WHERE id = :id LIMIT 1";
                    $existingClientStmt = $this->conn->prepare($existingClientQuery);
                    $existingClientStmt->bindValue(':id', $client_id, PDO::PARAM_INT);
                    $existingClientStmt->execute();

                    if ($existingClientStmt->rowCount() === 0) {
                        $client_id = null;
                    } else {
                        $updateClientQuery = "UPDATE clients SET full_name = :full_name, phone = :phone, updated_at = NOW() WHERE id = :id";
                        $updateClientStmt = $this->conn->prepare($updateClientQuery);
                        $updateClientStmt->bindValue(':full_name', $data['client_name'], PDO::PARAM_STR);
                        $updateClientStmt->bindValue(':phone', $data['client_phone'], PDO::PARAM_STR);
                        $updateClientStmt->bindValue(':id', $client_id, PDO::PARAM_INT);
                        $updateClientStmt->execute();
                    }
                }

                if (!$client_id) {
                    if ($clientStmt->rowCount() > 0) {
                        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
                        $client_id = $client['id'];
                        
                        // Update client name if different
                        $updateClientQuery = "UPDATE clients SET full_name = :full_name, updated_at = NOW() WHERE id = :id";
                        $updateClientStmt = $this->conn->prepare($updateClientQuery);
                        $updateClientStmt->bindValue(':full_name', $data['client_name'], PDO::PARAM_STR);
                        $updateClientStmt->bindValue(':id', $client_id, PDO::PARAM_INT);
                        $updateClientStmt->execute();
                    } else {
                        // Create new client only when no valid client_id and no existing phone match.
                        $client_code = 'CLT' . date('Ymd') . strtoupper(substr(uniqid(), -6));
                        $clientInsert = "INSERT INTO clients (client_code, full_name, phone, email, address, created_at) 
                                       VALUES (:client_code, :full_name, :phone, :email, :address, NOW())";
                        $clientInsertStmt = $this->conn->prepare($clientInsert);
                        $clientInsertStmt->bindValue(':client_code', $client_code, PDO::PARAM_STR);
                        $clientInsertStmt->bindValue(':full_name', $data['client_name'], PDO::PARAM_STR);
                        $clientInsertStmt->bindValue(':phone', $data['client_phone'], PDO::PARAM_STR);
                        $clientInsertStmt->bindValue(':email', isset($data['client_email']) ? $data['client_email'] : '', PDO::PARAM_STR);
                        $clientInsertStmt->bindValue(':address', isset($data['client_address']) ? $data['client_address'] : '', PDO::PARAM_STR);
                        
                        if ($clientInsertStmt->execute()) {
                            $client_id = $this->conn->lastInsertId();
                        } else {
                            throw new Exception("Failed to create client");
                        }
                    }
                }
                
                $product_ids = $this->normalizeIdList($data['product_ids'] ?? ($data['product_id'] ?? null));
                $replacement_product_ids = $this->normalizeIdList($data['replacement_product_ids'] ?? ($data['replacement_product_id'] ?? null));
                $product_name = isset($data['product_name']) ? trim((string)$data['product_name']) : '';

                if (empty($product_ids) && $product_name === '') {
                    $this->sendError("Product is required", 400);
                    return;
                }

                $product_id = null;
                if (!empty($product_ids)) {
                    $product_id = (int)$product_ids[0];
                } else {
                    // Check if product exists or create placeholder
                    $productQuery = "SELECT id FROM products WHERE product_name LIKE :product_name LIMIT 1";
                    $productStmt = $this->conn->prepare($productQuery);
                    $productStmt->bindValue(':product_name', "%$product_name%", PDO::PARAM_STR);
                    $productStmt->execute();
                    
                    if ($productStmt->rowCount() > 0) {
                        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                        $product_id = $product['id'];
                    } else {
                        // Create placeholder product
                        $product_code = 'PRD' . date('Ymd') . strtoupper(substr(uniqid(), -6));
                        $productInsert = "INSERT INTO products (product_code, product_name, category, price, stock_quantity, status, created_at) 
                                        VALUES (:product_code, :product_name, 'other', 0, :stock_quantity, 'active', NOW())";
                        $productInsertStmt = $this->conn->prepare($productInsert);
                        $productInsertStmt->bindValue(':product_code', $product_code, PDO::PARAM_STR);
                        $productInsertStmt->bindValue(':product_name', $product_name, PDO::PARAM_STR);
                        $productInsertStmt->bindValue(':stock_quantity', 1, PDO::PARAM_INT);
                        
                        if ($productInsertStmt->execute()) {
                            $product_id = $this->conn->lastInsertId();
                        } else {
                            $product_id = 0;
                        }
                    }

                    if ($product_id) {
                        $product_ids = [$product_id];
                    }
                }

                $product_ids_json = !empty($product_ids) ? json_encode($product_ids) : null;
                $replacement_product_ids_json = !empty($replacement_product_ids) ? json_encode($replacement_product_ids) : null;
                
                // Generate order code
                $order_code = 'ORD' . date('Ymd') . strtoupper(substr(uniqid(), -6));
                
                // Get amounts
                $estimated_cost = isset($data['estimated_cost']) ? floatval($data['estimated_cost']) : 0;
                $final_cost = isset($data['final_cost']) ? floatval($data['final_cost']) : $estimated_cost;
                $deposit_amount = isset($data['deposit_amount']) ? floatval($data['deposit_amount']) : 0;
                $payment_status = isset($data['payment_status']) ? $data['payment_status'] : 'pending';
                $service_type = isset($data['service_type']) && trim((string)$data['service_type']) !== ''
                    ? trim((string)$data['service_type'])
                    : 'general';
                $serviceOrdersHasCompanyId = $this->tableHasColumn('service_orders', 'company_id');
                $serviceOrdersHasIssueDescriptionMap = $this->tableHasColumn('service_orders', 'issue_description_map');
                $company_id = null;
                if ($serviceOrdersHasCompanyId && array_key_exists('company_id', $data)) {
                    $company_id = $this->normalizeExistingCompanyId($data['company_id']);
                }
                $incomingIssueMap = $this->normalizeIssueDescriptionMap($data['issue_description_map'] ?? null);
                $normalizedIssueMap = [];
                foreach ($product_ids as $pid) {
                    $key = (string)$pid;
                    $normalizedIssueMap[$key] = isset($incomingIssueMap[$key]) ? trim((string)$incomingIssueMap[$key]) : '';
                }
                $issue_description_map_json = !empty($normalizedIssueMap) ? json_encode($normalizedIssueMap) : '{}';
                $legacy_issue_description = 'N/A';
                foreach ($normalizedIssueMap as $issueText) {
                    $candidate = trim((string)$issueText);
                    if ($candidate !== '') {
                        $legacy_issue_description = $candidate;
                        break;
                    }
                }
                if (isset($data['issue_description']) && trim((string)$data['issue_description']) !== '') {
                    $legacy_issue_description = trim((string)$data['issue_description']);
                }
                
                // Create order
                $orderCompanyColumns = $serviceOrdersHasCompanyId ? ", company_id" : "";
                $orderCompanyValues = $serviceOrdersHasCompanyId ? ", :company_id" : "";
                $orderIssueMapColumns = $serviceOrdersHasIssueDescriptionMap ? ", issue_description_map" : "";
                $orderIssueMapValues = $serviceOrdersHasIssueDescriptionMap ? ", :issue_description_map" : "";
                $orderQuery = "INSERT INTO service_orders (order_code, client_id, product_id, product_ids, replacement_product_id, replacement_product_ids, staff_id, service_type,
                             issue_description, warranty_status, estimated_cost, final_cost, deposit_amount, 
                             payment_status, estimated_delivery_date, status, priority, notes{$orderCompanyColumns}{$orderIssueMapColumns}, created_at)
                             VALUES (:order_code, :client_id, :product_id, :product_ids, :replacement_product_id, :replacement_product_ids, :staff_id, :service_type,
                             :issue_description, :warranty_status, :estimated_cost, :final_cost, :deposit_amount,
                             :payment_status, :estimated_delivery_date, :status, :priority, :notes{$orderCompanyValues}{$orderIssueMapValues}, NOW())";
                
                $orderStmt = $this->conn->prepare($orderQuery);
                $orderStmt->bindValue(':order_code', $order_code, PDO::PARAM_STR);
                $orderStmt->bindValue(':client_id', $client_id, PDO::PARAM_INT);
                $orderStmt->bindValue(':product_id', $product_id, PDO::PARAM_INT);
                $orderStmt->bindValue(':product_ids', $product_ids_json, $product_ids_json ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $orderStmt->bindValue(':replacement_product_id', !empty($replacement_product_ids) ? (int)$replacement_product_ids[0] : null, !empty($replacement_product_ids) ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $orderStmt->bindValue(':replacement_product_ids', $replacement_product_ids_json, $replacement_product_ids_json ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $orderStmt->bindValue(':staff_id', isset($data['staff_id']) && !empty($data['staff_id']) ? $data['staff_id'] : null, PDO::PARAM_INT);
                $orderStmt->bindValue(':service_type', $service_type, PDO::PARAM_STR);
                $orderStmt->bindValue(':issue_description', $legacy_issue_description, PDO::PARAM_STR);
                $orderStmt->bindValue(':warranty_status', isset($data['warranty_status']) ? $data['warranty_status'] : 'out_of_warranty', PDO::PARAM_STR);
                $orderStmt->bindValue(':estimated_cost', $estimated_cost, PDO::PARAM_STR);
                $orderStmt->bindValue(':final_cost', $final_cost, PDO::PARAM_STR);
                $orderStmt->bindValue(':deposit_amount', $deposit_amount, PDO::PARAM_STR);
                $orderStmt->bindValue(':payment_status', $payment_status, PDO::PARAM_STR);
                $orderStmt->bindValue(':estimated_delivery_date', isset($data['estimated_delivery_date']) ? $data['estimated_delivery_date'] : date('Y-m-d', strtotime('+7 days')), PDO::PARAM_STR);
                $orderStmt->bindValue(':status', isset($data['status']) ? $data['status'] : 'pending', PDO::PARAM_STR);
                $orderStmt->bindValue(':priority', isset($data['priority']) ? $data['priority'] : 'medium', PDO::PARAM_STR);
                $orderStmt->bindValue(':notes', isset($data['notes']) ? $data['notes'] : '', PDO::PARAM_STR);
                if ($serviceOrdersHasCompanyId) {
                    $orderStmt->bindValue(':company_id', $company_id, $company_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
                }
                if ($serviceOrdersHasIssueDescriptionMap) {
                    $orderStmt->bindValue(':issue_description_map', $issue_description_map_json, PDO::PARAM_STR);
                }
                
                if ($orderStmt->execute()) {
                    $order_id = $this->conn->lastInsertId();

                    $this->syncOrderProducts((int)$order_id, $product_ids, false);
                    $this->syncOrderProducts((int)$order_id, $replacement_product_ids, true);
                    $this->updateServiceOrderExtendedFields((int)$order_id, $data, $product_ids);
                    
                    // Create payment record if there's an amount
                    if ($final_cost > 0) {
                        $this->createPaymentForOrder($order_id, $order_code, $final_cost, $deposit_amount, $payment_status, $data);
                    }
                    
                    // Commit transaction
                    $this->conn->commit();
                    
                    $this->sendSuccess([
                        'message' => 'Order created successfully',
                        'order_id' => $order_id,
                        'order_code' => $order_code
                    ]);
                } else {
                    throw new Exception("Failed to create order");
                }
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to create order: " . $e->getMessage(), 500);
        }
    }
    
    private function createPaymentForOrder($order_id, $order_code, $final_cost, $deposit_amount, $payment_status, $order_data) {
        try {
            // Generate payment code
            $payment_code = 'PAY-' . $order_code . '-' . time();
            
            // Determine payment amount based on payment status
            $payment_amount = 0;
            $payment_method = isset($order_data['payment_method']) ? $order_data['payment_method'] : 'cash';
            $transaction_id = isset($order_data['transaction_id']) ? $order_data['transaction_id'] : null;
            $created_by = isset($order_data['created_by']) ? $order_data['created_by'] : $this->user['user_id'];
            
            if ($payment_status === 'paid') {
                $payment_amount = $final_cost;
            } elseif ($payment_status === 'partially_paid' && $deposit_amount > 0) {
                $payment_amount = $deposit_amount;
            } else {
                // For pending or other statuses, don't create a payment record
                return;
            }
            
            // Create payment record
            $paymentQuery = "INSERT INTO payments (payment_code, order_id, estimated_cost, final_cost, 
                           deposit_amount, amount, payment_method, transaction_id, payment_status, 
                           notes, created_by, created_at)
                           VALUES (:payment_code, :order_id, :estimated_cost, :final_cost, 
                           :deposit_amount, :amount, :payment_method, :transaction_id, :payment_status,
                           :notes, :created_by, NOW())";
            
            $paymentStmt = $this->conn->prepare($paymentQuery);
            $paymentStmt->bindValue(':payment_code', $payment_code, PDO::PARAM_STR);
            $paymentStmt->bindValue(':order_id', $order_id, PDO::PARAM_INT);
            $paymentStmt->bindValue(':estimated_cost', isset($order_data['estimated_cost']) ? $order_data['estimated_cost'] : $final_cost, PDO::PARAM_STR);
            $paymentStmt->bindValue(':final_cost', $final_cost, PDO::PARAM_STR);
            $paymentStmt->bindValue(':deposit_amount', $deposit_amount, PDO::PARAM_STR);
            $paymentStmt->bindValue(':amount', $payment_amount, PDO::PARAM_STR);
            $paymentStmt->bindValue(':payment_method', $payment_method, PDO::PARAM_STR);
            $paymentStmt->bindValue(':transaction_id', $transaction_id, PDO::PARAM_STR);
            $paymentStmt->bindValue(':payment_status', $payment_status === 'partially_paid' ? 'paid' : $payment_status, PDO::PARAM_STR);
            $paymentStmt->bindValue(':notes', isset($order_data['payment_notes']) ? $order_data['payment_notes'] : 'Initial payment for order ' . $order_code, PDO::PARAM_STR);
            $paymentStmt->bindValue(':created_by', $created_by, PDO::PARAM_INT);
            
            return $paymentStmt->execute();
            
        } catch (Exception $e) {
            error_log("Failed to create payment for order {$order_id}: " . $e->getMessage());
            return false;
        }
    }
    
    private function updateOrder() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            $serviceOrdersHasCompanyId = $this->tableHasColumn('service_orders', 'company_id');
            $serviceOrdersHasIssueDescriptionMap = $this->tableHasColumn('service_orders', 'issue_description_map');
            
            if (empty($data['id'])) {
                $this->sendError("Order ID is required", 400);
                return;
            }
            
            // Start transaction
            $this->conn->beginTransaction();
            
            try {
                // Check if order exists
                $checkColumns = "id, order_code, payment_status, final_cost, deposit_amount, product_id, product_ids, replacement_product_id, replacement_product_ids";
                if ($serviceOrdersHasCompanyId) {
                    $checkColumns .= ", company_id";
                }
                $checkQuery = "SELECT {$checkColumns} FROM service_orders WHERE id = :id";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() === 0) {
                    throw new Exception("Order not found");
                }
                
                $existingOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                // Update client information if provided
                if (!empty($data['client_name']) && !empty($data['client_phone'])) {
                    $clientQuery = "UPDATE clients SET full_name = :full_name, phone = :phone, updated_at = NOW() 
                                  WHERE id = (SELECT client_id FROM service_orders WHERE id = :order_id)";
                    $clientStmt = $this->conn->prepare($clientQuery);
                    $clientStmt->bindValue(':full_name', $data['client_name'], PDO::PARAM_STR);
                    $clientStmt->bindValue(':phone', $data['client_phone'], PDO::PARAM_STR);
                    $clientStmt->bindValue(':order_id', $data['id'], PDO::PARAM_INT);
                    $clientStmt->execute();
                }
                
                // Update product information if provided
                if (!empty($data['product_name'])) {
                    $productQuery = "UPDATE products SET product_name = :product_name, updated_at = NOW() 
                                   WHERE id = (SELECT product_id FROM service_orders WHERE id = :order_id)";
                    $productStmt = $this->conn->prepare($productQuery);
                    $productStmt->bindValue(':product_name', $data['product_name'], PDO::PARAM_STR);
                    $productStmt->bindValue(':order_id', $data['id'], PDO::PARAM_INT);
                    $productStmt->execute();
                }

                $productIdsProvided = array_key_exists('product_ids', $data) || array_key_exists('product_id', $data);
                $replacementIdsProvided = array_key_exists('replacement_product_ids', $data) || array_key_exists('replacement_product_id', $data);
                $new_product_ids = null;
                $new_replacement_product_ids = null;
                $new_product_id = (int)($existingOrder['product_id'] ?? 0);
                $new_replacement_product_id = $existingOrder['replacement_product_id'] ?? null;
                $new_product_ids_json = $existingOrder['product_ids'] ?? null;
                $new_replacement_product_ids_json = $existingOrder['replacement_product_ids'] ?? null;
                $new_company_id = $serviceOrdersHasCompanyId ? $this->normalizeExistingCompanyId($existingOrder['company_id'] ?? null) : null;

                if ($productIdsProvided) {
                    $new_product_ids = $this->normalizeIdList($data['product_ids'] ?? ($data['product_id'] ?? null));
                    if (empty($new_product_ids)) {
                        throw new Exception("At least one product is required");
                    }
                    $new_product_id = (int)$new_product_ids[0];
                    $new_product_ids_json = json_encode($new_product_ids);
                }

                if ($replacementIdsProvided) {
                    $new_replacement_product_ids = $this->normalizeIdList($data['replacement_product_ids'] ?? ($data['replacement_product_id'] ?? null));
                    $new_replacement_product_id = !empty($new_replacement_product_ids) ? (int)$new_replacement_product_ids[0] : null;
                    $new_replacement_product_ids_json = !empty($new_replacement_product_ids) ? json_encode($new_replacement_product_ids) : null;
                }

                if ($serviceOrdersHasCompanyId && array_key_exists('company_id', $data)) {
                    $new_company_id = $this->normalizeExistingCompanyId($data['company_id']);
                }
                
                // Get new values
                $new_final_cost = isset($data['final_cost']) ? floatval($data['final_cost']) : floatval($existingOrder['final_cost']);
                $new_deposit_amount = isset($data['deposit_amount']) ? floatval($data['deposit_amount']) : floatval($existingOrder['deposit_amount']);
                $new_payment_status = isset($data['payment_status']) ? $data['payment_status'] : $existingOrder['payment_status'];
                $new_service_type = isset($data['service_type']) && trim((string)$data['service_type']) !== ''
                    ? trim((string)$data['service_type'])
                    : 'general';
                $incomingIssueMap = $this->normalizeIssueDescriptionMap($data['issue_description_map'] ?? null);
                $normalizedIssueMap = [];
                $targetIssueProductIds = !empty($new_product_ids) ? $new_product_ids : $this->normalizeIdList($existingOrder['product_ids'] ?? ($existingOrder['product_id'] ?? null));
                foreach ($targetIssueProductIds as $pid) {
                    $key = (string)$pid;
                    $normalizedIssueMap[$key] = isset($incomingIssueMap[$key]) ? trim((string)$incomingIssueMap[$key]) : '';
                }
                $issue_description_map_json = !empty($normalizedIssueMap) ? json_encode($normalizedIssueMap) : '{}';
                $legacy_issue_description = 'N/A';
                foreach ($normalizedIssueMap as $issueText) {
                    $candidate = trim((string)$issueText);
                    if ($candidate !== '') {
                        $legacy_issue_description = $candidate;
                        break;
                    }
                }
                if (isset($data['issue_description']) && trim((string)$data['issue_description']) !== '') {
                    $legacy_issue_description = trim((string)$data['issue_description']);
                }
                
                // Update order
                $query = "UPDATE service_orders SET 
                         product_id = :product_id,
                         product_ids = :product_ids,
                         replacement_product_id = :replacement_product_id,
                         replacement_product_ids = :replacement_product_ids,
                         service_type = :service_type,
                         issue_description = :issue_description,
                         " . ($serviceOrdersHasIssueDescriptionMap ? "issue_description_map = :issue_description_map," : "") . "
                         warranty_status = :warranty_status,
                         estimated_cost = :estimated_cost,
                         final_cost = :final_cost,
                         deposit_amount = :deposit_amount,
                         payment_status = :payment_status,
                         estimated_delivery_date = :estimated_delivery_date,
                         status = :status,
                         priority = :priority,
                         staff_id = :staff_id,
                         " . ($serviceOrdersHasCompanyId ? "company_id = :company_id," : "") . "
                         notes = :notes,
                         updated_at = NOW() 
                         WHERE id = :id";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
                $stmt->bindValue(':product_id', $new_product_id, PDO::PARAM_INT);
                $stmt->bindValue(':product_ids', $new_product_ids_json, $new_product_ids_json ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':replacement_product_id', $new_replacement_product_id, $new_replacement_product_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt->bindValue(':replacement_product_ids', $new_replacement_product_ids_json, $new_replacement_product_ids_json ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':service_type', $new_service_type, PDO::PARAM_STR);
                $stmt->bindValue(':issue_description', $legacy_issue_description, PDO::PARAM_STR);
                if ($serviceOrdersHasIssueDescriptionMap) {
                    $stmt->bindValue(':issue_description_map', $issue_description_map_json, PDO::PARAM_STR);
                }
                $stmt->bindValue(':warranty_status', $data['warranty_status'], PDO::PARAM_STR);
                $stmt->bindValue(':estimated_cost', $data['estimated_cost'], PDO::PARAM_STR);
                $stmt->bindValue(':final_cost', $new_final_cost, PDO::PARAM_STR);
                $stmt->bindValue(':deposit_amount', $new_deposit_amount, PDO::PARAM_STR);
                $stmt->bindValue(':payment_status', $new_payment_status, PDO::PARAM_STR);
                $stmt->bindValue(':estimated_delivery_date', $data['estimated_delivery_date'], PDO::PARAM_STR);
                $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
                $stmt->bindValue(':priority', $data['priority'], PDO::PARAM_STR);
                $stmt->bindValue(':staff_id', isset($data['staff_id']) && !empty($data['staff_id']) ? $data['staff_id'] : null, PDO::PARAM_INT);
                if ($serviceOrdersHasCompanyId) {
                    $stmt->bindValue(':company_id', $new_company_id, $new_company_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
                }
                $stmt->bindValue(':notes', isset($data['notes']) ? $data['notes'] : '', PDO::PARAM_STR);
                
                if ($stmt->execute()) {
                    if ($productIdsProvided && !is_null($new_product_ids)) {
                        $this->syncOrderProducts((int)$data['id'], $new_product_ids, false);
                    }

                    if ($replacementIdsProvided) {
                        $this->syncOrderProducts((int)$data['id'], $new_replacement_product_ids ?? [], true);
                    }
                    $effectiveProductIds = !empty($new_product_ids)
                        ? $new_product_ids
                        : $this->normalizeIdList($existingOrder['product_ids'] ?? ($existingOrder['product_id'] ?? null));
                    $this->updateServiceOrderExtendedFields((int)$data['id'], $data, $effectiveProductIds);

                    // Check if payment status changed and create payment record if needed
                    if ($new_payment_status !== $existingOrder['payment_status'] || 
                        $new_final_cost !== floatval($existingOrder['final_cost']) ||
                        $new_deposit_amount !== floatval($existingOrder['deposit_amount'])) {
                        
                        // Create payment record for the change
                        $this->createPaymentForOrderUpdate($data['id'], $existingOrder['order_code'], 
                                                          $new_final_cost, $new_deposit_amount, 
                                                          $new_payment_status, $data, $existingOrder);
                    }
                    
                    // Commit transaction
                    $this->conn->commit();
                    
                    $this->sendSuccess(['message' => 'Order updated successfully']);
                } else {
                    throw new Exception("Failed to update order");
                }
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to update order: " . $e->getMessage(), 500);
        }
    }
    
    private function createPaymentForOrderUpdate($order_id, $order_code, $final_cost, $deposit_amount, $payment_status, $update_data, $existing_order) {
        try {
            // Check if there are existing payments for this order
            $checkPaymentQuery = "SELECT COUNT(*) as payment_count FROM payments WHERE order_id = :order_id";
            $checkPaymentStmt = $this->conn->prepare($checkPaymentQuery);
            $checkPaymentStmt->bindValue(':order_id', $order_id, PDO::PARAM_INT);
            $checkPaymentStmt->execute();
            $payment_count = $checkPaymentStmt->fetch(PDO::FETCH_ASSOC)['payment_count'];
            
            // Generate payment code
            $payment_code = 'PAY-' . $order_code . '-' . time();
            
            // Determine payment amount based on payment status
            $payment_amount = 0;
            $payment_method = isset($update_data['payment_method']) ? $update_data['payment_method'] : 'cash';
            $transaction_id = isset($update_data['transaction_id']) ? $update_data['transaction_id'] : null;
            $created_by = $this->user['user_id'];
            
            if ($payment_status === 'paid' && $payment_count == 0) {
                // First payment for full amount
                $payment_amount = $final_cost;
            } elseif ($payment_status === 'partially_paid' && $deposit_amount > 0) {
                // Deposit payment
                $payment_amount = $deposit_amount;
            } elseif ($payment_status === 'paid' && $existing_order['payment_status'] === 'partially_paid') {
                // Balance payment
                $paid_amount = $deposit_amount;
                $balance_amount = $final_cost - $paid_amount;
                if ($balance_amount > 0) {
                    $payment_amount = $balance_amount;
                }
            } else {
                // No payment needed
                return;
            }
            
            if ($payment_amount > 0) {
                // Create payment record
                $paymentQuery = "INSERT INTO payments (payment_code, order_id, estimated_cost, final_cost, 
                               deposit_amount, amount, payment_method, transaction_id, payment_status, 
                               notes, created_by, created_at)
                               VALUES (:payment_code, :order_id, :estimated_cost, :final_cost, 
                               :deposit_amount, :amount, :payment_method, :transaction_id, :payment_status,
                               :notes, :created_by, NOW())";
                
                $paymentStmt = $this->conn->prepare($paymentQuery);
                $paymentStmt->bindValue(':payment_code', $payment_code, PDO::PARAM_STR);
                $paymentStmt->bindValue(':order_id', $order_id, PDO::PARAM_INT);
                $paymentStmt->bindValue(':estimated_cost', isset($update_data['estimated_cost']) ? $update_data['estimated_cost'] : $final_cost, PDO::PARAM_STR);
                $paymentStmt->bindValue(':final_cost', $final_cost, PDO::PARAM_STR);
                $paymentStmt->bindValue(':deposit_amount', $deposit_amount, PDO::PARAM_STR);
                $paymentStmt->bindValue(':amount', $payment_amount, PDO::PARAM_STR);
                $paymentStmt->bindValue(':payment_method', $payment_method, PDO::PARAM_STR);
                $paymentStmt->bindValue(':transaction_id', $transaction_id, PDO::PARAM_STR);
                $paymentStmt->bindValue(':payment_status', $payment_status === 'partially_paid' ? 'paid' : $payment_status, PDO::PARAM_STR);
                
                $notes = '';
                if ($payment_status === 'partially_paid') {
                    $notes = 'Deposit payment for order ' . $order_code;
                } elseif ($payment_status === 'paid' && $existing_order['payment_status'] === 'partially_paid') {
                    $notes = 'Balance payment for order ' . $order_code;
                } elseif ($payment_status === 'paid') {
                    $notes = 'Full payment for order ' . $order_code;
                }
                
                $paymentStmt->bindValue(':notes', isset($update_data['payment_notes']) ? $update_data['payment_notes'] : $notes, PDO::PARAM_STR);
                $paymentStmt->bindValue(':created_by', $created_by, PDO::PARAM_INT);
                
                return $paymentStmt->execute();
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to create payment for order update {$order_id}: " . $e->getMessage());
            return false;
        }
    }
    
    private function deleteOrder() {
        try {
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$id) {
                $this->sendError("Order ID is required", 400);
                return;
            }
            
            // Start transaction
            $this->conn->beginTransaction();
            
            try {
                // Check if order exists
                $checkQuery = "SELECT id FROM service_orders WHERE id = :id";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() === 0) {
                    throw new Exception("Order not found");
                }
                
                // Delete related payments first (cascade delete should handle this, but being explicit)
                $deletePaymentsQuery = "DELETE FROM payments WHERE order_id = :order_id";
                $deletePaymentsStmt = $this->conn->prepare($deletePaymentsQuery);
                $deletePaymentsStmt->bindValue(':order_id', $id, PDO::PARAM_INT);
                $deletePaymentsStmt->execute();

                $deleteProductsQuery = "DELETE FROM service_order_products WHERE order_id = :order_id";
                $deleteProductsStmt = $this->conn->prepare($deleteProductsQuery);
                $deleteProductsStmt->bindValue(':order_id', $id, PDO::PARAM_INT);
                $deleteProductsStmt->execute();
                
                // Delete the order
                $deleteOrderQuery = "DELETE FROM service_orders WHERE id = :id";
                $deleteOrderStmt = $this->conn->prepare($deleteOrderQuery);
                $deleteOrderStmt->bindValue(':id', $id, PDO::PARAM_INT);
                
                if ($deleteOrderStmt->execute()) {
                    $this->conn->commit();
                    $this->sendSuccess(['message' => 'Order deleted successfully']);
                } else {
                    throw new Exception("Failed to delete order");
                }
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to delete order: " . $e->getMessage(), 500);
        }
    }
    
    private function getClients() {
        try {
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            
            $query = "SELECT c.*, 
                     (SELECT COUNT(*) FROM service_orders WHERE client_id = c.id) as total_orders,
                     (SELECT COALESCE(SUM(final_cost), 0) FROM service_orders WHERE client_id = c.id AND payment_status = 'paid') as total_spent
                     FROM clients c WHERE 1=1";
            
            $params = [];
            $types = [];
            
            if (!empty($search)) {
                $query .= " AND (c.full_name LIKE :search OR c.email LIKE :search OR c.phone LIKE :search)";
                $params[':search'] = "%$search%";
                $types[':search'] = PDO::PARAM_STR;
            }
            
            $query .= " ORDER BY c.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, $types[$key] ?? PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendSuccess(['clients' => $clients]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get clients: " . $e->getMessage(), 500);
        }
    }
    
    private function createClient() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data['full_name']) || empty($data['phone'])) {
                $this->sendError("Full name and phone are required", 400);
                return;
            }
            
            // Check if client already exists with same phone
            $checkQuery = "SELECT id FROM clients WHERE phone = :phone";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':phone', $data['phone'], PDO::PARAM_STR);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                $this->sendError("Client with this phone number already exists", 400);
                return;
            }
            
            // Generate client code
            $client_code = 'CLT' . date('Ymd') . strtoupper(substr(uniqid(), -6));
            
            $query = "INSERT INTO clients (client_code, full_name, email, phone, address, 
                     city, state, zip_code, notes, created_at)
                     VALUES (:client_code, :full_name, :email, :phone, :address,
                     :city, :state, :zip_code, :notes, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':client_code', $client_code, PDO::PARAM_STR);
            $stmt->bindValue(':full_name', $data['full_name'], PDO::PARAM_STR);
            $stmt->bindValue(':email', isset($data['email']) ? $data['email'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':phone', $data['phone'], PDO::PARAM_STR);
            $stmt->bindValue(':address', isset($data['address']) ? $data['address'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':city', isset($data['city']) ? $data['city'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':state', isset($data['state']) ? $data['state'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':zip_code', isset($data['zip_code']) ? $data['zip_code'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':notes', isset($data['notes']) ? $data['notes'] : '', PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $client_id = $this->conn->lastInsertId();
                
                $this->sendSuccess([
                    'message' => 'Client created successfully',
                    'client_id' => $client_id,
                    'client_code' => $client_code
                ]);
            } else {
                $this->sendError("Failed to create client", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to create client: " . $e->getMessage(), 500);
        }
    }
    
    private function updateClient() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data['id'])) {
                $this->sendError("Client ID is required", 400);
                return;
            }
            
            // Check if client exists
            $checkQuery = "SELECT id FROM clients WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendError("Client not found", 404);
                return;
            }
            
            $query = "UPDATE clients SET full_name = :full_name, email = :email, phone = :phone,
                     address = :address, city = :city, state = :state, zip_code = :zip_code,
                     notes = :notes, updated_at = NOW() WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
            $stmt->bindValue(':full_name', $data['full_name'], PDO::PARAM_STR);
            $stmt->bindValue(':email', isset($data['email']) ? $data['email'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':phone', $data['phone'], PDO::PARAM_STR);
            $stmt->bindValue(':address', isset($data['address']) ? $data['address'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':city', isset($data['city']) ? $data['city'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':state', isset($data['state']) ? $data['state'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':zip_code', isset($data['zip_code']) ? $data['zip_code'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':notes', isset($data['notes']) ? $data['notes'] : '', PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'Client updated successfully']);
            } else {
                $this->sendError("Failed to update client", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to update client: " . $e->getMessage(), 500);
        }
    }
    
    private function deleteClient() {
        try {
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$id) {
                $this->sendError("Client ID is required", 400);
                return;
            }
            
            // Check if client exists
            $checkQuery = "SELECT id FROM clients WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendError("Client not found", 404);
                return;
            }
            
            // Check if client has orders
            $orderQuery = "SELECT COUNT(*) as order_count FROM service_orders WHERE client_id = :id";
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $orderStmt->execute();
            $orderCount = $orderStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($orderCount['order_count'] > 0) {
                $this->sendError("Cannot delete client with existing orders", 400);
                return;
            }
            
            $query = "DELETE FROM clients WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'Client deleted successfully']);
            } else {
                $this->sendError("Failed to delete client", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to delete client: " . $e->getMessage(), 500);
        }
    }
    
    private function getProducts() {
        try {
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $category = isset($_GET['category']) ? $_GET['category'] : '';
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            
            $query = "SELECT * FROM products WHERE 1=1";
            
            $params = [];
            $types = [];
            
            if (!empty($search)) {
                $query .= " AND (product_name LIKE :search OR brand LIKE :search OR model LIKE :search)";
                $params[':search'] = "%$search%";
                $types[':search'] = PDO::PARAM_STR;
            }
            
            if (!empty($category) && $category !== 'all') {
                $query .= " AND category = :category";
                $params[':category'] = $category;
                $types[':category'] = PDO::PARAM_STR;
            }
            
            if (!empty($status) && $status !== 'all') {
                $query .= " AND status = :status";
                $params[':status'] = $status;
                $types[':status'] = PDO::PARAM_STR;
            }
            
            $query .= " ORDER BY created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, $types[$key] ?? PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendSuccess(['products' => $products]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get products: " . $e->getMessage(), 500);
        }
    }
    
    private function createProduct() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            if (!is_array($data)) {
                $data = [];
            }
            $data = $this->normalizeProductPayload($data);

            $validClaimTypes = ['none', 'shop_claim', 'company_claim', 'sun_to_company', 'company_to_sun'];
            $validStatuses = ['active', 'inactive', 'discontinued', 'out_of_stock'];

            $insertProduct = function(array $row) use ($validClaimTypes, $validStatuses): array {
                $row = $this->normalizeProductPayload($row);

                if (empty($row['product_name']) || trim((string)$row['product_name']) === '') {
                    return ['success' => false, 'message' => 'Product name is required'];
                }

                $productName = trim((string)$row['product_name']);
                $serialNumber = isset($row['serial_number']) ? preg_replace('/\s+/', '', trim((string)$row['serial_number'])) : '';

                if ($serialNumber !== '') {
                    $serialCheck = $this->conn->prepare("SELECT id FROM products WHERE serial_number = :serial_number LIMIT 1");
                    $serialCheck->bindValue(':serial_number', $serialNumber, PDO::PARAM_STR);
                    $serialCheck->execute();
                    if ($serialCheck->fetch(PDO::FETCH_ASSOC)) {
                        return ['success' => false, 'message' => 'Serial number already exists'];
                    }
                }

                $category = isset($row['category']) && trim((string)$row['category']) !== ''
                    ? trim((string)$row['category'])
                    : 'other';

                $claimType = isset($row['claim_type']) ? strtolower(trim((string)$row['claim_type'])) : 'none';
                if (!in_array($claimType, $validClaimTypes, true)) {
                    $claimType = 'none';
                }

                $status = isset($row['status']) ? strtolower(trim((string)$row['status'])) : 'active';
                if (!in_array($status, $validStatuses, true)) {
                    $status = 'active';
                }

                $productCode = 'PRD' . date('Ymd') . strtoupper(substr(uniqid(), -6));

                $resolvedStockQuantity = isset($row['stock_quantity']) ? max(1, intval($row['stock_quantity'])) : 1;
                // If a concrete serial is provided, treat it as one physical unit.
                if ($serialNumber !== '') {
                    $resolvedStockQuantity = 1;
                }

                $query = "INSERT INTO products (
                            product_code, serial_number, is_spare_product, product_name, brand, model, category,
                            claim_type, specifications, purchase_date, warranty_period, price, stock_quantity, status, created_at
                          )
                          VALUES (
                            :product_code, :serial_number, :is_spare_product, :product_name, :brand, :model, :category,
                            :claim_type, :specifications, :purchase_date, :warranty_period, :price, :stock_quantity, :status, NOW()
                          )";

                $stmt = $this->conn->prepare($query);
                $stmt->bindValue(':product_code', $productCode, PDO::PARAM_STR);
                $stmt->bindValue(':serial_number', $serialNumber !== '' ? $serialNumber : null, PDO::PARAM_STR);
                $stmt->bindValue(':is_spare_product', !empty($row['is_spare_product']) ? 1 : 0, PDO::PARAM_INT);
                $stmt->bindValue(':product_name', $productName, PDO::PARAM_STR);
                $stmt->bindValue(':brand', isset($row['brand']) ? trim((string)$row['brand']) : '', PDO::PARAM_STR);
                $stmt->bindValue(':model', isset($row['model']) ? trim((string)$row['model']) : '', PDO::PARAM_STR);
                $stmt->bindValue(':category', $category, PDO::PARAM_STR);
                $stmt->bindValue(':claim_type', $claimType, PDO::PARAM_STR);
                $stmt->bindValue(':specifications', isset($row['specifications']) ? trim((string)$row['specifications']) : '', PDO::PARAM_STR);
                $stmt->bindValue(':purchase_date', isset($row['purchase_date']) && trim((string)$row['purchase_date']) !== '' ? $row['purchase_date'] : date('Y-m-d'), PDO::PARAM_STR);
                $stmt->bindValue(':warranty_period', isset($row['warranty_period']) && trim((string)$row['warranty_period']) !== '' ? trim((string)$row['warranty_period']) : '1 year', PDO::PARAM_STR);
                $stmt->bindValue(':price', isset($row['price']) ? (float)$row['price'] : 0);
                $stmt->bindValue(':stock_quantity', $resolvedStockQuantity, PDO::PARAM_INT);
                $stmt->bindValue(':status', $status, PDO::PARAM_STR);

                if (!$stmt->execute()) {
                    return ['success' => false, 'message' => 'Failed to create product'];
                }

                return [
                    'success' => true,
                    'product_id' => (int)$this->conn->lastInsertId(),
                    'product_code' => $productCode,
                    'product_name' => $productName
                ];
            };

            $isBatch = isset($data['products']) && is_array($data['products']);
            if ($isBatch) {
                $rows = $data['products'];
                if (count($rows) === 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Products array is empty']);
                    return;
                }

                $createdProducts = [];
                $errors = [];

                foreach ($rows as $index => $row) {
                    if (!is_array($row)) {
                        $errors[] = ['index' => $index, 'message' => 'Invalid row format'];
                        continue;
                    }

                    $normalizedRow = $this->normalizeProductPayload($row);
                    $result = $insertProduct($normalizedRow);
                    if (!empty($result['success'])) {
                        $createdProducts[] = [
                            'index' => $index,
                            'product_name' => $result['product_name'],
                            'product_id' => $result['product_id'],
                            'product_code' => $result['product_code']
                        ];
                    } else {
                        $errors[] = [
                            'index' => $index,
                            'product_name' => isset($normalizedRow['product_name']) ? trim((string)$normalizedRow['product_name']) : '',
                            'message' => $result['message'] ?? 'Failed to create product'
                        ];
                    }
                }

                $createdCount = count($createdProducts);
                $failedCount = count($errors);
                $allSuccess = $createdCount > 0 && $failedCount === 0;

                if ($createdCount === 0) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'No products were created',
                        'created_count' => 0,
                        'failed_count' => $failedCount,
                        'errors' => $errors
                    ]);
                    return;
                }

                http_response_code($allSuccess ? 201 : 207);
                echo json_encode([
                    'success' => $allSuccess,
                    'partial' => !$allSuccess,
                    'message' => $allSuccess
                        ? 'All products created successfully'
                        : 'Some products were created, but some rows failed',
                    'created_count' => $createdCount,
                    'failed_count' => $failedCount,
                    'created_products' => $createdProducts,
                    'errors' => $errors
                ]);
                return;
            }

            $result = $insertProduct($data);
            if (!empty($result['success'])) {
                http_response_code(201);
                $this->sendSuccess([
                    'message' => 'Product created successfully',
                    'product_id' => $result['product_id'],
                    'product_code' => $result['product_code']
                ]);
                return;
            }

            $this->sendError($result['message'] ?? 'Failed to create product', 400);
        } catch (Exception $e) {
            $this->sendError("Failed to create product: " . $e->getMessage(), 500);
        }
    }
    
    private function updateProduct() {
        try {
            $rawInput = file_get_contents("php://input");
            $data = json_decode($rawInput, true);
            if (!is_array($data)) {
                $data = [];
            }
            $data = $this->normalizeProductPayload($data);

            $resolvedId = null;
            if (!empty($_GET['id'])) {
                $resolvedId = $_GET['id'];
            } elseif (!empty($_REQUEST['id'])) {
                $resolvedId = $_REQUEST['id'];
            } elseif (!empty($data['id'])) {
                $resolvedId = $data['id'];
            } elseif (!empty($data['product_id'])) {
                $resolvedId = $data['product_id'];
            }

            if (empty($resolvedId) || intval($resolvedId) <= 0) {
                $this->sendError("Product ID is required", 400);
                return;
            }
            $data['id'] = intval($resolvedId);
            
            // Check if product exists
            $checkQuery = "SELECT * FROM products WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
            $checkStmt->execute();
            $existingProduct = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingProduct) {
                $this->sendError("Product not found", 404);
                return;
            }

            $serialNumber = isset($data['serial_number'])
                ? preg_replace('/\s+/', '', trim((string)$data['serial_number']))
                : (string)($existingProduct['serial_number'] ?? '');

            if ($serialNumber !== '') {
                $serialCheckQuery = "SELECT id FROM products WHERE serial_number = :serial_number AND id != :id LIMIT 1";
                $serialCheckStmt = $this->conn->prepare($serialCheckQuery);
                $serialCheckStmt->bindValue(':serial_number', $serialNumber, PDO::PARAM_STR);
                $serialCheckStmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
                $serialCheckStmt->execute();
                if ($serialCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                    $this->sendError("Serial number already exists", 400);
                    return;
                }
            }

            $validClaimTypes = ['none', 'shop_claim', 'company_claim', 'sun_to_company', 'company_to_sun'];
            $validStatuses = ['active', 'inactive', 'discontinued', 'out_of_stock'];

            $category = isset($data['category']) && trim((string)$data['category']) !== ''
                ? trim((string)$data['category'])
                : (string)($existingProduct['category'] ?? 'other');

            $claimType = isset($data['claim_type']) ? strtolower(trim((string)$data['claim_type'])) : strtolower((string)($existingProduct['claim_type'] ?? 'none'));
            if (!in_array($claimType, $validClaimTypes, true)) {
                $claimType = 'none';
            }

            $status = isset($data['status']) ? strtolower(trim((string)$data['status'])) : strtolower((string)($existingProduct['status'] ?? 'active'));
            if (!in_array($status, $validStatuses, true)) {
                $status = 'active';
            }
            
            $query = "UPDATE products
                     SET product_name = :product_name,
                         serial_number = :serial_number,
                         is_spare_product = :is_spare_product,
                         brand = :brand,
                         model = :model,
                         category = :category,
                         claim_type = :claim_type,
                         specifications = :specifications,
                         purchase_date = :purchase_date,
                         warranty_period = :warranty_period,
                         price = :price,
                         stock_quantity = :stock_quantity,
                         status = :status,
                         updated_at = NOW()
                     WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
            $stmt->bindValue(':product_name', isset($data['product_name']) && trim((string)$data['product_name']) !== '' ? trim((string)$data['product_name']) : (string)$existingProduct['product_name'], PDO::PARAM_STR);
            $stmt->bindValue(':serial_number', $serialNumber !== '' ? $serialNumber : null, PDO::PARAM_STR);
            $stmt->bindValue(':is_spare_product', isset($data['is_spare_product']) ? (!empty($data['is_spare_product']) ? 1 : 0) : (int)($existingProduct['is_spare_product'] ?? 0), PDO::PARAM_INT);
            $stmt->bindValue(':brand', isset($data['brand']) ? trim((string)$data['brand']) : (string)($existingProduct['brand'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':model', isset($data['model']) ? trim((string)$data['model']) : (string)($existingProduct['model'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':category', $category, PDO::PARAM_STR);
            $stmt->bindValue(':claim_type', $claimType, PDO::PARAM_STR);
            $stmt->bindValue(':specifications', isset($data['specifications']) ? trim((string)$data['specifications']) : (string)($existingProduct['specifications'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':purchase_date', isset($data['purchase_date']) && trim((string)$data['purchase_date']) !== '' ? $data['purchase_date'] : ($existingProduct['purchase_date'] ?? date('Y-m-d')), PDO::PARAM_STR);
            $stmt->bindValue(':warranty_period', isset($data['warranty_period']) && trim((string)$data['warranty_period']) !== '' ? trim((string)$data['warranty_period']) : (string)($existingProduct['warranty_period'] ?? '1 year'), PDO::PARAM_STR);
            $stmt->bindValue(':price', isset($data['price']) ? (float)$data['price'] : (float)($existingProduct['price'] ?? 0));
            $stockQuantity = isset($data['stock_quantity']) ? max(0, intval($data['stock_quantity'])) : intval($existingProduct['stock_quantity'] ?? 1);
            $stmt->bindValue(':stock_quantity', $stockQuantity, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'Product updated successfully']);
            } else {
                $this->sendError("Failed to update product", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to update product: " . $e->getMessage(), 500);
        }
    }
    
    private function deleteProduct() {
        try {
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$id) {
                $this->sendError("Product ID is required", 400);
                return;
            }
            
            // Check if product exists
            $checkQuery = "SELECT id FROM products WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendError("Product not found", 404);
                return;
            }
            
            // Check if product is used in orders
            $orderQuery = "SELECT COUNT(*) as order_count FROM service_orders WHERE product_id = :id";
            $orderStmt = $this->conn->prepare($orderQuery);
            $orderStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $orderStmt->execute();
            $orderCount = $orderStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($orderCount['order_count'] > 0) {
                // Instead of deleting, mark as discontinued
                $updateQuery = "UPDATE products SET status = 'discontinued', updated_at = NOW() WHERE id = :id";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
                
                if ($updateStmt->execute()) {
                    $this->sendSuccess(['message' => 'Product marked as discontinued']);
                } else {
                    $this->sendError("Failed to update product", 500);
                }
                return;
            }
            
            $query = "DELETE FROM products WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'Product deleted successfully']);
            } else {
                $this->sendError("Failed to delete product", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to delete product: " . $e->getMessage(), 500);
        }
    }
    
    private function getDeliveries() {
        try {
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
            $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
            
            $query = "SELECT d.*, o.order_code, c.full_name as client_name, p.product_name, p.serial_number AS product_serial_number, p.model AS product_model, p.brand AS product_brand
                     FROM deliveries d
                     LEFT JOIN service_orders o ON d.order_id = o.id
                     LEFT JOIN clients c ON o.client_id = c.id
                     LEFT JOIN products p ON COALESCE(d.product_id, o.product_id) = p.id
                     WHERE 1=1";
            
            $params = [];
            $types = [];
            
            if (!empty($status) && $status !== 'all') {
                $query .= " AND d.status = :status";
                $params[':status'] = $status;
                $types[':status'] = PDO::PARAM_STR;
            }
            
            if (!empty($date_from)) {
                $query .= " AND DATE(d.scheduled_date) >= :date_from";
                $params[':date_from'] = $date_from;
                $types[':date_from'] = PDO::PARAM_STR;
            }
            
            if (!empty($date_to)) {
                $query .= " AND DATE(d.scheduled_date) <= :date_to";
                $params[':date_to'] = $date_to;
                $types[':date_to'] = PDO::PARAM_STR;
            }
            
            $query .= " ORDER BY d.scheduled_date DESC, d.scheduled_time DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value, $types[$key] ?? PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendSuccess(['deliveries' => $deliveries]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get deliveries: " . $e->getMessage(), 500);
        }
    }
    
    private function updateDelivery() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data['id'])) {
                $this->sendError("Delivery ID is required", 400);
                return;
            }
            
            $normalizeDeliveryType = function ($value) {
                $normalized = strtolower(trim((string)$value));
                if ($normalized === 'in_hand' || $normalized === 'pickup') return 'inhand';
                if ($normalized === 'parcel_service' || $normalized === 'delivery') return 'parcelservice';
                if (in_array($normalized, ['inhand', 'courier', 'parcelservice'], true)) return $normalized;
                return 'inhand';
            };

            $query = "UPDATE deliveries SET status = :status, delivery_person = :delivery_person,
                     delivery_type = :delivery_type, notes = :notes, updated_at = NOW() WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $data['id'], PDO::PARAM_INT);
            $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
            $stmt->bindValue(':delivery_person', isset($data['delivery_person']) ? $data['delivery_person'] : '', PDO::PARAM_STR);
            $stmt->bindValue(':delivery_type', $normalizeDeliveryType($data['delivery_type'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':notes', isset($data['notes']) ? $data['notes'] : '', PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'Delivery updated successfully']);
            } else {
                $this->sendError("Failed to update delivery", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to update delivery: " . $e->getMessage(), 500);
        }
    }
    
    private function deleteDelivery() {
        try {
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$id) {
                $this->sendError("Delivery ID is required", 400);
                return;
            }
            
            // Check if delivery exists
            $checkQuery = "SELECT id FROM deliveries WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendError("Delivery not found", 404);
                return;
            }
            
            $query = "DELETE FROM deliveries WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'Delivery deleted successfully']);
            } else {
                $this->sendError("Failed to delete delivery", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to delete delivery: " . $e->getMessage(), 500);
        }
    }
    
    private function getStaffPerformance() {
        try {
            $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
            $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');

            $fromDate = DateTime::createFromFormat('Y-m-d', $date_from);
            $toDate = DateTime::createFromFormat('Y-m-d', $date_to);

            if (!$fromDate || $fromDate->format('Y-m-d') !== $date_from) {
                $date_from = date('Y-m-01');
            }

            if (!$toDate || $toDate->format('Y-m-d') !== $date_to) {
                $date_to = date('Y-m-d');
            }

            if ($date_from > $date_to) {
                [$date_from, $date_to] = [$date_to, $date_from];
            }
            
            $query = "SELECT u.id,
                     u.name,
                     u.email,
                     u.phone,
                     u.role,
                     u.avatar,
                     COALESCE(NULLIF(u.profile_image, ''), NULLIF(u.avatar, '')) as profile_image,
                     COALESCE(NULLIF(u.department, ''), 'Service') as department,
                     u.is_active,
                     u.last_login,
                     COUNT(o.id) as total_orders,
                     SUM(CASE WHEN o.status IN ('completed', 'delivered') THEN 1 ELSE 0 END) as completed_orders,
                     SUM(CASE WHEN o.status IN ('pending', 'scheduled', 'process', 'ready') THEN 1 ELSE 0 END) as active_orders,
                     COALESCE(SUM(CASE
                        WHEN o.status IN ('completed', 'delivered') AND o.payment_status <> 'refunded'
                        THEN (COALESCE(o.final_cost, 0) - COALESCE(o.deposit_amount, 0))
                        ELSE 0
                     END), 0) as total_revenue,
                     COALESCE(AVG(CASE
                        WHEN o.status IN ('completed', 'delivered') AND o.payment_status <> 'refunded' AND o.final_cost IS NOT NULL
                        THEN o.final_cost
                        ELSE NULL
                     END), 0) as avg_order_value,
                     COALESCE(AVG(CASE WHEN o.rating IS NOT NULL THEN o.rating ELSE NULL END), 0) as avg_rating
                     FROM users u
                     LEFT JOIN service_orders o ON u.id = o.staff_id 
                     AND DATE(o.created_at) BETWEEN :date_from AND :date_to
                     WHERE u.role <> 'admin' AND u.is_active = 1
                     GROUP BY u.id
                     ORDER BY completed_orders DESC, total_revenue DESC, total_orders DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':date_from', $date_from, PDO::PARAM_STR);
            $stmt->bindValue(':date_to', $date_to, PDO::PARAM_STR);
            $stmt->execute();
            
            $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate completion rate
            foreach ($staff as &$member) {
                $member['completion_rate'] = $member['total_orders'] > 0 
                    ? round(($member['completed_orders'] / $member['total_orders']) * 100, 2)
                    : 0;
                
                // Calculate performance score (weighted average of completion rate and revenue)
                $completion_score = $member['completion_rate'];
                $revenue_score = $member['total_revenue'] > 0 ? min(100, ($member['total_revenue'] / 10000) * 100) : 0;
                $member['performance_score'] = round(($completion_score * 0.7) + ($revenue_score * 0.3), 2);
                
                if ($member['last_login']) {
                    $member['last_login_formatted'] = date('M d, Y H:i', strtotime($member['last_login']));
                } else {
                    $member['last_login_formatted'] = 'Never';
                }
                
                if (empty($member['department'])) {
                    $member['department'] = 'Service';
                }
            }
            
            $this->sendSuccess([
                'staff' => $staff,
                'filters' => [
                    'date_from' => $date_from,
                    'date_to' => $date_to
                ]
            ]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get staff performance: " . $e->getMessage(), 500);
        }
    }
    
    private function getAnalytics() {
        try {
            $analytics = [];
            
            // Monthly service margin from service orders (final_cost - deposit_amount)
            $query = "SELECT DATE_FORMAT(o.created_at, '%Y-%m') as month,
                     COALESCE(SUM(COALESCE(o.final_cost, 0) - COALESCE(o.deposit_amount, 0)), 0) as revenue
                     FROM service_orders o
                     WHERE o.payment_status <> 'refunded'
                     AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                     GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
                     ORDER BY month";
            
            $stmt = $this->conn->query($query);
            $analytics['monthly_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Order trends (last 30 days)
            $query = "SELECT DATE(created_at) as date, COUNT(*) as orders
                     FROM service_orders 
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY DATE(created_at)
                     ORDER BY date";
            
            $stmt = $this->conn->query($query);
            $analytics['order_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Category distribution from orders
            $query = "SELECT p.category, COUNT(*) as count, COALESCE(SUM(COALESCE(o.final_cost, 0) - COALESCE(o.deposit_amount, 0)), 0) as value
                     FROM service_orders o
                     JOIN products p ON o.product_id = p.id
                     WHERE o.status NOT IN ('cancelled')
                     GROUP BY p.category
                     ORDER BY count DESC";
            
            $stmt = $this->conn->query($query);
            $analytics['category_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Order status distribution
            $query = "SELECT status, COUNT(*) as count
                     FROM service_orders
                     WHERE status NOT IN ('cancelled')
                     GROUP BY status
                     ORDER BY count DESC";
            
            $stmt = $this->conn->query($query);
            $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add colors for each status
            $statusColors = [
                'pending' => '#FFA500',
                'scheduled' => '#9b59b6',
                'process' => '#3498db',
                'ready' => '#2ecc71',
                'completed' => '#27ae60',
                'delivered' => '#16a085'
            ];
            
            foreach ($statusData as &$status) {
                $status['color'] = $statusColors[$status['status']] ?? '#95a5a6';
            }
            
            $analytics['status_distribution'] = $statusData;
            
            // Order priority distribution
            $query = "SELECT priority, COUNT(*) as count
                     FROM service_orders
                     WHERE status NOT IN ('cancelled')
                     GROUP BY priority
                     ORDER BY count DESC";
            
            $stmt = $this->conn->query($query);
            $priorityData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add colors for each priority
            $priorityColors = [
                'low' => '#2ecc71',
                'medium' => '#f39c12',
                'high' => '#e74c3c',
                'urgent' => '#c0392b'
            ];
            
            foreach ($priorityData as &$priority) {
                $priority['color'] = $priorityColors[$priority['priority']] ?? '#95a5a6';
            }
            
            $analytics['priority_distribution'] = $priorityData;
            
            // Daily service margin trend (last 7 days) from service orders
            $query = "SELECT DATE(o.created_at) as date, COALESCE(SUM(COALESCE(o.final_cost, 0) - COALESCE(o.deposit_amount, 0)), 0) as revenue
                     FROM service_orders o
                     WHERE o.payment_status <> 'refunded'
                     AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     GROUP BY DATE(o.created_at)
                     ORDER BY date";
            
            $stmt = $this->conn->query($query);
            $analytics['daily_revenue_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendSuccess(['analytics' => $analytics]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get analytics: " . $e->getMessage(), 500);
        }
    }
    
    private function getNotifications() {
        try {
            // Create some sample notifications since we don't have a notifications table
            $notifications = [
                [
                    'id' => 1,
                    'title' => 'New Order Received',
                    'message' => 'A new service order has been created',
                    'type' => 'info',
                    'is_read' => false,
                    'created_at' => date('Y-m-d H:i:s'),
                    'icon' => 'info'
                ],
                [
                    'id' => 2,
                    'title' => 'Low Stock Alert',
                    'message' => '3 products are running low on stock',
                    'type' => 'warning',
                    'is_read' => false,
                    'created_at' => date('Y-m-d H:i:s'),
                    'icon' => 'warning'
                ],
                [
                    'id' => 3,
                    'title' => 'Payment Received',
                    'message' => '₹5000 payment received for order ORD2026011151E14B',
                    'type' => 'success',
                    'is_read' => true,
                    'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                    'icon' => 'success'
                ]
            ];
            
            $this->sendSuccess(['notifications' => $notifications]);
            
        } catch (Exception $e) {
            $this->sendError("Failed to get notifications: " . $e->getMessage(), 500);
        }
    }

    private function getRealtimeNotifications() {
        try {
            $storedQuery = "SELECT id, title, message, type, is_read, created_at
                           FROM notifications
                           WHERE user_id = :user_id
                           ORDER BY created_at DESC
                           LIMIT 10";
            $storedStmt = $this->conn->prepare($storedQuery);
            $storedStmt->bindValue(':user_id', (int)$this->user['user_id'], PDO::PARAM_INT);
            $storedStmt->execute();

            $notifications = $storedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $pendingQuery = "SELECT
                                so.id,
                                so.order_code,
                                so.status,
                                so.created_at,
                                COALESCE(c.full_name, 'Unknown Customer') AS client_name,
                                COALESCE(u.name, 'Not Assigned') AS staff_name,
                                TIMESTAMPDIFF(DAY, DATE(so.created_at), CURDATE()) AS pending_days
                             FROM service_orders so
                             LEFT JOIN clients c ON so.client_id = c.id
                             LEFT JOIN users u ON so.staff_id = u.id
                             WHERE so.status IN ('pending', 'scheduled', 'process', 'ready')
                               AND DATE(so.created_at) <= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
                             ORDER BY so.created_at ASC
                             LIMIT 20";
            $pendingStmt = $this->conn->query($pendingQuery);
            $pendingOrders = $pendingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $createdAt = date('Y-m-d H:i:s');
            foreach ($pendingOrders as $order) {
                $days = max(2, (int)($order['pending_days'] ?? 2));
                $statusLabel = ucfirst(str_replace('_', ' ', (string)($order['status'] ?? 'pending')));
                $notifications[] = [
                    'id' => 800000 + (int)$order['id'],
                    'title' => 'Open Order Reminder',
                    'message' => "Order {$order['order_code']} for {$order['client_name']} has been open for {$days} days and is still {$statusLabel}. Assigned staff: {$order['staff_name']}.",
                    'type' => 'alert',
                    'is_read' => false,
                    'created_at' => $createdAt,
                    'icon' => 'alert'
                ];
            }

            usort($notifications, function ($a, $b) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            });

            $this->sendSuccess(['notifications' => $notifications]);

        } catch (Exception $e) {
            $this->sendError("Failed to get notifications: " . $e->getMessage(), 500);
        }
    }
    
    private function resetPassword() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data['user_id']) || empty($data['new_password'])) {
                $this->sendError("User ID and new password are required", 400);
                return;
            }
            
            // Check if user exists
            $checkQuery = "SELECT id FROM users WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindValue(':id', $data['user_id'], PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendError("User not found", 404);
                return;
            }
            
            // Hash new password
            $hashedPassword = password_hash($data['new_password'], PASSWORD_BCRYPT);
            
            $query = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':password', $hashedPassword, PDO::PARAM_STR);
            $stmt->bindValue(':id', $data['user_id'], PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $this->sendSuccess(['message' => 'Password reset successfully']);
            } else {
                $this->sendError("Failed to reset password", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Failed to reset password: " . $e->getMessage(), 500);
        }
    }

    private function backupDatabase() {
        try {
            $dbName = (string)$this->conn->query('SELECT DATABASE()')->fetchColumn();
            if ($dbName === '') {
                $this->sendError('Unable to determine active database', 500);
                return;
            }

            $backupDirectory = $this->getBackupDirectory();
            $this->purgeOldBackupFiles($backupDirectory);
            $tablesStmt = $this->conn->query('SHOW TABLES');
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $skippedTables = [];

            $dump = "-- Raj Communication Database Backup\n";
            $dump .= "-- Generated At: " . date('Y-m-d H:i:s') . "\n";
            $dump .= "-- Database: " . $dbName . "\n\n";
            $dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                $tableName = (string)$table;
                if ($tableName === '') {
                    continue;
                }

                $escapedTable = '`' . str_replace('`', '``', $tableName) . '`';

                try {
                    $createStmt = $this->conn->query("SHOW CREATE TABLE {$escapedTable}");
                    $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : null;
                    $createSql = $createRow['Create Table'] ?? null;

                    if (!$createSql) {
                        $skippedTables[] = $tableName . ' (missing CREATE TABLE metadata)';
                        continue;
                    }

                    $dump .= "--\n-- Table structure for table {$escapedTable}\n--\n";
                    $dump .= "DROP TABLE IF EXISTS {$escapedTable};\n";
                    $dump .= $createSql . ";\n\n";

                    $rowsStmt = $this->conn->query("SELECT * FROM {$escapedTable}");
                    $rows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

                    if (!empty($rows)) {
                        $columns = array_keys($rows[0]);
                        $columnSql = implode(', ', array_map(function ($column) {
                            return '`' . str_replace('`', '``', (string)$column) . '`';
                        }, $columns));

                        $dump .= "--\n-- Dumping data for table {$escapedTable}\n--\n";
                        foreach ($rows as $row) {
                            $values = [];
                            foreach ($columns as $column) {
                                $value = $row[$column] ?? null;
                                if ($value === null) {
                                    $values[] = 'NULL';
                                } else {
                                    $values[] = $this->conn->quote((string)$value);
                                }
                            }
                            $dump .= "INSERT INTO {$escapedTable} ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n";
                        }
                        $dump .= "\n";
                    }
                } catch (Exception $tableError) {
                    $skippedTables[] = $tableName . ' (' . $tableError->getMessage() . ')';
                    continue;
                }
            }

            if (!empty($skippedTables)) {
                $dump .= "-- Skipped tables during backup:\n";
                foreach ($skippedTables as $skippedTable) {
                    $dump .= "-- " . $skippedTable . "\n";
                }
                $dump .= "\n";
            }

            $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

            $fileName = 'raj_communication_backup_' . date('Ymd_His') . '.sql';
            $filePath = $backupDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (@file_put_contents($filePath, $dump) === false) {
                $this->sendError('Failed to store backup history file on server', 500);
                return;
            }

            header_remove('Content-Type');
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $dump;
            exit();
        } catch (Exception $e) {
            $this->sendError('Failed to generate backup: ' . $e->getMessage(), 500);
        }
    }

    private function getBackupHistory() {
        try {
            $backupDirectory = $this->getBackupDirectory();
            $this->purgeOldBackupFiles($backupDirectory);
            $history = [];

            foreach (glob($backupDirectory . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $filePath) {
                if (!is_file($filePath)) {
                    continue;
                }
                $history[] = [
                    'file_name' => basename($filePath),
                    'file_size' => (int)filesize($filePath),
                    'created_at' => date('Y-m-d H:i:s', (int)filemtime($filePath)),
                ];
            }

            usort($history, function ($a, $b) {
                return strcmp((string)$b['created_at'], (string)$a['created_at']);
            });

            $this->sendSuccess(['history' => $history]);
        } catch (Exception $e) {
            $this->sendError('Failed to load backup history: ' . $e->getMessage(), 500);
        }
    }

    private function getBackupDirectory() {
        $backupDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDirectory) && !@mkdir($backupDirectory, 0777, true) && !is_dir($backupDirectory)) {
            throw new Exception('Unable to create backup storage directory');
        }
        return $backupDirectory;
    }

    private function purgeOldBackupFiles($backupDirectory, $retentionDays = 30) {
        $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
        foreach (glob($backupDirectory . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }
            $modifiedTime = @filemtime($filePath);
            if ($modifiedTime !== false && $modifiedTime < $cutoffTime) {
                @unlink($filePath);
            }
        }
    }
    
    private function sendSuccess($data = []) {
        $response = array_merge(['success' => true], $data);
        echo json_encode($response);
    }
    
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit();
    }
}

// Handle the request
try {
    $api = new AdminAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
