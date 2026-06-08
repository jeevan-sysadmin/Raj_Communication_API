<?php
// Enable CORS for development
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include required files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/jwt_helper.php';
require_once __DIR__ . '/helpers/performance.php';

apiEnableCompression();

// Keep API responses lean in production.
error_reporting(E_ALL);
ini_set('display_errors', 0);

class LoginAPI {
    private $conn;
    private $data;
    private static $usersTableChecked = false;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        
        if (!$this->conn) {
            $this->sendError("Database connection failed");
            exit();
        }
        
        // Get input data
        $input = file_get_contents("php://input");
        $this->data = $input !== '' ? json_decode($input) : null;
        
        if ($input !== '' && !$this->data && json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError("Invalid JSON input");
            exit();
        }
    }

    public function login() {
        try {
            // Validate input
            if (!isset($this->data->email) || !isset($this->data->password)) {
                $this->sendError("Email and password are required", 400);
                return;
            }

            $email = filter_var(trim($this->data->email), FILTER_SANITIZE_EMAIL);
            $password = $this->data->password;

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendError("Invalid email format", 400);
                return;
            }

            if (!self::$usersTableChecked) {
                $stmt = $this->conn->query("SELECT 1 FROM users LIMIT 1");
                self::$usersTableChecked = true;
            }

            // Prepare SQL query
            $query = "SELECT id, name, email, password, role FROM users WHERE email = :email AND is_active = 1";
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt) {
                $this->sendError("Database query preparation failed", 500);
                return;
            }

            $stmt->bindParam(":email", $email, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Verify password
                    if (password_verify($password, $row['password'])) {
                        // Update last login
                        $this->updateLastLogin($row['id']);
                        
                        // Remove password from response
                        unset($row['password']);
                        
                        // Generate JWT token
                        $tokenPayload = [
                            'user_id' => $row['id'],
                            'email' => $row['email'],
                            'name' => $row['name'],
                            'role' => $row['role'],
                            'iat' => time(),
                            'exp' => time() + (24 * 60 * 60) // 24 hours
                        ];
                        $token = JWT::encode($tokenPayload);
                        
                        // Normalize role to supported values only
                        $role = $row['role'] === 'admin' ? 'admin' : 'user';

                        // Keep response role consistent for frontend
                        $row['role'] = $role;

                        // Determine redirect URL based on role
                        $redirectUrl = '/admin-dashboard';
                        
                        // Send success response
                        $this->sendSuccess([
                            "user" => $row,
                            "token" => $token,
                            "redirect" => $redirectUrl,
                            "role" => $role
                        ]);
                    } else {
                        $this->sendError("Invalid email or password", 401);
                    }
                } else {
                    $this->sendError("Invalid email or password", 401);
                }
            } else {
                $this->sendError("Database query failed", 500);
            }
            
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                $this->createDefaultUsers();
                self::$usersTableChecked = true;
                $this->login();
                return;
            }
            error_log("Login database error: " . $e->getMessage());
            $this->sendError("Server error occurred", 500);
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $this->sendError("Server error occurred", 500);
        }
    }

    private function createDefaultUsers() {
        try {
            // Create users table
            $createTable = "CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(100) UNIQUE NOT NULL,
                `password` VARCHAR(255) NOT NULL,
                `role` ENUM('admin', 'user') DEFAULT 'user',
                `is_active` TINYINT(1) DEFAULT 1,
                `last_login` TIMESTAMP NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $this->conn->exec($createTable);
            
            // Check if admin exists
            $checkAdmin = "SELECT id FROM users WHERE email = 'admin@sun.com'";
            $result = $this->conn->query($checkAdmin);
            
            if ($result->rowCount() == 0) {
                // Create default admin
                $hashedPassword = password_hash('password', PASSWORD_BCRYPT);
                $insertAdmin = "INSERT INTO users (name, email, password, role) 
                               VALUES ('Admin User', 'admin@sun.com', :password, 'admin')";
                $stmt = $this->conn->prepare($insertAdmin);
                $stmt->bindParam(':password', $hashedPassword);
                $stmt->execute();
                
                // Create default user
                $hashedPassword2 = password_hash('user123', PASSWORD_BCRYPT);
                $insertUser = "INSERT INTO users (name, email, password, role) 
                              VALUES ('Demo User', 'user@sun.com', :password, 'user')";
                $stmt2 = $this->conn->prepare($insertUser);
                $stmt2->bindParam(':password', $hashedPassword2);
                $stmt2->execute();
            }
        } catch (Exception $e) {
            error_log("User creation error: " . $e->getMessage());
        }
    }

    private function updateLastLogin($user_id) {
        try {
            $query = "UPDATE users SET last_login = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $user_id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to update last login: " . $e->getMessage());
        }
    }

    private function sendSuccess($data = []) {
        $response = array_merge([
            "success" => true,
            "message" => "Login successful"
        ], $data);
        
        echo json_encode($response);
    }

    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            "success" => false,
            "message" => $message
        ]);
    }
}

// Handle the request
try {
    $api = new LoginAPI();
    
    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        $api->login();
    } elseif ($method === 'GET') {
        // For testing - check if API is reachable
        echo json_encode([
            "success" => true,
            "message" => "Login API is running",
            "method" => "POST",
            "required_fields" => ["email", "password"],
            "test_accounts" => [
                [
                    "email" => "admin@sun.com",
                    "password" => "password",
                    "role" => "admin",
                    "redirects_to" => "/admin-dashboard"
                ],
                [
                    "email" => "user@sun.com",
                    "password" => "user123",
                    "role" => "user",
                    "redirects_to" => "/admin-dashboard"
                ]
            ]
        ]);
    } else {
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Method not allowed"
        ]);
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
