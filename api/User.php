<?php
// C:\xampp\htdocs\sun_computers\api\Users.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

class User {
    private $conn;
    private $table = 'users';

    public function __construct($db) {
        $this->conn = $db;
    }

    // Get all users with optional filtering
    public function getUsers($params = []) {
        $search = isset($params['search']) ? $params['search'] : '';
        $role = isset($params['role']) ? $params['role'] : '';
        
        $query = "SELECT id, name, email, phone, role, avatar, is_active, last_login, created_at 
                 FROM " . $this->table . " WHERE 1=1";
        
        $conditions = [];
        $bindParams = [];
        
        if (!empty($search)) {
            $conditions[] = "(name LIKE :search OR email LIKE :search OR phone LIKE :search)";
            $bindParams[':search'] = "%$search%";
        }
        
        if (!empty($role) && $role !== 'all') {
            $conditions[] = "role = :role";
            $bindParams[':role'] = $role;
        }
        
        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }
        
        $query .= " ORDER BY created_at DESC";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($bindParams);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Failed to get users: " . $e->getMessage());
        }
    }

    // Get single user by ID
    public function getUserById($id) {
        $query = "SELECT id, name, email, phone, role, avatar, is_active, last_login, created_at 
                 FROM " . $this->table . " WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            return null;
        } catch (PDOException $e) {
            throw new Exception("Failed to get user: " . $e->getMessage());
        }
    }

    // Create new user
    public function createUser($data) {
        // Validate required fields
        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            throw new Exception("Name, email, and password are required");
        }

        // Check if email exists
        $checkQuery = "SELECT id FROM " . $this->table . " WHERE email = :email";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':email', $data['email']);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() > 0) {
            throw new Exception("Email already exists");
        }
        
        // Hash password
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
        
        $query = "INSERT INTO " . $this->table . " 
                 (name, email, password, phone, role, avatar, is_active, created_at) 
                 VALUES (:name, :email, :password, :phone, :role, :avatar, :is_active, NOW())";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':name', $data['name']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':phone', $data['phone'] ?? '');
            $stmt->bindParam(':role', $data['role'] ?? 'user');
            $stmt->bindParam(':avatar', $data['avatar'] ?? '');
            $stmt->bindParam(':is_active', $data['is_active'] ?? 1);
            
            if ($stmt->execute()) {
                $user_id = $this->conn->lastInsertId();
                return [
                    'id' => $user_id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'role' => $data['role'] ?? 'user'
                ];
            }
            return false;
        } catch (PDOException $e) {
            throw new Exception("Failed to create user: " . $e->getMessage());
        }
    }

    // Update user
    public function updateUser($id, $data) {
        // Don't allow updating email if it already exists for another user
        if (!empty($data['email'])) {
            $checkQuery = "SELECT id FROM " . $this->table . " WHERE email = :email AND id != :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':email', $data['email']);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                throw new Exception("Email already exists for another user");
            }
        }
        
        $query = "UPDATE " . $this->table . " SET 
                 name = :name,
                 email = :email,
                 phone = :phone,
                 role = :role,
                 avatar = :avatar,
                 is_active = :is_active,
                 updated_at = NOW()
                 WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':name', $data['name']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':phone', $data['phone'] ?? '');
            $stmt->bindParam(':role', $data['role'] ?? 'user');
            $stmt->bindParam(':avatar', $data['avatar'] ?? '');
            $stmt->bindParam(':is_active', $data['is_active'] ?? 1);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new Exception("Failed to update user: " . $e->getMessage());
        }
    }

    // Update user password
    public function updatePassword($id, $oldPassword, $newPassword) {
        // First verify old password
        $query = "SELECT password FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($oldPassword, $row['password'])) {
                $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                
                $updateQuery = "UPDATE " . $this->table . " SET 
                               password = :password,
                               updated_at = NOW()
                               WHERE id = :id";
                
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(':password', $hashedPassword);
                $updateStmt->bindParam(':id', $id);
                
                return $updateStmt->execute();
            } else {
                throw new Exception("Old password is incorrect");
            }
        }
        throw new Exception("User not found");
    }

    // Delete user
    public function deleteUser($id) {
        // Don't allow deleting the last admin
        $checkQuery = "SELECT role FROM " . $this->table . " WHERE id = :id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            throw new Exception("User not found");
        }
        
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user['role'] === 'admin') {
            // Check if this is the only admin
            $adminCountQuery = "SELECT COUNT(*) as admin_count FROM " . $this->table . " WHERE role = 'admin'";
            $adminStmt = $this->conn->query($adminCountQuery);
            $adminCount = $adminStmt->fetch(PDO::FETCH_ASSOC)['admin_count'];
            
            if ($adminCount <= 1) {
                throw new Exception("Cannot delete the only admin user");
            }
        }
        
        $query = "DELETE FROM " . $this->table . " WHERE id = :id";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new Exception("Failed to delete user: " . $e->getMessage());
        }
    }

    // Get user statistics
    public function getUserStats() {
        $stats = [];
        
        try {
            // Total users
            $query = "SELECT COUNT(*) as total_users FROM " . $this->table;
            $stmt = $this->conn->query($query);
            $stats['total_users'] = $stmt->fetchColumn();
            
            // Active users
            $query = "SELECT COUNT(*) as active_users FROM " . $this->table . " WHERE is_active = 1";
            $stmt = $this->conn->query($query);
            $stats['active_users'] = $stmt->fetchColumn();
            
            // Admin count
            $query = "SELECT COUNT(*) as admin_count FROM " . $this->table . " WHERE role = 'admin'";
            $stmt = $this->conn->query($query);
            $stats['admin_count'] = $stmt->fetchColumn();
            
            // User count
            $query = "SELECT COUNT(*) as user_count FROM " . $this->table . " WHERE role = 'user'";
            $stmt = $this->conn->query($query);
            $stats['user_count'] = $stmt->fetchColumn();
            
            // Recent signups (last 7 days)
            $query = "SELECT COUNT(*) as recent_signups FROM " . $this->table . " WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $stmt = $this->conn->query($query);
            $stats['recent_signups'] = $stmt->fetchColumn();
            
            return $stats;
        } catch (PDOException $e) {
            throw new Exception("Failed to get user stats: " . $e->getMessage());
        }
    }
}

// Handle API requests
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $user = new User($db);
    
    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Handle different methods
    switch ($method) {
        case 'GET':
            // Get single user by ID
            if (isset($_GET['id'])) {
                $userId = $_GET['id'];
                $result = $user->getUserById($userId);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'data' => $result
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'success' => false,
                        'message' => 'User not found'
                    ]);
                }
            }
            // Get user statistics
            elseif (isset($_GET['stats'])) {
                $result = $user->getUserStats();
                echo json_encode([
                    'success' => true,
                    'data' => $result
                ]);
            }
            // Get all users
            else {
                $params = [
                    'search' => $_GET['search'] ?? '',
                    'role' => $_GET['role'] ?? ''
                ];
                
                $result = $user->getUsers($params);
                echo json_encode([
                    'success' => true,
                    'data' => $result,
                    'count' => count($result)
                ]);
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data)) {
                throw new Exception("No data provided");
            }
            
            $result = $user->createUser($data);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'User created successfully',
                    'data' => $result
                ]);
            } else {
                throw new Exception("Failed to create user");
            }
            break;
            
        case 'PUT':
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data) || !isset($data['id'])) {
                throw new Exception("User ID is required");
            }
            
            // Handle password update separately
            if (isset($data['old_password']) && isset($data['new_password'])) {
                $result = $user->updatePassword(
                    $data['id'],
                    $data['old_password'],
                    $data['new_password']
                );
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Password updated successfully'
                    ]);
                } else {
                    throw new Exception("Failed to update password");
                }
            } else {
                // Regular user update
                $result = $user->updateUser($data['id'], $data);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'User updated successfully'
                    ]);
                } else {
                    throw new Exception("Failed to update user");
                }
            }
            break;
            
        case 'DELETE':
            if (isset($_GET['id'])) {
                $userId = $_GET['id'];
                $result = $user->deleteUser($userId);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'User deleted successfully'
                    ]);
                } else {
                    throw new Exception("Failed to delete user");
                }
            } else {
                throw new Exception("User ID is required");
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
