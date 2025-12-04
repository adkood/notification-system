<?php
// models/Interview.php

require_once __DIR__ . '/Model.php';

class Interview extends Model {
    protected $table = 'interview_schedules';

    public function __construct() {
        parent::__construct();
        $this->table = 'interview_schedules';
    }

    /**
     * Creates a new interview schedule record.
     * @param array $data Array of interview details (candidate_id, employer_id, job_id, date, time, mode, location_or_link, etc.).
     * @return int|false The ID of the newly created interview, or false on failure.
     */
    public function create(array $data) {
        // The base Model::create method should handle sanitization and insertion.
        // Data keys must match your interview_schedules table columns exactly.
        return parent::create($data); 
    }

    /**
     * Finds an interview schedule by its primary ID.
     * @param int $id The interview ID.
     * @return array|false The interview record, or false if not found.
     */
    public function findById($id) {
        return parent::findById($id);
    }
    
    /**
     * Updates an existing interview schedule record.
     * @param int $id The interview ID.
     * @param array $data The data to update.
     * @return bool Success status.
     */
    public function update($id, array $data) {
        return parent::update($id, $data);
    }

    // --- Specific Reminder Methods ---

    public function mark24HourReminderSent($id) {
        return $this->update($id, ['reminder_sent_24h' => 1]);
    }

    public function mark2HourReminderSent($id) {
        return $this->update($id, ['reminder_sent_2h' => 1]);
    }
    
    /**
     * Retrieves upcoming interviews for reminder processing (e.g., for a cron job).
     * @param string $timeframe '24h' or '2h' to get interviews approaching that window.
     * @return array List of interviews.
     */
    public function getUpcomingInterviews(string $timeframe) {
        $now = date('Y-m-d H:i:s');
        $targetTime = match ($timeframe) {
            '24h' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            '2h' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            default => false
        };

        if (!$targetTime) {
            return [];
        }

        $reminderColumn = "reminder_sent_{$timeframe}";

        // This query finds interviews scheduled between now and the target time, 
        // that haven't had the specified reminder sent yet.
        $sql = "SELECT * FROM {$this->table} 
                WHERE CONCAT(interview_date, ' ', interview_time) BETWEEN ? AND ? 
                AND {$reminderColumn} = 0
                AND status = 'scheduled'";

        // NOTE: Your base Model::query() method must handle prepared statements (binding $now and $targetTime).
        // This is pseudo-code representation of the required logic.
        // return $this->query($sql, [$now, $targetTime])->fetchAll();
        return []; // Replace with actual Model::query() call
    }
}