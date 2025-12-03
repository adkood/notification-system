<?php
class Validation {
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function validateRequired($fields, $data) {
        $errors = [];
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                $errors[] = "$field is required";
            }
        }
        return $errors;
    }

    public static function validateLength($field, $value, $min = 0, $max = null) {
        $length = strlen($value);
        if ($length < $min) {
            return "$field must be at least $min characters";
        }
        if ($max !== null && $length > $max) {
            return "$field must be no more than $max characters";
        }
        return null;
    }

    public static function validateNumeric($field, $value) {
        if (!is_numeric($value)) {
            return "$field must be a number";
        }
        return null;
    }

    public static function validateDate($field, $value, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $value);
        if (!$d || $d->format($format) !== $value) {
            return "$field must be a valid date in format $format";
        }
        return null;
    }

    public static function validateArray($field, $value, $allowed = []) {
        if (!is_array($value)) {
            return "$field must be an array";
        }
        if (!empty($allowed)) {
            foreach ($value as $item) {
                if (!in_array($item, $allowed)) {
                    return "$field contains invalid value: $item";
                }
            }
        }
        return null;
    }
}