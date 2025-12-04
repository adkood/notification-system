<?php
// controllers/JobController.php

// Define ROOT_PATH if not defined elsewhere (essential for includes)
if (!defined('ROOT_PATH')) {
    // Assuming this file is two directories deep from the application root:
    define('ROOT_PATH', dirname(dirname(__DIR__)));
}

// Ensure ROOT_PATH is defined in api.php and used for includes
require_once ROOT_PATH . '/models/Job.php';
require_once ROOT_PATH . '/utils/Security.php';

// --- REQUIRED INCLUDES FOR JOB ALERTS ---
require_once ROOT_PATH . '/services/JobAlertService.php';
require_once ROOT_PATH . '/models/JobAlertSubscription.php';

class JobController {
    private $jobModel;
    private $security;
    private $jobAlertService; // NEW

    public function __construct() {
        $this->jobModel = new Job();
        $this->security = new Security();
        $this->jobAlertService = new JobAlertService(); // NEW
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        // Verify Auth Token and get user details
        $auth = $this->security->verifyAuthToken();
        
        // --- Define Action Categories ---
        $isPublicAction = ($method === 'GET' && ($action === 'list' || $action === 'view'));
        $isJobAlertAction = in_array($action, ['subscribe', 'manage', 'unsubscribe']);
        $isJobManagementAction = in_array($action, ['create', 'update']);

        // --- Global Authentication Check ---
        // If the action is not public, a token is required.
        if (!$auth && !$isPublicAction) {
            $this->sendResponse(401, ['error' => 'Unauthorized access.']);
            return;
        }

        // --- Role-Based Authorization Checks ---
        $userType = $auth['user_type'] ?? null;
        $userId = $auth['user_id'] ?? 0;

        // 1. Check Role for Job Management (POST:create, PUT:update)
        if ($isJobManagementAction && $userType !== 'employer' && $userType !== 'admin') {
            $this->sendResponse(403, ['error' => 'Only employers or admins can manage jobs.']);
            return;
        }

        // 2. Check Role for Job Alerts (Alerts are typically candidate-specific, but we allow if logged in)
        // If you restrict alerts only to candidates, add: 
        /*
        if ($isJobAlertAction && $userType !== 'candidate') {
            $this->sendResponse(403, ['error' => 'Only candidates can manage job alerts.']);
            return;
        }
        */

        // --- Route Handling ---
        switch ($method) {
            case 'GET':
                if ($isJobAlertAction) {
                    // GET for job alerts: action=manage
                    $this->handleAlertGet($action, $userId);
                } else {
                    // Generic GET for jobs: action=list, action=view
                    $this->handleGet($action);
                }
                break;

            case 'POST':
                if ($action === 'subscribe') {
                    // POST for job alerts: action=subscribe
                    $this->handleAlertPost($action, $userId);
                } else if ($action === 'create') {
                    // POST for job management: action=create
                    // Role check passed above.
                    $this->handlePost($action, $userId);
                } else {
                    $this->sendResponse(400, ['error' => 'Invalid POST action.']);
                }
                break;

            case 'PUT':
                // PUT for job management: action=update
                // Role check passed above.
                $this->handlePut($action, $userId);
                break;

            case 'DELETE':
                if ($action === 'unsubscribe') {
                    // DELETE for job alerts: action=unsubscribe
                    $this->handleAlertDelete($action, $userId);
                } else {
                    // Placeholder for job deletion
                    $this->sendResponse(400, ['error' => 'Invalid DELETE action.']);
                }
                break;
                
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed.']);
        }
    }

    // ------------------------------------------------------------------
    // --- Existing Handlers (Unchanged) ---
    // ------------------------------------------------------------------

    private function handleGet($action) {
        switch ($action) {
            case 'list':
                $limit = $_GET['limit'] ?? 10;
                $offset = $_GET['offset'] ?? 0;
                $filters = [
                    'search' => $_GET['search'] ?? '',
                    'company_id' => $_GET['company_id'] ?? null
                ];
                
                $jobs = $this->jobModel->getJobList($filters, $limit, $offset);
                $this->sendResponse(200, ['jobs' => $jobs]);
                break;

            case 'view':
                $jobId = $_GET['id'] ?? 0;
                if (!$jobId) {
                    $this->sendResponse(400, ['error' => 'Job ID is required.']);
                    return;
                }
                $job = $this->jobModel->findById($jobId);
                if (!$job) {
                    $this->sendResponse(404, ['error' => 'Job not found.']);
                    return;
                }
                $this->sendResponse(200, ['job' => $job]);
                break;

            default:
                $this->sendResponse(400, ['error' => 'Invalid action.']);
        }
    }

    private function handlePost($action, $userId) {
        $data = json_decode(file_get_contents('php://input'), true);

        if ($action === 'create') {
            $required = ['title', 'description', 'requirements', 'salary'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    $this->sendResponse(400, ['error' => "Missing required field: {$field}"]);
                    return;
                }
            }
            
            // Sanitize data
            $cleanData = $this->security->sanitizeInput($data);
            
            // Prepare data for insertion
            $jobData = [
                'title'          => $cleanData['title'],
                'description'    => $cleanData['description'],
                'requirements'   => $cleanData['requirements'],
                'salary'         => $cleanData['salary'],
                'location'       => $cleanData['location'] ?? '',
                'company_id'     => $userId, // Set the employer's ID
                'job_type'       => $cleanData['job_type'] ?? 'full-time',
                'is_active'      => 1,
            ];

            $newId = $this->jobModel->create($jobData);

            if ($newId) {
                $this->sendResponse(201, ['success' => true, 'id' => $newId, 'message' => 'Job posted successfully.']);
            } else {
                $this->sendResponse(500, ['success' => false, 'error' => 'Failed to post job.']);
            }
        } else {
            // NOTE: This line is now only hit if a POST action is sent that is neither 'subscribe' nor 'create'
            $this->sendResponse(400, ['error' => 'Invalid POST action.']);
        }
    }
    
    private function handlePut($action, $userId) {
        $data = json_decode(file_get_contents('php://input'), true);
        $jobId = $data['id'] ?? 0;

        if ($action === 'update' && $jobId) {
            $job = $this->jobModel->findById($jobId);

            if (!$job) {
                $this->sendResponse(404, ['error' => 'Job not found.']);
                return;
            }
            
            // Ownership check (Only the employer who posted the job can update it)
            if ($job['company_id'] !== $userId) {
                $this->sendResponse(403, ['error' => 'You do not have permission to modify this job.']);
                return;
            }

            // Remove non-updatable fields
            unset($data['id'], $data['company_id'], $data['created_at']);
            
            // Update the job
            $result = $this->jobModel->update($jobId, $this->security->sanitizeInput($data));

            if ($result) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Job updated successfully.']);
            } else {
                $this->sendResponse(500, ['success' => false, 'error' => 'Failed to update job.']);
            }
        } else {
            $this->sendResponse(400, ['error' => 'Invalid PUT action or missing Job ID.']);
        }
    }

    // ------------------------------------------------------------------
    // --- NEW JOB ALERT SUBSCRIPTION HANDLERS ---
    // ------------------------------------------------------------------

    private function handleAlertPost($action, $userId) {
        if ($action !== 'subscribe') {
            $this->sendResponse(400, ['error' => 'Invalid Alert POST action.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['location', 'role', 'frequency'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                $this->sendResponse(400, ['error' => "Missing required field: {$field} for subscription."]);
                return;
            }
        }

        if (!in_array($data['frequency'], ['instant', 'daily', 'weekly'])) {
            $this->sendResponse(400, ['error' => 'Invalid frequency. Must be instant, daily, or weekly.']);
            return;
        }

        $cleanData = $this->security->sanitizeInput($data);
        
        $subscriptionData = [
            'candidate_id' => $userId,
            'location'     => $cleanData['location'],
            'role'         => $cleanData['role'],
            'skills'       => $cleanData['skills'] ?? null,
            'company_id'   => $cleanData['company_id'] ?? null,
            'frequency'    => $cleanData['frequency'],
            'is_active'    => 1
        ];

        // Call the JobAlertService method
        $newId = $this->jobAlertService->createSubscription($subscriptionData);

        if ($newId) {
            $this->sendResponse(201, ['success' => true, 'id' => $newId, 'message' => 'Job alert subscription created successfully.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to create subscription.']);
        }
    }

    private function handleAlertGet($action, $userId) {
        if ($action === 'manage') {
            // Call the JobAlertService method
            $subscriptions = $this->jobAlertService->getSubscriptionsByCandidate($userId);
            $this->sendResponse(200, ['subscriptions' => $subscriptions]);
        } else {
            $this->sendResponse(400, ['error' => 'Invalid Alert GET action.']);
        }
    }

    private function handleAlertDelete($action, $userId) {
        $data = json_decode(file_get_contents('php://input'), true);
        $alertId = $data['alert_id'] ?? 0;

        if ($action === 'unsubscribe' && $alertId) {
            // Check ownership and delete via JobAlertService
            $result = $this->jobAlertService->deleteSubscription($alertId, $userId);

            if ($result) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Job alert subscription cancelled.']);
            } else {
                $this->sendResponse(404, ['success' => false, 'error' => 'Subscription not found or unauthorized.']);
            }
        } else {
            $this->sendResponse(400, ['error' => 'Missing alert ID for unsubscribe.']);
        }
    }

    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}