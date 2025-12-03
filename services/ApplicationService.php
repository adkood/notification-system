<?php
// services/ApplicationService.php

require_once ROOT_PATH . '/models/Application.php';
require_once ROOT_PATH . '/models/Job.php';
require_once ROOT_PATH . '/models/User.php';
require_once ROOT_PATH . '/services/NotificationService.php';
require_once ROOT_PATH . '/services/EmailService.php';

class ApplicationService {
    private $applicationModel;
    private $jobModel;
    private $userModel;
    private $notificationService;
    private $emailService;

    public function __construct() {
        $this->applicationModel = new Application();
        $this->jobModel = new Job();
        $this->userModel = new User();
        $this->notificationService = new NotificationService();
        $this->emailService = new EmailService();
    }

    // =========================================================
    // 1. Core Application Submission
    // =========================================================
    public function applyForJob($candidateId, $jobId) {
        // 1. Check if the job exists
        $job = $this->jobModel->findById($jobId);
        if (!$job || $job['is_active'] == 0) {
            return ['success' => false, 'error' => 'Job not found or is inactive.'];
        }

        // 2. Check if candidate already applied
        if ($this->applicationModel->hasApplied($candidateId, $jobId)) {
            return ['success' => false, 'error' => 'You have already applied for this job.'];
        }

        // 3. Create the application record
        $applicationData = [
            'candidate_id' => $candidateId,
            'job_id' => $jobId,
            'employer_id' => $job['company_id'],
            'status' => 'received' // Initial status
        ];
        
        $newId = $this->applicationModel->create($applicationData);

        if ($newId) {
            $candidate = $this->userModel->findById($candidateId);
            // Assuming employer name is stored in first_name for now
            $employer = $this->userModel->findById($job['company_id']); 

            // 4. Trigger Notifications & Emails

            // A. Notify Candidate of Successful Application (In-app + Email)
            // NOTE: The NotificationService must handle the 'received' status for this to work properly.
            $this->notificationService->sendApplicationStatusNotification(
                $candidateId, 
                $job['title'], 
                $employer['first_name'], 
                'received', 
                'Thank you for your application! We will review your profile shortly.'
            );
            
            // B. Notify Employer of New Application (In-app only)
            $this->notificationService->notificationModel->create([
                'user_id' => $job['company_id'],
                'user_type' => 'employer',
                'type' => 'new_application',
                'title' => 'New Application Received',
                'message' => "Candidate {$candidate['first_name']} {$candidate['last_name']} applied for '{$job['title']}'.",
                'link_url' => "/employer/applications/{$newId}"
            ]);

            // C. Optional: Send email to employer of new application (omitted for brevity, use sendEmail method if needed)

            return ['success' => true, 'application_id' => $newId];
        }

        return ['success' => false, 'error' => 'Failed to process application.'];
    }

    // ==========================================================
    // 2. Application Status Update (Crucial for Notifications)
    // ==========================================================
    /**
     * Updates the status of an application and triggers notifications to the candidate.
     * @param int $applicationId ID of the application to update.
     * @param string $newStatus The new status (e.g., 'shortlisted', 'rejected').
     * @param int $employerId ID of the user performing the update (for ownership check).
     * @param array $extraData Additional data for scheduling/custom messages.
     * @return array Status of the update.
     */
    public function updateApplicationStatus($applicationId, $newStatus, $employerId, $extraData = []) {
        $application = $this->applicationModel->findById($applicationId);
        
        if (!$application) {
            return ['success' => false, 'error' => 'Application not found.'];
        }
        
        // Ownership check: Ensure the user updating the status is the posting employer
        if ($application['employer_id'] != $employerId) {
            return ['success' => false, 'error' => 'Access denied. You do not own this application.'];
        }

        // Prevent updating to the same status
        if ($application['status'] === $newStatus) {
            return ['success' => false, 'error' => 'Application is already in this status.'];
        }
        
        // Update the database
        $updateResult = $this->applicationModel->update($applicationId, ['status' => $newStatus]);
        
        if ($updateResult) {
            $job = $this->jobModel->findById($application['job_id']);
            $employer = $this->userModel->findById($employerId);
            $jobTitle = $job['title'];
            $companyName = $employer['first_name'];
            
            // Trigger appropriate notification based on status change
            if ($newStatus === 'scheduled') {
                // If scheduled, gather required interview details from extraData
                $interviewData = array_merge($extraData, [
                    'candidate_id' => $application['candidate_id'],
                    'employer_id' => $employerId,
                    'job_title' => $jobTitle,
                    // NOTE: The controller must pass 'interview_date', 'interview_time', etc. in $extraData
                ]);
                
                // Use the specialized method from NotificationService
                $this->notificationService->sendInterviewScheduledNotification($interviewData);

            } else if (in_array($newStatus, ['shortlisted', 'rejected', 'hired'])) {
                $customMessage = $extraData['custom_message'] ?? '';
                
                // Use the general application status method
                $this->notificationService->sendApplicationStatusNotification(
                    $application['candidate_id'], 
                    $jobTitle, 
                    $companyName, 
                    $newStatus, 
                    $customMessage
                );
            }

            return ['success' => true, 'message' => "Status updated to {$newStatus}."];
        }

        return ['success' => false, 'error' => 'Failed to update application status in DB.'];
    }


    // ==========================================================
    // 3. Application Retrieval Methods
    // ==========================================================

   /**
     * Retrieves applications for a specific candidate.
     */
    public function getApplicationsByCandidate($candidateId, $limit = 10, $offset = 0) {
        return $this->applicationModel->getByCandidateId($candidateId, $limit, $offset);
    }
    
    /**
     * Retrieves applications submitted to a specific employer's jobs.
     */
    public function getApplicationsByEmployer($employerId, $limit = 10, $offset = 0) {
        return $this->applicationModel->getByEmployerId($employerId, $limit, $offset);
    }

    /**
     * Finds a single application by ID.
     */
    public function findApplicationById($applicationId) {
        return $this->applicationModel->findById($applicationId);
    }
}