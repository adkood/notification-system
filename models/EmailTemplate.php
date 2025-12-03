<?php
require_once 'Model.php';

class EmailTemplate extends Model {
    protected $table = 'email_templates';

    public function getAll() {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY template_name";
        $result = $this->query($sql);
        
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['placeholders']) {
                $row['placeholders'] = explode(',', $row['placeholders']);
            }
            $templates[] = $row;
        }
        return $templates;
    }

    public function getByName($template_name) {
        $template_name = $this->escape($template_name);
        
        $sql = "SELECT * FROM {$this->table} WHERE template_name = '$template_name' AND is_active = 1";
        $result = $this->query($sql);
        
        if ($result->num_rows > 0) {
            $template = $result->fetch_assoc();
            if ($template['placeholders']) {
                $template['placeholders'] = explode(',', $template['placeholders']);
            }
            return $template;
        }
        return null;
    }

    public function create($data) {
        $template_name = $this->escape($data['template_name']);
        $subject_template = $this->escape($data['subject_template']);
        $body_template = $this->escape($data['body_template']);
        $placeholders = isset($data['placeholders']) ? "'" . $this->escape(implode(',', $data['placeholders'])) . "'" : 'NULL';

        $sql = "INSERT INTO {$this->table} (template_name, subject_template, body_template, placeholders) 
                VALUES ('$template_name', '$subject_template', '$body_template', $placeholders)";
        
        return $this->query($sql);
    }

    public function update($id, $data) {
        $id = $this->escape($id);
        $updates = [];
        
        foreach ($data as $key => $value) {
            if ($key === 'placeholders' && is_array($value)) {
                $value = implode(',', $value);
            }
            $updates[] = "$key = '" . $this->escape($value) . "'";
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE id = '$id'";
        return $this->query($sql);
    }

    public function renderTemplate($template_name, $data) {
        $template = $this->getByName($template_name);
        if (!$template) {
            return null;
        }

        $subject = $template['subject_template'];
        $body = $template['body_template'];

        foreach ($data as $key => $value) {
            $placeholder = '{' . $key . '}';
            $subject = str_replace($placeholder, $value, $subject);
            $body = str_replace($placeholder, $value, $body);
        }

        return [
            'subject' => $subject,
            'body' => $body
        ];
    }
}