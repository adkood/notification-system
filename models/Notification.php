<?php
require_once 'Model.php';

class Notification extends Model {
    protected $table = 'notifications';

    public function create($data) {
        $user_id = $this->escape($data['user_id']);
        $user_type = $this->escape($data['user_type']);
        $type = $this->escape($data['type']);
        $title = $this->escape($data['title']);
        $message = $this->escape($data['message']);
        $link_url = isset($data['link_url']) ? "'" . $this->escape($data['link_url']) . "'" : 'NULL';
        $metadata = isset($data['metadata']) ? "'" . $this->escape(json_encode($data['metadata'])) . "'" : 'NULL';

        $sql = "INSERT INTO {$this->table} (user_id, user_type, type, title, message, link_url, metadata) 
                VALUES ('$user_id', '$user_type', '$type', '$title', '$message', $link_url, $metadata)";
        
        return $this->query($sql);
    }

    public function getUserNotifications($user_id, $user_type, $limit = 10, $offset = 0) {
        $user_id = $this->escape($user_id);
        $user_type = $this->escape($user_type);
        $limit = (int)$limit;
        $offset = (int)$offset;

        $sql = "SELECT * FROM {$this->table} 
                WHERE user_id = '$user_id' AND user_type = '$user_type' 
                ORDER BY created_at DESC 
                LIMIT $limit OFFSET $offset";
        
        $result = $this->query($sql);
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['metadata']) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
            $notifications[] = $row;
        }
        return $notifications;
    }

    public function markAsRead($notification_id, $user_id) {
        $notification_id = $this->escape($notification_id);
        $user_id = $this->escape($user_id);

        $sql = "UPDATE {$this->table} SET is_read = 1 
                WHERE id = '$notification_id' AND user_id = '$user_id'";
        
        return $this->query($sql);
    }

    public function markAllAsRead($user_id, $user_type) {
        $user_id = $this->escape($user_id);
        $user_type = $this->escape($user_type);

        $sql = "UPDATE {$this->table} SET is_read = 1 
                WHERE user_id = '$user_id' AND user_type = '$user_type' AND is_read = 0";
        
        return $this->query($sql);
    }

    public function getUnreadCount($user_id, $user_type) {
        $user_id = $this->escape($user_id);
        $user_type = $this->escape($user_type);

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE user_id = '$user_id' AND user_type = '$user_type' AND is_read = 0";
        
        $result = $this->query($sql);
        return $result->fetch_assoc()['count'];
    }
}