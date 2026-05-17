<?php
class JWT {
    private static $secret_key = 'sun-computers-secret-key-2025';
    
    public static function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secret_key, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    public static function decode($token) {
        try {
            $parts = explode('.', $token);
            if (count($parts) != 3) {
                return false;
            }
            
            list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
            
            $signature = base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlSignature));
            $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secret_key, true);
            
            if (!hash_equals($signature, $expectedSignature)) {
                return false;
            }
            
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlPayload)), true);
            
            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return false;
            }
            
            return $payload;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>