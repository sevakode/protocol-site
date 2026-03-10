<?php

$allowed_origins = [
    'https://protocol-s.io',
    'https://www.protocol-s.io',
];
$log_file = __DIR__ . '/log.txt';

$api_url = 'https://api.okocrm.com/v2/leads';
$api_token = 'f56024ca110389f9674e76547cef3335:e4c33f518569fa7859a9509d06144c87';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!in_array($origin, $allowed_origins)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['status' => 'error', 'message' => 'Access denied']));
}

header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$cache_file = __DIR__ . '/ip_cache.json';
$spam_limit = 5;
$time_window = 3600;

$ip_cache = [];
if (file_exists($cache_file)) {
    $ip_cache = json_decode(file_get_contents($cache_file), true) ?: [];
}

$ip_cache = array_filter($ip_cache, function ($entry) use ($time_window) {
    return $entry['timestamp'] > (time() - $time_window);
});

if (isset($ip_cache[$ip]) && $ip_cache[$ip]['count'] >= $spam_limit) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['status' => 'error', 'message' => 'Too many requests']));
}

$ip_cache[$ip] = [
    'count'     => ($ip_cache[$ip]['count'] ?? 0) + 1,
    'timestamp' => $ip_cache[$ip]['timestamp'] ?? time(),
];
file_put_contents($cache_file, json_encode($ip_cache));

function logRequest($message, $log_file) {
    $ts = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$ts] $message\n", FILE_APPEND);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $data = $_POST;
}

$referer    = $_SERVER['HTTP_REFERER'] ?? 'direct';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$contact  = trim($data['contact'] ?? '');
$formType = $data['form_type'] ?? 'contact';

if ($contact === '') {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['status' => 'error', 'message' => 'Contact field is required']));
}

logRequest("Incoming [{$formType}]: contact={$contact}, ip={$ip}, ref={$referer}", $log_file);

$lead_name = $formType === 'partner'
    ? 'Партнёрская заявка — protocol-s.io'
    : 'Новая заявка — protocol-s.io';

$note_text  = "Форма: {$formType}\n";
$note_text .= "Контакт: {$contact}\n";
$note_text .= "Источник: {$referer}\n";
$note_text .= "IP: {$ip}\n";
$note_text .= "UA: {$user_agent}\n";

$is_phone = preg_match('/^[\d\s\+\-\(\)]{7,}$/', $contact);

$curl_data = [
    'pipeline_id'    => '11100',
    'stages_id'      => '52094',
    'name'           => $lead_name,
    'contact[name]'  => $contact,
    'contact[phone]' => $is_phone ? $contact : '',
    'responsible'    => 'queue_24',
    'ip'             => (string)$ip,
    'note[text]'     => $note_text,
];

$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $curl_data,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Authorization: Bearer ' . $api_token,
    ],
    CURLOPT_TIMEOUT        => 15,
]);

$response   = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

logRequest("CRM [{$http_code}]: {$response}", $log_file);
if ($curl_error) {
    logRequest("CURL Error: {$curl_error}", $log_file);
}

$ok = $http_code >= 200 && $http_code < 300;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status'  => $ok ? 'success' : 'error',
    'message' => $ok ? 'OK' : 'CRM error',
], JSON_UNESCAPED_UNICODE);
