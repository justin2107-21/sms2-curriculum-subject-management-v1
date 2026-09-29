'use strict';
// No browser substitute: deterministic JS/source checks only.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const source = file => fs.readFileSync(path.join(root, file), 'utf8');

function cssStructure(file) {
  const css = source(file);
  let quote = '', comment = false, depth = 0;
  for (let i = 0; i < css.length; i++) {
    const c = css[i], next = css[i + 1];
    if (comment) { if (c === '*' && next === '/') { comment = false; i++; } continue; }
    if (quote) { if (c === '\\') i++; else if (c === quote) quote = ''; continue; }
    if (c === '/' && next === '*') { comment = true; i++; continue; }
    if (c === '"' || c === "'") { quote = c; continue; }
    if (c === '{') depth++;
    if (c === '}') { depth--; assert.ok(depth >= 0, file + ': unmatched closing brace'); }
  }
  assert.equal(depth, 0, file + ': braces');
  assert.ok(!quote && !comment, file + ': unclosed string/comment');
}
function walk(dir) {
  return fs.readdirSync(path.join(root, dir), {withFileTypes: true}).flatMap(entry =>
    entry.isDirectory() ? walk(path.join(dir, entry.name)) : [path.join(dir, entry.name)]);
}
const cssFiles = [...walk('css'), ...walk('modules')].filter(file => file.endsWith('.css'));
cssFiles.forEach(cssStructure);
console.log('PASS: ' + cssFiles.length + ' CSS structural checks');

const clock = {};
vm.createContext(clock);
vm.runInContext(source('js/topbar-clock.js'), clock);
for (const [hour, expected] of [[0,'12:02:03 AM'], [12,'12:02:03 PM'], [23,'11:02:03 PM']]) {
  assert.equal(clock.Sms2LocalTime.formatLocalTime(new Date(2026,0,1,hour,2,3).getTime()), expected);
}
assert.match(source('js/topbar-clock.js'), /setInterval\(updateLocalTime, 1000\)/);
console.log('PASS: clock seconds, midnight/noon and AM/PM formatting');

function node() {
  const classes = new Set(), handlers = {}, attrs = {};
  return {handlers, attrs, dataset: {}, tabIndex: 0,
    classList: {add: c => classes.add(c), contains: c => classes.has(c),
      toggle(c, enabled) { enabled = enabled === undefined ? !classes.has(c) : enabled; enabled ? classes.add(c) : classes.delete(c); }},
    setAttribute(k,v) {attrs[k] = v;}, toggleAttribute(k,v) {if (v) attrs[k]=''; else delete attrs[k];},
    addEventListener(k,fn) {handlers[k]=fn;}
  };
}
for (const width of [1440,1024,768,430,390,360]) {
  const storage = new Map([['sms2_sidebar_scroll','240'],['menu-a','0'],['menu-b','1']]);
  const sidebar = node(), hamburger = node(), overlay = node();
  function group(key, active) {
    const group = node(), toggle = node(), panel = node(), link = node();
    group.dataset.sidebarMenuStorageKey = key;
    group.querySelector = selector => selector.includes('toggle') ? toggle : panel;
    panel.querySelector = () => active ? link : null;
    panel.querySelectorAll = () => [link];
    return {group,toggle,panel,link};
  }
  const a = group('menu-a',true), b=group('menu-b',false);
  const nav = node();
  Object.assign(nav, {scrollTop:0,scrollHeight:1200,clientHeight:500});
  nav.querySelectorAll=()=>[a.group,b.group];
  nav.querySelector=()=>null;
  const document = {getElementById: id => ({sidebar,hamburgerBtn:hamburger,sidebarOverlay:overlay})[id],
    querySelector:()=>nav};
  const window = {innerWidth:width,matchMedia:()=>({matches:width<=900}),requestAnimationFrame:fn=>fn(),addEventListener(){}};
  const sessionStorage = {getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value)};
  vm.runInNewContext(source('js/sidebar-state.js'),{document,window,sessionStorage});
  assert.equal(a.toggle.attrs['aria-expanded'],'true','active child overrides stored closed');
  assert.equal(b.toggle.attrs['aria-expanded'],'true','second group independently restored');
  assert.equal(nav.scrollTop,240,'scroll restored');
  a.toggle.handlers.click();
  assert.equal(a.link.tabIndex,-1);
  assert.equal(b.link.tabIndex,0,'not a forced accordion');
  a.toggle.handlers.click();
  assert.equal(storage.get('menu-a'),'1');
  nav.scrollTop=350; nav.handlers.scroll();
  assert.equal(storage.get('sms2_sidebar_scroll'),'350');
  vm.runInNewContext(source('js/dashboard.js'),{document,window});
  assert.equal(sidebar.classList.contains('collapsed'),width<=900);
  hamburger.handlers.click();
  assert.equal(sidebar.classList.contains('collapsed'),width>900);
  if(width<=900) {assert.ok(overlay.classList.contains('active'));overlay.handlers.click();assert.ok(sidebar.classList.contains('collapsed'));}
}
console.log('PASS: sidebar state, independent menus, focusability, scroll persistence and hamburger logic at six viewport widths (mock DOM; NOT visual QA)');
