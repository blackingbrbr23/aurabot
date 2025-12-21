<?php
// api.php
// Simple endpoint to persist last action per clientId in data/<clientId>.json
// Recebe:
//  - GET ?clientId=...  -> devolve { success: true, command: "start", timestamp: 123 }
//  - POST JSON { clientId: "...", command: "start" } -> grava e devolve sucesso

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . "data";
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

function jsonResponse($arr, $code = 200){
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeClientId($id){
    // permite letras, números, - e _
    if (!is_string($id)) return false;
    $id = trim($id);
    if ($id === '') return false;
    if (preg_match('/^[a-zA-Z0-9\-_]+$/', $id)) return $id;
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $clientId = isset($_GET['clientId']) ? $_GET['clientId'] : null;
    $clientId = sanitizeClientId($clientId);
    if (!$clientId) {
        jsonResponse(['success' => false, 'error' => 'clientId inválido'], 400);
    }
    $file = $dataDir . DIRECTORY_SEPARATOR . $clientId . '.json';
    if (!file_exists($file)) {
        jsonResponse(['success' => true, 'command' => null, 'timestamp' => null]);
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        jsonResponse(['success' => false, 'error' => 'falha ao ler arquivo'], 500);
    }
    $obj = @json_decode($raw, true);
    if (!$obj) {
        jsonResponse(['success' => false, 'error' => 'dados inválidos no servidor'], 500);
    }
    jsonResponse(['success' => true, 'command' => $obj['command'] ?? null, 'timestamp' => $obj['timestamp'] ?? null]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents("php://input");
    $json = @json_decode($body, true);
    if (!is_array($json)) {
        jsonResponse(['success' => false, 'error' => 'JSON inválido'], 400);
    }
    $clientId = isset($json['clientId']) ? $json['clientId'] : null;
    $command  = isset($json['command']) ? strtolower(trim($json['command'])) : null;

    $clientId = sanitizeClientId($clientId);
    if (!$clientId) {
        jsonResponse(['success' => false, 'error' => 'clientId inválido'], 400);
    }
    if (!in_array($command, ['start','stop'], true)) {
        jsonResponse(['success' => false, 'error' => 'command inválido (start|stop)'], 400);
    }

    $payload = [
        'clientId' => $clientId,
        'command'  => $command,
        'timestamp'=> time()
    ];
    $file = $dataDir . DIRECTORY_SEPARATOR . $clientId . '.json';
    $ok = @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    if ($ok === false) {
        jsonResponse(['success' => false, 'error' => 'falha ao escrever no servidor'], 500);
    }

    jsonResponse(['success' => true, 'command' => $command, 'timestamp' => $payload['timestamp']]);
}

// método não permitido
jsonResponse(['success' => false, 'error' => 'método não suportado'], 405);
