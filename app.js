import {qrcodegen} from './vendor/qrcodegen.js';
import {translations} from './i18n.js';
const $=s=>document.querySelector(s),form=$('#bookingForm'),duration=$('#duration'),quantity=$('#count'),kind=$('#kind'),currency=$('#currency');
const priceLabel=$('#total'),discountLabel=$('#discountInfo'),currencyHelp=$('#currencyHelp'),submit=$('#sendOrder');
const pay=$('#paymentResult'),formStatus=$('#formStatus'),paymentFeedback=$('#payFeedback');
const original=new Map();document.querySelectorAll('[data-t]').forEach(el=>original.set(el,el.textContent));
const ui={
 cs:{discount:'Úspora {sum} (sleva {percent} %)',notePlaceholder:'Co by vám pomohlo cítit se při masáži dobře?',paid:'Platba potvrzena',created:'Objednávka vytvořena. Termín potvrdíme individuálně.',giftCreated:'Objednávka poukazu vytvořena. Po potvrzené platbě obdržíte poukaz e-mailem.',working:'Vytvářím objednávku…',invalid:'Zkontrolujte povinné údaje.',failed:'Objednávku se nepodařilo vytvořit.',copyOk:'Údaje byly zkopírovány.',copyFail:'Kopírování se nezdařilo.',stillPending:'Banka zatím úhradu nepotvrdila.',paymentConfirmed:'Úhrada byla ověřena bankou.',checkFail:'Stav nyní nelze ověřit.',voucherOpen:'Otevřít a vytisknout poukaz ↗',currencyLabel:'Měna platby',rateText:'Přepočet kurzem ČNB: 1 EUR = {rate} CZK. Přesná částka se potvrdí při objednávce.',eurOff:'EUR platby zatím nejsou aktivní. Je potřeba ověřený účet a Fio API. Zvolte CZK.',rateMissing:'Kurz EUR nyní není dostupný. Zvolte CZK.',czkHint:'Platba v korunách na účet Fio.'},
 sk:{currencyLabel:'Mena platby',rateText:'Prepočet kurzom ČNB: 1 EUR = {rate} CZK. Presnú sumu potvrdí objednávka.',eurOff:'Platby v EUR zatiaľ nie sú aktívne. Zvoľte CZK.',rateMissing:'Kurz EUR nie je dostupný. Zvoľte CZK.',czkHint:'Platba v CZK na účet Fio.'},
 en:{currencyLabel:'Payment currency',rateText:'CNB reference rate: 1 EUR = {rate} CZK. Final amount is confirmed when ordering.',eurOff:'EUR payments are not activated yet. Please select CZK.',rateMissing:'EUR exchange rate unavailable. Please select CZK.',czkHint:'CZK payment to Fio Bank.'},
 es:{currencyLabel:'Moneda de pago',rateText:'Cambio del banco central checo: 1 EUR = {rate} CZK. Importe final al confirmar.',eurOff:'Los pagos en EUR aún no están activos. Selecciona CZK.',rateMissing:'Cambio de EUR no disponible. Selecciona CZK.',czkHint:'Pago en CZK a Fio Bank.'},
 zh:{currencyLabel:'支付货币',rateText:'捷克央行参考汇率：1 欧元 = {rate} 捷克克朗。下单时确认最终金额。',eurOff:'欧元付款尚未启用。请选择 CZK。',rateMissing:'暂时无法获取欧元汇率。请选择 CZK。',czkHint:'通过 Fio 银行账户支付捷克克朗。'}
};
let lang='cs';try{const requested=new URLSearchParams(location.search).get('lang')||localStorage.getItem('mm_lang');if(['cs','sk','en','es','zh'].includes(requested))lang=requested;}catch{}
const regional={cs:'cs-CZ',sk:'sk-SK',en:'en-GB',es:'es-ES',zh:'zh-CN'};
let quote=null,latestOrder=null,epoch=0;
function t(k){return translations[lang]?.[k]||ui[lang]?.[k]||ui.cs[k]||k;}
function format(cents,ccy='CZK'){return new Intl.NumberFormat(regional[lang],{style:'currency',currency:ccy,minimumFractionDigits:ccy==='EUR'?2:0,maximumFractionDigits:ccy==='EUR'?2:0}).format(cents/100);}
function priceCents(){const base=duration.value==='180'?300000:200000,n=Number(quantity.value),pct=n===2?95:n===3?90:100;return base*n*pct/100;}
function renderLang(){
 document.documentElement.lang=lang==='zh'?'zh-CN':lang;$('#langPicker').value=lang;
 document.querySelectorAll('[data-t]').forEach(el=>{
  const k=el.dataset.t,val=translations[lang]?.[k]||original.get(el);
  if((k==='heroTitle'||k==='aboutTitle')&&val.includes('^')){
   const parts=val.split('^');el.replaceChildren(document.createTextNode(parts[0]),document.createElement('br'),document.createTextNode(parts.slice(1).join('^')));
  }else if(lang!=='cs'||!['heroTitle','aboutTitle'].includes(k)){el.textContent=val;}
 });
 form.querySelectorAll('[data-ph]').forEach(el=>el.placeholder=t(el.dataset.ph));
 form.querySelector('.currency-choice label>span').textContent=t('currencyLabel');
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
function updateKind(){const gift=kind.value==='gift';$('#recipientBox').hidden=!gift;$('#dateBox').hidden=gift;$('#timeBox').hidden=gift;$('#date').disabled=gift;$('#time').disabled=gift;}
function renderPrice(){
 const czk=priceCents(),n=Number(quantity.value),full=(duration.value==='180'?300000:200000)*n;
 if(currency.value==='EUR'){
  if(quote?.eur_enabled&&Number.isInteger(quote.eur_cents)){priceLabel.textContent=format(quote.eur_cents,'EUR');currencyHelp.textContent=t('rateText').replace('{rate}',String(quote.rate).replace('.',','));submit.disabled=false;}
  else{priceLabel.textContent='— EUR';currencyHelp.textContent=t(quote?'eurOff':'rateMissing');submit.disabled=true;}
 }else{priceLabel.textContent=format(czk);currencyHelp.textContent=t('czkHint');submit.disabled=false;}
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
 }catch{if(id!==epoch)return;quote={eur_enabled:false,eur_cents:null};renderPrice();}
}
for(const el of [duration,quantity,currency])el.addEventListener('change',refresh);
kind.addEventListener('change',()=>{updateKind();pay.hidden=true;});
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
 e.preventDefault();if(!form.checkValidity()){form.reportValidity();notice(t('invalid'),true);return;}
 if(currency.value==='EUR'&&!quote?.eur_enabled){notice(t('eurOff'),true);return;}
 const data=Object.fromEntries(new FormData(form));data.csrf=$('meta[name="mm-csrf"]').content;data.lang=lang;data.terms=form.elements.terms.checked;data.expected_amount_cents=currency.value==='EUR'?quote?.eur_cents:priceCents();
 submit.disabled=true;notice(t('working'));
 try{
  const response=await fetch('order.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data)});
  const result=await response.json();if(!response.ok)throw Error(result.error||t('failed'));
  if(!['CZK','EUR'].includes(result.currency)||!(result.amount>0)||!/^[0-9]{10}$/.test(result.vs))throw Error('Neplatná odpověď serveru.');
  latestOrder=result;renderQr(result);
  $('#payAmountValue').textContent=format(Math.round(result.amount*100),result.currency);
  $('#payAccountValue').textContent=result.account;$('#payIbanValue').textContent=result.iban;$('#payVsValue').textContent=result.vs;
  $('#payState').textContent=t('pending');paymentFeedback.textContent='';pay.hidden=false;notice(t(result.kind==='gift'?'giftCreated':'created'));
  pay.scrollIntoView({behavior:'smooth',block:'start'});
 }catch(err){notice(err.message||t('failed'),true);}
 finally{renderPrice();}
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
updateKind();renderLang();refresh();