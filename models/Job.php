<?php
// models/Job.php

require_once __DIR__ . '/Model.php';

class Job extends Model {
    // This is sufficient for setting the table name
    protected $table = 'jobs'; 

    public function __construct() {
        // Only call parent constructor; remove the redundant table assignment
        parent::__construct(); 
    }

    /**
     * Finds a job by its unique ID.
     */
    public function findById($jobId) {
        $jobId = $this->escape($jobId); // Use inherited escape() method
        $sql = "SELECT * FROM {$this->table} WHERE id = '{$jobId}' LIMIT 1";
        $result = $this->query($sql); // Use inherited query() method
        
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
            $companyId = $this->escape($filters['company_id']); // Use inherited escape() method
            $sql .= " AND j.company_id = '{$companyId}'";
        }
        
        // Example: Filter by search term
        if (isset($filters['search'])) {
            $search = $this->escape('%' . $filters['search'] . '%'); // Use inherited escape() method
            $sql .= " AND (j.title LIKE '{$search}' OR j.description LIKE '{$search}' OR u.first_name LIKE '{$search}')";
        }

        $sql .= " ORDER BY j.created_at DESC LIMIT {$limit} OFFSET {$offset}";
        
        // Use inherited queryAndFetchAll (or query) for cleaner fetching
        return $this->queryAndFetchAll($sql); 
    }

    public function searchAlertJobs($location, $role, $skills, $companyId, $lookbackDays) {
        $where = ["j.is_active = 1", "j.created_at >= DATE_SUB(NOW(), INTERVAL $lookbackDays DAY)"];
        
        // Build WHERE clauses based on subscription criteria
        if ($location) {
            $loc = $this->escape($location);
            $where[] = "j.location LIKE '%{$loc}%'";
        }
        if ($role) {
            // Searches for the role within the job title
            $r = $this->escape($role);
            $where[] = "j.title LIKE '%{$r}%'";
        }
        if ($skills) {
            // Note: Use CONCAT or full-text search for optimal performance with TEXT columns in production
            $s = $this->escape($skills);
            $where[] = "j.requirements LIKE '%{$s}%'";
        }
        if ($companyId) {
            $cid = $this->escape($companyId);
            $where[] = "j.company_id = '{$cid}'";
        }
        
        $sql = "SELECT j.id, j.title, j.location, u.first_name AS company_name 
                FROM {$this->table} j 
                JOIN users u ON j.company_id = u.id 
                WHERE " . implode(' AND ', $where) . "
                ORDER BY j.created_at DESC";
                
        return $this->queryAndFetchAll($sql);
    }
}