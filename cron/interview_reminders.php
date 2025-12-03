<?php
// processors/interview_reminder.php
// To be run via Cron: * * * * * /usr/bin/php /path/to/project/processors/interview_reminder.php

// Define ROOT_PATH relative to the script location
define('ROOT_PATH', dirname(__DIR__));

// Autoloading for required classes
require_once ROOT_PATH . '/models/Model.php';
require_once ROOT_PATH . '/models/Interview.php';
require_once ROOT_PATH . '/services/EmailService.php';
require_once ROOT_PATH . '/models/User.php'; // Required for EmailService context
require_once ROOT_PATH . '/models/EmailQueue.php';
// Configuration files
require_once ROOT_PATH . '/config/database.php';

class InterviewReminder {
    private $interviewModel;
    private $emailService;

    public function __construct() {
        $this->interviewModel = new Interview();
        $this->emailService = new EmailService(); // Requires other models/templates to be defined
    }

    public function send24HourReminders() {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $sql = "SELECT i.*, 
                c.first_name as candidate_first_name, c.last_name as candidate_last_name, c.email as candidate_email,
                e.first_name as employer_first_name
                FROM interview_schedules i
                JOIN users c ON i.candidate_id = c.id
                JOIN users e ON i.employer_id = e.id
                WHERE i.interview_date = '{$tomorrow}'
                AND i.status = 'scheduled'
                AND i.reminder_sent_24h = 0";
        
        $reminders_sent = $this->processReminders($sql, 24);

        return $reminders_sent;
    }

    public function send2HourReminders() {
        // Calculate the next 2-hour window (e.g., now until 2 hours from now)
        $future_time = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $current_time = date('Y-m-d H:i:s');
        
        $sql = "SELECT i.*, 
                c.first_name as candidate_first_name, c.last_name as candidate_last_name, c.email as candidate_email,
                e.first_name as employer_first_name
                FROM interview_schedules i
                JOIN users c ON i.candidate_id = c.id
                JOIN users e ON i.employer_id = e.id
                WHERE CONCAT(i.interview_date, ' ', i.interview_time) BETWEEN '{$current_time}' AND '{$future_time}'
                AND i.status = 'scheduled'
                AND i.reminder_sent_2h = 0";

        $reminders_sent = $this->processReminders($sql, 2);

        return $reminders_sent;
    }
    
    private function processReminders($sql, $hours) {
        $result = $this->interviewModel->query($sql);
        if (!$result) return 0;
        
        $reminders_sent = 0;

        while ($interview = $result->fetch_assoc()) {
            // NOTE: Missing job_title requires you to join the 'jobs' table in the SQL above.
            
            $email_data = [
                // Required by EmailService->sendInterviewReminder
                'candidate_email' => $interview['candidate_email'],
                'candidate_name' => $interview['candidate_first_name'] . ' ' . $interview['candidate_last_name'],
                'company_name' => $interview['employer_first_name'],
                
                // Interview details
                'id' => $interview['id'],
                'job_title' => $interview['job_title'] ?? 'Interview Position', // Fix this via better SQL join
                'interview_date' => $interview['interview_date'],
                'interview_time' => $interview['interview_time'],
                'mode' => $interview['mode'],
                'location_or_link' => $interview['location_or_link'],
                'additional_notes' => $interview['additional_notes']
            ];

            // Send reminder
            if ($this->emailService->sendInterviewReminder($email_data)) {
                // Mark as sent
                if ($hours === 24) {
                    $this->interviewModel->mark24HourReminderSent($interview['id']);
                } else {
                    $this->interviewModel->mark2HourReminderSent($interview['id']);
                }
                $reminders_sent++;
            }
        }
        return $reminders_sent;
    }
}

// --- EXECUTION BLOCK ---
$reminder = new InterviewReminder();

$results_24h = $reminder->send24HourReminders();
$results_2h = $reminder->send2HourReminders();

// Log results
$log_message = date('Y-m-d H:i:s') . " - 24h reminders sent: $results_24h, 2h reminders sent: $results_2h\n";
file_put_contents(ROOT_PATH . '/logs/interview_reminders.log', $log_message, FILE_APPEND);

echo "Interview Reminder finished. 24h: {$results_24h}, 2h: {$results_2h}\n";