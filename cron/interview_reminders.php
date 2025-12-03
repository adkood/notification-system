<?php
require_once '../config/database.php';
require_once '../models/Interview.php';
require_once '../services/EmailService.php';

class InterviewReminder {
    private $interviewModel;
    private $emailService;
    private $userModel;

    public function __construct() {
        $this->interviewModel = new Interview();
        $this->emailService = new EmailService();
        $this->userModel = new User();
    }

    public function send24HourReminders() {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // Get interviews scheduled for tomorrow
        $sql = "SELECT i.*, 
                c.first_name as candidate_first_name, c.last_name as candidate_last_name, c.email as candidate_email,
                e.first_name as employer_first_name
                FROM interview_schedules i
                JOIN users c ON i.candidate_id = c.id
                JOIN users e ON i.employer_id = e.id
                WHERE i.interview_date = '$tomorrow'
                AND i.status = 'scheduled'
                AND i.reminder_sent_24h = 0";
        
        $result = $this->interviewModel->query($sql);
        $reminders_sent = 0;

        while ($interview = $result->fetch_assoc()) {
            // Prepare email data
            $email_data = [
                'id' => $interview['id'],
                'candidate_id' => $interview['candidate_id'],
                'candidate_name' => $interview['candidate_first_name'] . ' ' . $interview['candidate_last_name'],
                'candidate_email' => $interview['candidate_email'],
                'job_title' => 'Interview Position', // You would join with jobs table
                'company_name' => $interview['employer_first_name'],
                'interview_date' => $interview['interview_date'],
                'interview_time' => $interview['interview_time'],
                'mode' => $interview['mode'],
                'location_or_link' => $interview['location_or_link'],
                'additional_notes' => $interview['additional_notes']
            ];

            // Send reminder
            if ($this->emailService->sendInterviewReminder($email_data)) {
                // Mark as sent
                $this->interviewModel->mark24HourReminderSent($interview['id']);
                $reminders_sent++;
            }
        }

        return $reminders_sent;
    }

    public function send2HourReminders() {
        $two_hours_from_now = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $current_date = date('Y-m-d');
        
        // Get interviews in next 2 hours
        $sql = "SELECT i.*, 
                c.first_name as candidate_first_name, c.last_name as candidate_last_name, c.email as candidate_email,
                e.first_name as employer_first_name
                FROM interview_schedules i
                JOIN users c ON i.candidate_id = c.id
                JOIN users e ON i.employer_id = e.id
                WHERE i.interview_date = '$current_date'
                AND CONCAT(i.interview_date, ' ', i.interview_time) <= '$two_hours_from_now'
                AND i.status = 'scheduled'
                AND i.reminder_sent_2h = 0";
        
        $result = $this->interviewModel->query($sql);
        $reminders_sent = 0;

        while ($interview = $result->fetch_assoc()) {
            $interview_time = strtotime($interview['interview_date'] . ' ' . $interview['interview_time']);
            if ($interview_time > time()) { // Only if not passed
                // Prepare email data
                $email_data = [
                    'id' => $interview['id'],
                    'candidate_id' => $interview['candidate_id'],
                    'candidate_name' => $interview['candidate_first_name'] . ' ' . $interview['candidate_last_name'],
                    'candidate_email' => $interview['candidate_email'],
                    'job_title' => 'Interview Position',
                    'company_name' => $interview['employer_first_name'],
                    'interview_date' => $interview['interview_date'],
                    'interview_time' => $interview['interview_time'],
                    'mode' => $interview['mode'],
                    'location_or_link' => $interview['location_or_link'],
                    'additional_notes' => $interview['additional_notes']
                ];

                // Send reminder
                if ($this->emailService->sendInterviewReminder($email_data)) {
                    // Mark as sent
                    $this->interviewModel->mark2HourReminderSent($interview['id']);
                    $reminders_sent++;
                }
            }
        }

        return $reminders_sent;
    }
}

// Run reminders
$reminder = new InterviewReminder();

$results_24h = $reminder->send24HourReminders();
$results_2h = $reminder->send2HourReminders();

// Log results
$log_message = date('Y-m-d H:i:s') . " - 24h reminders sent: $results_24h, 2h reminders sent: $results_2h\n";
file_put_contents('../logs/interview_reminders.log', $log_message, FILE_APPEND);

echo json_encode([
    'success' => true,
    '24h_reminders' => $results_24h,
    '2h_reminders' => $results_2h
]);