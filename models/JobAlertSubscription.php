<?php
// models/JobAlertSubscription.php

require_once __DIR__ . '/Model.php';

class JobAlertSubscription extends Model {
    protected $table = 'job_alert_subscriptions';

    public function __construct() {
        parent::__construct();
        $this->table = 'job_alert_subscriptions';
    }

    /**
     * Retrieves all active subscriptions for a specific email frequency.
     * @param string $frequency The desired frequency ('daily' or 'weekly').
     * @return array List of matching subscription records.
     */
    public function getByFrequency(string $frequency) {
        $frequency = $this->escape($frequency);
        
        $sql = "SELECT * FROM {$this->table} 
                WHERE frequency = '{$frequency}' 
                AND is_active = 1"; 
        
        return $this->queryAndFetchAll($sql);
    }
    
    
    public function findByCandidateId(int $candidateId) {
        $candidateId = $this->escape($candidateId);
        $sql = "SELECT * FROM {$this->table} 
                WHERE candidate_id = '{$candidateId}' 
                AND is_active = 1";
        
        return $this->queryAndFetchAll($sql);
    }

    /**
     * Finds a single active subscription by candidate ID and a specific frequency.
     * Useful for checking if a candidate already has an active alert of a certain type.
     * @param int $candidateId The ID of the candidate.
     * @param string $frequency The frequency ('daily', 'weekly', etc.).
     * @return array|false The subscription record or false if not found.
     */
    public function findActiveByCandidateAndFrequency(int $candidateId, string $frequency) {
        $candidateId = $this->escape($candidateId);
        $frequency = $this->escape($frequency);

        $sql = "SELECT * FROM {$this->table} 
                WHERE candidate_id = '{$candidateId}' 
                AND frequency = '{$frequency}'
                AND is_active = 1
                LIMIT 1";

        $result = $this->query($sql);
        return $result ? $result->fetch_assoc() : false;
    }
    
    /**
     * Deletes a record by its primary key ID.
     * Required for handleAlertDelete (action=unsubscribe).
     * @param int $id The ID of the subscription.
     * @return bool True on success, false on failure.
     */
    public function delete(int $id) {
        $id = $this->escape($id);
        $sql = "DELETE FROM {$this->table} WHERE id = '{$id}'";
        
        // Use executeAndGetAffectedRows to confirm deletion
        return $this->executeAndGetAffectedRows($sql) > 0;
    }
    
    public function safeEscape($data) {
        return $this->escape($data);
    }
}