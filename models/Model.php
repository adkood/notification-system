<?php

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
                // Throw an exception for the try/catch block to catch
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }
            // Ensure DB_CHARSET is defined in config/database.php
            $this->conn->set_charset(DB_CHARSET); 
        } catch (Exception $e) {
            // Stop execution if the database connection fails
            die("Database connection error: " . $e->getMessage()); 
        }
    }

    // Safely escape data for SQL queries
    protected function escape($data) {
        // Handle null values explicitly for proper insertion/update
        if ($data === null) {
            return null;
        }
        return $this->conn->real_escape_string($data);
    }

    // Execute a query (used for SELECT, UPDATE, INSERT, DELETE)
    public function query($sql) {
        $result = $this->conn->query($sql);
        if (!$result) {
            // Log the error for debugging, but don't expose sensitive info to user
            error_log("SQL Error: " . $this->conn->error . "\nQuery: " . $sql);
        }
        return $result;
    }

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