<?php
// companys.php - CRUD API for service companies

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';

class CompanyApi {
    private $conn;
    private $table = "companies";
    private $columnCache = null;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    private function safeText($value): string {
        return trim((string)($value ?? ''));
    }

    private function getAvailableColumns(): array {
        if (is_array($this->columnCache)) {
            return $this->columnCache;
        }

        $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = isset($row['Field']) ? trim((string)$row['Field']) : '';
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        $this->columnCache = $columns;
        return $this->columnCache;
    }

    private function hasColumn(string $column): bool {
        return in_array($column, $this->getAvailableColumns(), true);
    }

    private function buildSelectColumns(): string {
        $preferred = [
            'id',
            'company_code',
            'company_name',
            'product',
            'contact_person',
            'phone',
            'email',
            'address',
            'notes',
            'source_pdf',
            'created_at',
            'updated_at',
        ];

        $available = array_values(array_filter($preferred, array($this, 'hasColumn')));
        return implode(', ', $available);
    }

    private function companyCodeExists(string $companyCode): bool {
        $query = "SELECT id FROM " . $this->table . " WHERE company_code = :company_code LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':company_code', $companyCode, PDO::PARAM_STR);
        $stmt->execute();
        return (bool)$stmt->fetch();
    }

    private function nextCompanyId(): int {
        $query = "SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM " . $this->table;
        $stmt = $this->conn->query($query);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['next_id'] ?? 1);
    }

    private function generateCompanyCode(): string {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = "CMP" . date("Ymd") . strtoupper(substr(str_replace('.', '', uniqid('', true)), -6));
            if (!$this->companyCodeExists($code)) {
                return $code;
            }
        }

        return "CMP" . date("Ymd") . strtoupper(substr(str_replace('.', '', uniqid('', true)), -6));
    }

    public function getAll(string $search = '', string $startDate = '', string $endDate = ''): array {
        $query = "SELECT " . $this->buildSelectColumns() . "
                  FROM " . $this->table . " WHERE 1=1";

        $params = [];

        if ($search !== '') {
            $query .= " AND (
                company_name LIKE :search OR
                company_code LIKE :search OR
                product LIKE :search OR
                contact_person LIKE :search OR
                phone LIKE :search OR
                email LIKE :search OR
                " . ($this->hasColumn('source_pdf') ? "source_pdf LIKE :search" : "1=0") . "
            )";
            $params[':search'] = '%' . $search . '%';
        }

        if ($startDate !== '' && $this->hasColumn('created_at')) {
            $query .= " AND DATE(created_at) >= :start_date";
            $params[':start_date'] = $startDate;
        }

        if ($endDate !== '' && $this->hasColumn('created_at')) {
            $query .= " AND DATE(created_at) <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $query .= $this->hasColumn('created_at') ? " ORDER BY created_at DESC" : " ORDER BY id DESC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id) {
        $query = "SELECT " . $this->buildSelectColumns() . "
                  FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(array $input): array {
        $companyName = $this->safeText($input['company_name'] ?? '');
        $product = $this->safeText($input['product'] ?? '');

        if ($companyName === '' || $product === '') {
            return ['success' => false, 'message' => 'company_name and product are required'];
        }

        $companyCode = $this->generateCompanyCode();
        $contactPerson = $this->safeText($input['contact_person'] ?? '');
        $phone = $this->safeText($input['phone'] ?? '');
        $email = $this->safeText($input['email'] ?? '');
        $address = $this->safeText($input['address'] ?? '');
        $notes = $this->safeText($input['notes'] ?? '');
        $sourcePdf = $this->safeText($input['source_pdf'] ?? '');

        $insertData = [
            'company_code' => $companyCode,
            'company_name' => $companyName,
            'product' => $product,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'notes' => $notes,
        ];
        $createdCompanyId = null;
        if ($this->hasColumn('id')) {
            $createdCompanyId = $this->nextCompanyId();
            $insertData = array_merge(['id' => $createdCompanyId], $insertData);
        }
        if ($this->hasColumn('source_pdf')) {
            $insertData['source_pdf'] = $sourcePdf;
        }
        if ($this->hasColumn('created_at')) {
            $insertData['created_at'] = '__NOW__';
        }
        if ($this->hasColumn('updated_at')) {
            $insertData['updated_at'] = '__NOW__';
        }

        $columns = array_keys($insertData);
        $placeholders = [];
        foreach ($columns as $column) {
            if ($insertData[$column] === '__NOW__') {
                $placeholders[] = 'NOW()';
            } else {
                $placeholders[] = ':' . $column;
            }
        }

        $query = "INSERT INTO " . $this->table . " (" . implode(', ', $columns) . ")
                  VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $this->conn->prepare($query);
        foreach ($insertData as $column => $value) {
            if ($value === '__NOW__') {
                continue;
            }
            $stmt->bindValue(':' . $column, $value, $column === 'id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Company created successfully',
                'company_id' => (int)($createdCompanyId ?: $this->conn->lastInsertId()),
                'company_code' => $companyCode
            ];
        }

        return ['success' => false, 'message' => 'Failed to create company'];
    }

    public function update(int $id, array $input): array {
        $existing = $this->getById($id);
        if (!$existing) {
            return ['success' => false, 'message' => 'Company not found', 'status' => 404];
        }

        $companyName = $this->safeText($input['company_name'] ?? $existing['company_name']);
        $product = $this->safeText($input['product'] ?? $existing['product']);

        if ($companyName === '' || $product === '') {
            return ['success' => false, 'message' => 'company_name and product are required'];
        }

        $contactPerson = $this->safeText($input['contact_person'] ?? $existing['contact_person']);
        $phone = $this->safeText($input['phone'] ?? $existing['phone']);
        $email = $this->safeText($input['email'] ?? $existing['email']);
        $address = $this->safeText($input['address'] ?? $existing['address']);
        $notes = $this->safeText($input['notes'] ?? $existing['notes']);
        $sourcePdf = $this->safeText($input['source_pdf'] ?? ($existing['source_pdf'] ?? ''));

        $updateData = [
            'company_name' => $companyName,
            'product' => $product,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'notes' => $notes,
        ];
        if ($this->hasColumn('source_pdf')) {
            $updateData['source_pdf'] = $sourcePdf;
        }
        if ($this->hasColumn('updated_at')) {
            $updateData['updated_at'] = '__NOW__';
        }

        $setClauses = [];
        foreach (array_keys($updateData) as $column) {
            $setClauses[] = $updateData[$column] === '__NOW__'
                ? $column . " = NOW()"
                : $column . " = :" . $column;
        }

        $query = "UPDATE " . $this->table . "
                  SET " . implode(",\n                      ", $setClauses) . "
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        foreach ($updateData as $column => $value) {
            if ($value === '__NOW__') {
                continue;
            }
            $stmt->bindValue(':' . $column, $value, PDO::PARAM_STR);
        }

        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Company updated successfully'];
        }

        return ['success' => false, 'message' => 'Failed to update company'];
    }

    public function delete(int $id): array {
        $existing = $this->getById($id);
        if (!$existing) {
            return ['success' => false, 'message' => 'Company not found', 'status' => 404];
        }

        $query = "DELETE FROM " . $this->table . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Company deleted successfully'];
        }

        return ['success' => false, 'message' => 'Failed to delete company'];
    }
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database connection failed"]);
        exit();
    }

    $api = new CompanyApi($conn);
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            if (isset($_GET['id']) && $_GET['id'] !== '') {
                $id = (int)$_GET['id'];
                $company = $api->getById($id);
                if (!$company) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Company not found"]);
                    break;
                }

                echo json_encode(["success" => true, "company" => $company]);
                break;
            }

            $search = trim((string)($_GET['search'] ?? ''));
            $startDate = trim((string)($_GET['start_date'] ?? ''));
            $endDate = trim((string)($_GET['end_date'] ?? ''));
            $companies = $api->getAll($search, $startDate, $endDate);

            echo json_encode([
                "success" => true,
                "count" => count($companies),
                "companys" => $companies
            ]);
            break;

        case 'POST':
            $input = json_decode(file_get_contents("php://input"), true);
            if (!is_array($input)) {
                $input = $_POST;
            }

            $result = $api->create((array)$input);
            if ($result['success']) {
                http_response_code(201);
                echo json_encode($result);
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
            break;

        case 'PUT':
            $input = json_decode(file_get_contents("php://input"), true);
            if (!is_array($input)) {
                $input = [];
            }

            $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($input['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Company ID is required"]);
                break;
            }

            $result = $api->update($id, $input);
            if ($result['success']) {
                echo json_encode($result);
            } else {
                $status = isset($result['status']) ? (int)$result['status'] : 400;
                http_response_code($status);
                echo json_encode($result);
            }
            break;

        case 'DELETE':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Company ID is required"]);
                break;
            }

            $result = $api->delete($id);
            if ($result['success']) {
                echo json_encode($result);
            } else {
                $status = isset($result['status']) ? (int)$result['status'] : 400;
                http_response_code($status);
                echo json_encode($result);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(["success" => false, "message" => "Method not allowed"]);
            break;
    }
} catch (Throwable $e) {
    error_log('companys.php fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}
?>
