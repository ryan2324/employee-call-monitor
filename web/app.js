let employees=[];let selectedEmployeeId=null;let employeeModal;let qrModal;
const $=id=>document.getElementById(id);
async function api(path,opt={}){let r=await fetch(APP_CONFIG.API_BASE_URL.replace(/\/$/,'')+'/'+String(path).replace(/^\//,''),{credentials:'include',headers:{'Content-Type':'application/json'},...opt});let text=await r.text();let x={};try{x=text?JSON.parse(text):{}}catch(_){x={error:text||'Empty server response'}}if(!r.ok)throw Error(x.error||'Request failed');return x}
function esc(x){return String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
function sec(s){s=+s||0;return [Math.floor(s/3600),Math.floor(s%3600/60),s%60].map((x,i)=>i?String(x).padStart(2,'0'):String(x)).join(':')}
function statusLabel(s){return ({READY:'Ready',IN_CALL:'In Call',IDLE:'Idle',OFFLINE:'Offline'})[s]||'Offline'}
function render(){let q=$('search').value.toLowerCase(),f=$('filter').value;let a=employees.filter(e=>(!f||e.computed_status===f)&&(`${e.name} ${e.department||''} ${e.device_id||''} ${e.model||''}`).toLowerCase().includes(q));$('rows').innerHTML=a.map(e=>{let map={READY:['Ready','ready'],IN_CALL:['In Call','call'],IDLE:['Idle','idle'],OFFLINE:['Offline','offline']},m=map[e.computed_status]||map.OFFLINE;return `<tr class="employee-row" onclick="openEmployee(${e.id})"><td><b>${esc(e.name)}</b><br><small class="text-secondary">${esc(e.department||'')}</small></td><td>${esc(e.model||'Android')}<br><small>${esc(e.device_id||'')}</small></td><td><span class="dot ${m[1]}"></span>${m[0]}</td><td>${e.calls_today}</td><td>${sec(e.talk_seconds_today)}</td><td>${sec(e.idle_seconds_today)}</td><td>${e.battery_level==null?'—':e.battery_level+'%'}</td><td>${e.last_seen?new Date(e.last_seen.replace(' ','T')+'Z').toLocaleString():'—'}</td><td><button class="btn btn-sm ${e.computed_status==='IDLE'?'btn-warning':'btn-outline-primary'}" onclick="event.stopPropagation();openEmployee(${e.id})">View</button></td></tr>`}).join('');$('total').textContent=employees.length;$('ready').textContent=employees.filter(e=>e.computed_status==='READY').length;$('incall').textContent=employees.filter(e=>e.computed_status==='IN_CALL').length;$('idle').textContent=employees.filter(e=>e.computed_status==='IDLE').length}
async function refresh(){try{let x=await api('/dashboard');employees=x.employees||[];$('live').className='badge text-bg-success';$('live').textContent='Live';render()}catch(e){if(String(e.message).toLowerCase().includes('unauthorized')){location='login.html';return}$('live').className='badge text-bg-danger';$('live').textContent='Offline'}}
async function openEmployee(id){selectedEmployeeId=id;try{let x=await api('/employee/detail?id='+encodeURIComponent(id));let e=x.employee;$('detailName').textContent=e.name;$('detailDept').textContent=e.department||'No department';$('dStatus').textContent=statusLabel(e.computed_status);$('dDevice').textContent=(e.model||'Android')+' • '+(e.device_id||'—');$('dBattery').textContent=e.battery_level==null?'—':e.battery_level+'%';$('dCalls').textContent=e.calls_today;$('dTalk').textContent=sec(e.talk_seconds_today);$('dIdle').textContent=sec(e.idle_seconds_today);$('detailStatus').className='alert '+(e.computed_status==='IDLE'?'alert-warning':e.computed_status==='IN_CALL'?'alert-primary':e.computed_status==='READY'?'alert-success':'alert-secondary');$('detailStatus').textContent=e.computed_status==='IDLE'?'⚠ This employee is currently idle. Send a warning to the phone.':e.computed_status==='IN_CALL'?'Employee is currently in a call.':'Current status: '+statusLabel(e.computed_status);$('warnBtn').disabled=!e.device_id||e.computed_status==='OFFLINE';$('warnBtn').textContent=e.computed_status==='IDLE'?'⚠ Warn employee':'⚠ Send warning';$('recentCalls').innerHTML=(e.recent_calls||[]).map(c=>`<tr><td>${esc(c.direction)}</td><td>${esc(c.started_at||'—')}</td><td>${esc(c.ended_at||'—')}</td><td>${sec(c.duration_seconds)}</td></tr>`).join('')||'<tr><td colspan="4" class="text-secondary">No calls recorded today.</td></tr>';employeeModal.show()}catch(e){alert(e.message)}}
async function warnSelected(){if(!selectedEmployeeId)return;$('warnBtn').disabled=true;try{await api('/employee/warn',{method:'POST',body:JSON.stringify({employee_id:selectedEmployeeId,message:'You are idle. Please resume calling now.'})});$('warnBtn').textContent='✓ Warning queued';setTimeout(()=>{$('warnBtn').textContent='⚠ Send warning';$('warnBtn').disabled=false},2000)}catch(e){alert(e.message);$('warnBtn').disabled=false}}
async function generateQR(){
  try{
    $('qrError').classList.add('d-none');
    $('qrImage').removeAttribute('src');
    let x=await api('/join/token',{method:'POST',body:'{}'});
    let token=String(x.token||'').trim();
    if(!token)throw Error('Server did not return a join token');
    $('joinToken').value=token;
    $('expiry').textContent='Expires '+new Date(x.expires_at).toLocaleString();

    // IMPORTANT: encode ONLY the short one-time token.
    // This produces a smaller, denser QR that is much easier for Android cameras to scan.
    // No external QR JavaScript library is required.
    const data=encodeURIComponent(token);
    const sources=[
      'https://api.qrserver.com/v1/create-qr-code/?size=520x520&margin=18&ecc=H&data='+data,
      'https://quickchart.io/qr?size=520&margin=4&ecLevel=H&text='+data
    ];
    const img=$('qrImage');
    let attempt=0;
    const tryNext=()=>{
      if(attempt>=sources.length){
        $('qrError').textContent='QR image could not be loaded. Use the join token shown below; it is the same code the scanner reads.';
        $('qrError').classList.remove('d-none');
        return;
      }
      img.onerror=()=>{attempt++;tryNext()};
      img.onload=()=>{$('qrError').classList.add('d-none')};
      img.src=sources[attempt++];
    };
    tryNext();
    qrModal.show();
  }catch(e){alert(e.message)}
}
async function copyJoinToken(){let v=$('joinToken').value;if(!v)return;try{await navigator.clipboard.writeText(v);let b=document.querySelector('#qrModal .input-group button');let old=b.textContent;b.textContent='Copied';setTimeout(()=>b.textContent=old,1200)}catch(_){$('joinToken').select();document.execCommand('copy')}}
function downloadCsv(){location.href=APP_CONFIG.API_BASE_URL+'/reports/calls.csv'}
async function logout(){await api('/auth/logout',{method:'POST'});location='login.html'}
document.addEventListener('DOMContentLoaded',()=>{employeeModal=new bootstrap.Modal($('employeeModal'));qrModal=new bootstrap.Modal($('qrModal'));refresh();setInterval(refresh,3000)});
