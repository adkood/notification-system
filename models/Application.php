<?php
// models/Application.php

require_once __DIR__ . '/Model.php';

class Application extends Model {
    protected $table = 'applications';

    public function __construct() {
        parent::__construct();
        // Removed redundant: $this->table = 'applications';
    }
    
    // Method to check if a candidate already applied to a job
    public function hasApplied($candidateId, $jobId) {
        // --- CRITICAL FIX: Changed $this->db->escape() to $this->escape() ---
        $candidateId = $this->escape($candidateId);
        $jobId = $this->escape($jobId);
        
        $sql = "SELECT id FROM {$this->table} WHERE candidate_id = '{$candidateId}' AND job_id = '{$jobId}' LIMIT 1";
        
        // --- CRITICAL FIX: Changed $this->db->query() to $this->query() ---
        $result = $this->query($sql);
        
        return $result && $result->num_rows > 0;
    }

    /**
     * Retrieves applications submitted by a specific candidate, joining job and company names.
     */
    public function getByCandidateId($candidateId, $limit = 10, $offset = 0) {
        // --- FIX: Using inherited escape() ---
        $candidateId = $this->escape($candidateId);
        $sql = "
            SELECT a.*, j.title, j.location, u.first_name as company_name 
            FROM applications a 
            JOIN jobs j ON a.job_id = j.id 
            JOIN users u ON j.company_id = u.id 
            WHERE a.candidate_id = '{$candidateId}'
            ORDER BY a.applied_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        // Assuming your Model.php provides queryAndFetchAll() using inherited methods
        return $this->queryAndFetchAll($sql); 
    }

    /**
     * Retrieves applications received by a specific employer, joining job and candidate names.
     */
    public function getByEmployerId($employerId, $limit = 10, $offset = 0) {
        // --- FIX: Using inherited escape() ---
        $employerId = $this->escape($employerId);
        $sql = "
            SELECT a.*, j.title AS job_title, c.first_name AS candidate_first_name, c.last_name AS candidate_last_name 
            FROM applications a 
            JOIN jobs j ON a.job_id = j.id 
            JOIN users c ON a.candidate_id = c.id 
            -- NOTE: This filter is incorrect. 'a.employer_id' field doesn't exist in applications table.
            -- It should filter on the job's company_id (j.company_id).
            WHERE j.company_id = '{$employerId}' 
            ORDER BY a.applied_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        // Assuming your Model.php provides queryAndFetchAll() using inherited methods
        return $this->queryAndFetchAll($sql);
    }
}