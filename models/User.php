<?php

require_once __DIR__ . '/Model.php';

class User extends Model {
    protected $table = 'users';

    public function create($data) {
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = $key;
            // Value is enclosed in quotes, or should be NULL if value is null
            $values[] = ($value === null) ? 'NULL' : "'" . $this->escape($value) . "'";
        }
        
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $values) . ")";
        
        // The query() method from Model.php is used here
        return $this->query($sql); 
    }

    public function findByEmail($email) {
        $email = $this->escape($email);
        
        $sql = "SELECT * FROM {$this->table} WHERE email = '$email'";
        $result = $this->query($sql);
        
        return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    public function findById($id) {
        $id = $this->escape($id);
        
        $sql = "SELECT * FROM {$this->table} WHERE id = '$id'";
        $result = $this->query($sql);
        
        return $result && $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    public function update($id, $data) {
        $id = $this->escape($id);
        $updates = [];
        
        foreach ($data as $key => $value) {
            // CRITICAL FIX: Properly handle setting a database column to NULL
            $value_sql = ($value === null) ? 'NULL' : "'" . $this->escape($value) . "'";
            $updates[] = "$key = $value_sql";
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE id = '$id'";
        return $this->query($sql);
    }

    public function getAllUsersByType($user_type, $filters = []) {
        $user_type = $this->escape($user_type);
        $where = ["user_type = '$user_type'", "is_active = 1"];
        
        if (!empty($filters['industry'])) {
            $where[] = "industry = '" . $this->escape($filters['industry']) . "'";
        }
        if (!empty($filters['location'])) {
            $where[] = "location LIKE '%" . $this->escape($filters['location']) . "%'";
        }
        if (!empty($filters['start_date'])) {
            $where[] = "created_at >= '" . $this->escape($filters['start_date']) . "'";
        }
        if (!empty($filters['end_date'])) {
            $where[] = "created_at <= '" . $this->escape($filters['end_date']) . "'";
        }
        
        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where);
        
        $result = $this->query($sql);
        $users = [];
        // Only proceed if the query was successful
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        return $users;
    }
}