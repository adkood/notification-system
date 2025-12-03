<?php
// controllers/ApplicationController.php

require_once ROOT_PATH . '/services/ApplicationService.php';
require_once ROOT_PATH . '/utils/Security.php';

class ApplicationController {
    private $applicationService;
    private $security;

    public function __construct() {
        $this->applicationService = new ApplicationService();
        $this->security = new Security();
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        $authPayload = $this->security->verifyAuthToken();
        if (!$authPayload) {
            $this->sendResponse(401, ['error' => 'Unauthorized access.']);
            return;
        }

        switch ($method) {
            case 'GET':
                $this->handleGet($action, $authPayload);
                break;
            case 'POST':
                // Only candidates should be able to apply via POST
                if ($authPayload['user_type'] !== 'candidate') {
                    $this->sendResponse(403, ['error' => 'Only candidates can perform this POST action.']);
                    return;
                }
                $this->handlePost($action, $authPayload);
                break;
            case 'PUT':
                // Only employers/admins should be able to update status via PUT
                if ($authPayload['user_type'] !== 'employer' && $authPayload['user_type'] !== 'admin') {
                    $this->sendResponse(403, ['error' => 'Only employers/admins can update application status.']);
                    return;
                }
                $this->handlePut($action, $authPayload);
                break;
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed.']);
        }
    }

    private function handleGet($action, $authPayload) {
        $userId = $authPayload['user_id'];
        $userType = $authPayload['user_type'];
        $limit = $_GET['limit'] ?? 10;
        $offset = $_GET['offset'] ?? 0;

        switch ($action) {
            case 'list_candidate':
                if ($userType !== 'candidate') {
                    $this->sendResponse(403, ['error' => 'Access denied.']);
                    return;
                }
                $applications = $this->applicationService->getApplicationsByCandidate($userId, $limit, $offset);
                $this->sendResponse(200, ['applications' => $applications]);
                break;

            case 'list_employer':
                if ($userType !== 'employer' && $userType !== 'admin') {
                    $this->sendResponse(403, ['error' => 'Access denied.']);
                    return;
                }
                $applications = $this->applicationService->getApplicationsByEmployer($userId, $limit, $offset);
                $this->sendResponse(200, ['applications' => $applications]);
                break;

            default:
                $this->sendResponse(400, ['error' => 'Invalid GET action.']);
        }
    }

    private function handlePost($action, $authPayload) {
        $data = json_decode(file_get_contents('php://input'), true);

        if ($action === 'apply') {
            if (!isset($data['job_id'])) {
                $this->sendResponse(400, ['error' => 'Job ID is required to apply.']);
                return;
            }

            $jobId = $this->security->sanitizeInput($data['job_id']);
            $candidateId = $authPayload['user_id'];
            
            $result = $this->applicationService->applyForJob($candidateId, $jobId);

            if ($result['success']) {
                $this->sendResponse(201, ['success' => true, 'message' => 'Application submitted successfully.', 'application_id' => $result['application_id']]);
            } else {
                $this->sendResponse(400, $result);
            }
        } else {
            $this->sendResponse(400, ['error' => 'Invalid POST action.']);
        }
    }

    private function handlePut($action, $authPayload) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'status_update') {
            $required = ['application_id', 'status'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    $this->sendResponse(400, ['error' => "Missing required field: {$field}"]);
                    return;
                }
            }

            $applicationId = $this->security->sanitizeInput($data['application_id']);
            $newStatus = $this->security->sanitizeInput($data['status']);
            $employerId = $authPayload['user_id'];
            
            // Extra data is used for scheduling details or custom messages
            $extraData = $this->security->sanitizeInput(array_diff_key($data, array_flip($required)));

            $result = $this->applicationService->updateApplicationStatus($applicationId, $newStatus, $employerId, $extraData);

            if ($result['success']) {
                $this->sendResponse(200, $result);
            } else {
                $this->sendResponse(400, $result);
            }

        } else {
            $this->sendResponse(400, ['error' => 'Invalid PUT action.']);
        }
    }


    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}