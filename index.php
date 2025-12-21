<?php
<p class="text-muted">Clique em <strong>INICIAR</strong> para enviar o comando de iniciar. O cliente na sua máquina precisa ficar ouvindo a API para executar o comando.</p>


<div class="row mb-3">
<div class="col-12 col-sm-6 mb-2">
<button id="btnStart" class="btn btn-success btn-lg btn-block">INICIAR</button>
</div>
<div class="col-12 col-sm-6 mb-2">
<button id="btnStop" class="btn btn-danger btn-lg btn-block">STOP</button>
</div>
</div>


<div class="mt-3">
<div class="d-flex align-items-center mb-2">
<span id="statusBullet" class="status-bullet" style="background:#ffc107"></span>
<strong>Estado atual:</strong>
<span id="currentStatus" class="ml-2 text-muted">carregando...</span>
</div>
<small class="text-muted">Última atualização: <span id="lastUpdated">--</span></small>
</div>


<hr>
<div class="small text-muted">Dicas: para testar localmente execute um cliente que consulte <code>/api.php?action=status</code> e <code>/api.php</code> para postar comandos.</div>
</div>
</div>
</div>


<script>
const btnStart = document.getElementById('btnStart');
const btnStop = document.getElementById('btnStop');
const currentStatus = document.getElementById('currentStatus');
const statusBullet = document.getElementById('statusBullet');
const lastUpdated = document.getElementById('lastUpdated');


async function postCommand(cmd){
try{
const res = await fetch('api.php',{
method:'POST',
headers:{'Content-Type':'application/json'},
body: JSON.stringify({ command: cmd })
});
const j = await res.json();
updateUI(j);
}catch(err){
console.error(err);
currentStatus.textContent = 'erro ao enviar';
statusBullet.style.background = '#6c757d';
}
}


async function fetchStatus(){
try{
const res = await fetch('api.php?action=status');
const j = await res.json();
updateUI(j);
}catch(err){
console.error(err);
currentStatus.textContent = 'offline';
statusBullet.style.background = '#6c757d';
}
}


function updateUI({ command, updated_at }){
currentStatus.textContent = command || 'nenhum';
lastUpdated.textContent = updated_at || '--';
if(command === 'start') statusBullet.style.background = '#28a745';
else if(command === 'stop') statusBullet.style.background = '#dc3545';
else statusBullet.style.background = '#ffc107';
}


btnStart.addEventListener('click', ()=> postCommand('start'));
btnStop.addEventListener('click', ()=> postCommand('stop'));


// polling UI status a cada 3s para manter painel atualizado
fetchStatus();
setInterval(fetchStatus, 3000);
</script>
</body>
</html>