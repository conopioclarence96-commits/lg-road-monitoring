<?php
// Database configuration for LGU Road and Infrastructure Department
class Database
{
    // Defaults are for local development only.
    // In production, set environment variables:
    // DB_HOST, DB_NAME, DB_USER, DB_PASS (optionally DB_PORT)
    private $host;
    private $username;
    private $password;
    private $database;
    private $port;
    private $conn;

    public function __construct()
    {
        $this->loadConfig();
        $this->connect();
    }

    private function loadConfig()
    {
        // Allow optional local override file (do NOT commit secrets):
        // road_and_infra_dept/config/database.local.php returning an array:
        // ['host'=>..., 'username'=>..., 'password'=>..., 'database'=>..., 'port'=>...]
        $localFile = __DIR__ . '/database.local.php';
        $local = [];
        if (is_file($localFile)) {
            $maybe = include $localFile;
            if (is_array($maybe)) {
                $local = $maybe;
            }
        }

        $this->host = $local['host'] ?? (getenv('DB_HOST') ?: 'localhost');
        $this->username = $local['username'] ?? (getenv('DB_USER') ?: 'root');
        $this->password = $local['password'] ?? (getenv('DB_PASS') ?: 'root123');
        $this->database = $local['database'] ?? (getenv('DB_NAME') ?: 'rgmap_lgu_road_infra');
        $this->port = (int)($local['port'] ?? (getenv('DB_PORT') ?: 3306));
    }

    private function connect()
    {
        try {
            $this->conn = new mysqli(
                $this->host,
                $this->username,
                $this->password,
                $this->database,
                $this->port
            );

            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }

            // Set charset to utf8mb4
            $this->conn->set_charset("utf8mb4");

        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw $e;
        }
    }

    public function getConnection()
    {
        return $this->conn;
    }

    public function close()
    {
        if ($this->conn) {
            $this->conn->close();
        }
    }

    // Prevent SQL injection with prepared statements
    public function prepare($sql)
    {
        return $this->conn->prepare($sql);
    }

    // Execute query with error handling
    public function query($sql)
    {
        $result = $this->conn->query($sql);
        if (!$result) {
            error_log("Query error: " . $this->conn->error);
            return false;
        }
        return $result;
    }

    // Get last insert ID
    public function getLastInsertId()
    {
        return $this->conn->insert_id;
    }

    // Escape string for security
    public function escapeString($string)
    {
        return $this->conn->real_escape_string($string);
    }

    public function __destruct()
    {
        $this->close();
    }
}
?>