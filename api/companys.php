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

    public function __construct($conn) {
        $this->conn = $conn;
    }

    private function safeText($value): string {
        return trim((string)($value ?? ''));
    }

    private function companyCodeExists(string $companyCode): bool {
        $query = "SELECT id FROM " . $this->table . " WHERE company_code = :company_code LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':company_code', $companyCode, PDO::PARAM_STR);
        $stmt->execute();
        return (bool)$stmt->fetch();
    }

    private function generateCompanyCode(): string {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = "CMP" . date("Ymd") . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            if (!$this->companyCodeExists($code)) {
                return $code;
            }
        }

        return "CMP" . date("Ymd") . strtoupper(substr(uniqid('', true), -6));
    }

    public function getAll(string $search = '', string $startDate = '', string $endDate = ''): array {
        $query = "SELECT id, company_code, company_name, product, contact_person, phone, email, address, notes, source_pdf, created_at, updated_at
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
                source_pdf LIKE :search
            )";
            $params[':search'] = '%' . $search . '%';
        }

        if ($startDate !== '') {
            $query .= " AND DATE(created_at) >= :start_date";
            $params[':start_date'] = $startDate;
        }

        if ($endDate !== '') {
            $query .= " AND DATE(created_at) <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $query .= " ORDER BY created_at DESC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id) {
        $query = "SELECT id, company_code, company_name, product, contact_person, phone, email, address, notes, source_pdf, created_at, updated_at
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

        $query = "INSERT INTO " . $this->table . " (
                    company_code, company_name, product, contact_person, phone, email, address, notes, source_pdf, created_at, updated_at
                  ) VALUES (
                    :company_code, :company_name, :product, :contact_person, :phone, :email, :address, :notes, :source_pdf, NOW(), NOW()
                  )";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':company_code', $companyCode, PDO::PARAM_STR);
        $stmt->bindValue(':company_name', $companyName, PDO::PARAM_STR);
        $stmt->bindValue(':product', $product, PDO::PARAM_STR);
        $stmt->bindValue(':contact_person', $contactPerson, PDO::PARAM_STR);
        $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':address', $address, PDO::PARAM_STR);
        $stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
        $stmt->bindValue(':source_pdf', $sourcePdf, PDO::PARAM_STR);

        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Company created successfully',
                'company_id' => (int)$this->conn->lastInsertId(),
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
        $sourcePdf = $this->safeText($input['source_pdf'] ?? $existing['source_pdf']);

        $query = "UPDATE " . $this->table . "
                  SET company_name = :company_name,
                      product = :product,
                      contact_person = :contact_person,
                      phone = :phone,
                      email = :email,
                      address = :address,
                      notes = :notes,
                      source_pdf = :source_pdf,
                      updated_at = NOW()
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':company_name', $companyName, PDO::PARAM_STR);
        $stmt->bindValue(':product', $product, PDO::PARAM_STR);
        $stmt->bindValue(':contact_person', $contactPerson, PDO::PARAM_STR);
        $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':address', $address, PDO::PARAM_STR);
        $stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
        $stmt->bindValue(':source_pdf', $sourcePdf, PDO::PARAM_STR);

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
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}
?>
