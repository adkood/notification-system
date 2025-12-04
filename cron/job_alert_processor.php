<?php
// cron/job_alert_processor.php

// --- Setup ---
define('ROOT_PATH', dirname(__DIR__));

// Minimal Autoloading for required classes
require_once ROOT_PATH . '/models/Model.php';
require_once ROOT_PATH . '/models/Job.php';
require_once ROOT_PATH . '/models/User.php';
require_once ROOT_PATH . '/models/EmailQueue.php';
require_once ROOT_PATH . '/models/JobAlertSubscription.php'; // New model

// Configuration files
require_once ROOT_PATH . '/config/database.php';

set_time_limit(600);

class JobAlertProcessor {
    private $jobModel;
    private $userModel;
    private $emailQueue;
    private $subscriptionModel;

    public function __construct() {
        $this->jobModel = new Job();
        $this->userModel = new User();
        $this->emailQueue = new EmailQueue();
        $this->subscriptionModel = new JobAlertSubscription();
    }

    public function processAlerts() {
        $queued_emails = 0;
        $processed_subscriptions = 0;

        // Determine which subscriptions to run today.
        // If run daily, we process both 'daily' and 'weekly'.
        $dailySubs = $this->subscriptionModel->getSubscriptionsByFrequency('daily');
        $weeklySubs = $this->subscriptionModel->getSubscriptionsByFrequency('weekly');
        
        $subscriptions = array_merge($dailySubs, $weeklySubs);
        
        foreach ($subscriptions as $sub) {
            $processed_subscriptions++;

            // 1. Determine lookback window based on frequency
            // Daily check looks back 1 day; Weekly looks back 7 days.
            $lookbackDays = ($sub['frequency'] === 'weekly') ? 7 : 1;
            
            // 2. Find matching jobs
            $matchingJobs = $this->findMatchingJobs($sub, $lookbackDays);
            
            if (!empty($matchingJobs)) {
                $candidate = $this->userModel->findById($sub['candidate_id']);
                
                if ($candidate) {
                    // 3. Generate Alert Email and Queue it
                    $emailData = $this->generateAlertEmail($candidate, $matchingJobs, $sub['frequency']);
                    $this->emailQueue->addToQueue($emailData);
                    $queued_emails++;
                }
            }
        }

        return ['processed_subscriptions' => $processed_subscriptions, 'queued_emails' => $queued_emails];
    }

    /**
     * Executes the personalized job search query.
     */
    private function findMatchingJobs($subscription, $lookbackDays) {
        $location = $subscription['location'] ?? null;
        $role = $subscription['role'] ?? null;
        $skills = $subscription['skills'] ?? null;
        $companyId = $subscription['company_id'] ?? null;
        
        // This method needs to be added to your Job model (see below)
        return $this->jobModel->searchAlertJobs($location, $role, $skills, $companyId, $lookbackDays);
    }
    
    private function generateAlertEmail($candidate, $jobs, $frequency) {
        // Build personalized email content here (similar to the digest, but tailored)
        $job_list_html = '';
        foreach ($jobs as $job) {
            $job_list_html .= "
                <div style='padding: 10px 0;'>
                    <h3>{$job['title']}</h3>
                    <p><strong>Company:</strong> {$job['company_name']}</p>
                    <a href='[LINK_TO_JOB_PAGE]?id={$job['id']}'>View & Apply</a>
                </div>";
        }

        $subject = "🔔 Your {$frequency} job alert is here: " . count($jobs) . " matches!";
        $body_html = "
            <h2>Hi {$candidate['first_name']},</h2>
            <p>We found " . count($jobs) . " new jobs matching your saved criteria.</p>
            {$job_list_html}
            <p>Not interested? You can update your alert settings anytime.</p>";
            
        return [
            'to_email' => $candidate['email'],
            'subject' => $subject,
            'body_html' => $body_html,
            'body_text' => strip_tags($body_html),
            'metadata' => ['type' => 'job_alert', 'subscription_id' => $subscription['id']],
        ];
    }
}

// --- EXECUTION BLOCK ---
$processor = new JobAlertProcessor();
$results = $processor->processAlerts(); 

// Log results
$log_message = date('Y-m-d H:i:s') . " - Subscriptions Processed: {$results['processed_subscriptions']}, Emails Queued: {$results['queued_emails']}\n";
file_put_contents(ROOT_PATH . '/logs/job_alert.log', $log_message, FILE_APPEND);

echo "Job Alert Processor finished. Results: Emails Queued {$results['queued_emails']}\n";