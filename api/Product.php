<?php
// Product.php - UPDATED WITH SERIAL NUMBER, CLAIM TYPE, AND "NONE" OPTION

// Enable error reporting
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

function splitSerialInputValues($value) {
    if ($value === null) {
        return [];
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\r\n\t,;|]+/', $raw);
    if (!$parts) {
        return [];
    }

    $serials = [];
    $seen = [];

    foreach ($parts as $part) {
        $normalized = preg_replace('/\s+/', '', trim((string)$part));
        if ($normalized === '') {
            continue;
        }
        if (isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $serials[] = $normalized;
    }

    return $serials;
}

function expandProductRowsBySerial($row) {
    if (!is_array($row)) {
        return [];
    }

    $serials = array_key_exists('serial_number', $row)
        ? splitSerialInputValues($row['serial_number'])
        : [];

    if (count($serials) <= 1) {
        $row['serial_number'] = count($serials) === 1 ? $serials[0] : '';
        return [$row];
    }

    $expandedRows = [];
    foreach ($serials as $serial) {
        $copy = $row;
        $copy['serial_number'] = $serial;
        $copy['stock_quantity'] = 1;
        $expandedRows[] = $copy;
    }

    return $expandedRows;
}

function normalizeProductPayloadAliases($row) {
    if (!is_array($row)) {
        return $row;
    }

    if (!isset($row['product_name']) || trim((string)$row['product_name']) === '') {
        $aliasKeys = ['productName', 'name'];
        foreach ($aliasKeys as $aliasKey) {
            if (isset($row[$aliasKey]) && trim((string)$row[$aliasKey]) !== '') {
                $row['product_name'] = trim((string)$row[$aliasKey]);
                break;
            }
        }
    }

    if (!isset($row['stock_quantity']) || $row['stock_quantity'] === '' || $row['stock_quantity'] === null) {
        $stockAliasKeys = ['stockQuantity', 'quantity', 'qty'];
        foreach ($stockAliasKeys as $aliasKey) {
            if (array_key_exists($aliasKey, $row) && $row[$aliasKey] !== '' && $row[$aliasKey] !== null) {
                $row['stock_quantity'] = $row[$aliasKey];
                break;
            }
        }
    }

    return $row;
}

require_once __DIR__ . '/config/database.php';

function ensureProductsStockColumns(PDO $conn) {
    $checkQuery = "SELECT COLUMN_NAME
                   FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'products'
                     AND COLUMN_NAME = 'stock_quantity'";
    $stmt = $conn->query($checkQuery);
    $existing = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['COLUMN_NAME'])) {
            $existing[$row['COLUMN_NAME']] = true;
        }
    }

    if (!isset($existing['stock_quantity'])) {
        $conn->exec("ALTER TABLE products ADD COLUMN stock_quantity INT(10) UNSIGNED NOT NULL DEFAULT 1 AFTER price");
    }

    $conn->exec("ALTER TABLE products MODIFY stock_quantity INT(10) UNSIGNED NOT NULL DEFAULT 1");
    $conn->exec("UPDATE products SET stock_quantity = 1 WHERE stock_quantity IS NULL OR stock_quantity <= 0");
}

// Product class
class Product {
    private $conn;
    private $table = 'products';
    private $columnExistsCache = [];
    
    // Valid claim types
    private $valid_claim_types = ['none', 'shop_claim', 'company_claim', 'sun_to_company', 'company_to_sun'];
    private $valid_categories = ['cctv', 'harddisk', 'DVR', 'wificamer', 'others'];
    private $valid_statuses = ['active', 'inactive', 'discontinued', 'out_of_stock'];

    public $id;
    public $product_code;
    public $serial_number;
    public $stock_quantity;
    public $is_spare_product;
    public $product_name;
    public $brand;
    public $model;
    public $category;
    public $claim_type;
    public $specifications;
    public $purchase_date;
    public $warranty_period;
    public $price;
    public $status;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function normalizeSerialNumber($serial_number) {
        $serial_number = trim((string)$serial_number);
        return preg_replace('/\s+/', '', $serial_number);
    }

    private function normalizedSerialSql($columnName = 'serial_number') {
        return "REPLACE(REPLACE(REPLACE(REPLACE(TRIM(" . $columnName . "), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), '')";
    }

    private function isDuplicateEntryException($exception) {
        if (!($exception instanceof PDOException)) {
            return false;
        }

        $code = $exception->errorInfo[1] ?? null;
        return (int)$code === 1062;
    }

    private function normalizeStockQuantity($stock_quantity) {
        if ($stock_quantity === null || $stock_quantity === '') {
            return 1;
        }

        $value = intval($stock_quantity);
        return $value < 0 ? 0 : $value;
    }

    private function hasColumn($columnName) {
        if (array_key_exists($columnName, $this->columnExistsCache)) {
            return $this->columnExistsCache[$columnName];
        }

        $query = "SELECT COUNT(*) AS cnt
                  FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table_name
                    AND COLUMN_NAME = :column_name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':table_name', $this->table);
        $stmt->bindParam(':column_name', $columnName);
        $stmt->execute();
        $count = (int)($stmt->fetch()['cnt'] ?? 0);
        $exists = $count > 0;
        $this->columnExistsCache[$columnName] = $exists;
        return $exists;
    }

    private function productCodeExists($product_code) {
        $query = "SELECT id FROM " . $this->table . " WHERE product_code = :product_code LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_code', $product_code);
        $stmt->execute();
        return $stmt->fetch() ? true : false;
    }

    private function generateUniqueProductCode() {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = 'PRD' . date('Ymd') . strtoupper(bin2hex(random_bytes(3))); // 17 chars
            if (!$this->productCodeExists($candidate)) {
                return $candidate;
            }
        }

        return 'PRD' . date('Ymd') . strtoupper(substr(uniqid('', true), -6));
    }
    
    // Validate claim type
    private function validateClaimType($claim_type) {
        if (empty($claim_type)) {
            return 'none';
        }
        if (in_array($claim_type, $this->valid_claim_types)) {
            return $claim_type;
        }
        return 'none';
    }
    
    // Validate category
    private function validateCategory($category) {
        if (empty($category)) {
            return 'cctv';
        }
        if (in_array($category, $this->valid_categories)) {
            return $category;
        }
        return 'cctv';
    }
    
    // Validate status
    private function validateStatus($status) {
        if (empty($status)) {
            return 'active';
        }
        if (in_array($status, $this->valid_statuses)) {
            return $status;
        }
        return 'active';
    }

    public function getAll($search = '', $status = '', $category = '', $claim_type = '') {
        $query = "SELECT * FROM " . $this->table . " WHERE 1=1";
        $params = [];
        
        if(!empty($search)) {
            $query .= " AND (product_name LIKE :search OR brand LIKE :search OR model LIKE :search OR product_code LIKE :search OR serial_number LIKE :search)";
            $params[':search'] = "%" . $search . "%";
        }
        
        if(!empty($status) && $status != 'all') {
            $query .= " AND status = :status";
            $params[':status'] = $status;
        }
        
        if(!empty($category) && $category != 'all') {
            $query .= " AND category = :category";
            $params[':category'] = $category;
        }
        
        if(!empty($claim_type) && $claim_type != 'all') {
            $query .= " AND claim_type = :claim_type";
            $params[':claim_type'] = $claim_type;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        foreach($params as $key => $value) {
            $stmt->bindValue($key, $value);
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

    public function getBySerialNumber($serial_number) {
        $serial_number = $this->normalizeSerialNumber($serial_number);
        if ($serial_number === '') {
            return false;
        }

        $query = "SELECT * FROM " . $this->table . " 
                  WHERE " . $this->normalizedSerialSql('serial_number') . " = :serial_number
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':serial_number', $serial_number);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function checkSerialNumberExists($serial_number, $exclude_id = null) {
        $serial_number = $this->normalizeSerialNumber($serial_number);
        if ($serial_number === '') {
            return false;
        }

        $query = "SELECT id FROM " . $this->table . " 
                  WHERE " . $this->normalizedSerialSql('serial_number') . " = :serial_number";
        if ($exclude_id) {
            $query .= " AND id != :exclude_id";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':serial_number', $serial_number);
        if ($exclude_id) {
            $stmt->bindParam(':exclude_id', $exclude_id);
        }
        $stmt->execute();
        return $stmt->fetch() ? true : false;
    }

    public function create() {
        // Validate inputs
        $this->serial_number = $this->normalizeSerialNumber($this->serial_number);
        $this->product_name = trim($this->product_name);
        
        // Check if serial number already exists
        if (!empty($this->serial_number) && $this->checkSerialNumberExists($this->serial_number)) {
            return ['success' => false, 'message' => 'Serial number already exists'];
        }
        
        // Generate product code
        $this->product_code = $this->generateUniqueProductCode();
        
        // Validate and set defaults
        $this->brand = !empty($this->brand) ? trim($this->brand) : null;
        $this->model = !empty($this->model) ? trim($this->model) : null;
        $this->category = $this->validateCategory($this->category);
        $this->claim_type = $this->validateClaimType($this->claim_type);
        $this->specifications = !empty($this->specifications) ? trim($this->specifications) : null;
        $this->serial_number = !empty($this->serial_number) ? $this->serial_number : null;
        $this->purchase_date = !empty($this->purchase_date) ? $this->purchase_date : null;
        $this->warranty_period = !empty($this->warranty_period) ? trim($this->warranty_period) : null;
        $this->price = !empty($this->price) ? floatval($this->price) : 0;
        $this->stock_quantity = $this->normalizeStockQuantity($this->stock_quantity);
        $this->status = $this->validateStatus($this->status);
        $this->is_spare_product = !empty($this->is_spare_product) ? 1 : 0;
        
        $hasStockQuantity = $this->hasColumn('stock_quantity');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $fields = [
                "product_code = :product_code",
                "serial_number = :serial_number",
                "is_spare_product = :is_spare_product",
                "product_name = :product_name",
                "brand = :brand",
                "model = :model",
                "category = :category",
                "claim_type = :claim_type",
                "specifications = :specifications",
                "purchase_date = :purchase_date",
                "warranty_period = :warranty_period",
                "price = :price",
                "status = :status",
                "created_at = NOW()"
            ];
            if ($hasStockQuantity) {
                $fields[] = "stock_quantity = :stock_quantity";
            }
            $query = "INSERT INTO " . $this->table . " SET " . implode(", ", $fields);
            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(':product_code', $this->product_code);
            $stmt->bindParam(':serial_number', $this->serial_number);
            $stmt->bindParam(':is_spare_product', $this->is_spare_product);
            $stmt->bindParam(':product_name', $this->product_name);
            $stmt->bindParam(':brand', $this->brand);
            $stmt->bindParam(':model', $this->model);
            $stmt->bindParam(':category', $this->category);
            $stmt->bindParam(':claim_type', $this->claim_type);
            $stmt->bindParam(':specifications', $this->specifications);
            $stmt->bindParam(':purchase_date', $this->purchase_date);
            $stmt->bindParam(':warranty_period', $this->warranty_period);
            $stmt->bindParam(':price', $this->price);
            if ($hasStockQuantity) {
                $stmt->bindParam(':stock_quantity', $this->stock_quantity);
            }
            $stmt->bindParam(':status', $this->status);
            try {
                if ($stmt->execute()) {
                    return [
                        'success' => true,
                        'product_id' => $this->conn->lastInsertId(),
                        'product_code' => $this->product_code,
                        'message' => 'Product created successfully'
                    ];
                }
            } catch (PDOException $e) {
                if ($this->isDuplicateEntryException($e)) {
                    $errorText = strtolower($e->getMessage());

                    if (strpos($errorText, 'serial_number') !== false || strpos($errorText, 'ux_products_serial_number') !== false) {
                        return ['success' => false, 'message' => 'Serial number already exists'];
                    }

                    if (strpos($errorText, 'product_code') !== false) {
                        $this->product_code = $this->generateUniqueProductCode();
                        $stmt->bindParam(':product_code', $this->product_code);
                        continue;
                    }
                }
                $errorText = strtolower($e->getMessage());
                if ($hasStockQuantity && strpos($errorText, "unknown column 'stock_quantity'") !== false) {
                    $hasStockQuantity = false;
                    $this->columnExistsCache['stock_quantity'] = false;
                    continue;
                }
                throw $e;
            }
        }

        return ['success' => false, 'message' => 'Failed to create product'];
    }

    public function update() {
        // Validate inputs
        $this->serial_number = $this->normalizeSerialNumber($this->serial_number);
        $this->product_name = trim($this->product_name);
        
        // Check if serial number already exists for other product
        if (!empty($this->serial_number) && $this->checkSerialNumberExists($this->serial_number, $this->id)) {
            return ['success' => false, 'message' => 'Serial number already exists for another product'];
        }
        
        // Validate and set defaults
        $this->brand = !empty($this->brand) ? trim($this->brand) : null;
        $this->model = !empty($this->model) ? trim($this->model) : null;
        $this->category = $this->validateCategory($this->category);
        $this->claim_type = $this->validateClaimType($this->claim_type);
        $this->specifications = !empty($this->specifications) ? trim($this->specifications) : null;
        $this->serial_number = !empty($this->serial_number) ? $this->serial_number : null;
        $this->purchase_date = !empty($this->purchase_date) ? $this->purchase_date : null;
        $this->warranty_period = !empty($this->warranty_period) ? trim($this->warranty_period) : null;
        $this->price = !empty($this->price) ? floatval($this->price) : 0;
        $this->stock_quantity = $this->normalizeStockQuantity($this->stock_quantity);
        $this->status = $this->validateStatus($this->status);
        $this->is_spare_product = !empty($this->is_spare_product) ? 1 : 0;
        
        $hasStockQuantity = $this->hasColumn('stock_quantity');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $fields = [
                "serial_number = :serial_number",
                "is_spare_product = :is_spare_product",
                "product_name = :product_name",
                "brand = :brand",
                "model = :model",
                "category = :category",
                "claim_type = :claim_type",
                "specifications = :specifications",
                "purchase_date = :purchase_date",
                "warranty_period = :warranty_period",
                "price = :price",
                "status = :status",
                "updated_at = NOW()"
            ];
            if ($hasStockQuantity) {
                $fields[] = "stock_quantity = :stock_quantity";
            }
            $query = "UPDATE " . $this->table . " SET " . implode(", ", $fields) . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(':id', $this->id);
            $stmt->bindParam(':serial_number', $this->serial_number);
            $stmt->bindParam(':is_spare_product', $this->is_spare_product);
            $stmt->bindParam(':product_name', $this->product_name);
            $stmt->bindParam(':brand', $this->brand);
            $stmt->bindParam(':model', $this->model);
            $stmt->bindParam(':category', $this->category);
            $stmt->bindParam(':claim_type', $this->claim_type);
            $stmt->bindParam(':specifications', $this->specifications);
            $stmt->bindParam(':purchase_date', $this->purchase_date);
            $stmt->bindParam(':warranty_period', $this->warranty_period);
            $stmt->bindParam(':price', $this->price);
            if ($hasStockQuantity) {
                $stmt->bindParam(':stock_quantity', $this->stock_quantity);
            }
            $stmt->bindParam(':status', $this->status);
            try {
                if($stmt->execute()) {
                    return ['success' => true, 'message' => 'Product updated successfully'];
                }
                return ['success' => false, 'message' => 'Failed to update product'];
            } catch (PDOException $e) {
                $errorText = strtolower($e->getMessage());
                if ($hasStockQuantity && strpos($errorText, "unknown column 'stock_quantity'") !== false) {
                    $hasStockQuantity = false;
                    $this->columnExistsCache['stock_quantity'] = false;
                    continue;
                }
                throw $e;
            }
        }
        return ['success' => false, 'message' => 'Failed to update product'];
    }

    public function delete() {
        // First check if product has any orders
        $checkQuery = "SELECT COUNT(*) as order_count FROM service_orders WHERE product_id = :product_id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':product_id', $this->id);
        $checkStmt->execute();
        $result = $checkStmt->fetch();
        
        if ($result && $result['order_count'] > 0) {
            // Product has orders, mark as discontinued instead of deleting
            $query = "UPDATE " . $this->table . " SET status = 'discontinued', updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $this->id);
            if($stmt->execute()) {
                return ['success' => true, 'message' => 'Product has associated orders. Status changed to discontinued'];
            }
            return ['success' => false, 'message' => 'Failed to update product status'];
        } else {
            // No orders, safe to delete
            $query = "DELETE FROM " . $this->table . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $this->id);
            if($stmt->execute()) {
                return ['success' => true, 'message' => 'Product deleted successfully'];
            }
            return ['success' => false, 'message' => 'Failed to delete product'];
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
ensureProductsStockColumns($db);
$product = new Product($db);

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch($method) {
        case 'GET':
            if(isset($_GET['id'])) {
                $data = $product->getById($_GET['id']);
                if($data) {
                    echo json_encode(["success" => true, "product" => $data]);
                } else {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Product not found"]);
                }
            } elseif(isset($_GET['serial_number'])) {
                $data = $product->getBySerialNumber($_GET['serial_number']);
                if($data) {
                    echo json_encode(["success" => true, "product" => $data]);
                } else {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Product not found with this serial number"]);
                }
            } else {
                $search = isset($_GET['search']) ? $_GET['search'] : '';
                $status = isset($_GET['status']) ? $_GET['status'] : '';
                $category = isset($_GET['category']) ? $_GET['category'] : '';
                $claim_type = isset($_GET['claim_type']) ? $_GET['claim_type'] : '';
                
                $stmt = $product->getAll($search, $status, $category, $claim_type);
                $products = $stmt->fetchAll();
                
                // Clean up null values for JSON response
                foreach($products as &$prod) {
                    if($prod['brand'] === null) $prod['brand'] = '';
                    if($prod['model'] === null) $prod['model'] = '';
                    if($prod['specifications'] === null) $prod['specifications'] = '';
                    if($prod['purchase_date'] === null) $prod['purchase_date'] = '';
                    if($prod['warranty_period'] === null) $prod['warranty_period'] = '';
                    if(!isset($prod['is_spare_product']) || $prod['is_spare_product'] === null) $prod['is_spare_product'] = 0;
                }
                
                echo json_encode([
                    "success" => true,
                    "products" => $products,
                    "count" => count($products)
                ]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents("php://input"), true);
            
            if(!$input) {
                $input = $_POST;
            }

            $input = normalizeProductPayloadAliases($input);

            if (!isset($input) || !is_array($input)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid input data"]);
                break;
            }

            $isBatch = isset($input['products']) && is_array($input['products']);

            if (!$isBatch) {
                $expandedInputRows = expandProductRowsBySerial($input);
                if (count($expandedInputRows) > 1) {
                    $isBatch = true;
                    $input = ['products' => $expandedInputRows];
                } elseif (count($expandedInputRows) === 1) {
                    $input = $expandedInputRows[0];
                }
            }

            if ($isBatch) {
                $rawRows = $input['products'];
                if (count($rawRows) === 0) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "Products array is empty"]);
                    break;
                }

                $rows = [];
                $createdProducts = [];
                $errors = [];

                foreach ($rawRows as $index => $row) {
                    if (!is_array($row)) {
                        $errors[] = ["index" => $index, "message" => "Invalid row format"];
                        continue;
                    }

                    $row = normalizeProductPayloadAliases($row);
                    $expandedRows = expandProductRowsBySerial($row);
                    foreach ($expandedRows as $expandedRow) {
                        $expandedRow['__source_index'] = $index;
                        $rows[] = $expandedRow;
                    }
                }

                foreach ($rows as $index => $row) {
                    $sourceIndex = isset($row['__source_index']) ? intval($row['__source_index']) : $index;

                    if (empty($row['product_name']) || trim((string)$row['product_name']) === '') {
                        $errors[] = ["index" => $sourceIndex, "message" => "Product name is required"];
                        continue;
                    }

                    $product->serial_number = isset($row['serial_number']) ? $row['serial_number'] : '';
                    $product->is_spare_product = isset($row['is_spare_product']) ? $row['is_spare_product'] : 0;
                    $product->product_name = $row['product_name'];
                    $product->brand = isset($row['brand']) ? $row['brand'] : '';
                    $product->model = isset($row['model']) ? $row['model'] : '';
                    $product->category = isset($row['category']) ? $row['category'] : 'cctv';
                    $product->claim_type = isset($row['claim_type']) ? $row['claim_type'] : 'none';
                    $product->specifications = isset($row['specifications']) ? $row['specifications'] : '';
                    $product->purchase_date = isset($row['purchase_date']) ? $row['purchase_date'] : null;
                    $product->warranty_period = isset($row['warranty_period']) ? $row['warranty_period'] : '';
                    $product->price = isset($row['price']) ? $row['price'] : 0;
                    $product->stock_quantity = isset($row['stock_quantity']) ? $row['stock_quantity'] : 1;
                    $product->status = isset($row['status']) ? $row['status'] : 'active';

                    $result = $product->create();
                    if ($result['success']) {
                        $createdProducts[] = [
                            "index" => $sourceIndex,
                            "product_name" => $row['product_name'],
                            "product_id" => $result['product_id'],
                            "product_code" => $result['product_code']
                        ];
                    } else {
                        $errors[] = [
                            "index" => $sourceIndex,
                            "product_name" => $row['product_name'],
                            "message" => $result['message']
                        ];
                    }
                }

                $createdCount = count($createdProducts);
                $failedCount = count($errors);
                $allSuccess = $createdCount > 0 && $failedCount === 0;

                if ($createdCount === 0) {
                    http_response_code(400);
                    echo json_encode([
                        "success" => false,
                        "message" => "No products were created",
                        "created_count" => 0,
                        "failed_count" => $failedCount,
                        "errors" => $errors
                    ]);
                    break;
                }

                http_response_code($allSuccess ? 201 : 207);
                echo json_encode([
                    "success" => $allSuccess,
                    "partial" => !$allSuccess,
                    "message" => $allSuccess
                        ? "All products created successfully"
                        : "Some products were created, but some rows failed",
                    "created_count" => $createdCount,
                    "failed_count" => $failedCount,
                    "created_products" => $createdProducts,
                    "errors" => $errors
                ]);
                break;
            }

            if(empty($input['product_name']) || trim((string)$input['product_name']) === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Product name is required"]);
                break;
            }

            // Set product properties
            $product->serial_number = isset($input['serial_number']) ? $input['serial_number'] : '';
            $product->is_spare_product = isset($input['is_spare_product']) ? $input['is_spare_product'] : 0;
            $product->product_name = $input['product_name'];
            $product->brand = isset($input['brand']) ? $input['brand'] : '';
            $product->model = isset($input['model']) ? $input['model'] : '';
            $product->category = isset($input['category']) ? $input['category'] : 'cctv';
            $product->claim_type = isset($input['claim_type']) ? $input['claim_type'] : 'none';
            $product->specifications = isset($input['specifications']) ? $input['specifications'] : '';
            $product->purchase_date = isset($input['purchase_date']) ? $input['purchase_date'] : null;
            $product->warranty_period = isset($input['warranty_period']) ? $input['warranty_period'] : '';
            $product->price = isset($input['price']) ? $input['price'] : 0;
            $product->stock_quantity = isset($input['stock_quantity']) ? $input['stock_quantity'] : 1;
            $product->status = isset($input['status']) ? $input['status'] : 'active';

            $result = $product->create();

            if($result['success']) {
                http_response_code(201);
                echo json_encode([
                    "success" => true,
                    "message" => $result['message'],
                    "product_id" => $result['product_id'],
                    "product_code" => $result['product_code']
                ]);
            } else {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => $result['message']]);
            }
            break;
            
        case 'PUT':
            $rawInput = file_get_contents("php://input");
            $input = json_decode($rawInput, true);
            if(!$input || !is_array($input)) {
                $input = $_POST;
            }

            $id = isset($_GET['id']) ? $_GET['id'] : null;
            if(!$id && isset($_REQUEST['id'])) {
                $id = $_REQUEST['id'];
            }
            if(!$id && isset($input['id'])) {
                $id = $input['id'];
            }
            if(!$id && isset($input['product_id'])) {
                $id = $input['product_id'];
            }
            if(!$id && !empty($_SERVER['QUERY_STRING'])) {
                parse_str($_SERVER['QUERY_STRING'], $queryParams);
                if (isset($queryParams['id'])) {
                    $id = $queryParams['id'];
                }
            }
            if(!$id && !empty($_SERVER['REQUEST_URI'])) {
                $uriParts = parse_url($_SERVER['REQUEST_URI']);
                if (isset($uriParts['query'])) {
                    parse_str($uriParts['query'], $uriQueryParams);
                    if (isset($uriQueryParams['id'])) {
                        $id = $uriQueryParams['id'];
                    }
                }
            }

            if(!$id || intval($id) <= 0) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Product ID is required"]);
                break;
            }

            $input = normalizeProductPayloadAliases($input);

            if (isset($input['serial_number'])) {
                $serialEntries = splitSerialInputValues($input['serial_number']);
                if (count($serialEntries) > 1) {
                    http_response_code(400);
                    echo json_encode([
                        "success" => false,
                        "message" => "Only one serial number is allowed while editing a product"
                    ]);
                    break;
                }
                $input['serial_number'] = count($serialEntries) === 1 ? $serialEntries[0] : '';
            }
            
            // Verify product exists
            $existingProduct = $product->getById($id);
            if(!$existingProduct) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Product not found"]);
                break;
            }
            
            // Set product properties with existing values as fallback
            $product->id = $id;
            $product->serial_number = isset($input['serial_number']) ? $input['serial_number'] : $existingProduct['serial_number'];
            $product->is_spare_product = isset($input['is_spare_product']) ? $input['is_spare_product'] : ($existingProduct['is_spare_product'] ?? 0);
            $product->product_name = isset($input['product_name']) ? $input['product_name'] : $existingProduct['product_name'];
            $product->brand = isset($input['brand']) ? $input['brand'] : ($existingProduct['brand'] ?? '');
            $product->model = isset($input['model']) ? $input['model'] : ($existingProduct['model'] ?? '');
            $product->category = isset($input['category']) ? $input['category'] : ($existingProduct['category'] ?? 'cctv');
            $product->claim_type = isset($input['claim_type']) ? $input['claim_type'] : ($existingProduct['claim_type'] ?? 'none');
            $product->specifications = isset($input['specifications']) ? $input['specifications'] : ($existingProduct['specifications'] ?? '');
            $product->purchase_date = isset($input['purchase_date']) ? $input['purchase_date'] : ($existingProduct['purchase_date'] ?? null);
            $product->warranty_period = isset($input['warranty_period']) ? $input['warranty_period'] : ($existingProduct['warranty_period'] ?? '');
            $product->price = isset($input['price']) ? $input['price'] : ($existingProduct['price'] ?? 0);
            $product->stock_quantity = isset($input['stock_quantity']) ? $input['stock_quantity'] : ($existingProduct['stock_quantity'] ?? 1);
            $product->status = isset($input['status']) ? $input['status'] : ($existingProduct['status'] ?? 'active');
            
            $result = $product->update();
            
            if($result['success']) {
                echo json_encode(["success" => true, "message" => $result['message']]);
            } else {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => $result['message']]);
            }
            break;
            
        case 'DELETE':
            if(isset($_GET['id'])) {
                $product->id = $_GET['id'];
                
                // Verify product exists
                $existingProduct = $product->getById($product->id);
                if(!$existingProduct) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Product not found"]);
                    break;
                }
                
                $result = $product->delete();
                
                if($result['success']) {
                    echo json_encode(["success" => true, "message" => $result['message']]);
                } else {
                    http_response_code(500);
                    echo json_encode(["success" => false, "message" => $result['message']]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Product ID required in URL parameter"]);
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
        "message" => "Server error: " . $e->getMessage(),
        "error" => $e->getMessage()
    ]);
}
?>
