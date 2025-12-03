<?php
// models/Application.php

require_once __DIR__ . '/Model.php';

class Application extends Model {
    protected $table = 'applications';

    public function __construct() {
        parent::__construct();
        $this->table = 'applications';
    }
    
    // Method to check if a candidate already applied to a job
    public function hasApplied($candidateId, $jobId) {
        $candidateId = $this->db->escape($candidateId);
        $jobId = $this->db->escape($jobId);
        $sql = "SELECT id FROM {$this->table} WHERE candidate_id = '{$candidateId}' AND job_id = '{$jobId}' LIMIT 1";
        $result = $this->db->query($sql);
        return $result && $result->num_rows > 0;
    }

    /**
     * Retrieves applications submitted by a specific candidate, joining job and company names.
     */
    public function getByCandidateId($candidateId, $limit = 10, $offset = 0) {
        $candidateId = $this->db->escape($candidateId);
        $sql = "
            SELECT a.*, j.title, j.location, u.first_name as company_name 
            FROM applications a 
            JOIN jobs j ON a.job_id = j.id 
            JOIN users u ON j.company_id = u.id 
            WHERE a.candidate_id = '{$candidateId}'
            ORDER BY a.applied_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        return $this->db->queryAndFetchAll($sql);
    }

    /**
     * Retrieves applications received by a specific employer, joining job and candidate names.
     */
    public function getByEmployerId($employerId, $limit = 10, $offset = 0) {
        $employerId = $this->db->escape($employerId);
        $sql = "
            SELECT a.*, j.title AS job_title, c.first_name AS candidate_first_name, c.last_name AS candidate_last_name 
            FROM applications a 
            JOIN jobs j ON a.job_id = j.id 
            JOIN users c ON a.candidate_id = c.id 
            WHERE a.employer_id = '{$employerId}'
            ORDER BY a.applied_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        return $this->db->queryAndFetchAll($sql);
    }
}