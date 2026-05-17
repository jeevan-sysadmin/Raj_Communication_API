<?php
require_once __DIR__ . '/../helpers/jwt_helper.php';

class Auth {
    
    public static function getBearerToken() {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        // Also check for auth in other locations
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    public static function verifyToken($token = null) {
        if (!$token) {
            $token = self::getBearerToken();
        }
        
        if (!$token) {
            return false;
        }
        
        $payload = JWT::decode($token);
        return $payload;
    }
    
    public static function requireAuth() {
        $user = self::verifyToken();
        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Authentication required'
            ]);
            exit();
        }
        return $user;
    }
}
?>