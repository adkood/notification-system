<?php
// models/Model.php (Updated with CRUD Methods)

require_once ROOT_PATH . '/config/database.php';

class Model {
    protected $conn;
    protected $table;

    public function __construct() {
        $this->connect();
    }

    private function connect() {
        try {
            $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }
            // Ensure DB_CHARSET is defined in config/database.php
            $this->conn->set_charset(DB_CHARSET); 
        } catch (Exception $e) {
            die("Database connection error: " . $e->getMessage()); 
        }
    }

    // Safely escape data for SQL queries
    protected function escape($data) {
        if ($data === null) {
            return null;
        }
        return $this->conn->real_escape_string($data);
    }

    // Execute a query (used for SELECT, UPDATE, INSERT, DELETE)
    public function query($sql) {
        $result = $this->conn->query($sql);
        if (!$result) {
            error_log("SQL Error: " . $this->conn->error . "\nQuery: " . $sql);
        }
        return $result;
    }

    public function executeAndGetAffectedRows($sql) {
        $result = $this->query($sql);
        // Only return affected rows if the query was successful (not a SELECT)
        return $result ? $this->conn->affected_rows : 0;
    }
    
    // Executes a SELECT query and fetches all results as an associative array.
    public function queryAndFetchAll($sql) {
        $result = $this->query($sql);
        $data = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            // Don't free result here if it's needed elsewhere (but generally safe for Model methods)
        }
        return $data;
    }

    // --- ESSENTIAL CRUD HELPER METHODS ---
    public function findById($id) {
        $id = $this->escape($id);
        $sql = "SELECT * FROM {$this->table} WHERE id = '{$id}' LIMIT 1";
        $result = $this->query($sql);
        return $result ? $result->fetch_assoc() : false;
    }

    public function create(array $data) {
        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            $fields[] = "`{$key}`";
            // Check if value is null to avoid quoting "NULL" string
            $values[] = ($value === null) ? 'NULL' : "'" . $this->escape($value) . "'";
        }
        $fields_sql = implode(', ', $fields);
        $values_sql = implode(', ', $values);
        
        $sql = "INSERT INTO {$this->table} ({$fields_sql}) VALUES ({$values_sql})";
        
        if ($this->query($sql)) {
            return $this->conn->insert_id;
        }
        return false;
    }

    public function update($id, array $data) {
        $id = $this->escape($id);
        $setClauses = [];
        
        foreach ($data as $key => $value) {
            // Check if value is null
            $val = ($value === null) ? 'NULL' : "'" . $this->escape($value) . "'";
            $setClauses[] = "`{$key}` = {$val}";
        }
        
        $set_sql = implode(', ', $setClauses);
        
        $sql = "UPDATE {$this->table} SET {$set_sql} WHERE id = '{$id}'";
        
        return $this->query($sql);
    }
    // --- END CRUD HELPER METHODS ---

    // Get the ID of the last inserted row
    public function getLastInsertId() {
        return $this->conn->insert_id;
    }

    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}