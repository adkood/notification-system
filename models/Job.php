<?php
// models/Job.php

require_once __DIR__ . '/Model.php';

class Job extends Model {
    protected $table = 'jobs';

    public function __construct() {
        parent::__construct();
        // Ensure the table is set for the Model's methods
        $this->table = 'jobs'; 
    }

    /**
     * Finds a job by its unique ID.
     */
    public function findById($jobId) {
        $jobId = $this->db->escape($jobId);
        $sql = "SELECT * FROM {$this->table} WHERE id = '{$jobId}' LIMIT 1";
        $result = $this->db->query($sql);
        
        return $result ? $result->fetch_assoc() : false;
    }

    /**
     * Gets a list of jobs with optional filters.
     */
    public function getJobList($filters = [], $limit = 10, $offset = 0) {
        $sql = "SELECT j.*, u.first_name as company_name 
                FROM {$this->table} j 
                JOIN users u ON j.company_id = u.id 
                WHERE j.is_active = 1"; // Only show active jobs

        // Example: Filter by company_id
        if (isset($filters['company_id'])) {
            $companyId = $this->db->escape($filters['company_id']);
            $sql .= " AND j.company_id = '{$companyId}'";
        }
        
        // Example: Filter by search term
        if (isset($filters['search'])) {
            $search = $this->db->escape('%' . $filters['search'] . '%');
            $sql .= " AND (j.title LIKE '{$search}' OR j.description LIKE '{$search}' OR u.first_name LIKE '{$search}')";
        }

        $sql .= " ORDER BY j.created_at DESC LIMIT {$limit} OFFSET {$offset}";
        
        $result = $this->db->query($sql);
        
        $jobs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $jobs[] = $row;
            }
        }
        return $jobs;
    }
}