<?php
function send_store_mail($to, $subject, $html, $headers, $transport = null) {
    if ($transport !== null) return (bool)$transport($to, $subject, $html, $headers);
    if (getenv('MAIL_TRANSPORT') === 'log') {
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
