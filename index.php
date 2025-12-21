<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AuraBot — Painel Start / Stop</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --bg-1: #0f1724;
      --bg-2: #0b1220;
      --card: #0b1228;
      --accent: #7c5cff;
      --muted: #9aa6b2;
      --glass: rgba(255,255,255,0.03);
    }

    html,body{height:100%;}
    body {
      background: linear-gradient(180deg,var(--bg-1),var(--bg-2));
      color: #e6eef6;
      font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
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
      box-shadow: 0 10px 30px rgba(2,6,23,0.6);
    }

    .brand {
      display:flex;
      align-items:center;
      gap:12px;
      justify-content:center;
    }

    .logo {
      width:56px;
      height:56px;
      border-radius:12px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      background: linear-gradient(135deg, rgba(124,92,255,0.95), rgba(62,199,255,0.95));
      box-shadow: 0 8px 22px rgba(124,92,255,0.14), inset 0 -6px 18px rgba(0,0,0,0.12);
    }

    /* small decorative spiral inside logo using SVG */
    .title {
      font-size:1.5rem;
      font-weight:800;
      letter-spacing:0.4px;
      margin:0;
    }

    .btn-lg {
      border-radius: 12px;
      padding: 0.9rem 1rem;
      font-weight:700;
      letter-spacing:0.4px;
    }

    .status-box{
      background: var(--glass);
      border-radius:10px;
      padding:14px;
      margin-top:12px;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      border:1px solid rgba(255,255,255,0.02);
    }

    .chip {
      padding:6px 10px;
      border-radius:999px;
      font-weight:700;
      font-size:0.9rem;
    }

    .chip-start { background: rgba(37, 211, 102, 0.12); color: #22c55e; border: 1px solid rgba(34,197,94,0.12); }
    .chip-stop  { background: rgba(239,68,68,0.08); color: #fb7185; border: 1px solid rgba(239,68,68,0.08); }
    .chip-wait  { background: rgba(255,255,255,0.02); color: var(--muted); border: 1px solid rgba(255,255,255,0.02); }

    @media (max-width:480px){
      .card{padding:18px;}
    }
  </style>
</head>

<body>
  <div class="container d-flex justify-content-center align-items-center" style="min-height:100vh;">
    <div class="card p-4" style="max-width:640px; width:100%;">
      <div class="brand mb-3">
        <div class="logo" aria-hidden="true">
          <!-- small svg decorative swirl (no letters) -->
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M12 2C13.1046 2 14 2.89543 14 4C14 5.10457 13.1046 6 12 6C10.8954 6 10 5.10457 10 4C10 3.44772 10.4477 3 11 3" stroke="white" stroke-opacity="0.92" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M4 12C4 8.68629 6.68629 6 10 6H14" stroke="white" stroke-opacity="0.85" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M20 12C20 15.3137 17.3137 18 14 18H10" stroke="white" stroke-opacity="0.7" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>

        <div>
          <p class="title">AuraBot</p>
        </div>
      </div>

      <div class="row">
        <div class="col-12 col-md-6 mb-2">
          <button id="btnStart" class="btn btn-success btn-lg btn-block">INICIAR</button>
        </div>
        <div class="col-12 col-md-6 mb-2">
          <button id="btnStop" class="btn btn-danger btn-lg btn-block">STOP</button>
        </div>
      </div>

      <div class="status-box mt-3">
        <strong style="opacity:0.9;">Status:</strong>
        <div id="status" style="margin-left:6px;">aguardando...</div>
      </div>
    </div>
  </div>

<script>
/*
  Lógica preservada:
  - Cada navegador gera um clientId salvo em localStorage ("AuraBotClientId").
  - Última ação salva em localStorage ("AuraBotLastAction") com {command,timestamp}.
  - Enviamos POST para api.php com {clientId, command} toda vez que o usuário clicar.
  - Ao carregar, priorizamos localStorage; se não existir consultamos o servidor GET api.php?clientId=...
*/

function uuidv4(){
  if (crypto && crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c){
    const r = Math.random()*16|0, v = c=='x' ? r : (r&0x3|0x8);
    return v.toString(16);
  });
}

const STORAGE_KEY_ID = "AuraBotClientId";
const STORAGE_KEY_ACTION = "AuraBotLastAction";
const API_URL = "api.php";

function getClientId(){
  let id = localStorage.getItem(STORAGE_KEY_ID);
  if(!id){
    id = uuidv4();
    localStorage.setItem(STORAGE_KEY_ID, id);
  }
  return id;
}

function saveLocalAction(command){
  const payload = { command: command, timestamp: Date.now() };
  localStorage.setItem(STORAGE_KEY_ACTION, JSON.stringify(payload));
}

function readLocalAction(){
  const raw = localStorage.getItem(STORAGE_KEY_ACTION);
  if(!raw) return null;
  try { return JSON.parse(raw); } catch(e){ return null; }
}

function setUI(command){
  const statusEl = document.getElementById("status");
  const btnStart = document.getElementById("btnStart");
  const btnStop  = document.getElementById("btnStop");

  btnStart.classList.remove("active");
  btnStop.classList.remove("active");

  if(command === "start"){
    statusEl.innerHTML = '<span class="chip chip-start">INICIADO</span>';
    btnStart.classList.add("active");
  } else if(command === "stop"){
    statusEl.innerHTML = '<span class="chip chip-stop">PARADO</span>';
    btnStop.classList.add("active");
  } else {
    statusEl.innerHTML = '<span class="chip chip-wait">aguardando...</span>';
  }
}

async function sendCommandToServer(clientId, command){
  try{
    const r = await fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ clientId: clientId, command: command })
    });
    if(!r.ok) {
      console.warn("Erro ao enviar para servidor:", r.status);
      return null;
    }
    const j = await r.json();
    return j;
  } catch(e){
    console.warn("Falha na conexão com api.php:", e);
    return null;
  }
}

async function fetchServerAction(clientId){
  try {
    const r = await fetch(API_URL + "?clientId=" + encodeURIComponent(clientId), { method: "GET" });
    if(!r.ok) return null;
    const j = await r.json();
    if(j && j.command) return j;
    return null;
  } catch(e){
    console.warn("Erro ao buscar ação no servidor:", e);
    return null;
  }
}

async function send(cmd){
  const clientId = getClientId();

  setUI(cmd);
  saveLocalAction(cmd);

  const resp = await sendCommandToServer(clientId, cmd);
  if(resp && resp.success){
    // servidor confirmou
  } else {
    // sem confirmação do servidor (continua salvo localmente)
  }
}

(async function init(){
  const clientId = getClientId();

  const local = readLocalAction();
  if(local && local.command){
    setUI(local.command);
  } else {
    const server = await fetchServerAction(clientId);
    if(server && server.command){
      setUI(server.command);
      saveLocalAction(server.command);
    } else {
      setUI(null);
    }
  }

  document.getElementById("btnStart").onclick = ()=> send("start");
  document.getElementById("btnStop").onclick  = ()=> send("stop");
})();
</script>

</body>
</html>
