<?php
// index.php - login e painel AuraBot (atualizado para campo de mensagem com emoji)
// - usa data/users.db (cria se necessário)
// - persiste last_command/last_timestamp no DB por usuário
// - gera client_id ao primeiro login caso ainda não exista
// - exibe client_id no lado direito (permanente até admin remover)

session_start();

$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . "data";
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0755, true);
}
$DB_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . "users.db";

try {
    $pdo = new PDO('sqlite:' . $DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro ao abrir banco: " . htmlspecialchars($e->getMessage());
    exit;
}

/* --- Cria tabela se não existir e garante client_id coluna --- */
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_command TEXT DEFAULT NULL,
    last_timestamp INTEGER DEFAULT NULL
)");
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$has_client_id = false;
foreach ($cols as $c) { if (isset($c['name']) && $c['name'] === 'client_id') { $has_client_id = true; break; } }
if (!$has_client_id) {
    $pdo->exec("ALTER TABLE users ADD COLUMN client_id TEXT");
}

/* --- Garante admin padrão --- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
$stmt->execute([':u' => 'admin']);
if ((int)$stmt->fetchColumn() === 0) {
    $hash = password_hash('blackingbr', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, is_admin, created_at, client_id) VALUES (:u, :p, 1, :t, :cid)");
    $stmt->execute([':u' => 'admin', ':p' => $hash, ':t' => time(), ':cid' => bin2hex(random_bytes(8))]);
}

/* --- Helpers --- */
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function find_user_by_username($pdo, $username) {
    $stmt = $pdo->prepare("SELECT id, username, password, is_admin, created_at, last_command, last_timestamp, client_id FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function find_user_by_id($pdo, $id) {
    $stmt = $pdo->prepare("SELECT id, username, password, is_admin, created_at, last_command, last_timestamp, client_id FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function verify_credentials($pdo, $username, $password) {
    $user = find_user_by_username($pdo, $username);
    if (!$user) return false;
    if (password_verify($password, $user['password'])) {
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
            $stmt->execute([':p' => $newHash, ':id' => $user['id']]);
        }
        return $user;
    }
    return false;
}
function generate_unique_client_id($pdo) {
    for ($i=0;$i<10;$i++){
        $cid = bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE client_id = :cid");
        $stmt->execute([':cid'=>$cid]);
        if ((int)$stmt->fetchColumn() === 0) return $cid;
    }
    return uniqid('cid_', true);
}

/* --- Endpoints AJAX (somente para usuários logados) --- */
if (isset($_GET['action']) && $_GET['action'] === 'get_status') {
    header('Content-Type: application/json; charset=UTF-8');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success'=>false,'error'=>'não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $user = find_user_by_id($pdo, $_SESSION['user_id']);
    if (!$user) {
        echo json_encode(['success'=>false,'error'=>'usuário não encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => $user['last_command'] ?? null,
        'timestamp'=> $user['last_timestamp'] ? (int)$user['last_timestamp'] : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'send_command' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success'=>false,'error'=>'não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $body = file_get_contents('php://input');
    $json = @json_decode($body, true);
    if (!is_array($json)) {
        echo json_encode(['success'=>false,'error'=>'payload inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Aceita tanto "message" quanto retro-compatibilidade "command"
    $message = null;
    if (isset($json['message'])) $message = trim((string)$json['message']);
    elseif (isset($json['command'])) $message = trim((string)$json['command']);

    if ($message === null || $message === '') {
        echo json_encode(['success'=>false,'error'=>'mensagem vazia'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Limite razoável para evitar abusos
    if (mb_strlen($message, 'UTF-8') > 4000) {
        echo json_encode(['success'=>false,'error'=>'mensagem muito longa'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ts = time();
    $stmt = $pdo->prepare("UPDATE users SET last_command = :msg, last_timestamp = :ts WHERE id = :id");
    $stmt->execute([':msg' => $message, ':ts' => $ts, ':id' => $_SESSION['user_id']]);

    echo json_encode(['success'=>true,'message'=>$message,'timestamp'=>$ts], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- Logout via ?logout=1 --- */
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

/* --- Processa login (POST) --- */
$currentUser = null;
$errors = [];
if (!empty($_SESSION['user_id'])) {
    $currentUser = find_user_by_id($pdo, $_SESSION['user_id']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $errors[] = 'Informe usuário e senha.';
    } else {
        $user = verify_credentials($pdo, $username, $password);
        if ($user) {
            // se não tiver client_id, gera e salva (persistente)
            if (empty($user['client_id'])) {
                $cid = generate_unique_client_id($pdo);
                $stmt = $pdo->prepare("UPDATE users SET client_id = :cid WHERE id = :id");
                $stmt->execute([':cid'=>$cid, ':id'=>$user['id']]);
                // atualizar variável $user
                $user['client_id'] = $cid;
            }
            $_SESSION['user_id'] = $user['id'];
            header('Location: index.php');
            exit;
        } else {
            $errors[] = 'Usuário ou senha incorretos.';
        }
    }
}

/* --- caso usuário já logado, garantir que client_id exista e esteja atualizado na $currentUser --- */
if (!empty($_SESSION['user_id'])) {
    $currentUser = find_user_by_id($pdo, $_SESSION['user_id']);
    if ($currentUser && empty($currentUser['client_id'])) {
        $cid = generate_unique_client_id($pdo);
        $stmt = $pdo->prepare("UPDATE users SET client_id = :cid WHERE id = :id");
        $stmt->execute([':cid'=>$cid, ':id'=>$currentUser['id']]);
        $currentUser['client_id'] = $cid;
    }
}

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AuraBot — Faça login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --bg-1: #07101a;
      --bg-2: #0b1420;
      --accent-a: #7c5cff;
      --accent-b: #3ec7ff;
      --muted: #9aa6b2;
      --glass: rgba(255,255,255,0.03);
    }
    html,body{height:100%;}
    body {
      background: linear-gradient(180deg,var(--bg-1),var(--bg-2));
      color: #e6eef6;
      font-family: "Inter", -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
    }
    .card {
      background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
      border: 1px solid rgba(255,255,255,0.04);
      border-radius:1rem;
      box-shadow: 0 12px 36px rgba(2,6,23,0.6);
      padding:28px;
      width:100%;
      max-width:720px;
    }
    .title {
      margin:0;
      font-weight:900;
      letter-spacing: -1px;
      line-height:0.95;
      font-size:40px;
      text-align:center;
      background: linear-gradient(90deg, var(--accent-a), var(--accent-b));
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
      color: transparent;
      display:inline-block;
      padding:4px 8px;
      border-radius:10px;
    }
    .subtitle { color: var(--muted); text-align:center; margin-bottom:18px; }
    .form-control { background: rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.04); color: #e6eef6; }
    .btn-primary { background: linear-gradient(90deg,var(--accent-a),var(--accent-b)); border: none; font-weight:700; }
    .muted { color: var(--muted); font-size:0.9rem; text-align:center; margin-top:8px; }
    .user-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
    .chip { padding:6px 10px; border-radius:999px; background: rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.02); }
    .cid-chip { font-family:monospace; background:rgba(255,255,255,0.02); padding:6px 10px; border-radius:8px; color:#e6eef6; margin-left:8px; }
    .chip-wait  { background: rgba(255,255,255,0.02); color: var(--muted); border: 1px solid rgba(255,255,255,0.02); padding:7px 12px;border-radius:999px;}
    .copy-btn { background:transparent; border:0; color:#9aa6b2; cursor:pointer; margin-left:6px; }
    .message-box { background: var(--glass); border-radius:10px; padding:14px; margin-top:18px; }
    .emoji-btn { background:transparent; border:0; font-size:20px; cursor:pointer; margin-right:6px; }
    .emoji-panel { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
    .emoji-pill { background: rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.02); padding:6px 8px; border-radius:8px; cursor:pointer; }
    .send-btn { display:flex; align-items:center; justify-content:center; }
    .small-muted { color:var(--muted); font-size:0.85rem; }
  </style>
</head>
<body>
  <div class="card">
    <?php if (!$currentUser): ?>
      <h1 class="title">AuraBot</h1>
      <p class="subtitle">Faça login no AuraBot</p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
          <?php foreach ($errors as $er) echo e($er) . "<br>"; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="mb-2">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
          <label for="username">Usuário</label>
          <input id="username" name="username" class="form-control" placeholder="seu usuário" required>
        </div>
        <div class="form-group">
          <label for="password">Senha</label>
          <input id="password" name="password" type="password" class="form-control" placeholder="sua senha" required>
        </div>

        <div class="row">
          <div class="col-12 col-md-6">
            <button class="btn btn-primary btn-block">Entrar</button>
          </div>
          <div class="col-12 col-md-6">
            <!-- botão de ajuda removido conforme solicitado -->
            <button type="button" class="btn btn-outline-light btn-block" onclick="void(0);"> </button>
          </div>
        </div>
      </form>

    <?php else: 
      // Usuário autenticado — mostra a interface AuraBot (painel)
    ?>
      <div class="user-bar">
        <div>
          <strong>Conectado como:</strong> <span class="chip"><?php echo e($currentUser['username']); ?></span>
        </div>
        <div style="display:flex; align-items:center;">
          <?php if (!empty($currentUser['client_id'])): ?>
            <span class="cid-chip" id="clientIdDisplay"><?php echo e($currentUser['client_id']); ?></span>
            <button class="copy-btn" onclick="copyClientId()" title="Copiar client ID">📋</button>
          <?php endif; ?>
          <a href="index.php?logout=1" class="btn btn-sm btn-outline-light" style="margin-left:8px;">Sair</a>
        </div>
      </div>

      <div style="text-align:center; margin-bottom:14px;">
        <h1 class="title">AuraBot</h1>
      </div>

      <!-- Campo de mensagem e emojis -->
      <div class="message-box">
        <label for="messageInput"><strong>Mensagem para o cliente</strong></label>
        <textarea id="messageInput" class="form-control" rows="3" placeholder="Escreva algo..."></textarea>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; gap:12px;">
          <div>
            <button id="toggleEmojiPanel" class="emoji-btn" title="Abrir painel de emojis">😀</button>
            <span class="small-muted">Clique em um emoji para inserir</span>
          </div>
          <div style="display:flex; gap:8px;">
            <button id="sendMessageBtn" class="btn btn-primary send-btn">Enviar</button>
            <button id="clearMessageBtn" class="btn btn-outline-light">Limpar</button>
          </div>
        </div>

        <div id="emojiPanel" class="emoji-panel" style="display:none; margin-top:10px;">
          <!-- emojis básicos -->
          <button class="emoji-pill" data-emoji="😊">😊</button>
          <button class="emoji-pill" data-emoji="😂">😂</button>
          <button class="emoji-pill" data-emoji="😍">😍</button>
          <button class="emoji-pill" data-emoji="👍">👍</button>
          <button class="emoji-pill" data-emoji="👎">👎</button>
          <button class="emoji-pill" data-emoji="🎉">🎉</button>
          <button class="emoji-pill" data-emoji="🔥">🔥</button>
          <button class="emoji-pill" data-emoji="🤖">🤖</button>
          <button class="emoji-pill" data-emoji="💬">💬</button>
          <button class="emoji-pill" data-emoji="⚠️">⚠️</button>
        </div>
      </div>

      <div class="status-box mt-3" style="background:var(--glass); border-radius:10px; padding:12px; margin-top:18px;">
        <strong style="opacity:0.92;">Última mensagem:</strong>
        <div id="status" style="margin-top:8px;">
          <span class="chip-wait">aguardando...</span>
        </div>
        <div id="statusTime" class="small-muted" style="margin-top:8px;"></div>
      </div>

      <script>
      // Funções utilitárias
      function e(s){ return String(s || ''); }

      // copia client id para clipboard
      function copyClientId(){
        var el = document.getElementById('clientIdDisplay');
        if(!el) return;
        var text = el.textContent || el.innerText;
        if(!navigator.clipboard) { alert('Seu navegador não suporta copiar por script.'); return; }
        navigator.clipboard.writeText(text).then(function(){ alert('Client ID copiado'); }, function(){ alert('Falha ao copiar'); });
      }

      // Gera clientId local (mantido para compatibilidade com api.php)
      function uuidv4(){
        if (crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c){
          const r = Math.random()*16|0, v = c=='x' ? r : (r&0x3|0x8);
          return v.toString(16);
        });
      }
      const STORAGE_KEY_ID = "AuraBotClientId";
      const STORAGE_KEY_ACTION = "AuraBotLastAction";
      const API_URL = "api.php"; // seu endpoint original (opcional)

      function getClientId(){
        let id = localStorage.getItem(STORAGE_KEY_ID);
        if(!id){
          id = uuidv4();
          localStorage.setItem(STORAGE_KEY_ID, id);
        }
        return id;
      }

      // UI de status
      function setUI(message, ts){
        const statusEl = document.getElementById("status");
        const timeEl = document.getElementById("statusTime");

        if(!message){
          statusEl.innerHTML = '<span class="chip-wait">aguardando...</span>';
          timeEl.textContent = '';
          return;
        }

        // inserir mensagem com segurança (textContent)
        statusEl.textContent = message;

        if(ts){
          const d = new Date(ts * 1000);
          timeEl.textContent = 'Enviado em ' + d.toLocaleString();
        } else {
          timeEl.textContent = '';
        }
      }

      // envia mensagem ao servidor (por usuário) — endpoint interno index.php?action=send_command
      async function updateServerMessage(message){
        try {
          const r = await fetch('index.php?action=send_command', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ message: message })
          });
          if(!r.ok) return null;
          const j = await r.json();
          return j;
        } catch(e){
          console.warn('Falha ao atualizar mensagem no servidor:', e);
          return null;
        }
      }

      // busca status do servidor (por usuário)
      async function fetchServerStatus(){
        try {
          const r = await fetch('index.php?action=get_status', { method: 'GET' });
          if(!r.ok) return null;
          const j = await r.json();
          if(j && j.success) return j;
          return null;
        } catch(e){
          console.warn('Falha ao obter status do servidor:', e);
          return null;
        }
      }

      // envia também para api.php (se quiser manter o armazenamento por clientId)
      async function sendToApiPhp(clientId, message){
        try {
          await fetch(API_URL, {
            method: "POST",
            headers: { "Content-Type": "application/json; charset=utf-8" },
            body: JSON.stringify({ clientId: clientId, command: message })
          });
        } catch(e){
          // não crítico
        }
      }

      async function sendMessage(){
        const input = document.getElementById('messageInput');
        const txt = (input.value || '').trim();
        if(!txt){
          alert('Escreva uma mensagem antes de enviar.');
          return;
        }

        // atualiza servidor (persistência por usuário) — principal
        const srv = await updateServerMessage(txt);
        if (srv && srv.success) {
          setUI(srv.message, srv.timestamp);
        } else {
          // se falhar, ao menos atualiza a UI localmente
          setUI(txt, Math.floor(Date.now()/1000));
        }

        // atualiza também a API por clientId (compatibilidade)
        sendToApiPhp(getClientId(), txt);

        // salva localmente (opcional)
        localStorage.setItem(STORAGE_KEY_ACTION, JSON.stringify({ message: txt, timestamp: Date.now() }));

        // limpar campo opcionalmente
        // input.value = '';
      }

      (async function init(){
        // tenta obter o status do servidor para este usuário
        const server = await fetchServerStatus();
        if (server && (server.message !== undefined)) {
          setUI(server.message, server.timestamp);
        } else {
          setUI(null);
        }

        // botões
        document.getElementById('sendMessageBtn').onclick = (e) => { e.preventDefault(); sendMessage(); };
        document.getElementById('clearMessageBtn').onclick = (e) => { e.preventDefault(); document.getElementById('messageInput').value = ''; };

        // emoji panel toggle
        const toggleBtn = document.getElementById('toggleEmojiPanel');
        const emojiPanel = document.getElementById('emojiPanel');
        toggleBtn.onclick = function(e){
          e.preventDefault();
          emojiPanel.style.display = (emojiPanel.style.display === 'none' || emojiPanel.style.display === '') ? 'flex' : 'none';
        };

        // emoji insertion
        const emojiButtons = document.querySelectorAll('.emoji-pill');
        emojiButtons.forEach(function(b){
          b.addEventListener('click', function(){
            const em = b.getAttribute('data-emoji') || '';
            const input = document.getElementById('messageInput');
            // inserir no cursor / fim do texto
            const start = input.selectionStart || input.value.length;
            const end = input.selectionEnd || start;
            const before = input.value.substring(0, start);
            const after = input.value.substring(end);
            input.value = before + em + after;
            // posiciona o cursor após o emoji
            const pos = before.length + em.length;
            input.setSelectionRange(pos, pos);
            input.focus();
          });
        });

      })();
      </script>

    <?php endif; ?>
  </div>
</body>
</html>
