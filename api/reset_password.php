<?php
// C:\xampp\htdocs\sun_computers\api\reset_password.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    // Get authorization header
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (empty($authHeader)) {
        throw new Exception("No token provided");
    }
    
    // Extract token
    $token = str_replace('Bearer ', '', $authHeader);
    
    // Validate token
    $decoded = JWT::decode($token);
    if (!$decoded) {
        throw new Exception("Invalid or expired token");
    }
    
    // Check if user is admin
    if ($decoded['role'] !== 'admin') {
        throw new Exception("Admin access required");
    }
    
    // Get request data
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (empty($data)) {
        throw new Exception("No data provided");
    }
    
    // Validate required fields
    if (empty($data['user_id']) || empty($data['new_password'])) {
        throw new Exception("User ID and new password are required");
    }
    
    // Check if user exists
    $checkQuery = "SELECT id, role FROM users WHERE id = :user_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception("User not found");
    }
    
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    // Hash new password
    $hashedPassword = password_hash($data['new_password'], PASSWORD_BCRYPT);
    
    // Update password directly (admin reset - no old password check)
    $updateQuery = "UPDATE users SET 
                   password = :password,
                   updated_at = NOW()
                   WHERE id = :user_id";
    
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':password', $hashedPassword);
    $updateStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
    
    if ($updateStmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Password reset successfully'
        ]);
    } else {
        throw new Exception("Failed to reset password");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>