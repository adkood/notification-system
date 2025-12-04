<?php
// services/ApplicationService.php

require_once ROOT_PATH . '/models/Application.php';
require_once ROOT_PATH . '/models/Job.php';
require_once ROOT_PATH . '/models/User.php';
require_once ROOT_PATH . '/models/Interview.php';
require_once ROOT_PATH . '/services/NotificationService.php';
require_once ROOT_PATH . '/services/EmailService.php';

class ApplicationService {
    private $applicationModel;
    private $jobModel;
    private $userModel;
    private $notificationService;
    private $emailService;
    private $interviewModel;

    public function __construct() {
        $this->applicationModel = new Application();
        $this->jobModel = new Job();
        $this->userModel = new User();
        $this->notificationService = new NotificationService();
        $this->emailService = new EmailService();
        $this->interviewModel = new Interview();
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
            // 'employer_id' => $job['company_id'],
            'status' => 'pending' // Initial status
        ];
        
        $newId = $this->applicationModel->create($applicationData);

        if ($newId) {
            $candidate = $this->userModel->findById($candidateId);
            $employer = $this->userModel->findById($job['company_id']); 

            // A. Notify Candidate of Successful Application (In-app + Email)
            $this->notificationService->sendApplicationStatusNotification(
                $candidateId, 
                $job['title'], 
                $employer['first_name'], 
                'received', 
                'Thank only you for your application! We will review your profile shortly.',
                $newId // Passing the application ID for email metadata
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

            return ['success' => true, 'application_id' => $newId, 'message' => 'Application submitted successfully.'];
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
        
        $job = $this->jobModel->findById($application['job_id']);
        
        // Ownership check
        if ($job['company_id'] != $employerId) {
            return ['success' => false, 'error' => 'Access denied. You do not own this application.'];
        }
        
        // Prevent updating to the same status
        if ($application['status'] === $newStatus) {
            return ['success' => false, 'error' => 'Application is already in this status.'];
        }
        
        // Update the application status in DB
        $updateResult = $this->applicationModel->update($applicationId, ['status' => $newStatus]);
        
        if ($updateResult) {
            $employer = $this->userModel->findById($employerId);
            $jobTitle = $job['title'];
            $companyName = $employer['company_name'] ?? $employer['first_name'];
            
            // Trigger appropriate notification based on status change
            if ($newStatus === 'scheduled') {
                
                // 1. --- CRITICAL FIX: SCHEDULE THE INTERVIEW IN DB ---
                $interviewDataToSave = [
                    'candidate_id' => $application['candidate_id'],
                    'employer_id' => $employerId,
                    'job_id' => $application['job_id'],
                    'interview_date' => $extraData['interview_date'],
                    'interview_time' => $extraData['interview_time'],
                    'mode' => $extraData['mode'],
                    'location_or_link' => $extraData['location_or_link'],
                    'additional_notes' => $extraData['additional_notes'] ?? null
                ];
                
                $newInterviewId = $this->interviewModel->create($interviewDataToSave);
                
                if (!$newInterviewId) {
                    // Handle case where interview scheduling failed (e.g., rollback status)
                    return ['success' => false, 'error' => 'Failed to save interview schedule.'];
                }
                
                // 2. Prepare data for NotificationService, including the new ID
                $interviewData = array_merge($extraData, [
                    'id' => $newInterviewId, // Pass the new Interview ID for the email metadata
                    'candidate_id' => $application['candidate_id'],
                    'employer_id' => $employerId,
                    'job_title' => $jobTitle,
                    'company_name' => $companyName,
                    'application_id' => $applicationId
                ]);
                
                // 3. Send notifications
                $this->notificationService->sendInterviewScheduledNotification($interviewData);

            } else if (in_array($newStatus, ['shortlisted', 'rejected', 'hired'])) {
                $customMessage = $extraData['custom_message'] ?? '';
                
                $this->notificationService->sendApplicationStatusNotification(
                    $application['candidate_id'], 
                    $jobTitle, 
                    $companyName, 
                    $newStatus, 
                    $customMessage,
                    $applicationId
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