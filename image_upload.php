<?php
// C:\xampp\htdocs\raj_communication\api\image_upload.php

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
require_once __DIR__ . '/api/config/database.php';
require_once __DIR__ . '/api/helpers/jwt_helper.php';

class ImageUpload {
    private $conn;
    private $user;
    private $upload_dir = __DIR__ . '/../uploads/profile_images/';
    private $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    private $max_size = 5 * 1024 * 1024; // 5MB

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        
        if (!$this->conn) {
            $this->sendError("Database connection failed", 500);
            exit();
        }
        
        // Verify authentication
        $this->verifyAuth();
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            mkdir($this->upload_dir, 0777, true);
        }
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
    }
    
    private function getBearerToken() {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        if ($method === 'POST') {
            $this->uploadImage();
        } elseif ($method === 'DELETE') {
            $this->deleteImage();
        } else {
            $this->sendError("Method not allowed", 405);
        }
    }
    
    private function uploadImage() {
        try {
            // Check if file was uploaded
            if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
                $this->sendError("No file uploaded or upload error", 400);
                return;
            }
            
            $file = $_FILES['profile_image'];
            
            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, $this->allowed_types)) {
                $this->sendError("Invalid file type. Allowed types: JPEG, PNG, GIF, WebP", 400);
                return;
            }
            
            // Validate file size
            if ($file['size'] > $this->max_size) {
                $this->sendError("File size too large. Maximum size: 5MB", 400);
                return;
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('profile_', true) . '.' . $extension;
            $file_path = $this->upload_dir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                $this->sendError("Failed to move uploaded file", 500);
                return;
            }
            
            // Save to database
            $query = "INSERT INTO images (user_id, filename, original_name, file_path, file_size, mime_type) 
                     VALUES (:user_id, :filename, :original_name, :file_path, :file_size, :mime_type)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':user_id', $this->user['user_id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':filename', $filename, PDO::PARAM_STR);
            $stmt->bindValue(':original_name', $file['name'], PDO::PARAM_STR);
            $stmt->bindValue(':file_path', $file_path, PDO::PARAM_STR);
            $stmt->bindValue(':file_size', $file['size'], PDO::PARAM_INT);
            $stmt->bindValue(':mime_type', $mime_type, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $image_id = $this->conn->lastInsertId();
                $image_url = $this->getImageUrl($filename);
                
                $this->sendSuccess([
                    'message' => 'Image uploaded successfully',
                    'image_id' => $image_id,
                    'filename' => $filename,
                    'image_url' => $image_url,
                    'original_name' => $file['name']
                ]);
            } else {
                // Delete the uploaded file if database insertion fails
                unlink($file_path);
                $this->sendError("Failed to save image info to database", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Upload failed: " . $e->getMessage(), 500);
        }
    }
    
    private function deleteImage() {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (empty($data['filename']) || empty($data['user_id'])) {
                $this->sendError("Filename and user ID are required", 400);
                return;
            }
            
            $filename = $data['filename'];
            $user_id = $data['user_id'];
            $file_path = $this->upload_dir . $filename;
            
            // Delete from database first
            $query = "DELETE FROM images WHERE filename = :filename AND user_id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':filename', $filename, PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                // Delete file from server
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                
                $this->sendSuccess(['message' => 'Image deleted successfully']);
            } else {
                $this->sendError("Failed to delete image from database", 500);
            }
            
        } catch (Exception $e) {
            $this->sendError("Delete failed: " . $e->getMessage(), 500);
        }
    }
    
    private function getImageUrl($filename) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $base_url = $protocol . '://' . $host . '/raj_communication/uploads/profile_images/';
        return $base_url . $filename;
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
    $upload = new ImageUpload();
    $upload->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
