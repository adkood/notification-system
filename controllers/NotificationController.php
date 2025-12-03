<?php

require_once ROOT_PATH . '/services/NotificationService.php';
require_once ROOT_PATH . '/utils/Security.php';

class NotificationController {
    private $notificationService;
    private $security;

    public function __construct() {
        $this->notificationService = new NotificationService();
        $this->security = new Security();
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        // Verify authentication token
        $auth = $this->security->verifyAuthToken();
        if (!$auth) {
            $this->sendResponse(401, ['error' => 'Unauthorized']);
            return;
        }

        switch ($method) {
            case 'GET':
                $this->handleGet($action);
                break;
            case 'POST':
                $this->handlePost($action);
                break;
            case 'PUT':
                $this->handlePut($action);
                break;
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed']);
        }
    }

    private function handleGet($action) {
        switch ($action) {
            case 'list':
                $user_id = $_GET['user_id'] ?? 0;
                $user_type = $_GET['user_type'] ?? '';
                $limit = $_GET['limit'] ?? 10;
                $offset = $_GET['offset'] ?? 0;
                
                if (!$user_id || !$user_type) {
                    $this->sendResponse(400, ['error' => 'user_id and user_type are required']);
                    return;
                }

                $notifications = $this->notificationService->getNotifications($user_id, $user_type, $limit, $offset);
                $this->sendResponse(200, ['notifications' => $notifications]);
                break;

            case 'unread_count':
                $user_id = $_GET['user_id'] ?? 0;
                $user_type = $_GET['user_type'] ?? '';
                
                if (!$user_id || !$user_type) {
                    $this->sendResponse(400, ['error' => 'user_id and user_type are required']);
                    return;
                }

                $count = $this->notificationService->getUnreadCount($user_id, $user_type);
                $this->sendResponse(200, ['unread_count' => $count]);
                break;

            default:
                $this->sendResponse(400, ['error' => 'Invalid action']);
        }
    }

    private function handlePost($action) {
        $data = json_decode(file_get_contents('php://input'), true);

        switch ($action) {
            case 'mark_read':
                if (!isset($data['notification_id']) || !isset($data['user_id'])) {
                    $this->sendResponse(400, ['error' => 'notification_id and user_id are required']);
                    return;
                }

                $result = $this->notificationService->markAsRead($data['notification_id'], $data['user_id']);
                $this->sendResponse(200, ['success' => $result]);
                break;

            case 'mark_all_read':
                if (!isset($data['user_id']) || !isset($data['user_type'])) {
                    $this->sendResponse(400, ['error' => 'user_id and user_type are required']);
                    return;
                }

                $result = $this->notificationService->markAllAsRead($data['user_id'], $data['user_type']);
                $this->sendResponse(200, ['success' => $result]);
                break;

            default:
                $this->sendResponse(400, ['error' => 'Invalid action']);
        }
    }

    private function handlePut($action) {
        // For future extensions
        $this->sendResponse(501, ['error' => 'Not implemented']);
    }

    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}