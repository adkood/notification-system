<?php
// controllers/JobController.php

// Ensure ROOT_PATH is defined in api.php and used for includes
require_once ROOT_PATH . '/models/Job.php';
require_once ROOT_PATH . '/utils/Security.php';

class JobController {
    private $jobModel;
    private $security;

    public function __construct() {
        $this->jobModel = new Job();
        $this->security = new Security();
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        // All Job management actions (POST, PUT, DELETE) require a valid token
        $auth = $this->security->verifyAuthToken();
        $isPublicAction = ($method === 'GET' && ($action === 'list' || $action === 'view'));

        if (!$auth && !$isPublicAction) {
            $this->sendResponse(401, ['error' => 'Unauthorized access.']);
            return;
        }

        switch ($method) {
            case 'GET':
                $this->handleGet($action);
                break;
            case 'POST':
                // Check if the authenticated user is an employer/admin for creation
                if (!$auth || ($auth['user_type'] !== 'employer' && $auth['user_type'] !== 'admin')) {
                    $this->sendResponse(403, ['error' => 'Only employers or admins can post jobs.']);
                    return;
                }
                $this->handlePost($action, $auth['user_id']);
                break;
            case 'PUT':
                // Check if the authenticated user is an employer/admin for update
                if (!$auth || ($auth['user_type'] !== 'employer' && $auth['user_type'] !== 'admin')) {
                    $this->sendResponse(403, ['error' => 'Only employers or admins can update jobs.']);
                    return;
                }
                $this->handlePut($action, $auth['user_id']);
                break;
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed.']);
        }
    }

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

    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}