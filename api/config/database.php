<?php
class Database {
    private $host = "127.0.0.1";
    private $db_name = "raj communication";
    private $username = "root";
    private $password = "";
    private static $sharedConn = null;
    public $conn;

    private function getDatabaseCandidates() {
        $candidates = [
            $this->db_name,
            'raj_communication',
        ];

        return array_values(array_unique(array_filter($candidates, function ($name) {
            return is_string($name) && trim($name) !== '';
        })));
    }

    private function configureConnection(PDO $conn) {
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $conn->setAttribute(PDO::ATTR_PERSISTENT, true);
        $conn->setAttribute(PDO::ATTR_TIMEOUT, 5);
        $conn->exec("SET SESSION sql_mode = REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', '')");
        return $conn;
    }

    public function getConnection() {
        if (self::$sharedConn instanceof PDO) {
            $this->conn = self::$sharedConn;
            return $this->conn;
        }

        $this->conn = null;

        foreach ($this->getDatabaseCandidates() as $dbName) {
            try {
                $this->conn = new PDO(
                    "mysql:host=" . $this->host . ";dbname=" . $dbName . ";charset=utf8mb4",
                    $this->username,
                    $this->password
                );
                $this->conn = $this->configureConnection($this->conn);
                self::$sharedConn = $this->conn;
                return $this->conn;
            } catch(PDOException $exception) {
                error_log("Connection error for database '" . $dbName . "': " . $exception->getMessage());
                $this->conn = null;
            }
        }

        return null;
    }
}
