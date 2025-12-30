<?php
// dados.php - painel admin (autônomo) atualizado
// - cria data/ e data/users.db automaticamente
// - admin padrão: admin / blackingbr
// - permite criar/remover clientes (usuários não-admin)
// - ao criar usuário, mostra um "olho" para revelar a senha criada (apenas imediatamente)

session_start();

/* --- Config / DB bootstrap --- */
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

/* --- Criar tabela users com colunas de status --- */
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_command TEXT DEFAULT NULL,
    last_timestamp INTEGER DEFAULT NULL
)");

/* --- Garante admin padrão --- */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
$stmt->execute([':u' => 'admin']);
if ((int)$stmt->fetchColumn() === 0) {
    $hash = password_hash('blackingbr', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, is_admin, created_at) VALUES (:u, :p, 1, :t)");
    $stmt->execute([':u' => 'admin', ':p' => $hash, ':t' => time()]);
}

/* --- Helpers --- */
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function find_user_by_username($pdo, $username) {
    $stmt = $pdo->prepare("SELECT id, username, password, is_admin, created_at, last_command, last_timestamp FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function find_user_by_id($pdo, $id) {
    $stmt = $pdo->prepare("SELECT id, username, password, is_admin, created_at, last_command, last_timestamp FROM users WHERE id = :id LIMIT 1");
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
function require_admin($pdo) {
    if (empty($_SESSION['user_id'])) {
        header('Location: dados.php');
        exit;
    }
    $user = find_user_by_id($pdo, $_SESSION['user_id']);
    if (!$user || (int)$user['is_admin'] !== 1) {
        http_response_code(403);
        echo "Acesso negado. Você precisa ser admin.";
        exit;
    }
}

/* --- Logout via GET ?logout=1 --- */
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: dados.php');
    exit;
}

/* --- Se não logado: processa tentativa de login admin --- */
$adminErrors = [];
if (empty($_SESSION['user_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $adminErrors[] = 'Informe usuário e senha.';
        } else {
            $user = verify_credentials($pdo, $username, $password);
            if ($user && (int)$user['is_admin'] === 1) {
                $_SESSION['user_id'] = $user['id'];
                header('Location: dados.php');
                exit;
            } else {
                $adminErrors[] = 'Credenciais inválidas ou usuário não é admin.';
            }
        }
    }

    // Tela de login admin
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
      <meta charset="utf-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <title>Painel Admin — AuraBot</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
        body{ background:#07101a; color:#e6eef6; display:flex; align-items:center; justify-content:center; height:100vh; }
        .card{ background:rgba(255,255,255,0.02); padding:24px; border-radius:12px; width:100%; max-width:480px; }
      </style>
    </head>
    <body>
      <div class="card">
        <h3 class="mb-3">Painel Admin — AuraBot</h3>
        <?php if (!empty($adminErrors)): ?>
          <div class="alert alert-danger"><?php foreach ($adminErrors as $er) echo e($er) . "<br>"; ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="admin_login">
          <div class="form-group">
            <label>Usuário</label>
            <input name="username" class="form-control" placeholder="admin" required>
          </div>
          <div class="form-group">
            <label>Senha</label>
            <input type="password" name="password" class="form-control" placeholder="sua senha" required>
          </div>
          <div class="d-flex">
            <button class="btn btn-primary mr-2">Entrar</button>
            <a class="btn btn-secondary" href="index.php">Voltar</a>
          </div>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* --- Usuário está logado e é admin --- */
require_admin($pdo);

/* --- Processa criar usuário e deletar --- */
$admin_msg = '';
$created_plain_map = []; // id => plain password (apenas para mostrar imediatamente)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $new_user = trim($_POST['new_username'] ?? '');
    $new_pass = $_POST['new_password'] ?? '';
    if ($new_user === '' || $new_pass === '') {
        $admin_msg = "Preencha usuário e senha para criar.";
    } else {
        $exists = find_user_by_username($pdo, $new_user);
        if ($exists) {
            $admin_msg = "Usuário já existe.";
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, is_admin, created_at) VALUES (:u, :p, 0, :t)");
            $stmt->execute([':u' => $new_user, ':p' => $hash, ':t' => time()]);
            $newId = (int)$pdo->lastInsertId();
            $admin_msg = "Usuário criado com sucesso.";
            // guarda temporariamente para exibir o password (só nesta renderização)
            $created_plain_map[$newId] = $new_pass;
        }
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $user = find_user_by_id($pdo, $id);
    if ($user) {
        if ((int)$user['is_admin'] === 1) {
            $admin_msg = "Não é permitido deletar um usuário admin.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $admin_msg = "Usuário removido.";
        }
    } else {
        $admin_msg = "Usuário não encontrado.";
    }
}

/* --- Lista usuários --- */
$stmt = $pdo->query("SELECT id, username, is_admin, created_at, last_command, last_timestamp FROM users ORDER BY id ASC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dados — Painel Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{ background:#07101a; color:#e6eef6; padding:28px; font-family:Inter,Arial; }
    .card { background:rgba(255,255,255,0.02); border-radius:12px; padding:16px; }
    .small-muted { color:#9aa6b2; font-size:0.9rem; }
    .eye-btn { background:transparent; border:0; color:#9aa6b2; cursor:pointer; }
    .plain-pass { font-family:monospace; background:rgba(0,0,0,0.25); padding:4px 8px; border-radius:6px; }
    .status-dot { width:10px; height:10px; display:inline-block; border-radius:999px; margin-right:6px; vertical-align:middle; }
    .status-start { background:#22c55e; }
    .status-stop { background:#fb7185; }
    .status-wait { background:#9aa6b2; }
  </style>
</head>
<body>
  <div class="container" style="max-width:980px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3>Painel Admin — AuraBot</h3>
      <div>
        <a href="index.php" class="btn btn-outline-light btn-sm">Ir ao Painel</a>
        <a href="dados.php?logout=1" class="btn btn-secondary btn-sm">Sair</a>
      </div>
    </div>

    <?php if ($admin_msg): ?><div class="alert alert-info"><?php echo e($admin_msg); ?></div><?php endif; ?>

    <div class="card mb-3">
      <h5>Adicionar novo cliente</h5>
      <form method="post" class="form-inline">
        <input type="hidden" name="action" value="create_user">
        <div class="form-row" style="width:100%;">
          <div class="col-sm-4 mb-2">
            <input name="new_username" class="form-control form-control-sm" placeholder="nome de usuário (ex: cliente01)" required>
          </div>
          <div class="col-sm-4 mb-2">
            <input name="new_password" class="form-control form-control-sm" placeholder="senha" required>
          </div>
          <div class="col-sm-2 mb-2">
            <button class="btn btn-success btn-block btn-sm">Criar</button>
          </div>
          <div class="col-sm-2 mb-2 text-right small-muted" style="display:flex;align-items:center;justify-content:flex-end;">
            <span>As senhas não ficam gravadas em texto no banco.</span>
          </div>
        </div>
      </form>
      <p class="small-muted mt-2">Ao criar um cliente você poderá usar esse usuário/senha para acessar o painel AuraBot (index.php).</p>
    </div>

    <div class="card">
      <h5>Clientes cadastrados</h5>
      <div class="table-responsive">
        <table class="table table-sm table-borderless" style="color:#e6eef6;">
          <thead>
            <tr class="small-muted">
              <th>#</th>
              <th>Usuário</th>
              <th>Status</th>
              <th>Admin?</th>
              <th>Criado em</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): 
              $status = $u['last_command'] ?? null;
              $ts = $u['last_timestamp'] ? date('Y-m-d H:i', (int)$u['last_timestamp']) : '-';
            ?>
              <tr>
                <td><?php echo (int)$u['id']; ?></td>
                <td><?php echo e($u['username']); ?></td>
                <td>
                  <?php if ($status === 'start'): ?>
                    <span class="status-dot status-start"></span> INICIADO (<?php echo e($ts); ?>)
                  <?php elseif ($status === 'stop'): ?>
                    <span class="status-dot status-stop"></span> PARADO (<?php echo e($ts); ?>)
                  <?php else: ?>
                    <span class="status-dot status-wait"></span> aguardando (<?php echo e($ts); ?>)
                  <?php endif; ?>
                </td>
                <td><?php echo (int)$u['is_admin'] === 1 ? '<span class="badge badge-info">Sim</span>' : '<span class="badge badge-light">Não</span>'; ?></td>
                <td><?php echo date('Y-m-d H:i', (int)$u['created_at']); ?></td>
                <td style="text-align:right;">
                  <?php if ((int)$u['is_admin'] !== 1): ?>
                    <?php if (isset($created_plain_map[$u['id']])): ?>
                      <!-- mostra olho apenas para o usuário recém-criado (senha em plain disponível nesta renderização) -->
                      <button class="eye-btn" data-plain="<?php echo e($created_plain_map[$u['id']]); ?>" onclick="togglePassword(this)" title="Mostrar senha">
                        👁️
                      </button>
                    <?php else: ?>
                      <!-- sem senha disponível para exibir -->
                      <span class="small-muted">—</span>
                    <?php endif; ?>
                    <a href="dados.php?delete=<?php echo (int)$u['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Deseja remover este usuário?')">Remover</a>
                  <?php else: ?>
                    <span class="small-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

<script>
function togglePassword(btn){
  var plain = btn.getAttribute('data-plain') || '';
  if(!plain) return;
  if(btn._shown){
    btn._shown = false;
    btn.textContent = '👁️';
    btn.title = 'Mostrar senha';
    // opcional: mostrar um pequeno toast? aqui apenas alternamos atributo
  } else {
    btn._shown = true;
    // substituir o botão pelo texto da senha temporariamente
    var container = document.createElement('span');
    container.className = 'plain-pass';
    container.textContent = plain;
    // inserimos após o botão e removemos o botão
    btn.parentNode.insertBefore(container, btn);
    btn.remove();
  }
}
</script>

</body>
</html>
