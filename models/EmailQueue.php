<?php
require_once 'Model.php';

class EmailQueue extends Model {
    protected $table = 'email_queue';

    public function addToQueue($email_data) {
        $to_email = $this->escape($email_data['to_email']);
        $subject = $this->escape($email_data['subject']);
        $body_html = $this->escape($email_data['body_html']);
        $body_text = isset($email_data['body_text']) ? "'" . $this->escape($email_data['body_text']) . "'" : 'NULL';
        $send_at = isset($email_data['send_at']) ? "'" . $this->escape($email_data['send_at']) . "'" : 'NOW()';
        $metadata = isset($email_data['metadata']) ? "'" . $this->escape(json_encode($email_data['metadata'])) . "'" : 'NULL';

        $sql = "INSERT INTO {$this->table} (to_email, subject, body_html, body_text, send_at, metadata) 
                VALUES ('$to_email', '$subject', '$body_html', $body_text, $send_at, $metadata)";
        
        return $this->query($sql);
    }

    public function getPendingEmails($limit = 50) {
        $limit = (int)$limit;
        
        $sql = "SELECT * FROM {$this->table} 
                WHERE status = 'pending' 
                AND send_at <= NOW() 
                AND retry_count < max_retries 
                ORDER BY created_at ASC 
                LIMIT $limit";
        
        $result = $this->query($sql);
        $emails = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['metadata']) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
            $emails[] = $row;
        }
        return $emails;
    }

    public function updateStatus($id, $status, $error_message = null) {
        $id = $this->escape($id);
        $status = $this->escape($status);
        $error_message = $error_message ? "'" . $this->escape($error_message) . "'" : 'NULL';
        $sent_at = $status === 'sent' ? ', sent_at = NOW()' : '';

        $sql = "UPDATE {$this->table} SET status = '$status', error_message = $error_message $sent_at 
                WHERE id = '$id'";
        
        return $this->query($sql);
    }

    public function incrementRetry($id) {
        $id = $this->escape($id);
        
        $sql = "UPDATE {$this->table} SET retry_count = retry_count + 1 
                WHERE id = '$id'";
        
        return $this->query($sql);
    }

    public function getStats() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM {$this->table}";
        
        $result = $this->query($sql);
        return $result->fetch_assoc();
    }
}