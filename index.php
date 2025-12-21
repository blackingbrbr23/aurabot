<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AuraBot — Painel Start / Stop</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --bg-1: #07101a;
      --bg-2: #0b1420;
      --card: #081421;
      --accent-a: #7c5cff;   /* purple */
      --accent-b: #3ec7ff;   /* aqua */
      --accent-c: #ffd166;   /* warm gold for tiny highlight */
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

    /* Header / brand */
    .brand {
      display:flex;
      align-items:center;
      gap:18px;
      justify-content:center;
      margin-bottom:18px;
      flex-direction:column;
    }

    /* Big, professional title */
    .title {
      margin:0;
      font-weight:900;
      letter-spacing: -1px;
      line-height:0.95;
      font-family: "GT America", "Inter", "Helvetica Neue", Arial, sans-serif;
      font-size:48px; /* desktop */
      text-align:center;
      /* gradient text */
      background: linear-gradient(90deg, var(--accent-a), var(--accent-b));
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
      color: transparent;
      text-shadow: 0 6px 24px rgba(124,92,255,0.12), 0 2px 6px rgba(0,0,0,0.6);
      display:inline-block;
      padding:4px 8px;
      border-radius:10px;
    }

    /* subtle subline (removed earlier, kept hidden for future) */
    .subtitle {
      margin:0;
      color:var(--muted);
      font-size:0.95rem;
    }

    /* Buttons */
    .btn-lg {
      border-radius: 12px;
      padding: 0.95rem 1.15rem;
      font-weight:800;
      letter-spacing:0.6px;
    }

    .row-buttons { margin-top:10px; }

    .status-box{
      background: var(--glass);
      border-radius:10px;
      padding:14px;
      margin-top:18px;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:12px;
      border:1px solid rgba(255,255,255,0.03);
    }

    .chip {
      padding:7px 12px;
      border-radius:999px;
      font-weight:800;
      font-size:0.95rem;
    }

    .chip-start { background: rgba(37, 211, 102, 0.12); color: #22c55e; border: 1px solid rgba(34,197,94,0.08); }
    .chip-stop  { background: rgba(239,68,68,0.08); color: #fb7185; border: 1px solid rgba(239,68,68,0.08); }
    .chip-wait  { background: rgba(255,255,255,0.02); color: var(--muted); border: 1px solid rgba(255,255,255,0.02); }

    /* responsive adjustments */
    @media (max-width:900px){
      .title { font-size:40px; }
    }
    @media (max-width:600px){
      .title { font-size:34px; }
      .row-buttons .col-12 { margin-bottom:10px; }
    }
  </style>
</head>

<body>
  <div class="card">
    <div class="brand">
      <h1 class="title">AuraBot</h1>
    </div>

    <div class="row row-buttons">
      <div class="col-12 col-md-6 mb-2">
        <button id="btnStart" class="btn btn-success btn-lg btn-block">INICIAR</button>
      </div>
      <div class="col-12 col-md-6 mb-2">
        <button id="btnStop" class="btn btn-danger btn-lg btn-block">STOP</button>
      </div>
    </div>

    <div class="status-box mt-3">
      <strong style="opacity:0.92;">Status:</strong>
      <div id="status" style="margin-left:8px;">aguardando...</div>
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
    // servidor confirmou — nada extra necessário
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
