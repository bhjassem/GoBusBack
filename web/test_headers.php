<?php
header('Content-Type: application/json');
echo json_encode([
    'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT_SET',
    'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'NOT_SET',
    'Authorization_Header' => getallheaders()['Authorization'] ?? 'NOT_SET',
    'PHP_SAPI' => PHP_SAPI,
    'All_Headers' => getallheaders(),
]);