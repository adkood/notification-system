<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../config/constants.php';


class Security {

    /**
     * Extracts the token from the Authorization header (Bearer token).
     * @return string The token string or empty string if not found.
     */
    private function extractTokenFromHeaders() {
        $headers = getallheaders();
        // Check both common header cases
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }
        return '';
    }

    /**
     * Attempts to decode and validate a JWT token.
     * @param string $token The raw token string.
     * @return array|false The decoded payload (assoc array) on success, or false on failure.
     */
    private function validateJwt($token) {
        try {
            // JWT_SECRET must be defined in config/constants.php
            $secretKey = JWT_SECRET;
            $algorithm = 'HS256'; 
            
            // JWT::decode handles expiration, issuer, and signature verification
            $decoded = JWT::decode($token, new Key($secretKey, $algorithm));
            
            // Return the payload data as an array
            return (array) $decoded->data; 
        } catch (\Exception $e) {
            // Log the error (Expired token, invalid signature, etc.)
            error_log("JWT Validation Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generates a new JWT token for a successful login.
     */
    public function generateJwt(array $userData) {
        $issuedAt = time();
        $expirationTime = $issuedAt + (60 * 60 * 24 * 7); // Token valid for 7 days

        // The token payload (claims)
        $payload = [
            'iat'  => $issuedAt,              // Issued at
            'exp'  => $expirationTime,        // Expiration time
            'iss'  => BASE_URL,               // Issuer
            'data' => [                       // Custom data
                'user_id'   => $userData['id'],
                'user_type' => $userData['user_type'],
                // Add 'is_admin' based on user type for quick access
                'is_admin'  => $userData['user_type'] === 'admin' 
            ]
        ];

        $secretKey = JWT_SECRET;
        $algorithm = 'HS256';

        return JWT::encode($payload, $secretKey, $algorithm);
    }
    
    // ==========================================================
    // Public Token Verification Methods (UPDATED)
    // ==========================================================

    /**
     * Validates the standard user access token (JWT).
     * @return array|false The decoded token payload (the 'data' array), or false.
     */
    public function verifyAuthToken() {
        $token = $this->extractTokenFromHeaders();
        if (empty($token)) {
            return false;
        }
        
        // Use the robust JWT validation logic
        return $this->validateJwt($token);
    }

    /**
     * Validates an admin access token, requiring both JWT validity and an admin claim.
     * @return array|false The decoded token payload if valid and user is admin, or false.
     */
    public function verifyAdminToken() {
        $token = $this->extractTokenFromHeaders();
        if (empty($token)) {
            return false;
        }
        
        // 1. Validate the JWT token structure and signature
        $payload = $this->validateJwt($token);

        if ($payload) {
            // 2. Check for the specific admin claim in the payload data
            if (isset($payload['is_admin']) && $payload['is_admin'] === true) {
                return $payload;
            }
        }
        
        return false;
    }

    // ==========================================================
    // Other Utility Methods (No changes needed)
    // ==========================================================
    
    public function generateCSRFToken() {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_expiry'] = time() + CSRF_TOKEN_EXPIRY;
        return $token;
    }

    public function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_expiry'])) {
            return false;
        }

        if ($_SESSION['csrf_token_expiry'] < time()) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expiry']);
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public function generateVerificationToken() {
        return bin2hex(random_bytes(32));
    }

    public function sanitizeInput($input) {
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = $this->sanitizeInput($value);
            }
            return $input;
        }
        
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        return $input;
    }

    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}