import {qrcodegen} from './vendor/qrcodegen.js';
import {translations} from './i18n.js';
const $=s=>document.querySelector(s),form=$('#bookingForm'),duration=$('#duration'),quantity=$('#count'),kind=$('#kind'),currency=$('#currency');
const priceLabel=$('#total'),discountLabel=$('#discountInfo'),currencyHelp=$('#currencyHelp'),submit=$('#sendOrder');
const pay=$('#paymentResult'),formStatus=$('#formStatus'),paymentFeedback=$('#payFeedback');
const original=new Map();document.querySelectorAll('[data-t]').forEach(el=>original.set(el,el.textContent));
const originalHeading=new Map();for(const key of ['heroTitle','aboutTitle']){const el=document.querySelector('[data-t="'+key+'"]');if(el)originalHeading.set(el,el.innerHTML);}
const ui={
 cs:{discount:'Úspora {sum} (sleva {percent} %)',notePlaceholder:'Co by vám pomohlo cítit se při masáži dobře?',paid:'Platba potvrzena',created:'Objednávka vytvořena. Termín potvrdíme individuálně.',giftCreated:'Objednávka poukazu vytvořena. Po potvrzené platbě obdržíte poukaz e-mailem.',working:'Vytvářím objednávku…',invalid:'Zkontrolujte povinné údaje.',failed:'Objednávku se nepodařilo vytvořit.',copyOk:'Údaje byly zkopírovány.',copyFail:'Kopírování se nezdařilo.',stillPending:'Banka zatím úhradu nepotvrdila.',paymentConfirmed:'Úhrada byla ověřena bankou.',checkFail:'Stav nyní nelze ověřit.',voucherOpen:'Otevřít a vytisknout poukaz ↗',currencyLabel:'Měna platby',rateText:'Přepočet kurzem ČNB: 1 EUR = {rate} CZK. Cena v EUR je zaokrouhlena na celé euro a potvrdí se při objednávce.',eurOff:'EUR platby zatím nejsou aktivní. Zvolte CZK.',rateMissing:'Kurz EUR nyní není dostupný. Zvolte CZK.',czkHint:'Platba v korunách na účet Fio.'},
 sk:{currencyLabel:'Mena platby',rateText:'Prepočet kurzom ČNB: 1 EUR = {rate} CZK. Cena v EUR je zaokrúhlená na celé euro a potvrdí sa pri objednávke.',eurOff:'Platby v EUR zatiaľ nie sú aktívne. Zvoľte CZK.',rateMissing:'Kurz EUR nie je dostupný. Zvoľte CZK.',czkHint:'Platba v CZK na účet Fio.'},
 en:{currencyLabel:'Payment currency',rateText:'CNB reference rate: 1 EUR = {rate} CZK. EUR prices are rounded to whole euros; the final amount is confirmed when ordering.',eurOff:'EUR payments are not activated yet. Please select CZK.',rateMissing:'EUR exchange rate unavailable. Please select CZK.',czkHint:'CZK payment to Fio Bank.'},
 es:{currencyLabel:'Moneda de pago',rateText:'Cambio del banco central checo: 1 EUR = {rate} CZK. EUR se redondea a euros enteros. Importe final al confirmar.',eurOff:'Los pagos en EUR aún no están activos. Selecciona CZK.',rateMissing:'Cambio de EUR no disponible. Selecciona CZK.',czkHint:'Pago en CZK a Fio Bank.'},
 zh:{currencyLabel:'支付货币',rateText:'捷克央行参考汇率：1 欧元 = {rate} 捷克克朗。欧元金额四舍五入至整数，下单时确认最终金额。',eurOff:'欧元付款尚未启用。请选择 CZK。',rateMissing:'暂时无法获取欧元汇率。请选择 CZK。',czkHint:'通过 Fio 银行账户支付捷克克朗。'}
};
let lang='cs';try{const requested=new URLSearchParams(location.search).get('lang')||localStorage.getItem('mm_lang');if(['cs','sk','en','es','zh'].includes(requested))lang=requested;}catch{}
const regional={cs:'cs-CZ',sk:'sk-SK',en:'en-GB',es:'es-ES',zh:'zh-CN'};
let quote=null,latestOrder=null,epoch=0;
function t(k){return translations[lang]?.[k]||ui[lang]?.[k]||ui.cs[k]||k;}
function format(cents,ccy='CZK'){return new Intl.NumberFormat(regional[lang],{style:'currency',currency:ccy,minimumFractionDigits:cents%100===0?0:2,maximumFractionDigits:cents%100===0?0:2}).format(cents/100);}
function priceCents(){const base=duration.value==='180'?300000:200000,n=Number(quantity.value),pct=n===2?95:n===3?90:100;return base*n*pct/100;}
const contactCopy={
 cs:{eyebrow:'OSOBNÍ KONTAKT',title:'Napište mi. Rád odpovím.',description:'Máte otázku k masážím, volným termínům nebo dárkovým poukazům? Nechte mi zprávu. Odpověď dostanete osobně.',name:'Jméno a příjmení *',email:'E-mail *',phone:'Telefon (nepovinný)',subject:'Předmět zprávy *',subjectMassage:'Dotaz k masáži',subjectBooking:'Termíny a rezervace',subjectGift:'Dárkové poukazy',subjectOther:'Jiný dotaz',message:'Vaše zpráva *',consent:'Souhlasím se zpracováním údajů pouze pro vyřízení dotazu a odpověď.',privacy:'Ochrana osobních údajů',send:'Odeslat zprávu ↗',adminLink:'Administrace',sending:'Odesílám zprávu…',sent:'Děkuji! Zpráva je bezpečně uložena. Potvrzení přijde e-mailem.',error:'Zprávu nelze odeslat. Zkuste to prosím znovu.',invalid:'Doplňte povinné údaje.'},
 sk:{eyebrow:'OSOBNÝ KONTAKT',title:'Napíšte mi. Rád odpoviem.',description:'Máte otázky k masážam, termínom alebo poukazom? Pošlite mi správu.',name:'Meno a priezvisko *',email:'E-mail *',phone:'Telefón (voliteľný)',subject:'Predmet správy *',subjectMassage:'Otázka k masáži',subjectBooking:'Termíny a rezervácie',subjectGift:'Darčekové poukazy',subjectOther:'Iná otázka',message:'Vaša správa *',consent:'Súhlasím so spracovaním údajov na vybavenie otázky a odpoveď.',privacy:'Ochrana osobných údajov',send:'Odoslať správu ↗',adminLink:'Administrácia',sending:'Odosielam správu…',sent:'Ďakujem! Správa bola uložená. Potvrdenie príde e-mailom.',error:'Správu nemožno odoslať.',invalid:'Doplňte povinné údaje.'},
 en:{eyebrow:'GET IN TOUCH',title:'Have a question? I’m here to help.',description:'Ask about massages, available times or gift vouchers. I will respond personally.',name:'Full name *',email:'Email *',phone:'Phone (optional)',subject:'Message subject *',subjectMassage:'Question about a massage',subjectBooking:'Appointments and reservations',subjectGift:'Gift vouchers',subjectOther:'Other question',message:'Your message *',consent:'I agree to the processing of my details only to answer this enquiry.',privacy:'Privacy policy',send:'Send message ↗',adminLink:'Administration',sending:'Sending your message…',sent:'Thank you! Your message has been saved. A confirmation email will follow.',error:'Unable to send the message.',invalid:'Please complete the required fields.'},
 es:{eyebrow:'CONTACTO PERSONAL',title:'Escríbeme. Estaré encantado de responder.',description:'¿Tienes preguntas sobre masajes, horarios o tarjetas regalo? Envíame un mensaje.',name:'Nombre completo *',email:'Correo electrónico *',phone:'Teléfono (opcional)',subject:'Asunto *',subjectMassage:'Consulta sobre masajes',subjectBooking:'Citas y reservas',subjectGift:'Tarjetas regalo',subjectOther:'Otra consulta',message:'Tu mensaje *',consent:'Acepto el tratamiento de los datos únicamente para responder a mi consulta.',privacy:'Privacidad',send:'Enviar mensaje ↗',adminLink:'Administración',sending:'Enviando mensaje…',sent:'¡Gracias! Tu mensaje se ha guardado. Recibirás una confirmación por email.',error:'No se pudo enviar el mensaje.',invalid:'Completa los campos obligatorios.'},
 zh:{eyebrow:'直接联系',title:'欢迎留言，我会亲自回复。',description:'对按摩、预约时间或礼品券有疑问？请给我留言。',name:'姓名 *',email:'邮箱 *',phone:'电话（选填）',subject:'主题 *',subjectMassage:'按摩咨询',subjectBooking:'预约与时间',subjectGift:'礼品券',subjectOther:'其他问题',message:'留言 *',consent:'我同意仅为回复咨询而处理我的联系信息。',privacy:'隐私政策',send:'发送消息 ↗',adminLink:'管理后台',sending:'正在发送…',sent:'谢谢！您的留言已保存，确认邮件稍后发送。',error:'暂时无法发送消息。',invalid:'请填写必填信息。'}
};
function renderContactLanguage(){
 const messages=contactCopy[lang]||contactCopy.cs;
 document.querySelectorAll('[data-contact]').forEach(el=>{const key=el.dataset.contact;if(messages[key])el.textContent=messages[key];});
}

function renderLang(){
 document.documentElement.lang=lang==='zh'?'zh-CN':lang;$('#langPicker').value=lang;
 document.querySelectorAll('[data-t]').forEach(el=>{
  const k=el.dataset.t,val=translations[lang]?.[k]||original.get(el);
  if((k==='heroTitle'||k==='aboutTitle')&&val.includes('^')){
   const parts=val.split('^');el.replaceChildren(document.createTextNode(parts[0]),document.createElement('br'),document.createTextNode(parts.slice(1).join('^')));
  }else if(lang==='cs'&&originalHeading.has(el)){el.innerHTML=originalHeading.get(el);}
  else{el.textContent=val;}
 });
 form.querySelectorAll('[data-ph]').forEach(el=>el.placeholder=t(el.dataset.ph));
 form.querySelector('.currency-choice label>span').textContent=t('currencyLabel');
 setSlotNotice($('#date').value?'ready':'initial');
 renderContactLanguage();
 document.title=({cs:'Moje masáže',sk:'Moje masáže',en:'My Massages',es:'Mis masajes',zh:'我的按摩'})[lang]+' · Dotyk & klid';
 renderPrice();
}
$('#langPicker').addEventListener('change',e=>{
 lang=e.target.value;
 try{localStorage.setItem('mm_lang',lang);const u=new URL(location.href);u.searchParams.set('lang',lang);history.replaceState(null,'',u);}catch{}
 renderLang();
});
const menu=$('#navigation'),toggle=$('#menuToggle');
toggle.addEventListener('click',()=>{const opened=menu.classList.toggle('open');toggle.setAttribute('aria-expanded',String(opened));});
menu.addEventListener('click',e=>{if(e.target.closest('a')){menu.classList.remove('open');toggle.setAttribute('aria-expanded','false');}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){menu.classList.remove('open');toggle.setAttribute('aria-expanded','false');}});
document.querySelectorAll('[data-select-duration]').forEach(a=>a.addEventListener('click',()=>{duration.value=a.dataset.selectDuration;refresh();}));
document.querySelectorAll('[data-select-gift]').forEach(a=>a.addEventListener('click',()=>{kind.value='gift';updateKind();pay.hidden=true;}));
function updateKind(){const gift=kind.value==='gift';$('#recipientBox').hidden=!gift;$('#dateBox').hidden=false;$('#timeBox').hidden=false;$('#date').disabled=false;$('#time').disabled=false;}
let submittingOrder=false;
function renderPrice(){
 const czk=priceCents(),n=Number(quantity.value),full=(duration.value==='180'?300000:200000)*n;
 if(currency.value==='EUR'){
  if(quote?.eur_enabled&&Number.isInteger(quote.eur_cents)){priceLabel.textContent=format(quote.eur_cents,'EUR');currencyHelp.textContent=t('rateText').replace('{rate}',String(quote.rate).replace('.',','));submit.disabled=submittingOrder;}
  else{priceLabel.textContent='— EUR';currencyHelp.textContent=t(quote?.eur_enabled===false?'eurOff':'rateMissing');submit.disabled=true;}
 }else{priceLabel.textContent=format(czk);currencyHelp.textContent=t('czkHint');submit.disabled=submittingOrder;}
 const saved=full-czk,pct=n===2?5:n===3?10:0;
 discountLabel.textContent=saved?t('discount').replace('{sum}',format(saved)).replace('{amount}',format(saved)).replace('{percent}',String(pct)):'';
}
async function refresh(){
 pay.hidden=true;latestOrder=null;quote=null;renderPrice();const id=++epoch;
 try{
  const res=await fetch('pricing.php?duration='+encodeURIComponent(duration.value)+'&count='+encodeURIComponent(quantity.value),{cache:'no-store'});
  if(!res.ok)throw Error('quote');
  const result=await res.json();if(id!==epoch)return;
  if(result.czk_cents!==priceCents())throw Error('mismatch');
  quote=result;renderPrice();
 }catch{if(id!==epoch)return;renderPrice();}
}
for(const el of [duration,quantity,currency])el.addEventListener('change',refresh);
kind.addEventListener('change',()=>{updateKind();pay.hidden=true;});
const slotsMessages={
 cs:{initial:'Datum a hodinu můžete zvolit před zaškrtnutím souhlasu. Bez konkrétní hodiny zůstane termín dle domluvy.',loading:'Ověřuji volné hodiny…',ready:'Volné hodiny jsou v nabídce. Obsazené časy nelze vybrat; termín potvrzuje masér.',unavailable:'Pro tento den už nejsou volné hodiny. Vyberte jiné datum nebo Dle domluvy.',error:'Dostupnost nyní nelze ověřit. Zvolte jiný den nebo Dle domluvy.'},
 sk:{initial:'Dátum aj hodinu môžete vybrať pred súhlasom. Bez hodiny ostáva termín na dohodu.',loading:'Kontrolujem voľné hodiny…',ready:'Dostupné hodiny sú v zozname. Termín potvrdí masér.',unavailable:'Na tento deň už nie je voľný čas.',error:'Dostupnosť teraz nie je možné overiť.'},
 en:{initial:'Choose date and time before giving consent. Leave the time blank to arrange it later.',loading:'Checking available times…',ready:'Available times are shown. Your therapist will confirm the appointment.',unavailable:'No available slots for this date.',error:'Availability is temporarily unavailable.'},
 es:{initial:'Selecciona fecha y hora antes de aceptar las condiciones. Puedes dejar la hora para acordarla.',loading:'Comprobando horarios disponibles…',ready:'Horarios disponibles. El masajista confirmará la cita.',unavailable:'No hay horas disponibles en esta fecha.',error:'No se puede comprobar la disponibilidad.'},
 zh:{initial:'勾选同意前即可选择日期和时间，也可以稍后商定。',loading:'正在查询空闲时段…',ready:'可选时段已显示，最终时间需由按摩师确认。',unavailable:'当天没有可预订时间。',error:'暂时无法查询可用时间。'}
};
let slotsEpoch=0;
function setSlotNotice(message,type=''){const el=$('#slotNotice');if(!el)return;el.textContent=(slotsMessages[lang]||slotsMessages.cs)[message];el.classList.toggle('warning',type==='warning');}
async function refreshSlots(){
 const selected=$('#date').value,select=$('#time'),epoch=++slotsEpoch;
 if(!selected){for(const opt of select.options)opt.disabled=false;setSlotNotice('initial');return;}
 setSlotNotice('loading');
 for(const opt of select.options)if(opt.value!=='')opt.disabled=true;
 try{
  const res=await fetch('slots.php?date='+encodeURIComponent(selected)+'&duration='+encodeURIComponent(duration.value),{cache:'no-store'});
  if(!res.ok)throw Error('slots');
  const data=await res.json();if(epoch!==slotsEpoch)return;
  const slots=new Map(data.slots.map(x=>[x.time,Boolean(x.available)]));
  let count=0;
  for(const opt of select.options){
   if(opt.value==='')continue;
   const free=slots.get(opt.value)===true;opt.disabled=!free;
   opt.textContent=opt.value+(free?' ✓':' · obsazeno');if(free)count++;
  }
  if(select.selectedOptions[0]?.disabled)select.value='';
  setSlotNotice(count?'ready':'unavailable',count?'':'warning');
 }catch(err){
  if(epoch!==slotsEpoch)return;
  for(const opt of select.options)if(opt.value!=='')opt.disabled=true;
  select.value='';setSlotNotice('error','warning');
 }
}
$('#date').addEventListener('change',refreshSlots);
duration.addEventListener('change',refreshSlots);

const min=new Date();min.setDate(min.getDate()+1);
$('#date').min=[min.getFullYear(),String(min.getMonth()+1).padStart(2,'0'),String(min.getDate()).padStart(2,'0')].join('-');
function notice(msg,error=false){formStatus.textContent=msg;formStatus.classList.toggle('error',error);}
function renderQr(o){
 const iban=o.iban.replace(/\s/g,''),number=Number(o.amount).toFixed(2),vs=o.vs;
 if(!/^[A-Z]{2}[0-9A-Z]{13,32}$/.test(iban)||!/^[0-9]{10}$/.test(vs))throw Error('Neplatné bankovní údaje.');
 const payload='SPD*1.0*ACC:'+iban+'*AM:'+number+'*CC:'+o.currency+'*X-VS:'+vs+'*MSG:MMVS-'+vs;
 const qr=qrcodegen.QrCode.encodeText(payload,qrcodegen.QrCode.Ecc.MEDIUM),ns='http://www.w3.org/2000/svg',border=4,width=qr.size+border*2;
 const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 '+width+' '+width);svg.setAttribute('role','img');svg.setAttribute('aria-label','Platební QR kód');svg.setAttribute('shape-rendering','crispEdges');
 const bg=document.createElementNS(ns,'rect');bg.setAttribute('width','100%');bg.setAttribute('height','100%');bg.setAttribute('fill','#fff');svg.append(bg);
 const path=document.createElementNS(ns,'path');let d='';
 for(let y=0;y<qr.size;y++)for(let x=0;x<qr.size;x++)if(qr.getModule(x,y))d+='M'+(x+border)+' '+(y+border)+'h1v1h-1z';
 path.setAttribute('d',d);path.setAttribute('fill','#000');svg.append(path);$('#qrCode').replaceChildren(svg);
}
form.addEventListener('submit',async e=>{
 e.preventDefault();if(submittingOrder)return;
 if(!form.checkValidity()){form.reportValidity();notice(t('invalid'),true);return;}
 if(currency.value==='EUR'&&!quote?.eur_enabled){notice(t('eurOff'),true);return;}
 const data=Object.fromEntries(new FormData(form));data.csrf=$('meta[name="mm-csrf"]').content;data.lang=lang;data.terms=form.elements.terms.checked;data.expected_amount_cents=currency.value==='EUR'?quote?.eur_cents:priceCents();
 submittingOrder=true;submit.disabled=true;notice(t('working'));
 try{
  const response=await fetch('order.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data)});
  const raw=await response.text();let result;
  try{result=JSON.parse(raw);}catch{throw Error('Objednávkový server nevrátil platnou odpověď. Zkontrolujte system-status.php na webu.');}
  if(!response.ok)throw Error((result.error||t('failed'))+(response.status>=500?' (server '+response.status+')':''));
  if(!['CZK','EUR'].includes(result.currency)||!(result.amount>0)||!/^[0-9]{10}$/.test(result.vs))throw Error('Neplatná odpověď serveru.');
  latestOrder=result;
  const page=$('#paymentPage');
  if(page){page.href='payment.php?token='+encodeURIComponent(result.token);page.hidden=false;}
  let qrOk=true;
  try{renderQr(result);}catch(qrError){
   qrOk=false;
   $('#qrCode').textContent='QR kód nelze v tomto prohlížeči vykreslit. Použijte platební stránku nebo ruční zadání bankovních údajů.';
  }
  $('#payAmountValue').textContent=format(Math.round(result.amount*100),result.currency);
  $('#payAccountValue').textContent=result.account;$('#payIbanValue').textContent=result.iban;$('#payVsValue').textContent=result.vs;
  $('#payState').textContent=t('pending');paymentFeedback.textContent=qrOk?'':'Objednávka byla uložena. Platební údaje jsou dostupné na platební stránce.';pay.hidden=false;notice(t(result.kind==='gift'?'giftCreated':'created'));
  pay.scrollIntoView({behavior:'smooth',block:'start'});
 }catch(err){notice(err.message||t('failed'),true);}
 finally{submittingOrder=false;renderPrice();}
});
$('#copyPay').addEventListener('click',async()=>{
 if(!latestOrder)return;
 const o=latestOrder,str='Jiří Janoušek\nIBAN: '+o.iban+'\nBIC: '+o.bic+'\nÚčet: '+o.account+'\nČástka: '+o.amount.toFixed(2)+' '+o.currency+'\nVS: '+o.vs+'\nZpráva: MMVS-'+o.vs;
 try{await navigator.clipboard.writeText(str);paymentFeedback.textContent=t('copyOk');}catch{paymentFeedback.textContent=t('copyFail');}
});
$('#checkPay').addEventListener('click',async()=>{
 if(!latestOrder)return;
 try{
  const res=await fetch('status.php?token='+encodeURIComponent(latestOrder.token),{cache:'no-store'});if(!res.ok)throw Error('status');
  const data=await res.json();$('#payState').textContent=t(data.status==='paid'?'paid':'pending');paymentFeedback.textContent=t(data.status==='paid'?'paymentConfirmed':'stillPending');
  if(data.status==='paid'&&data.voucher_url){
   const a=document.createElement('a');a.href=data.voucher_url;a.target='_blank';a.rel='noopener';a.textContent=t('voucherOpen');paymentFeedback.append(document.createElement('br'),a);
  }
 }catch{paymentFeedback.textContent=t('checkFail');}
});
const messageForm=$('#messageForm'),messageStatus=$('#messageStatus'),messageButton=$('#sendMessage');
if(messageForm&&messageStatus&&messageButton){
 messageForm.addEventListener('submit',async e=>{
  e.preventDefault();
  const msg=contactCopy[lang]||contactCopy.cs;
  if(!messageForm.checkValidity()){messageForm.reportValidity();messageStatus.textContent=msg.invalid;messageStatus.classList.add('error');return;}
  const data=Object.fromEntries(new FormData(messageForm));data.terms=messageForm.elements.terms.checked;data.csrf=$('meta[name="mm-csrf"]').content;
  messageButton.disabled=true;messageStatus.textContent=msg.sending;messageStatus.classList.remove('error');
  try{
   const result=await fetch('contact-message.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data)});
   const raw=await result.text();let body;
   try{body=JSON.parse(raw);}catch{throw Error('Webový server nevrátil platnou odpověď. Zkontrolujte system-status.php.');}
   if(!result.ok)throw Error((body.error||msg.error)+(result.status>=500?' (server '+result.status+')':''));
   messageForm.reset();messageStatus.textContent=msg.sent;
  }catch(err){messageStatus.textContent=err.message||msg.error;messageStatus.classList.add('error');}
  finally{messageButton.disabled=false;}
 });
}

updateKind();renderLang();refresh();refreshSlots();