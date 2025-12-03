<?php
// require_once '../services/EmailService.php';
// require_once '../models/EmailTemplate.php';
// require_once '../models/User.php';
// require_once '../utils/Security.php';

require_once ROOT_PATH . '/services/EmailService.php';
require_once ROOT_PATH . '/models/EmailTemplate.php';
require_once ROOT_PATH . '/models/User.php';
require_once ROOT_PATH . '/utils/Security.php';

class EmailController {
    private $emailService;
    private $emailTemplate;
    private $userModel;
    private $security;

    public function __construct() {
        $this->emailService = new EmailService();
        $this->emailTemplate = new EmailTemplate();
        $this->userModel = new User();
        $this->security = new Security();
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        // For admin actions, verify admin token
        if ($action === 'bulk_send' || $action === 'send_template') {
            $auth = $this->security->verifyAdminToken();
            if (!$auth) {
                $this->sendResponse(401, ['error' => 'Admin access required']);
                return;
            }
        }

        switch ($method) {
            case 'GET':
                $this->handleGet($action);
                break;
            case 'POST':
                $this->handlePost($action);
                break;
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed']);
        }
    }

    private function handleGet($action) {
        switch ($action) {
            case 'templates':
                $templates = $this->emailTemplate->getAll();
                $this->sendResponse(200, ['templates' => $templates]);
                break;

            case 'template':
                $template_name = $_GET['name'] ?? '';
                if (!$template_name) {
                    $this->sendResponse(400, ['error' => 'Template name is required']);
                    return;
                }

                $template = $this->emailTemplate->getByName($template_name);
                if (!$template) {
                    $this->sendResponse(404, ['error' => 'Template not found']);
                    return;
                }

                $this->sendResponse(200, ['template' => $template]);
                break;

            default:
                $this->sendResponse(400, ['error' => 'Invalid action']);
        }
    }

    private function handlePost($action) {
        $data = json_decode(file_get_contents('php://input'), true);

        switch ($action) {
            case 'send':
                if (!isset($data['to']) || !isset($data['subject']) || !isset($data['body'])) {
                    $this->sendResponse(400, ['error' => 'to, subject, and body are required']);
                    return;
                }

                $result = $this->emailService->sendEmail(
                    $data['to'],
                    $data['subject'],
                    $data['body'],
                    $data['text_body'] ?? null,
                    $data['metadata'] ?? null
                );
                $this->sendResponse(200, ['success' => $result, 'message' => 'Email added to queue']);
                break;

            case 'send_template':
                if (!isset($data['template_name']) || !isset($data['to']) || !isset($data['data'])) {
                    $this->sendResponse(400, ['error' => 'template_name, to, and data are required']);
                    return;
                }

                $rendered = $this->emailTemplate->renderTemplate($data['template_name'], $data['data']);
                if (!$rendered) {
                    $this->sendResponse(404, ['error' => 'Template not found or invalid data']);
                    return;
                }

                $result = $this->emailService->sendEmail(
                    $data['to'],
                    $rendered['subject'],
                    $rendered['body'],
                    strip_tags($rendered['body']),
                    ['type' => 'template', 'template_name' => $data['template_name']]
                );
                $this->sendResponse(200, ['success' => $result, 'message' => 'Email added to queue']);
                break;

            case 'bulk_send':
                if (!isset($data['user_type']) || !isset($data['template_name']) || !isset($data['data'])) {
                    $this->sendResponse(400, ['error' => 'user_type, template_name, and data are required']);
                    return;
                }

                // Get users by type with optional filters
                $filters = $data['filters'] ?? [];
                $users = $this->userModel->getAllUsersByType($data['user_type'], $filters);

                $results = [];
                foreach ($users as $user) {
                    // Merge user data with template data
                    $template_data = array_merge($data['data'], [
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'email' => $user['email'],
                        'user_id' => $user['id']
                    ]);

                    $rendered = $this->emailTemplate->renderTemplate($data['template_name'], $template_data);
                    if ($rendered) {
                        $result = $this->emailService->sendEmail(
                            $user['email'],
                            $rendered['subject'],
                            $rendered['body'],
                            strip_tags($rendered['body']),
                            ['type' => 'bulk', 'template_name' => $data['template_name'], 'user_id' => $user['id']]
                        );
                        $results[] = ['user_id' => $user['id'], 'email' => $user['email'], 'success' => $result];
                    }
                }

                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Bulk emails added to queue',
                    'results' => $results
                ]);
                break;

            case 'create_template':
                if (!isset($data['template_name']) || !isset($data['subject_template']) || !isset($data['body_template'])) {
                    $this->sendResponse(400, ['error' => 'template_name, subject_template, and body_template are required']);
                    return;
                }

                $result = $this->emailTemplate->create($data);
                $this->sendResponse(201, ['success' => $result, 'message' => 'Template created']);
                break;

            default:
                $this->sendResponse(400, ['error' => 'Invalid action']);
        }
    }

    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}