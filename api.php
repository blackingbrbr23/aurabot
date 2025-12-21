<?php
// api.php
header('Content-Type: application/json');
// local file onde vamos armazenar o comando (pode ser substituído por DB)
$storage = __DIR__ . '/commands.json';


if($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'status'){
if(file_exists($storage)){
$json = file_get_contents($storage);
echo $json;
} else {
echo json_encode(['command'=>null, 'updated_at'=>null]);
}
exit;
}


if($_SERVER['REQUEST_METHOD'] === 'POST'){
// esperar JSON no body
$body = file_get_contents('php://input');
$data = json_decode($body, true);
$cmd = isset($data['command']) ? $data['command'] : null;
if(!$cmd){
http_response_code(400);
echo json_encode(['error'=>'comando ausente']);
exit;
}


// salvar em arquivo com timestamp
$payload = [
'command' => $cmd,
'updated_at' => date('c')
];
file_put_contents($storage, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));


echo json_encode($payload);
exit;
}


// se chegar aqui
http_response_code(405);
echo json_encode(['error'=>'método não permitido']);
?>