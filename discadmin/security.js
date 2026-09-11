window.BRVTALSecurity = {mount(root) {

const csrf=root.dataset.csrf;
const setup=root.querySelector("#" + 'setup'),msg=root.querySelector("#" + 'message');
const post=async(action,data={})=>{const r=await fetch('/discadmin/totp-api.php?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data),credentials:'same-origin'});const j=await r.json().catch(()=>({ok:false,error:'INVALID_RESPONSE'}));if(!r.ok||!j.ok)throw new Error(j.message||j.error||'Request failed');return j};
const say=(text,cls='success')=>{msg.className=cls;msg.textContent=text};
const start=root.querySelector("#" + 'start');
if(start)start.onclick=async()=>{start.disabled=true;try{const j=await post('start');root.querySelector("#" + 'secret').textContent=j.secret;setup.classList.add('show');if(window.QRCode){new QRCode(root.querySelector("#" + 'qr'),{text:j.otpauth,width:210,height:210,correctLevel:QRCode.CorrectLevel.M})}else{root.querySelector("#" + 'qr').textContent='QR library unavailable. Use the setup key above.'}}catch(e){start.disabled=false;say(e.message,'error')}};
const confirmBtn=root.querySelector("#" + 'confirm');
if(confirmBtn)confirmBtn.onclick=async()=>{confirmBtn.disabled=true;try{const j=await post('confirm',{code:root.querySelector("#" + 'code').value});say('2FA enabled. Save these recovery codes now; they will not be shown again.');setup.classList.add('show');root.querySelector("#" + 'secret').textContent='';root.querySelector("#" + 'qr').innerHTML='';const pre=document.createElement('pre');pre.className='codes';pre.textContent=j.recovery_codes.join('\n');root.querySelector("#" + 'qr').after(pre)}catch(e){confirmBtn.disabled=false;say(e.message,'error')}};
const disable=root.querySelector("#" + 'disable'),disableBox=root.querySelector("#" + 'disableBox'),disableConfirm=root.querySelector("#" + 'disableConfirm');
if(disable)disable.onclick=()=>{disableBox.classList.toggle('show');if(disableBox.classList.contains('show'))root.querySelector("#" + 'disableCode').focus()};
if(disableConfirm)disableConfirm.onclick=async()=>{disableConfirm.disabled=true;try{await post('disable',{code:root.querySelector("#" + 'disableCode').value});go('security')}catch(e){disableConfirm.disabled=false;say(e.message,'error')}};


}};
