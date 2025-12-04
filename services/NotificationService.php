<?php

require_once ROOT_PATH . '/models/Notification.php';
require_once ROOT_PATH . '/models/User.php';
require_once __DIR__ . '/EmailService.php';

class NotificationService {
    public $notificationModel;
    protected $emailService;

    public function __construct() {
        $this->notificationModel = new Notification();
        $this->emailService = new EmailService();
    }

    public function sendRegistrationNotification($user_data) {
        // Create in-app notification
        $notification_data = [
            'user_id' => $user_data['id'],
            'user_type' => $user_data['user_type'],
            'type' => 'registration',
            'title' => $user_data['user_type'] === 'candidate' ? 
                'Your account has been created' : 
                'Welcome to Mindware Job Portal',
            'message' => $user_data['user_type'] === 'candidate' ?
                'Welcome! Complete your profile and set up job alerts to get started.' :
                'Welcome employer! Complete your company profile to start posting jobs.',
            'link_url' => $user_data['user_type'] === 'candidate' ? '/profile' : '/employer/profile'
        ];

        $this->notificationModel->create($notification_data);

        // Send welcome email
        return $this->emailService->sendWelcomeEmail($user_data);
    }

    public function sendApplicationStatusNotification($candidate_id, $job_title, $company_name, $status, $custom_message = '',$application_id = null) {
        $userModel = new User();
        $candidate = $userModel->findById($candidate_id);
        
        if (!$candidate) return false;

        // Create in-app notification
        $status_titles = [
            'received' => 'Application Received!',
            'shortlisted' => 'You have been shortlisted!',
            'scheduled' => 'Interview Scheduled',
            'rejected' => 'Application Update',
            'hired' => 'Congratulations! You have been hired!'
        ];

        $notification_data = [
            'user_id' => $candidate_id,
            'user_type' => 'candidate',
            'type' => 'application_status',
            'title' => $status_titles[$status] ?? 'Application Update',
            'message' => "Your application for {$job_title} at {$company_name} has been {$status}. {$custom_message}",
            'link_url' => '/applications',
            'metadata' => ['job_title' => $job_title, 'company_name' => $company_name, 'status' => $status]
        ];

        $this->notificationModel->create($notification_data);

        // Send email notification
        return $this->emailService->sendApplicationStatusEmail(
            $candidate['email'],
            $candidate['first_name'] . ' ' . $candidate['last_name'],
            $job_title,
            $company_name,
            $status,
            $custom_message,
            $application_id
        );
    }

    public function sendInterviewScheduledNotification($interview_data) {
        // Create notification for candidate
        $notification_data = [
            'user_id' => $interview_data['candidate_id'],
            'user_type' => 'candidate',
            'type' => 'interview',
            'title' => 'Interview Scheduled',
            'message' => "Interview scheduled for {$interview_data['job_title']} on {$interview_data['interview_date']} at {$interview_data['interview_time']}",
            'link_url' => '/interviews',
            'metadata' => $interview_data
        ];

        $this->notificationModel->create($notification_data);

        // Create notification for employer
        $notification_data = [
            'user_id' => $interview_data['employer_id'],
            'user_type' => 'employer',
            'type' => 'interview',
            'title' => 'Interview Scheduled',
            'message' => "Interview scheduled with candidate for {$interview_data['job_title']}",
            'link_url' => '/employer/interviews',
            'metadata' => $interview_data
        ];

        $this->notificationModel->create($notification_data);

        // Send email to candidate
        $userModel = new User();
        $candidate = $userModel->findById($interview_data['candidate_id']);
        $employer = $userModel->findById($interview_data['employer_id']);

        $companyName = $interview_data['company_name'] ?? $employer['company_name'];

        $email_data = array_merge($interview_data, [
            'candidate_name' => $candidate['first_name'] . ' ' . $candidate['last_name'],
            'company_name' => $companyName ?? $employer['first_name'],
            'candidate_email' => $candidate['email']
        ]);

        return $this->emailService->sendInterviewReminder($email_data);
    }

    public function getNotifications($user_id, $user_type, $limit = 10, $offset = 0) {
        return $this->notificationModel->getUserNotifications($user_id, $user_type, $limit, $offset);
    }

    public function getUnreadCount($user_id, $user_type) {
        return $this->notificationModel->getUnreadCount($user_id, $user_type);
    }

    public function markAsRead($notification_id, $user_id) {
        return $this->notificationModel->markAsRead($notification_id, $user_id);
    }

    public function markAllAsRead($user_id, $user_type) {
        return $this->notificationModel->markAllAsRead($user_id, $user_type);
    }
}