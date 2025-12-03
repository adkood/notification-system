<?php

class AuthController {
    private $userModel;
    private $security;
    private $notificationService;

    public function __construct() {
        $this->userModel = new User();
        $this->security = new Security();
        $this->notificationService = new NotificationService();
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';

        switch ($method) {
            case 'POST':
                $this->handlePost($action);
                break;
            case 'GET':
                $this->handleGet($action);
                break;
            default:
                $this->sendResponse(405, ['error' => 'Method not allowed']);
        }
    }

    private function handlePost($action) {
        $data = json_decode(file_get_contents('php://input'), true);

        switch ($action) {
            case 'register':
                $this->register($data);
                break;
            case 'login':
                $this->login($data);
                break;
            case 'verify':
                $this->verifyEmail($data);
                break;
            case 'forgot_password':
                $this->forgotPassword($data);
                break;
            case 'reset_password':
                $this->resetPassword($data);
                break;
            default:
                $this->sendResponse(400, ['error' => 'Invalid action']);
        }
    }

    private function handleGet($action) {
        switch ($action) {
            case 'check_email':
                $email = $_GET['email'] ?? '';
                $this->checkEmailExists($email);
                break;
            case 'verify':
                $token = $_GET['token'] ?? '';
                $this->verifyEmailLink($token);
                break;
            default:
                $this->sendResponse(400, ['error' => 'Invalid action']);
        }
    }

    private function register($data) {
        // Validate required fields
        $required = ['email', 'password', 'user_type', 'first_name'];
        $errors = Validation::validateRequired($required, $data);
        
        if (!empty($errors)) {
            $this->sendResponse(400, ['error' => 'Validation failed', 'errors' => $errors]);
            return;
        }

        // Validate email
        if (!Validation::validateEmail($data['email'])) {
            $this->sendResponse(400, ['error' => 'Invalid email address']);
            return;
        }

        // Check if email already exists
        $existingUser = $this->userModel->findByEmail($data['email']);
        if ($existingUser) {
            $this->sendResponse(400, ['error' => 'Email already registered']);
            return;
        }

        // Prepare user data
        $userData = [
            'email' => $data['email'],
            'password' => $this->security->hashPassword($data['password']),
            'user_type' => $data['user_type'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'location' => $data['location'] ?? '',
            'skills' => isset($data['skills']) ? json_encode($data['skills']) : null,
            'verification_token' => $this->security->generateVerificationToken(),
            'verification_expires' => date('Y-m-d H:i:s', time() + 86400) // 24 hours
        ];

        // Create user
        $result = $this->userModel->create($userData);
        
        if ($result) {
            $userId = $this->userModel->getLastInsertId();
            $userData['id'] = $userId;

            // Send welcome notification and email
            $this->notificationService->sendRegistrationNotification($userData);

            // Send verification email
            $this->sendVerificationEmail($userData);

            $this->sendResponse(201, [
                'success' => true,
                'message' => 'Registration successful',
                'user_id' => $userId,
                'verification_required' => true
            ]);
        } else {
            $this->sendResponse(500, ['error' => 'Registration failed']);
        }
    }

    private function login($data) {
        // Validate required fields
        if (empty($data['email']) || empty($data['password'])) {
            $this->sendResponse(400, ['error' => 'Email and password are required']);
            return;
        }

        // Find user
        $user = $this->userModel->findByEmail($data['email']);
        if (!$user) {
            $this->sendResponse(401, ['error' => 'Invalid credentials']);
            return;
        }

        // Verify password
        if (!$this->security->verifyPassword($data['password'], $user['password'])) {
            $this->sendResponse(401, ['error' => 'Invalid credentials']);
            return;
        }

        // Check if email is verified
        if (!$user['email_verified']) {
            $this->sendResponse(403, [
                'error' => 'Email not verified',
                'verification_required' => true
            ]);
            return;
        }

        // Check if account is active
        if (!$user['is_active']) {
            $this->sendResponse(403, ['error' => 'Account is deactivated']);
            return;
        }

        // Generate auth token (in production, use JWT)
        // $authToken = bin2hex(random_bytes(32));
        $authToken = $this->security->generateJwt($user);
        
        // Store token in database (you can create a sessions table)
        $this->userModel->update($user['id'], [
            'last_login' => date('Y-m-d H:i:s')
        ]);

        // Remove sensitive data
        unset($user['password']);
        unset($user['verification_token']);

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
            'token' => $authToken
        ]);
    }

    private function verifyEmail($data) {
        if (empty($data['token'])) {
            $this->sendResponse(400, ['error' => 'Verification token is required']);
            return;
        }

        // Find user by token
        $sql = "SELECT * FROM users WHERE verification_token = '" . $this->userModel->escape($data['token']) . "'";
        $result = $this->userModel->query($sql);
        
        if ($result->num_rows === 0) {
            $this->sendResponse(404, ['error' => 'Invalid verification token']);
            return;
        }

        $user = $result->fetch_assoc();

        // Check if token is expired
        if (strtotime($user['verification_expires']) < time()) {
            $this->sendResponse(400, ['error' => 'Verification token expired']);
            return;
        }

        // Update user as verified
        $this->userModel->update($user['id'], [
            'email_verified' => 1,
            'verification_token' => null,
            'verification_expires' => null
        ]);

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Email verified successfully'
        ]);
    }

    private function verifyEmailLink($token) {
        if (empty($token)) {
            $this->sendResponse(400, ['error' => 'Verification token is required']);
            return;
        }

        // HTML response for browser verification
        $sql = "SELECT * FROM users WHERE verification_token = '" . $this->userModel->escape($token) . "'";
        $result = $this->userModel->query($sql);
        
        if ($result->num_rows === 0) {
            echo "<h1>Verification Failed</h1>";
            echo "<p>Invalid verification token.</p>";
            return;
        }

        $user = $result->fetch_assoc();

        // Check if token is expired
        if (strtotime($user['verification_expires']) < time()) {
            echo "<h1>Verification Failed</h1>";
            echo "<p>Verification token has expired.</p>";
            echo "<p><a href='/api.php?endpoint=auth&action=resend_verification&email=" . urlencode($user['email']) . "'>Resend verification email</a></p>";
            return;
        }

        // Update user as verified
        $this->userModel->update($user['id'], [
            'email_verified' => 1,
            'verification_token' => null,
            'verification_expires' => null
        ]);

        echo "<h1>Email Verified Successfully!</h1>";
        echo "<p>Your email has been verified. You can now login to your account.</p>";
        echo "<p><a href='/'>Go to Login Page</a></p>";
    }

    private function forgotPassword($data) {
        if (empty($data['email'])) {
            $this->sendResponse(400, ['error' => 'Email is required']);
            return;
        }

        $user = $this->userModel->findByEmail($data['email']);
        if (!$user) {
            // Don't reveal if user exists for security
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'If the email exists, a password reset link will be sent'
            ]);
            return;
        }

        // Generate reset token
        $resetToken = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $this->userModel->update($user['id'], [
            'verification_token' => $resetToken,
            'verification_expires' => $expires
        ]);

        // Send reset email
        $this->sendPasswordResetEmail($user, $resetToken);

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Password reset instructions sent to your email'
        ]);
    }

    private function resetPassword($data) {
        if (empty($data['token']) || empty($data['password'])) {
            $this->sendResponse(400, ['error' => 'Token and new password are required']);
            return;
        }

        // Find user by token
        $sql = "SELECT * FROM users WHERE verification_token = '" . $this->userModel->escape($data['token']) . "'";
        $result = $this->userModel->query($sql);
        
        if ($result->num_rows === 0) {
            $this->sendResponse(404, ['error' => 'Invalid reset token']);
            return;
        }

        $user = $result->fetch_assoc();

        // Check if token is expired
        if (strtotime($user['verification_expires']) < time()) {
            $this->sendResponse(400, ['error' => 'Reset token expired']);
            return;
        }

        // Update password
        $hashedPassword = $this->security->hashPassword($data['password']);
        
        $this->userModel->update($user['id'], [
            'password' => $hashedPassword,
            'verification_token' => null,
            'verification_expires' => null
        ]);

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Password reset successful'
        ]);
    }

    private function checkEmailExists($email) {
        if (empty($email)) {
            $this->sendResponse(400, ['error' => 'Email is required']);
            return;
        }

        $user = $this->userModel->findByEmail($email);
        
        $this->sendResponse(200, [
            'exists' => $user !== null,
            'email' => $email
        ]);
    }

    private function sendVerificationEmail($user) {
        $verificationLink = BASE_URL . "/api.php?endpoint=auth&action=verify&token=" . $user['verification_token'];
        
        $subject = "Verify Your Email - Mindware Job Portal";
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4F46E5; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 24px; background: #4F46E5; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Verify Your Email</h1>
                </div>
                <div class='content'>
                    <p>Hello {$user['first_name']},</p>
                    <p>Thank you for registering with Mindware Job Portal. Please verify your email address by clicking the button below:</p>
                    <a href='{$verificationLink}' class='button'>Verify Email Address</a>
                    <p>This link will expire in 24 hours.</p>
                    <p>If you didn't create an account, you can safely ignore this email.</p>
                    <p>Best regards,<br>The Mindware Team</p>
                </div>
                <div class='footer'>
                    <p>&copy; 2024 Mindware Job Portal. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        // Use EmailService to send
        $emailService = new EmailService();
        $emailService->sendEmail(
            $user['email'],
            $subject,
            $body,
            strip_tags($body),
            ['type' => 'verification', 'user_id' => $user['id']]
        );
    }

    private function sendPasswordResetEmail($user, $token) {
        $resetLink = BASE_URL . "/reset-password?token=" . $token;
        
        $subject = "Reset Your Password - Mindware Job Portal";
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #DC2626; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 24px; background: #DC2626; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Reset Your Password</h1>
                </div>
                <div class='content'>
                    <p>Hello {$user['first_name']},</p>
                    <p>We received a request to reset your password. Click the button below to create a new password:</p>
                    <a href='{$resetLink}' class='button'>Reset Password</a>
                    <p>This link will expire in 1 hour.</p>
                    <p>If you didn't request a password reset, please ignore this email or contact support if you have concerns.</p>
                    <p>Best regards,<br>The Mindware Team</p>
                </div>
                <div class='footer'>
                    <p>&copy; 2024 Mindware Job Portal. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        $emailService = new EmailService();
        $emailService->sendEmail(
            $user['email'],
            $subject,
            $body,
            strip_tags($body),
            ['type' => 'password_reset', 'user_id' => $user['id']]
        );
    }

    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}