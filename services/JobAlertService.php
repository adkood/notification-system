<?php
// services/JobAlertService.php

require_once ROOT_PATH . '/models/JobAlertSubscription.php';
require_once ROOT_PATH . '/models/Job.php';
require_once ROOT_PATH . '/models/User.php';
require_once ROOT_PATH . '/services/EmailService.php';

class JobAlertService {
    private $alertModel;
    private $jobModel;
    private $userModel;
    private $emailService;

    public function __construct() {
        $this->alertModel = new JobAlertSubscription();
        $this->jobModel = new Job();
        $this->userModel = new User();
        $this->emailService = new EmailService();
    }
    
    // ------------------------------------------------------------------
    // --- Public API Methods (Called by JobController) ---
    // ------------------------------------------------------------------

    /**
     * Creates a new job alert subscription or updates an existing one for the candidate.
     * @param array $data Subscription data (location, role, frequency, etc., including candidate_id).
     * @return int|bool The ID of the created subscription or false on failure.
     */
    public function createSubscription(array $data) {
        // Option 1: Find existing subscription by candidate_id and matching preferences
        // For simplicity, we'll just create a new one, but ideally, you'd check 
        // if a subscription with the exact same preferences already exists.
        
        // If you only allow one subscription per candidate, you'd update instead of create:
        /*
        $existing = $this->alertModel->findByCandidateId($data['candidate_id']);
        if ($existing) {
            return $this->alertModel->update($existing['id'], $data);
        }
        */
        
        return $this->alertModel->create($data);
    }

    /**
     * Retrieves all active subscriptions for a specific candidate.
     * @param int $candidateId The ID of the user (candidate).
     * @return array List of subscriptions.
     */
    public function getSubscriptionsByCandidate(int $candidateId) {
        // Assuming JobAlertSubscription Model has a method: findByCandidateId($candidateId)
        return $this->alertModel->findByCandidateId($candidateId);
    }

    /**
     * Deletes a subscription for a candidate, ensuring ownership.
     * @param int $alertId The ID of the subscription to delete.
     * @param int $candidateId The ID of the candidate attempting the deletion.
     * @return bool True on successful deletion, false otherwise.
     */
    public function deleteSubscription(int $alertId, int $candidateId) {
        $subscription = $this->alertModel->findById($alertId);
        
        if (!$subscription || $subscription['candidate_id'] != $candidateId) {
            // Subscription not found or user is not the owner
            return false;
        }
        
        // Assuming Model has a delete method
        return $this->alertModel->delete($alertId);
    }


    // ------------------------------------------------------------------
    // --- Private Cron Methods (Unchanged Logic Flow) ---
    // ------------------------------------------------------------------
    

    private function findMatchingJobs(array $criteria, string $timeframe) {
        // Determine the time interval for the SQL query
        $interval = ($timeframe === 'daily') ? '1 DAY' : '7 DAY';
        
        // Escape and convert criteria to lowercase for robust searching
        $location = strtolower($this->alertModel->safeEscape($criteria['location'] ?? ''));
        $role = strtolower($this->alertModel->safeEscape($criteria['role'] ?? ''));
        $companyId = $this->alertModel->safeEscape($criteria['company_id'] ?? ''); // Company ID check remains numeric/string
        $skills = $criteria['skills'] ?? '';

        $sql = "
            SELECT id, title, location, company_id, description 
            FROM jobs 
            WHERE is_active = 1
            AND created_at >= DATE_SUB(NOW(), INTERVAL {$interval})
        ";
        
        // 1. Location Matching (Mandatory, Case-Insensitive)
        if (!empty($location)) {
            // Use LOWER() on the database column for comparison
            $sql .= " AND LOWER(location) LIKE '%{$location}%'";
        }

        // 2. Role/Title Matching (Mandatory, Case-Insensitive)
        if (!empty($role)) {
            // Use LOWER() on the database column for comparison
            $sql .= " AND LOWER(title) LIKE '%{$role}%'";
        }
        
        // 3. Skills Matching (Mandatory, Case-Insensitive)
        if (!empty($skills)) {
            $skills_array = array_filter(array_map('trim', explode(',', $skills)));
            $skill_clauses = [];
            foreach ($skills_array as $skill) {
                $escaped_skill = strtolower($this->alertModel->safeEscape($skill));
                // Check if ANY skill is present in the job description
                $skill_clauses[] = "LOWER(description) LIKE '%{$escaped_skill}%'"; 
            }
            if (!empty($skill_clauses)) {
                // Must match at least ONE skill (OR logic within the mandatory skills block)
                $sql .= " AND (" . implode(' OR ', $skill_clauses) . ")";
            }
        }
        
        // 4. Company ID Matching (Optional, strict match)
        if (!empty($companyId)) {
            $sql .= " AND company_id = '{$companyId}'";
        }

        // *** REMOVE TEMPORARY DEBUG CODE BEFORE DEPLOYMENT ***
        error_log("Job Alert SQL: " . $sql); 
        // ***************************************************

        return $this->jobModel->queryAndFetchAll($sql);
    }
    /**
     * Processes alerts for a specific frequency (daily/weekly).
     * @param string $frequency 'daily' or 'weekly'
     * @return int Count of emails queued.
     */
    public function processAlerts(string $frequency) {
        $total_emails_queued = 0;
        
        // 1. Get all active subscriptions for this frequency
        $subscriptions = $this->alertModel->getByFrequency($frequency);
        
        if (empty($subscriptions)) {
            return 0;
        }

        foreach ($subscriptions as $subscription) {
            
            // 2. Find matching jobs posted since the last alert period
            $matchingJobs = $this->findMatchingJobs($subscription, $frequency);
            
            if (empty($matchingJobs)) {
                continue; // Skip if no new jobs match
            }
            
            // 3. Get candidate details for email
            $candidate = $this->userModel->findById($subscription['candidate_id']);
            
            if (!$candidate) {
                error_log("JobAlertService: Candidate ID {$subscription['candidate_id']} not found.");
                continue;
            }

            // 4. Queue the email
            if ($this->emailService->sendJobAlertEmail(
                $candidate['email'], 
                $candidate['first_name'], 
                $matchingJobs,
                $frequency
            )) {
                $total_emails_queued++;
            }
        }
        
        return $total_emails_queued;
    }
}