<?php
// models/JobAlertSubscription.php

require_once dirname(__FILE__) . '/Model.php';

class JobAlertSubscription extends Model {
    protected $table = 'job_alert_subscriptions';
    
    /**
     * Retrieves active subscriptions for a given frequency.
     * @param string $frequency 'daily' or 'weekly'
     * @return array
     */
    public function getSubscriptionsByFrequency($frequency) {
        $freq = $this->escape($frequency);
        $sql = "SELECT * FROM {$this->table} 
                WHERE frequency = '$freq' 
                AND is_active = TRUE";
        
        return $this->queryAndFetchAll($sql);
    }
}