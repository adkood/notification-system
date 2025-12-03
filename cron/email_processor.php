<?php
require_once '../config/database.php';
require_once '../config/email.php';
require_once '../models/EmailQueue.php';

// Set maximum execution time
set_time_limit(300);

// Import PHPMailer if not already available
require_once '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailProcessor {
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
                    throw new Exception('Send failed');
                }
            } catch (Exception $e) {
                // Increment retry count
                $this->emailQueue->incrementRetry($email['id']);
                
                // Get current retry count
                $sql = "SELECT retry_count, max_retries FROM email_queue WHERE id = " . $email['id'];
                $result = $this->emailQueue->query($sql);
                $email_data = $result->fetch_assoc();
                
                if ($email_data['retry_count'] >= $email_data['max_retries']) {
                    $this->emailQueue->updateStatus($email['id'], 'failed', $e->getMessage());
                    $results['failed']++;
                }
            }
            
            $results['processed']++;
            
            // Small delay to avoid rate limiting
            usleep(100000); // 0.1 second
        }

        return $results;
    }

    public function cleanupOldEmails($days = 30) {
        $sql = "DELETE FROM email_queue 
                WHERE (status = 'sent' OR status = 'failed') 
                AND created_at < DATE_SUB(NOW(), INTERVAL $days DAY)";
        
        $result = $this->emailQueue->query($sql);
        return $this->emailQueue->conn->affected_rows;
    }
}

// Run processor
$processor = new EmailProcessor();

// Process pending emails
$results = $processor->processPendingEmails();

// Cleanup old emails
$cleaned = $processor->cleanupOldEmails(30);

// Log results
$log_message = date('Y-m-d H:i:s') . " - Processed: {$results['processed']}, Sent: {$results['sent']}, Failed: {$results['failed']}, Cleaned: $cleaned\n";
file_put_contents('../logs/email_processor.log', $log_message, FILE_APPEND);

echo json_encode([
    'success' => true,
    'results' => $results,
    'cleaned' => $cleaned
]);