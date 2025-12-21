<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Painel Start / Stop</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body {
      background: linear-gradient(180deg,#f8fafc,#ffffff);
      min-height:100vh;
    }
    .card {
      border-radius:1rem;
      box-shadow:0 8px 24px rgba(0,0,0,0.08);
    }
  </style>
</head>

<body>
<div class="container d-flex justify-content-center align-items-center" style="min-height:100vh;">
  <div class="card p-4" style="max-width:600px;width:100%;">
    <h3 class="mb-3 text-center">Painel de Controle</h3>

    <div class="row">
      <div class="col-12 col-md-6 mb-2">
        <button id="btnStart" class="btn btn-success btn-lg btn-block">INICIAR</button>
      </div>
      <div class="col-12 col-md-6 mb-2">
        <button id="btnStop" class="btn btn-danger btn-lg btn-block">STOP</button>
      </div>
    </div>

    <div class="mt-3 text-center">
      <strong>Status:</strong> <span id="status">aguardando...</span>
    </div>
  </div>
</div>

<script>
async function send(cmd){
  const r = await fetch("api.php",{
    method:"POST",
    headers:{ "Content-Type":"application/json" },
    body: JSON.stringify({command:cmd})
  });
  const j = await r.json();
  document.getElementById("status").innerText = j.command;
}

document.getElementById("btnStart").onclick = ()=>send("start");
document.getElementById("btnStop").onclick  = ()=>send("stop");
</script>

</body>
</html>
