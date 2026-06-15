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

require_once __DIR__ . '/config/database.php';

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
if (!$db) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}
$client = new Client($db);

$method = $_SERVER['REQUEST_METHOD'];

function normalizeImportKey($key) {
    $normalizedKey = strtolower(trim((string)$key));
    $normalizedKey = preg_replace('/[\s\-\.\/]+/', '_', $normalizedKey);
    $normalizedKey = preg_replace('/[^\w]/', '', $normalizedKey);
    return trim((string)$normalizedKey, '_');
}

function getNormalizedImportRow($row) {
    if (isset($row['_raw_import_row']) && is_array($row['_raw_import_row'])) {
        $row = array_merge($row['_raw_import_row'], $row);
    }

    $normalizedRow = [];
    foreach ($row as $key => $value) {
        if ($key === '_raw_import_row') {
            continue;
        }

        $normalizedKey = normalizeImportKey($key);
        if ($normalizedKey !== '') {
            $normalizedRow[$normalizedKey] = trim((string)($value ?? ''));
        }
    }

    return $normalizedRow;
}

function getFirstImportValue($row, $keys) {
    foreach ($keys as $key) {
        $normalizedKey = normalizeImportKey($key);
        $value = trim((string)($row[$normalizedKey] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function looksLikeImportPhone($value) {
    $digits = preg_replace('/\D+/', '', (string)$value);
    return strlen($digits) >= 6;
}

function extractCombinedClientCell($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return ['full_name' => '', 'phone' => ''];
    }

    preg_match('/(?:\+?\d[\d\s\-\(\)]{5,}\d)/', $value, $matches);
    $phone = isset($matches[0]) ? trim((string)$matches[0]) : '';
    $full_name = trim(preg_replace('/\s+/', ' ', str_replace([';', ',', '|'], ' ', str_replace($phone, ' ', $value))));

    return ['full_name' => $full_name, 'phone' => $phone];
}

function mapImportedClientRow($row) {
    $normalizedRow = getNormalizedImportRow($row);
    $orderedValues = array_values(array_filter(array_map(function($value) {
        return trim((string)($value ?? ''));
    }, array_values($normalizedRow)), function($value) {
        return $value !== '';
    }));

    $full_name = getFirstImportValue($normalizedRow, ['full_name', 'full_name_', 'name', 'client_name', 'customer_name', 'client', 'customer']);
    $phone = getFirstImportValue($normalizedRow, ['phone', 'phone_number', 'mobile', 'mobile_no', 'mobile_number', 'contact', 'contact_number', 'whatsapp', 'whatsapp_number']);
    $email = getFirstImportValue($normalizedRow, ['email', 'mail']);
    $address = getFirstImportValue($normalizedRow, ['address']);
    $city = getFirstImportValue($normalizedRow, ['city']);
    $state = getFirstImportValue($normalizedRow, ['state']);
    $zip_code = getFirstImportValue($normalizedRow, ['zip_code', 'zipcode', 'pin_code', 'pincode']);
    $notes = getFirstImportValue($normalizedRow, ['notes', 'remark', 'remarks']);

    if ($full_name === '' || $phone === '') {
        foreach ($orderedValues as $value) {
            $combined = extractCombinedClientCell($value);

            if ($full_name === '' && $combined['full_name'] !== '' && !looksLikeImportPhone($combined['full_name'])) {
                $full_name = $combined['full_name'];
            }

            if ($phone === '' && $combined['phone'] !== '') {
                $phone = $combined['phone'];
            }

            if ($full_name !== '' && $phone !== '') {
                break;
            }
        }
    }

    if ($full_name === '') {
        foreach ($orderedValues as $value) {
            if (!looksLikeImportPhone($value) && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $full_name = $value;
                break;
            }
        }
    }

    if ($phone === '') {
        foreach ($orderedValues as $value) {
            if (looksLikeImportPhone($value)) {
                $phone = $value;
                break;
            }
        }
    }

    return [
        'full_name' => trim((string)$full_name),
        'phone' => trim((string)$phone),
        'email' => trim((string)$email),
        'address' => trim((string)$address),
        'city' => trim((string)$city),
        'state' => trim((string)$state),
        'zip_code' => trim((string)$zip_code),
        'notes' => trim((string)$notes),
    ];
}

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

            if (isset($input['clients']) && is_array($input['clients'])) {
                $createdCount = 0;
                $errors = [];

                foreach ($input['clients'] as $index => $row) {
                    if (!is_array($row)) {
                        $errors[] = [
                            "row" => $index + 1,
                            "message" => "Invalid client row format",
                        ];
                        continue;
                    }

                    $mappedRow = mapImportedClientRow($row);
                    if ($mappedRow['full_name'] === '' || $mappedRow['phone'] === '') {
                        $errors[] = [
                            "row" => $index + 1,
                            "message" => "Full name and phone are required",
                        ];
                        continue;
                    }

                    $client->full_name = $mappedRow['full_name'];
                    $client->email = $mappedRow['email'];
                    $client->phone = $mappedRow['phone'];
                    $client->address = $mappedRow['address'];
                    $client->city = $mappedRow['city'];
                    $client->state = $mappedRow['state'];
                    $client->zip_code = $mappedRow['zip_code'];
                    $client->notes = $mappedRow['notes'] !== '' ? $mappedRow['notes'] : 'Imported from CSV';

                    try {
                        if($client->create()) {
                            $createdCount++;
                        } else {
                            $errors[] = [
                                "row" => $index + 1,
                                "message" => "Failed to create client",
                            ];
                        }
                    } catch (Throwable $e) {
                        $errors[] = [
                            "row" => $index + 1,
                            "message" => $e->getMessage(),
                        ];
                    }
                }

                $failedCount = count($errors);
                if ($createdCount > 0 && $failedCount > 0) {
                    http_response_code(207);
                    echo json_encode([
                        "success" => false,
                        "partial" => true,
                        "message" => "Some clients were imported successfully",
                        "created_count" => $createdCount,
                        "failed_count" => $failedCount,
                        "errors" => $errors,
                    ]);
                } elseif ($createdCount > 0) {
                    http_response_code(201);
                    echo json_encode([
                        "success" => true,
                        "message" => "Clients imported successfully",
                        "created_count" => $createdCount,
                        "failed_count" => 0,
                        "errors" => [],
                    ]);
                } else {
                    http_response_code(400);
                    echo json_encode([
                        "success" => false,
                        "message" => "No clients were imported",
                        "created_count" => 0,
                        "failed_count" => $failedCount,
                        "errors" => $errors,
                    ]);
                }
                break;
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
