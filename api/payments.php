<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/jwt_helper.php';
require_once __DIR__ . '/helpers/performance.php';

apiEnableCompression();

$database = new Database();
$db = $database->getConnection();

// Check connection
if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

// Get authorization header
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (empty($authHeader)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "No token provided"]);
    exit();
}

// Extract token
$token = str_replace('Bearer ', '', $authHeader);

// Validate token
$decoded = JWT::decode($token);
if (!$decoded) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit();
}

$user_id = $decoded['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Helper function to update order payment status
function updateOrderPaymentStatus($db, $order_id) {
    // Get order details
    $orderQuery = "SELECT final_cost, deposit_amount FROM service_orders WHERE id = :order_id";
    $orderStmt = $db->prepare($orderQuery);
    $orderStmt->bindParam(':order_id', $order_id);
    $orderStmt->execute();
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) return false;
    
    $final_cost = $order['final_cost'];
    $deposit_amount = $order['deposit_amount'];
    
    // Calculate total payments for this order
    $totalQuery = "SELECT COALESCE(SUM(amount), 0) as total_paid 
                  FROM payments 
                  WHERE order_id = :order_id AND payment_status IN ('completed', 'paid')";
    
    $totalStmt = $db->prepare($totalQuery);
    $totalStmt->bindParam(':order_id', $order_id);
    $totalStmt->execute();
    $result = $totalStmt->fetch(PDO::FETCH_ASSOC);
    $total_paid = $result['total_paid'] ?: 0;
    
    // Determine payment status
    if ($total_paid >= $final_cost) {
        $payment_status = 'paid';
    } elseif ($total_paid > 0 && $total_paid < $final_cost) {
        $payment_status = 'partially_paid';
    } else {
        $payment_status = 'pending';
    }
    
    // Update order payment status
    $updateQuery = "UPDATE service_orders 
                    SET payment_status = :payment_status,
                        updated_at = NOW()
                    WHERE id = :order_id";
    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':payment_status', $payment_status);
    $updateStmt->bindParam(':order_id', $order_id);
    
    return $updateStmt->execute();
}

try {
    switch ($method) {
        case 'GET':
            if (apiServeCachedJson('payments', 5)) {
                exit();
            }
            // Get all payments or payments for specific order
            $order_id = isset($_GET['order_id']) ? $_GET['order_id'] : null;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
            $offset = ($page - 1) * $limit;
            
            if ($order_id) {
                // Get payments for specific order
                $query = "SELECT p.*, 
                                 so.order_code, 
                                 so.final_cost,
                                 so.deposit_amount,
                                 c.full_name as client_name,
                                 c.phone as client_phone,
                                 u.name as created_by_name
                          FROM payments p
                          LEFT JOIN service_orders so ON p.order_id = so.id
                          LEFT JOIN clients c ON so.client_id = c.id
                          LEFT JOIN users u ON p.created_by = u.id
                          WHERE p.order_id = :order_id
                          ORDER BY p.created_at DESC";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':order_id', $order_id);
                $stmt->execute();
                
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calculate totals
                $total_paid = 0;
                $deposit_paid = 0;
                $final_paid = 0;
                
                foreach ($payments as $payment) {
                    $total_paid += $payment['amount'];
                    
                    // Categorize payments
                    if (strpos(strtolower($payment['notes']), 'deposit') !== false) {
                        $deposit_paid += $payment['amount'];
                    } else {
                        $final_paid += $payment['amount'];
                    }
                }
                
                // Get order details
                $orderQuery = "SELECT final_cost, deposit_amount, payment_status FROM service_orders WHERE id = :order_id";
                $orderStmt = $db->prepare($orderQuery);
                $orderStmt->bindParam(':order_id', $order_id);
                $orderStmt->execute();
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
                
                $balance = $order['final_cost'] - $total_paid;
                
                $response = json_encode([
                    "success" => true,
                    "payments" => $payments,
                    "summary" => [
                        "total_paid" => $total_paid,
                        "deposit_paid" => $deposit_paid,
                        "final_paid" => $final_paid,
                        "final_cost" => $order['final_cost'],
                        "deposit_amount" => $order['deposit_amount'],
                        "balance" => $balance,
                        "payment_status" => $order['payment_status']
                    ],
                    "count" => count($payments)
                ]);
                apiCacheJsonResponse('payments', $response);
                echo $response;
            } else {
                // Get all payments with pagination
                $countQuery = "SELECT COUNT(*) as total FROM payments";
                $countStmt = $db->query($countQuery);
                $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $totalResult['total'] ?? 0;
                
                // Get payments with order and client info
                $query = "SELECT p.*, 
                                 so.order_code, 
                                 so.final_cost,
                                 so.payment_status as order_payment_status,
                                 c.full_name as client_name,
                                 c.phone as client_phone,
                                 u.name as created_by_name
                          FROM payments p
                          LEFT JOIN service_orders so ON p.order_id = so.id
                          LEFT JOIN clients c ON so.client_id = c.id
                          LEFT JOIN users u ON p.created_by = u.id
                          ORDER BY p.created_at DESC
                          LIMIT :limit OFFSET :offset";
                
                $stmt = $db->prepare($query);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $response = json_encode([
                    "success" => true,
                    "payments" => $payments,
                    "total" => (int)$total,
                    "page" => $page,
                    "total_pages" => ceil($total / $limit),
                    "limit" => $limit,
                    "timestamp" => date('Y-m-d H:i:s')
                ]);
                apiCacheJsonResponse('payments', $response);
                echo $response;
            }
            break;
            
        case 'POST':
            // Create new payment
            $input = json_decode(file_get_contents("php://input"), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid input"]);
                exit();
            }
            
            // Validate required fields
            $required_fields = ['order_id', 'amount'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field]) || empty($input[$field])) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "Missing required field: $field"]);
                    exit();
                }
            }
            
            // Begin transaction
            $db->beginTransaction();
            
            try {
                // Get order details first
                $orderQuery = "SELECT order_code, final_cost FROM service_orders WHERE id = :order_id";
                $orderStmt = $db->prepare($orderQuery);
                $orderStmt->bindParam(':order_id', $input['order_id']);
                $orderStmt->execute();
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$order) {
                    throw new Exception("Order not found");
                }
                
                // Generate payment code
                $payment_code = 'PAY-' . $order['order_code'] . '-' . date('YmdHis') . substr(uniqid(), 0, 6);
                
                // Insert payment
                $query = "INSERT INTO payments 
                         SET payment_code = :payment_code,
                             order_id = :order_id,
                             amount = :amount,
                             payment_method = :payment_method,
                             transaction_id = :transaction_id,
                             payment_status = :payment_status,
                             notes = :notes,
                             created_by = :created_by,
                             created_at = NOW(),
                             updated_at = NOW()";
                
                $stmt = $db->prepare($query);
                
                $stmt->bindParam(':payment_code', $payment_code);
                $stmt->bindParam(':order_id', $input['order_id']);
                $stmt->bindParam(':amount', $input['amount']);
                $stmt->bindParam(':payment_method', $input['payment_method'] ?? 'cash');
                $stmt->bindParam(':transaction_id', $input['transaction_id'] ?? null);
                $stmt->bindParam(':payment_status', $input['payment_status'] ?? 'completed');
                $stmt->bindParam(':notes', $input['notes'] ?? 'Manual payment entry');
                $stmt->bindParam(':created_by', $user_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create payment");
                }
                
                $payment_id = $db->lastInsertId();
                
                // Update order payment status
                updateOrderPaymentStatus($db, $input['order_id']);
                
                // Get updated payment with details
                $getPaymentQuery = "SELECT p.*, so.order_code, u.name as created_by_name
                                   FROM payments p
                                   LEFT JOIN service_orders so ON p.order_id = so.id
                                   LEFT JOIN users u ON p.created_by = u.id
                                   WHERE p.id = :payment_id";
                $getPaymentStmt = $db->prepare($getPaymentQuery);
                $getPaymentStmt->bindParam(':payment_id', $payment_id);
                $getPaymentStmt->execute();
                $payment = $getPaymentStmt->fetch(PDO::FETCH_ASSOC);
                
                // Commit transaction
                $db->commit();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Payment created successfully",
                    "payment_id" => $payment_id,
                    "payment_code" => $payment_code,
                    "payment" => $payment
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to create payment",
                    "error" => $e->getMessage()
                ]);
            }
            break;
            
        case 'PUT':
            // Update payment
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Payment ID required"]);
                exit();
            }
            
            $input = json_decode(file_get_contents("php://input"), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid input"]);
                exit();
            }
            
            // Begin transaction
            $db->beginTransaction();
            
            try {
                // Build update query
                $fields = [];
                $params = [':id' => $id];
                
                if (isset($input['amount'])) {
                    $fields[] = 'amount = :amount';
                    $params[':amount'] = $input['amount'];
                }
                
                if (isset($input['payment_method'])) {
                    $fields[] = 'payment_method = :payment_method';
                    $params[':payment_method'] = $input['payment_method'];
                }
                
                if (isset($input['payment_status'])) {
                    $fields[] = 'payment_status = :payment_status';
                    $params[':payment_status'] = $input['payment_status'];
                }
                
                if (isset($input['transaction_id'])) {
                    $fields[] = 'transaction_id = :transaction_id';
                    $params[':transaction_id'] = $input['transaction_id'];
                }
                
                if (isset($input['notes'])) {
                    $fields[] = 'notes = :notes';
                    $params[':notes'] = $input['notes'];
                }
                
                $fields[] = 'updated_at = NOW()';
                
                if (empty($fields)) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "No fields to update"]);
                    exit();
                }
                
                $query = "UPDATE payments SET " . implode(', ', $fields) . " WHERE id = :id";
                
                $stmt = $db->prepare($query);
                
                if (!$stmt->execute($params)) {
                    throw new Exception("Failed to update payment");
                }
                
                // Get order_id from payment
                $orderQuery = "SELECT order_id FROM payments WHERE id = :id";
                $orderStmt = $db->prepare($orderQuery);
                $orderStmt->bindParam(':id', $id);
                $orderStmt->execute();
                $payment = $orderStmt->fetch(PDO::FETCH_ASSOC);
                $order_id = $payment['order_id'];
                
                // Update order payment status
                updateOrderPaymentStatus($db, $order_id);
                
                // Commit transaction
                $db->commit();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Payment updated successfully"
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to update payment",
                    "error" => $e->getMessage()
                ]);
            }
            break;
            
        case 'DELETE':
            // Delete payment
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Payment ID required"]);
                exit();
            }
            
            // Begin transaction
            $db->beginTransaction();
            
            try {
                // Get order_id before deletion
                $orderQuery = "SELECT order_id FROM payments WHERE id = :id";
                $orderStmt = $db->prepare($orderQuery);
                $orderStmt->bindParam(':id', $id);
                $orderStmt->execute();
                $payment = $orderStmt->fetch(PDO::FETCH_ASSOC);
                $order_id = $payment['order_id'];
                
                // Delete payment
                $query = "DELETE FROM payments WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete payment");
                }
                
                // Update order payment status
                updateOrderPaymentStatus($db, $order_id);
                
                // Commit transaction
                $db->commit();
                
                echo json_encode([
                    "success" => true,
                    "message" => "Payment deleted successfully"
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode([
                    "success" => false,
                    "message" => "Failed to delete payment",
                    "error" => $e->getMessage()
                ]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(["success" => false, "message" => "Method not allowed"]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}
?>
