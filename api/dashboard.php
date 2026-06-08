<?php
// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Include required files - FIXED PATH
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/middleware/Auth.php';
require_once __DIR__ . '/helpers/performance.php';

apiEnableCompression();

// Initialize objects
try {
    $auth = new Auth();
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed. Please check database credentials.");
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Initialization failed',
        'error' => $e->getMessage()
    ]);
    exit();
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Verify token for all requests except OPTIONS
try {
    $user = $auth->requireAuth();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication failed',
        'error' => $e->getMessage()
    ]);
    exit();
}

// Get the actual request URI
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = '/raj_communication/api/dashboard';
$path = str_replace($base_path, '', $request_uri);
$path = trim($path, '/');
$path_parts = $path ? explode('/', $path) : [];

// Route the request based on path segments
$action = isset($path_parts[0]) && !empty($path_parts[0]) ? $path_parts[0] : 'overview';

if ($method === 'GET') {
    $cacheTtls = [
        'overview' => 5,
        'stats' => 15,
        'orders' => 5,
    ];
    if (isset($cacheTtls[$action]) && apiServeCachedJson('dashboard_' . $action, $cacheTtls[$action])) {
        exit();
    }
}

switch ($action) {
    case 'deliveries':
        handleDeliveries($conn, $method, $path_parts);
        break;
    case 'stats':
        getDashboardStats($conn);
        break;
    case 'orders':
        getRecentOrders($conn);
        break;
    case 'overview':
    default:
        getDashboardOverview($conn);
        break;
}

/**
 * Handle deliveries CRUD operations
 */
function handleDeliveries($conn, $method, $path_parts) {
    switch ($method) {
        case 'GET':
            getDeliveries($conn, $path_parts);
            break;
        case 'POST':
            createDelivery($conn);
            break;
        case 'PUT':
            updateDelivery($conn, $path_parts);
            break;
        case 'DELETE':
            deleteDelivery($conn, $path_parts);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
}

/**
 * Get deliveries with optional filters
 */
function getDeliveries($conn, $path_parts) {
    try {
        // Check if fetching single delivery by ID
        if (isset($path_parts[1]) && is_numeric($path_parts[1])) {
            $query = "SELECT d.*, so.order_code, c.full_name AS client_name 
                      FROM deliveries d 
                      LEFT JOIN service_orders so ON d.order_id = so.id 
                      LEFT JOIN clients c ON so.client_id = c.id
                      WHERE d.id = :id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $path_parts[1], PDO::PARAM_INT);
            $stmt->execute();
            
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($delivery) {
                echo json_encode([
                    'success' => true,
                    'delivery' => $delivery
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Delivery not found'
                ]);
            }
        } else {
            // Get all deliveries with optional filters
            $whereClause = '';
            $params = [];
            
            // Filter by status if provided
            if (isset($_GET['status']) && !empty($_GET['status'])) {
                $whereClause = " WHERE d.status = :status";
                $params[':status'] = $_GET['status'];
            }
            
            // Filter by delivery person if provided
            if (isset($_GET['delivery_person']) && !empty($_GET['delivery_person'])) {
                $whereClause .= $whereClause ? " AND d.delivery_person = :delivery_person" : " WHERE d.delivery_person = :delivery_person";
                $params[':delivery_person'] = $_GET['delivery_person'];
            }
            
            // Filter by date range if provided
            if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                $whereClause .= $whereClause ? " AND d.scheduled_date >= :start_date" : " WHERE d.scheduled_date >= :start_date";
                $params[':start_date'] = $_GET['start_date'];
            }
            
            if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                $whereClause .= $whereClause ? " AND d.scheduled_date <= :end_date" : " WHERE d.scheduled_date <= :end_date";
                $params[':end_date'] = $_GET['end_date'];
            }
            
            $query = "SELECT d.*, so.order_code, c.full_name AS client_name, c.phone as client_phone
                      FROM deliveries d 
                      LEFT JOIN service_orders so ON d.order_id = so.id 
                      LEFT JOIN clients c ON so.client_id = c.id
                      {$whereClause}
                      ORDER BY d.scheduled_date DESC, d.scheduled_time DESC";
            
            $stmt = $conn->prepare($query);
            
            // Bind parameters if any
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format dates for better readability
            foreach ($deliveries as &$delivery) {
                if ($delivery['scheduled_date']) {
                    $delivery['scheduled_date_formatted'] = date('M d, Y', strtotime($delivery['scheduled_date']));
                }
                if ($delivery['delivered_date']) {
                    $delivery['delivered_date_formatted'] = date('M d, Y H:i', strtotime($delivery['delivered_date']));
                }
            }
            
            echo json_encode([
                'success' => true,
                'count' => count($deliveries),
                'deliveries' => $deliveries,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Create a new delivery
 */
function createDelivery($conn) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Validate required fields
        $required = ['order_id', 'delivery_type', 'address', 'contact_person', 'contact_phone', 'scheduled_date'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Missing required field: {$field}",
                    'required_fields' => $required
                ]);
                return;
            }
        }
        
        // Generate delivery code
        $delivery_code = 'DEL' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 6));
        
        $query = "INSERT INTO deliveries (
            order_id, delivery_code, delivery_type, address,
            contact_person, contact_phone, scheduled_date,
            scheduled_time, delivery_person, status, notes
        ) VALUES (
            :order_id, :delivery_code, :delivery_type, :address,
            :contact_person, :contact_phone, :scheduled_date,
            :scheduled_time, :delivery_person, :status, :notes
        )";
        
        $stmt = $conn->prepare($query);
        
        // Set default values
        $status = isset($data['status']) ? $data['status'] : 'scheduled';
        $scheduled_time = isset($data['scheduled_time']) ? $data['scheduled_time'] : '09:00:00';
        $delivery_person = isset($data['delivery_person']) ? $data['delivery_person'] : 'Staff';
        
        $stmt->bindParam(':order_id', $data['order_id'], PDO::PARAM_INT);
        $stmt->bindParam(':delivery_code', $delivery_code);
        $stmt->bindParam(':delivery_type', $data['delivery_type']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':contact_person', $data['contact_person']);
        $stmt->bindParam(':contact_phone', $data['contact_phone']);
        $stmt->bindParam(':scheduled_date', $data['scheduled_date']);
        $stmt->bindParam(':scheduled_time', $scheduled_time);
        $stmt->bindParam(':delivery_person', $delivery_person);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':notes', $data['notes'] ?? null);
        
        if ($stmt->execute()) {
            $delivery_id = $conn->lastInsertId();
            
            // Fetch the created delivery
            $fetchQuery = "SELECT d.*, so.order_code, c.full_name AS client_name 
                           FROM deliveries d 
                           LEFT JOIN service_orders so ON d.order_id = so.id 
                           LEFT JOIN clients c ON so.client_id = c.id
                           WHERE d.id = :id";
            $fetchStmt = $conn->prepare($fetchQuery);
            $fetchStmt->bindParam(':id', $delivery_id, PDO::PARAM_INT);
            $fetchStmt->execute();
            $delivery = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Delivery scheduled successfully',
                'delivery_id' => $delivery_id,
                'delivery_code' => $delivery_code,
                'delivery' => $delivery
            ]);
        } else {
            throw new Exception('Failed to execute query');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to schedule delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Update an existing delivery
 */
function updateDelivery($conn, $path_parts) {
    try {
        $id = isset($path_parts[1]) ? $path_parts[1] : null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Check if delivery exists
        $checkQuery = "SELECT id FROM deliveries WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery not found']);
            return;
        }
        
        // Build update query dynamically
        $fields = [];
        $params = [':id' => $id];
        
        // Define allowed fields to update
        $allowed_fields = [
            'order_id', 'delivery_type', 'address', 'contact_person', 
            'contact_phone', 'scheduled_date', 'scheduled_time', 
            'delivery_person', 'status', 'notes', 'delivered_date'
        ];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            return;
        }
        
        $query = "UPDATE deliveries SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        // Bind parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            // Fetch updated delivery
            $fetchQuery = "SELECT d.*, so.order_code, c.full_name AS client_name 
                           FROM deliveries d 
                           LEFT JOIN service_orders so ON d.order_id = so.id 
                           LEFT JOIN clients c ON so.client_id = c.id
                           WHERE d.id = :id";
            $fetchStmt = $conn->prepare($fetchQuery);
            $fetchStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $fetchStmt->execute();
            $delivery = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Delivery updated successfully',
                'delivery' => $delivery
            ]);
        } else {
            throw new Exception('Failed to execute update');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Delete a delivery
 */
function deleteDelivery($conn, $path_parts) {
    try {
        $id = isset($path_parts[1]) ? $path_parts[1] : null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
            return;
        }
        
        // Check if delivery exists
        $checkQuery = "SELECT id FROM deliveries WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery not found']);
            return;
        }
        
        // Delete delivery
        $query = "DELETE FROM deliveries WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Delivery deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete delivery');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Get dashboard overview stats
 */
function getDashboardOverview($conn) {
    try {
        $monthStart = date('Y-m-01');
        $overviewQuery = "SELECT
            (SELECT COUNT(*) FROM clients) AS total_clients,
            (SELECT COUNT(*) FROM service_orders) AS total_orders,
            (SELECT COUNT(*) FROM service_orders WHERE status IN ('pending', 'process', 'scheduled')) AS pending_orders,
            (SELECT COUNT(*) FROM service_orders WHERE status = 'delivered' AND updated_at >= :month_start) AS completed_orders_month,
            (SELECT COALESCE(SUM(final_cost), 0) FROM service_orders WHERE status = 'delivered' AND updated_at >= :month_start AND final_cost IS NOT NULL) AS total_revenue_month";
        $overviewStmt = $conn->prepare($overviewQuery);
        $overviewStmt->bindValue(':month_start', $monthStart);
        $overviewStmt->execute();
        $overview = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        // Get recent orders (last 5)
        $recentOrdersQuery = "SELECT so.id, so.order_code, so.status, so.priority, so.estimated_cost, 
                              so.final_cost, so.payment_status, so.estimated_delivery_date, so.created_at,
                              c.full_name as client_name, p.product_name 
                              FROM service_orders so 
                              LEFT JOIN clients c ON so.client_id = c.id 
                              LEFT JOIN products p ON so.product_id = p.id 
                              ORDER BY so.created_at DESC 
                              LIMIT 5";
        $recentOrdersStmt = $conn->query($recentOrdersQuery);
        $recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get low stock products
        $lowStockQuery = "SELECT product_name, stock_quantity, min_stock_level 
                          FROM products 
                          WHERE stock_quantity <= min_stock_level 
                          AND status = 'active' 
                          ORDER BY stock_quantity ASC 
                          LIMIT 5";
        $lowStockStmt = $conn->query($lowStockQuery);
        $lowStockProducts = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get upcoming deliveries
        $today = date('Y-m-d');
        $deliveriesQuery = "SELECT d.*, so.order_code, c.full_name as client_name 
                            FROM deliveries d 
                            LEFT JOIN service_orders so ON d.order_id = so.id 
                            LEFT JOIN clients c ON so.client_id = c.id 
                            WHERE d.scheduled_date >= :today 
                            AND d.status IN ('scheduled', 'in_transit')
                            ORDER BY d.scheduled_date ASC, d.scheduled_time ASC 
                            LIMIT 5";
        $deliveriesStmt = $conn->prepare($deliveriesQuery);
        $deliveriesStmt->bindParam(':today', $today);
        $deliveriesStmt->execute();
        $upcomingDeliveries = $deliveriesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response = json_encode([
            'success' => true,
            'overview' => [
                'total_clients' => (int)($overview['total_clients'] ?? 0),
                'total_orders' => (int)($overview['total_orders'] ?? 0),
                'pending_orders' => (int)($overview['pending_orders'] ?? 0),
                'completed_orders_month' => (int)($overview['completed_orders_month'] ?? 0),
                'total_revenue_month' => (float)($overview['total_revenue_month'] ?? 0)
            ],
            'recent_orders' => $recentOrders,
            'low_stock_products' => $lowStockProducts,
            'upcoming_deliveries' => $upcomingDeliveries,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        apiCacheJsonResponse('dashboard_overview', $response);
        echo $response;
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Get dashboard statistics only
 */
function getDashboardStats($conn) {
    try {
        // Get order status distribution
        $statusQuery = "SELECT status, COUNT(*) as count 
                        FROM service_orders 
                        GROUP BY status";
        $statusStmt = $conn->query($statusQuery);
        $orderStatus = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get monthly revenue (last 6 months)
        $monthlyRevenueQuery = "SELECT 
            DATE_FORMAT(updated_at, '%Y-%m') as month,
            COALESCE(SUM(final_cost), 0) as revenue
            FROM service_orders 
            WHERE status = 'delivered' 
            AND updated_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
            ORDER BY month DESC";
        $monthlyRevenueStmt = $conn->query($monthlyRevenueQuery);
        $monthlyRevenue = $monthlyRevenueStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get top products by service count
        $topProductsQuery = "SELECT 
            COALESCE(p.product_name, 'Unknown Product') as product_name,
            COUNT(so.id) as service_count
            FROM service_orders so
            LEFT JOIN products p ON so.product_id = p.id
            WHERE so.status = 'delivered'
            GROUP BY p.product_name
            ORDER BY service_count DESC
            LIMIT 5";
        $topProductsStmt = $conn->query($topProductsQuery);
        $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response = json_encode([
            'success' => true,
            'stats' => [
                'order_status' => $orderStatus,
                'monthly_revenue' => $monthlyRevenue,
                'top_products' => $topProducts
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        apiCacheJsonResponse('dashboard_stats', $response);
        echo $response;
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Get recent orders
 */
function getRecentOrders($conn) {
    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $limit = min($limit, 50); // Limit to 50 max
        
        $query = "SELECT 
            so.id,
            so.order_code,
            so.status,
            so.priority,
            so.estimated_cost,
            so.final_cost,
            so.payment_status,
            so.estimated_delivery_date,
            so.created_at,
            c.full_name as client_name,
            c.phone as client_phone,
            COALESCE(p.product_name, 'Unknown') as product_name
            FROM service_orders so
            LEFT JOIN clients c ON so.client_id = c.id
            LEFT JOIN products p ON so.product_id = p.id
            ORDER BY so.created_at DESC
            LIMIT :limit";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format dates for better readability
        foreach ($orders as &$order) {
            if ($order['created_at']) {
                $order['created_at_formatted'] = date('M d, Y H:i', strtotime($order['created_at']));
            }
            if ($order['estimated_delivery_date']) {
                $order['estimated_delivery_date_formatted'] = date('M d, Y', strtotime($order['estimated_delivery_date']));
            }
        }
        
        $response = json_encode([
            'success' => true,
            'orders' => $orders,
            'count' => count($orders),
            'limit' => $limit,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        apiCacheJsonResponse('dashboard_orders', $response);
        echo $response;
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error',
            'error' => $e->getMessage()
        ]);
    }
}
?>
