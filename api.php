<?php
// api.php — retorna o comando para um clientId (GET clientId=...)
// Requer que seu DB esteja em data/users.db e tenha a coluna client_id, last_command, last_timestamp

header('Content-Type: application/json; charset=UTF-8');

$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . "data";
$DB_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . "users.db";

if (!isset($_GET['clientId']) || !is_string($_GET['clientId']) || trim($_GET['clientId']) === '') {
    echo json_encode(['success' => false, 'error' => 'clientId ausente'], JSON_UNESCAPED_UNICODE);
    exit;
}
$clientId = trim($_GET['clientId']);

if (!file_exists($DB_FILE)) {
    echo json_encode(['success' => false, 'error' => 'banco não encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT username, last_command, last_timestamp, client_id FROM users WHERE client_id = :cid LIMIT 1");
    $stmt->execute([':cid' => $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'clientId não encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Normaliza saída: se last_timestamp for nulo, devolve null; se string->int quando possível
    $ts = null;
    if (!empty($row['last_timestamp'])) {
        $ts = (int)$row['last_timestamp'];
    }

    $cmd = null;
    if (!empty($row['last_command'])) {
        $cmd = strtolower($row['last_command']);
    }

    echo json_encode([
        'success' => true,
        'client_id' => $row['client_id'],
        'username' => $row['username'],
        'command' => $cmd,
        'timestamp' => $ts
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'erro no servidor: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
