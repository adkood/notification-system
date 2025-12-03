<?php
// processors/email_processor.php
// To be run via Cron: * * * * * /usr/bin/php /path/to/project/processors/email_processor.php

// Define ROOT_PATH relative to the script location
define('ROOT_PATH', dirname(__DIR__));

// Minimal Autoloading for required classes
require_once ROOT_PATH . '/models/Model.php';
require_once ROOT_PATH . '/models/EmailQueue.php';

// Configuration files
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/email.php';

// Set maximum execution time
set_time_limit(300);

// Import PHPMailer
require_once ROOT_PATH . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailProcessor {
    // ... (Your existing EmailProcessor class methods are placed here) ...
    // NOTE: The PHP class definition is moved here from your previous block.

    private $emailQueue;
    private $mailer;

    public function __construct() {
        $this->emailQueue = new EmailQueue();
        $this->initializeMailer();
    }

    private function initializeMailer() {
        $this->mailer = new PHPMailer(true);
        
        // Server settings
        $this->mailer->isSMTP();
        $this->mailer->Host       = SMTP_HOST;
        $this->mailer->Port       = SMTP_PORT;
        $this->mailer->SMTPAuth   = true;
        $this->mailer->Username   = SMTP_USERNAME;
        $this->mailer->Password   = SMTP_PASSWORD;
        $this->mailer->SMTPSecure = SMTP_ENCRYPTION;
        
        // Sender
        $this->mailer->setFrom(FROM_EMAIL, FROM_NAME);
        $this->mailer->isHTML(true);
    }

    public function processPendingEmails($batch_size = 50) {
        $pending_emails = $this->emailQueue->getPendingEmails($batch_size);
        $results = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0
        ];

        foreach ($pending_emails as $email) {
            try {
                // Prepare email
                $this->mailer->clearAddresses();
                $this->mailer->addAddress($email['to_email']);
                $this->mailer->Subject = $email['subject'];
                $this->mailer->Body    = $email['body_html'];
                $this->mailer->AltBody = $email['body_text'] ?: strip_tags($email['body_html']);

                // Send email
                if ($this->mailer->send()) {
                    $this->emailQueue->updateStatus($email['id'], 'sent');
                    $results['sent']++;
                } else {
                    // Log the specific SMTP error, which PHPMailer puts in $this->mailer->ErrorInfo
                    throw new Exception('Send failed: ' . $this->mailer->ErrorInfo);
                }
            } catch (Exception $e) {
                // Handle failure
                $this->emailQueue->incrementRetry($email['id']);
                
                $sql = "SELECT retry_count, max_retries FROM {$this->emailQueue->table} WHERE id = " . $email['id'];
                $result = $this->emailQueue->query($sql);
                $email_data = $result->fetch_assoc();
                
                if ($email_data && $email_data['retry_count'] >= $email_data['max_retries']) {
                    $this->emailQueue->updateStatus($email['id'], 'failed', $e->getMessage());
                    $results['failed']++;
                }
            }
            
            $results['processed']++;
            usleep(100000); // 0.1 second delay
        }

        return $results;
    }

    public function cleanupOldEmails($days = 30) {
        $sql = "DELETE FROM {$this->emailQueue->table} 
                WHERE (status = 'sent' OR status = 'failed') 
                AND created_at < DATE_SUB(NOW(), INTERVAL $days DAY)";
        
        return $this->emailQueue->executeAndGetAffectedRows($sql);
    }
}

// --- EXECUTION BLOCK ---
$processor = new EmailProcessor();

// Process pending emails
$results = $processor->processPendingEmails();

// Cleanup old emails
$cleaned = $processor->cleanupOldEmails(30);

// Log results (Ensure the logs directory exists and is writable)
$log_message = date('Y-m-d H:i:s') . " - Processed: {$results['processed']}, Sent: {$results['sent']}, Failed: {$results['failed']}, Cleaned: $cleaned\n";
file_put_contents(ROOT_PATH . '/logs/email_processor.log', $log_message, FILE_APPEND);

echo "Email Processor finished. Results: Sent {$results['sent']}, Failed {$results['failed']}\n";