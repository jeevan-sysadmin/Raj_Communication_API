<?php
// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get request method and URI
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];

// Parse the URI to get endpoint
$base_path = '/sun_computers/api';
$path = parse_url($request_uri, PHP_URL_PATH);
$path = str_replace($base_path, '', $path);
$path = trim($path, '/');
$segments = $path ? explode('/', $path) : [];

// Get query parameters
$queryParams = $_GET;

// Handle different endpoints
$endpoint = $segments[0] ?? '';

switch ($endpoint) {
    case 'login':
        require_once __DIR__ . '/login.php';
        break;
        
    case 'dashboard':
        // Pass path parameter for routing within dashboard.php
        if (isset($segments[1])) {
            $_GET['path'] = implode('/', array_slice($segments, 1));
        }
        require_once __DIR__ . '/dashboard.php';
        break;
        
    case 'payments':
        require_once __DIR__ . '/payments.php';
        break;
        
    case 'notifications':
        require_once __DIR__ . '/notifications.php';
        break;
        
    case 'test':
        // Test endpoint
        echo json_encode([
            "success" => true,
            "message" => "Sun Computers API is working",
            "method" => $method,
            "endpoint" => $endpoint,
            "path" => $path,
            "timestamp" => date('Y-m-d H:i:s'),
            "server" => [
                "php_version" => phpversion(),
                "server_software" => $_SERVER['SERVER_SOFTWARE']
            ]
        ]);
        break;
        
    case 'health':
        // Health check
        try {
            require_once __DIR__ . '/../config/Database.php';
            $database = new Database();
            $conn = $database->getConnection();
            
            $status = "healthy";
            $http_code = 200;
            $db_status = "connected";
            
            if (!$conn) {
                $status = "unhealthy";
                $http_code = 503;
                $db_status = "disconnected";
            } else {
                // Test database query
                $test_query = $conn->query("SELECT 1");
                if (!$test_query) {
                    $status = "unhealthy";
                    $http_code = 503;
                    $db_status = "query_failed";
                }
            }
            
            http_response_code($http_code);
            echo json_encode([
                "status" => $status,
                "timestamp" => date('c'),
                "database" => $db_status,
                "service" => "Sun Computers API",
                "version" => "1.0"
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => $e->getMessage(),
                "timestamp" => date('c')
            ]);
        }
        break;
        
    default:
        if (empty($endpoint)) {
            // API home
            echo json_encode([
                "success" => true,
                "message" => "Sun Computers API v1.0",
                "timestamp" => date('Y-m-d H:i:s'),
                "documentation" => "API endpoints",
                "endpoints" => [
                    "POST /api/login" => "User authentication",
                    "GET /api/dashboard" => "Dashboard overview and stats",
                    "GET /api/dashboard/stats" => "Detailed statistics",
                    "GET /api/dashboard/orders" => "Recent orders",
                    "GET /api/dashboard/deliveries" => "Delivery management",
                    "GET /api/payments" => "Payment listing and management",
                    "POST /api/payments" => "Create new payment",
                    "GET /api/notifications" => "User notifications",
                    "GET /api/test" => "Test endpoint",
                    "GET /api/health" => "System health check"
                ],
                "authentication" => "Bearer token required for all endpoints except /login",
                "usage" => [
                    "login" => "Send POST request with email and password",
                    "protected_endpoints" => "Include Authorization: Bearer {token} header"
                ]
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => "Endpoint not found: /$endpoint",
                "available_endpoints" => [
                    "/login",
                    "/dashboard",
                    "/payments",
                    "/notifications",
                    "/test",
                    "/health"
                ]
            ]);
        }
        break;
}