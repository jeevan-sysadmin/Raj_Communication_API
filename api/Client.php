<?php
// Client.php - UPDATED WITH PROPER ERROR HANDLING

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// SIMPLE PATH FIX - Just create the Database class inline
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
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database connection failed"]);
            exit();
        }
        return $this->conn;
    }
}

// Client class
class Client {
    private $conn;
    private $table = 'clients';

    public $id;
    public $client_code;
    public $full_name;
    public $email;
    public $phone;
    public $address;
    public $city;
    public $state;
    public $zip_code;
    public $notes;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll($search = '') {
        $query = "SELECT * FROM " . $this->table . " WHERE 1=1";
        if(!empty($search)) {
            $query .= " AND (full_name LIKE :search OR phone LIKE :search OR email LIKE :search OR client_code LIKE :search)";
        }
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        if(!empty($search)) {
            $searchTerm = "%" . $search . "%";
            $stmt->bindParam(':search', $searchTerm);
        }
        $stmt->execute();
        return $stmt;
    }

    public function getById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function create() {
        $this->client_code = 'CLT' . date('Ymd') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $query = "INSERT INTO " . $this->table . " 
                 SET client_code = :client_code,
                     full_name = :full_name,
                     email = :email,
                     phone = :phone,
                     address = :address,
                     city = :city,
                     state = :state,
                     zip_code = :zip_code,
                     notes = :notes,
                     created_at = NOW()";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':client_code', $this->client_code);
        $stmt->bindParam(':full_name', $this->full_name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':address', $this->address);
        $stmt->bindParam(':city', $this->city);
        $stmt->bindParam(':state', $this->state);
        $stmt->bindParam(':zip_code', $this->zip_code);
        $stmt->bindParam(':notes', $this->notes);
        
        if($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function update() {
        $query = "UPDATE " . $this->table . " 
                 SET full_name = :full_name,
                     email = :email,
                     phone = :phone,
                     address = :address,
                     city = :city,
                     state = :state,
                     zip_code = :zip_code,
                     notes = :notes,
                     updated_at = NOW()
                 WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $this->id);
        $stmt->bindParam(':full_name', $this->full_name);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':address', $this->address);
        $stmt->bindParam(':city', $this->city);
        $stmt->bindParam(':state', $this->state);
        $stmt->bindParam(':zip_code', $this->zip_code);
        $stmt->bindParam(':notes', $this->notes);
        
        return $stmt->execute();
    }

    public function delete() {
        // First check if client has any orders
        $checkQuery = "SELECT COUNT(*) as order_count FROM service_orders WHERE client_id = :client_id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':client_id', $this->id);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result && $result['order_count'] > 0) {
            // Client has orders, we can't delete but we can mark as inactive
            $query = "UPDATE " . $this->table . " SET status = 'inactive', updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $this->id);
            return $stmt->execute();
        } else {
            // No orders, safe to delete
            $query = "DELETE FROM " . $this->table . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $this->id);
            return $stmt->execute();
        }
    }
}

// Create connection and process request
$database = new Database();
$db = $database->getConnection();
$client = new Client($db);

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch($method) {
        case 'GET':
            if(isset($_GET['id'])) {
                $data = $client->getById($_GET['id']);
                if($data) {
                    echo json_encode(["success" => true, "data" => $data]);
                } else {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Client not found"]);
                }
            } else {
                $search = isset($_GET['search']) ? $_GET['search'] : '';
                $stmt = $client->getAll($search);
                $clients = $stmt->fetchAll();
                echo json_encode(["success" => true, "data" => $clients]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents("php://input"), true);
            
            if(!$input) {
                $input = $_POST;
            }
            
            if(!empty($input['full_name']) && !empty($input['phone'])) {
                $client->full_name = $input['full_name'];
                $client->email = $input['email'] ?? '';
                $client->phone = $input['phone'];
                $client->address = $input['address'] ?? '';
                $client->city = $input['city'] ?? '';
                $client->state = $input['state'] ?? '';
                $client->zip_code = $input['zip_code'] ?? '';
                $client->notes = $input['notes'] ?? '';
                
                if($client_id = $client->create()) {
                    http_response_code(201);
                    echo json_encode([
                        "success" => true,
                        "message" => "Client created successfully",
                        "client_id" => $client_id,
                        "client_code" => $client->client_code
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(["success" => false, "message" => "Failed to create client"]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Full name and phone are required"]);
            }
            break;
            
        case 'PUT':
            // Get ID from URL parameter first, then from request body
            $id = isset($_GET['id']) ? $_GET['id'] : null;
            
            if(!$id) {
                $input = json_decode(file_get_contents("php://input"), true);
                $id = $input['id'] ?? null;
            }
            
            if(!$id) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Client ID is required"]);
                break;
            }
            
            $input = json_decode(file_get_contents("php://input"), true);
            
            if(!$input) {
                $input = $_POST;
            }
            
            // Verify client exists
            $existingClient = $client->getById($id);
            if(!$existingClient) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Client not found"]);
                break;
            }
            
            $client->id = $id;
            $client->full_name = $input['full_name'] ?? $existingClient['full_name'];
            $client->email = $input['email'] ?? $existingClient['email'];
            $client->phone = $input['phone'] ?? $existingClient['phone'];
            $client->address = $input['address'] ?? $existingClient['address'];
            $client->city = $input['city'] ?? $existingClient['city'];
            $client->state = $input['state'] ?? $existingClient['state'];
            $client->zip_code = $input['zip_code'] ?? $existingClient['zip_code'];
            $client->notes = $input['notes'] ?? $existingClient['notes'];
            
            if($client->update()) {
                echo json_encode(["success" => true, "message" => "Client updated successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Failed to update client"]);
            }
            break;
            
        case 'DELETE':
            // FIXED: Get ID from URL parameter
            if(isset($_GET['id'])) {
                $client->id = $_GET['id'];
                
                // Verify client exists
                $existingClient = $client->getById($client->id);
                if(!$existingClient) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Client not found"]);
                    break;
                }
                
                if($client->delete()) {
                    echo json_encode(["success" => true, "message" => "Client deleted successfully"]);
                } else {
                    http_response_code(500);
                    echo json_encode(["success" => false, "message" => "Failed to delete client"]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Client ID required in URL parameter"]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(["success" => false, "message" => "Method not allowed"]);
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