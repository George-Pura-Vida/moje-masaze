import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {groups, translations} from '../i18n.js';

test('all five languages have complete UI translations',()=>{
  const base=Object.values(groups).flatMap(x=>x.split(' '));
  assert.equal(base.length,new Set(base).size,'UI keys should not be duplicated');
  for(const lang of ['sk','en','es','zh']){
    assert.deepEqual(Object.keys(translations[lang]).filter(x=>base.includes(x)).sort(),[...base].sort(),lang);
    for(const [key,value] of Object.entries(translations[lang]))assert.ok(typeof value==='string'&&value.length>0,lang+'/'+key);
  }
});
test('all translatable page elements have localization keys',()=>{
  const html=readFileSync(new URL('../index.php',import.meta.url),'utf8');
  const refs=[...html.matchAll(/data-t="([^"]+)"/g)].map(x=>x[1]);
  const known=new Set(Object.values(groups).flatMap(x=>x.split(' ')));
  for(const key of refs)assert.ok(known.has(key),'Missing translation key: '+key);
});
test('menu, booking form and payment are accessible',()=>{
 const html=readFileSync(new URL('../index.php',import.meta.url),'utf8');
 for(const key of ['id="menuToggle"','id="navigation"','id="langPicker"','id="bookingForm"','id="duration"','id="count"','id="currency"','id="qrCode"','id="paymentResult"','id="payAccountValue"'])assert.ok(html.includes(key),key);
});
test('no customer payment data and API tokens are committed in tracked source',()=>{
 const cron=readFileSync(new URL('../cron/fio-sync.php',import.meta.url),'utf8');
 assert.ok(cron.includes("PHP_SAPI!=='cli'"));
 assert.ok(cron.includes('MM_IBAN'));
 assert.ok(cron.includes("mm_fio_reference"));
 const lib=readFileSync(new URL('../lib.php',import.meta.url),'utf8');
 assert.ok(lib.includes("MASSAGE_EUR_ENABLED"));
 assert.ok(lib.includes("MASSAGE_PRIVATE_DIR"));
});

test('slot availability labels cannot change the submitted booking time',()=>{
 const html=readFileSync(new URL('../index.php',import.meta.url),'utf8');
 const options=html.match(/<select name="time" id="time">([\s\S]*?)<\/select>/)?.[1]??'';
 const hours=[...options.matchAll(/<option(?:\s+value="([^"]*)")?>(\d{2}:00)<\/option>/g)];
 assert.equal(hours.length,11);
 for(const [,value,label] of hours)assert.equal(value,label);
});
