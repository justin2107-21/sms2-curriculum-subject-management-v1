'use strict';
// The legacy filename is retained; the supported interface is the global CIMF panel.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = new Map();
class Element {
  constructor(id) { this.id=id; this.hidden=true; this.dataset={}; this.style={}; this.value=''; this.innerHTML=''; this.textContent=''; this.listeners={}; this.attributes={}; this.classList={toggle(){}}; }
  addEventListener(type, fn) { this.listeners[type]=fn; }
  setAttribute(name,value) { this.attributes[name]=value; }
  focus() { this.focused=true; }
  contains(target) { return target?.id?.startsWith('csmsCimf') || target?.id?.startsWith('globalCimf'); }
  querySelectorAll() { return []; }
  querySelector() { return get(this.id+'Submit'); }
  append() {}
  closest() { return null; }
}
function get(id) { if (!elements.has(id)) elements.set(id,new Element(id)); return elements.get(id); }
const document = {getElementById:get, addEventListener(type,fn){this[type]=fn;},querySelector(){return get('content');},createElement(){return new Element('generated');}};
const panel=get('csmsCimfPanel');
panel.dataset={profile:'subject_master',actions:JSON.stringify({review:'Review',ask:'Ask',compare:'Compare'}),fields:JSON.stringify(['subject_id']),api:'/mock-cimf',route:'subjects',pageToken:'synthetic-token',csrf:'synthetic-csrf',title:'Subject Master'};
let selected='123', requests=[];
const window={addEventListener(){},CSMSPageCIMFContext:()=>({subject_id:selected}),CurriculumUI:{createAutocomplete(){return {destroy(){}};}}};
const sandbox={window,document,innerWidth:1200,localStorage:{getItem(){return null;},setItem(){}},AbortController,FormData,URL,queueMicrotask,MutationObserver:class{observe(){}},location:{href:'http://127.0.0.1/subjects',replace(){throw Error('Unexpected redirect');}},
fetch:async(url,options)=>{ requests.push({url,options}); return {ok:true,status:200,json:async()=>({success:true,analysis:{status:'needs_human_review',summary:'Synthetic advisory <script>',findings:[],limitations:'Human review only'}})}; }};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../js/cimf-panel.js'),'utf8'),sandbox);
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function click(id) { get(id).listeners.click({target:get(id)}); }
function action(name) { get('csmsCimfActions').listeners.click({target:{closest:()=>({dataset:{cimfAction:name}})}}); }
(async()=>{
  click('csmsCimfLauncher');
  assert.equal(panel.hidden,false); assert.equal(get('csmsCimfHome').hidden,false); assert.equal(requests.length,0);
  action('review'); await tick();
  assert.equal(requests.length,1); assert.equal(get('csmsCimfResult').hidden,false);
  assert.match(get('csmsCimfStatus').textContent,/Human review required/);
  const result=get('csmsCimfResult').innerHTML;
  assert.match(result,/&lt;script&gt;/); assert.doesNotMatch(result,/<script>/);
  const form=requests[0].options.body;
  assert.equal(requests[0].options.method,'POST'); assert.equal(requests[0].options.credentials,'same-origin');
  for(const [key,value] of Object.entries({operation:'analyze',action:'review',profile:'subject_master',route:'subjects',page_token:'synthetic-token',_csrf_token:'synthetic-csrf'})) assert.equal(form.get(key),value);
  assert.deepEqual(JSON.parse(form.get('context')),{subject_id:'123'});
  action('ask'); assert.equal(get('csmsCimfAsk').hidden,false); click('csmsCimfBack');
  assert.equal(get('csmsCimfResult').innerHTML,result); assert.equal(requests.length,1);
  action('compare'); await tick(); assert.equal(get('csmsCimfCompare').hidden,false); assert.equal(requests.length,1);
  assert.equal(get('csmsCimfCompareSubmit').disabled,true); click('csmsCimfBack');
  assert.equal(get('csmsCimfResult').innerHTML,result); assert.equal(get('csmsCimfResult').hidden,false);
  click('csmsCimfCollapse'); assert.equal(get('csmsCimfPeek').hidden,false); click('csmsCimfPeek');
  assert.equal(get('csmsCimfResult').innerHTML,result); assert.equal(requests.length,1);
  selected='456'; document['csms:context-changed'](); await tick();
  assert.equal(get('csmsCimfHome').hidden,false); assert.equal(get('csmsCimfResult').hidden,true); assert.equal(requests.length,1);
  click('csmsCimfClose'); assert.equal(panel.hidden,true); assert.equal(get('csmsCimfLauncher').focused,true);
  click('csmsCimfLauncher'); assert.equal(get('csmsCimfHome').hidden,false); assert.equal(requests.length,1);
  console.log('PASS: global CIMF navigation, preserved advisory, context invalidation, escaped output, request security fields, no unnecessary API calls.');
})().catch(error=>{console.error(error);process.exitCode=1;});
