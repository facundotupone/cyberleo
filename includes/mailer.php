<?php
function safe_mail_header_value($value): string {
    $value = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', (string)$value);
    $value = preg_replace('/\b(?:bcc|cc|to|from|reply-to|subject)\s*:/i', '', $value);
    return trim(str_replace(['<', '>'], '', $value));
}
function send_store_mail($to, $subject, $html, $headers, $transport = null) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', (string)$to) || preg_match('/[\r\n]/', (string)$subject)) {
        error_log('Rejected invalid store mail headers.');
        return false;
    }
    if ($transport !== null) return (bool)$transport($to, $subject, $html, $headers);
    if (getenv('MAIL_TRANSPORT') === 'log') {
        if (getenv('APP_ENV') !== 'test') {
            error_log('Test mail transport rejected outside test environment.');
            return false;
        }
        $path = getenv('MAIL_LOG_PATH');
        if ($path === false || $path === '') {
            error_log('MAIL_LOG_PATH is required for the log mail transport.');
            return false;
        }
        $record = json_encode(
            ['to' => $to, 'subject' => $subject, 'html' => $html, 'headers' => $headers],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        return $record !== false
            && @file_put_contents($path, $record . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
    return mail($to, $subject, $html, $headers);
}
