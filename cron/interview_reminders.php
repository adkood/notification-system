<?php

define('ROOT_PATH', dirname(__DIR__)); 

require_once ROOT_PATH . '/config/constants.php';
require_once ROOT_PATH . '/config/database.php';

require_once ROOT_PATH . '/models/Model.php';
require_once ROOT_PATH . '/models/Interview.php';
require_once ROOT_PATH . '/services/EmailService.php';
require_once ROOT_PATH . '/models/User.php'; 
require_once ROOT_PATH . '/models/EmailQueue.php';
require_once ROOT_PATH . '/models/EmailTemplate.php'; 

class InterviewReminder {
    private $interviewModel;
    private $emailService;

    public function __construct() {
        $this->interviewModel = new Interview();
        $this->emailService = new EmailService(); 
    }

    public function send24HourReminders() {
        
        $sql = "SELECT i.*, 
                c.first_name AS candidate_first_name, c.last_name AS candidate_last_name, c.email AS candidate_email,
                e.first_name AS employer_first_name,
                j.title AS job_title
                FROM interview_schedules i
                JOIN users c ON i.candidate_id = c.id
                JOIN users e ON i.employer_id = e.id
                JOIN jobs j ON i.job_id = j.id  
                WHERE i.status = 'scheduled'
                AND i.reminder_sent_24h = 0
                AND TIMESTAMP(i.interview_date, i.interview_time) > NOW()
                AND TIMESTAMP(i.interview_date, i.interview_time) <= DATE_ADD(NOW(), INTERVAL 24 HOUR)";
        
        $reminders_sent = $this->processReminders($sql, 24);

        return $reminders_sent;
    }

    public function send2HourReminders() {
        
        $sql = "SELECT i.*, 
                c.first_name AS candidate_first_name, c.last_name AS candidate_last_name, c.email AS candidate_email,
                e.first_name AS employer_first_name,
                j.title AS job_title
                FROM interview_schedules i
                JOIN users c ON i.candidate_id = c.id
                JOIN users e ON i.employer_id = e.id
                JOIN jobs j ON i.job_id = j.id  
                WHERE i.status = 'scheduled'
                AND i.reminder_sent_2h = 0
                AND TIMESTAMP(i.interview_date, i.interview_time) > NOW()
                AND TIMESTAMP(i.interview_date, i.interview_time) <= DATE_ADD(NOW(), INTERVAL 2 HOUR)";

        $reminders_sent = $this->processReminders($sql, 2);

        return $reminders_sent;
    }
    
    private function processReminders($sql, $hours) {
        $result = $this->interviewModel->query($sql);
        if (!$result || $result->num_rows === 0) return 0;
        
        $reminders_sent = 0;

        while ($interview = $result->fetch_assoc()) {
            
            $email_data = [
                'candidate_email' => $interview['candidate_email'],
                'candidate_name' => $interview['candidate_first_name'] . ' ' . $interview['candidate_last_name'],
                'company_name' => $interview['company_name'] ?? $interview['employer_first_name'],
                
                'id' => $interview['id'],
                'job_title' => $interview['job_title'], 
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
                    // Requires Model::update or specific InterviewModel method
                    $this->interviewModel->update($interview['id'], ['reminder_sent_24h' => 1]); 
                } else {
                    $this->interviewModel->update($interview['id'], ['reminder_sent_2h' => 1]);
                }
                $reminders_sent++;
            }
        }
        return $reminders_sent;
    }
}

// --- EXECUTION BLOCK ---
// NOTE: Ensure your EmailService has access to database.php for its dependencies.

// Ensure all necessary dependencies are loaded for EmailService
require_once ROOT_PATH . '/config/email.php'; 

$reminder = new InterviewReminder();

$results_24h = $reminder->send24HourReminders();
$results_2h = $reminder->send2HourReminders();

// Log results
$log_message = date('Y-m-d H:i:s') . " - 24h reminders sent: $results_24h, 2h reminders sent: $results_2h\n";
file_put_contents(ROOT_PATH . '/logs/interview_reminders.log', $log_message, FILE_APPEND);

echo "Interview Reminder finished. 24h: {$results_24h}, 2h: {$results_2h}\n";