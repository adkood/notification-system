<?php
// models/Interview.php (New File)
require_once __DIR__ . '/Model.php';

class Interview extends Model {
    protected $table = 'interview_schedules'; // Assuming this is your interview table name

    public function __construct() {
        parent::__construct();
        $this->table = 'interview_schedules';
    }

    public function mark24HourReminderSent($id) {
        return $this->update($id, ['reminder_sent_24h' => 1]);
    }

    public function mark2HourReminderSent($id) {
        return $this->update($id, ['reminder_sent_2h' => 1]);
    }
}