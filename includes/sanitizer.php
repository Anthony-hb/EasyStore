<?php
/**
 * Utility Class for Input Sanitization, Validation and XSS Prevention
 */
class Sanitizer {

    /**
     * Sanitizes a plain text string.
     * Strips invalid UTF-8 sequences, control characters, HTML tags, and trims whitespace.
     */
    public static function string($val, ?int $maxLength = null, bool $allowLineBreaks = false): string {
        if ($val === null || $val === false) {
            return '';
        }
        $val = (string)$val;
        // Fix invalid UTF-8
        $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
        // Strip null bytes and control chars (preserve \n \r \t if allowLineBreaks is true)
        if ($allowLineBreaks) {
            $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $val);
        } else {
            $val = preg_replace('/[\x00-\x1F\x7F]/u', '', $val);
        }
        // Strip HTML tags for clean plain text
        $val = strip_tags($val);
        $val = trim($val);
        if ($maxLength !== null && $maxLength > 0) {
            $val = mb_substr($val, 0, $maxLength, 'UTF-8');
        }
        return $val;
    }

    /**
     * Sanitizes multiline text preserving newlines.
     */
    public static function text($val, ?int $maxLength = null): string {
        return self::string($val, $maxLength, true);
    }

    /**
     * Sanitizes and validates an email address.
     */
    public static function email($val): string {
        if (empty($val)) return '';
        $val = self::string($val, 255);
        $val = strtolower($val);
        $sanitized = filter_var($val, FILTER_SANITIZE_EMAIL);
        return $sanitized !== false ? $sanitized : '';
    }

    /**
     * Checks if a string is a valid email format.
     */
    public static function isValidEmail(string $email): bool {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Sanitizes an integer input.
     */
    public static function int($val, ?int $min = null, ?int $max = null, int $default = 0): int {
        if ($val === null || $val === '') return $default;
        if (!is_numeric($val)) return $default;
        $num = (int)$val;
        if ($min !== null && $num < $min) return $min;
        if ($max !== null && $num > $max) return $max;
        return $num;
    }

    /**
     * Sanitizes a float numeric input (handles comma decimals).
     */
    public static function float($val, ?float $min = null, ?float $max = null, float $default = 0.0): float {
        if ($val === null || $val === '') return $default;
        $cleanStr = str_replace(',', '.', (string)$val);
        if (!is_numeric($cleanStr)) return $default;
        $num = (float)$cleanStr;
        if ($min !== null && $num < $min) return $min;
        if ($max !== null && $num > $max) return $max;
        return $num;
    }

    /**
     * Sanitizes phone numbers keeping only digits and optional leading plus sign (+).
     */
    public static function phone($val): string {
        if (empty($val)) return '';
        $val = trim((string)$val);
        $hasPlus = str_starts_with($val, '+');
        $digits = preg_replace('/[^0-9]/', '', $val);
        return ($hasPlus ? '+' : '') . $digits;
    }

    /**
     * Sanitizes a username string (alphanumeric, dots, underscores, hyphens).
     */
    public static function username($val, int $maxLength = 50): string {
        $clean = self::string($val, $maxLength);
        return preg_replace('/[^A-Za-z0-9._-]/', '', $clean);
    }

    /**
     * Escapes string for safe HTML rendering to prevent XSS.
     */
    public static function escapeHtml($val): string {
        if ($val === null) return '';
        return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitizes filenames to prevent Directory Traversal attacks (../ or ..\).
     */
    public static function filename($val): string {
        $val = basename((string)$val);
        return preg_replace('/[^a-zA-Z0-9_\.-]/', '', $val);
    }

    /**
     * Safely reads and parses JSON request payload from php://input.
     *
     * Se quita el BOM UTF-8 (EF BB BF) si viene al inicio: segun RFC 8259 no
     * es valido ahi, por lo que json_decode fallaria siempre en ese caso.
     */
    public static function readJsonInput(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return [];
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
