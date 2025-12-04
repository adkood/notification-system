<?php

require_once ROOT_PATH . '/models/EmailQueue.php';
require_once ROOT_PATH . '/models/EmailTemplate.php';
require_once ROOT_PATH . '/config/email.php';
require_once ROOT_PATH . '/config/constants.php';

class EmailService {
    private $emailQueue;

    public function __construct() {
        $this->emailQueue = new EmailQueue();
    }

    /**
     * Adds an email message to the queue for asynchronous sending.
     */
    public function sendEmail($to, $subject, $body, $text_body = null, $metadata = null) {
        // Add to queue for processing
        $email_data = [
            'to_email' => $to,
            'subject' => $subject,
            'body_html' => $body,
            'body_text' => $text_body,
            'metadata' => $metadata
        ];
        
        return $this->emailQueue->addToQueue($email_data);
    }
    
    // --- Helper for Job Alerts ---

    /**
     * Generates the HTML list of jobs for the email body.
     * @param array $jobs List of jobs (must contain id, title, location).
     * @return string HTML unordered list.
     */
    private function generateJobListHtml(array $jobs): string {
        $html = '<ul style="list-style: none; padding: 0; margin: 0;">';
        foreach ($jobs as $job) {
            $jobLink = BASE_URL . "/job/view/{$job['id']}";
            $html .= '<li style="margin-bottom: 15px; border-left: 3px solid #007bff; padding: 10px; background-color: #f8f9fa;">';
            $html .= '<strong><a href="' . $jobLink . '" style="color: #007bff; text-decoration: none; font-size: 16px;">' . htmlspecialchars($job['title']) . '</a></strong><br>';
            $html .= '<span style="color: #6c757d;">Location: ' . htmlspecialchars($job['location']) . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    // --- Template-Based Email Senders ---

    public function sendWelcomeEmail($user_data) {
        $template_name = $user_data['user_type'] === 'candidate' ? 'welcome_candidate' : 'welcome_employer';
        
        $data = [
            'first_name' => $user_data['first_name'],
            // Use 'entity_name' to handle either Candidate's name or Company name for flexibility
            'entity_name' => $user_data['company_name'] ?? $user_data['first_name'],
            'profile_link' => BASE_URL . '/profile/complete',
            'alerts_link' => BASE_URL . '/alerts/setup',
            'kyc_link' => BASE_URL . '/employer/kyc'
        ];

        $emailTemplate = new EmailTemplate();
        $rendered = $emailTemplate->renderTemplate($template_name, $data);
        
        if ($rendered) {
            return $this->sendEmail(
                $user_data['email'],
                $rendered['subject'],
                $rendered['body'],
                strip_tags($rendered['body']),
                ['type' => 'welcome', 'user_id' => $user_data['id']]
            );
        }
        return false;
    }

    public function sendApplicationStatusEmail($candidate_email, $candidate_name, $job_title, $company_name, $status, $custom_message = '', $application_id = '') {
        $template_map = [
            'received'    => 'application_received',
            'shortlisted' => 'application_shortlisted',
            'scheduled' => 'interview_scheduled',
            'rejected' => 'application_rejected',
            'hired' => 'application_hired'
        ];

        $template_name = $template_map[$status] ?? 'application_update';
        
        $data = [
            'candidate_name' => $candidate_name,
            'job_title' => $job_title,
            'company_name' => $company_name, // Key is 'company_name'
            'application_id' => $application_id,
            'custom_message' => $custom_message,
            'dashboard_link' => BASE_URL . '/dashboard',
            'status' => ucfirst($status)
        ];

        $emailTemplate = new EmailTemplate();
        $rendered = $emailTemplate->renderTemplate($template_name, $data);
        
        if ($rendered) {
            return $this->sendEmail(
                $candidate_email,
                $rendered['subject'],
                $rendered['body'],
                strip_tags($rendered['body']),
                ['type' => 'application_status', 'status' => $status, 'application_id' => $application_id]
            );
        }

        error_log("EmailService Error: Failed to render email template for status '{$status}'. Template name: '{$template_name}'");
        return false;
    }

    public function sendInterviewReminder($interview_data) {
        $data = [
            'candidate_name' => $interview_data['candidate_name'],
            'job_title' => $interview_data['job_title'],
            'company_name' => $interview_data['company_name'],
            'interview_date' => date('F j, Y', strtotime($interview_data['interview_date'])),
            'interview_time' => date('g:i A', strtotime($interview_data['interview_time'])),
            'interview_mode' => ucfirst($interview_data['mode']),
            'interview_location' => $interview_data['mode'] === 'online' ? 
                "Meeting Link: <a href='{$interview_data['location_or_link']}'>{$interview_data['location_or_link']}</a>" : 
                "Location: {$interview_data['location_or_link']}",
            'additional_notes' => $interview_data['additional_notes'] ?? '',
            'meeting_link' => $interview_data['location_or_link'],
            'dashboard_link' => BASE_URL . '/dashboard'
        ];

        $emailTemplate = new EmailTemplate();
        $rendered = $emailTemplate->renderTemplate('interview_scheduled', $data); // Uses existing scheduled template
        
        if ($rendered) {
            // Modify subject for reminder
            $subject = "Reminder: " . $rendered['subject'];
            
            return $this->sendEmail(
                $interview_data['candidate_email'],
                $subject,
                $rendered['body'],
                strip_tags($rendered['body']),
                ['type' => 'interview_reminder', 'interview_id' => $interview_data['id']]
            );
        }
        return false;
    }


    /**
     * Sends the Job Alert email, using the job list generated by the helper method.
     */
    public function sendJobAlertEmail($candidate_email, $candidate_name, array $jobs, $frequency) {
        
        // Use the dedicated helper to generate the list HTML
        $jobListHtml = $this->generateJobListHtml($jobs);

        $data = [
            'candidate_name' => $candidate_name,
            'job_count' => count($jobs),
            'frequency' => ucfirst($frequency),
            // THIS KEY MATCHES THE TEMPLATE PLACHOLDER BELOW
            'job_list_html' => $jobListHtml, 
            'manage_alerts_link' => BASE_URL . '/candidate/alerts/manage',
        ];

        $emailTemplate = new EmailTemplate();
        $rendered = $emailTemplate->renderTemplate('job_alert', $data);
        
        if ($rendered) {
            // Use the subject provided by the template or generate one
            $subject = $rendered['subject'] ?? (count($jobs) . " New Job Alerts for you (" . ucfirst($frequency) . ")");
            
            return $this->sendEmail(
                $candidate_email,
                $subject,
                $rendered['body'],
                strip_tags($rendered['body']),
                ['type' => 'job_alert', 'frequency' => $frequency, 'job_count' => count($jobs)]
            );
        }
        return false;
    }
}