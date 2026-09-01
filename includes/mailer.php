<?php
function send_store_mail($to, $subject, $html, $headers, $transport = null) {
    if ($transport !== null) return (bool)$transport($to, $subject, $html, $headers);
    return mail($to, $subject, $html, $headers);
}
