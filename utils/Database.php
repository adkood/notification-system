<?php
// utils/Database.php
require_once __DIR__ . '/../config/database.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        // Connect to the database
        $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        // Check connection
        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }
    }

    /**
     * Singleton pattern to ensure only one connection exists
     */
    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Executes a query (SELECT)
     */
    public function query($sql) {
        $result = $this->connection->query($sql);
        if (!$result) {
            // Log error or throw exception in production
            error_log("Database Error: " . $this->connection->error . "\nSQL: " . $sql);
            return false;
        }
        return $result;
    }

    /**
     * Executes a non-SELECT query (INSERT, UPDATE, DELETE)
     */
    public function execute($sql) {
        $result = $this->connection->query($sql);
        if (!$result) {
            error_log("Database Execute Error: " . $this->connection->error . "\nSQL: " . $sql);
        }
        return $result;
    }

    /**
     * Escapes data to prevent SQL injection
     */
    public function escape($value) {
        return $this->connection->real_escape_string($value);
    }

    public function getLastInsertId() {
        return $this->connection->insert_id;
    }

    public function getConnection() {
        return $this->connection;
    }
}