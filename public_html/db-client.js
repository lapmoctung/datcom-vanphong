/* Lớp dữ liệu thay cho kho dữ liệu của Claude: giữ nguyên giao diện doc/collection/add/get/set/update/onSnapshot,
   nhưng gọi API PHP (api/index.php) và tự cập nhật bằng cách hỏi lại server mỗi vài giây. */
(function(){
  var API = (window.DATCOM_API || 'api/index.php');
  var POLL_MS = 8000;
  var tok = ''; try { tok = sessionStorage.getItem('dc_tok') || '' } catch(e){}
  function setTok(t){ tok = t || ''; try { if (t) sessionStorage.setItem('dc_tok', t); else sessionStorage.removeItem('dc_tok') } catch(e){} }
  function clone(o){ return o === undefined ? undefined : JSON.parse(JSON.stringify(o)) }
  function mkErr(j, status){ var e = new Error((j && j.message) || 'Không kết nối được máy chủ'); e.code = (j && j.error) || ('http_' + status); return e }
  async function call(method, qs, body){
    var h = {}; if (tok) h['X-Admin-Token'] = tok; if (body) h['Content-Type'] = 'application/json';
    var r; try { r = await fetch(API + '?' + qs, { method: method, headers: h, body: body ? JSON.stringify(body) : undefined, cache: 'no-store' }) }
    catch(e){ throw mkErr(null, 0) }
    var j = null; try { j = await r.json() } catch(e){}
    if (!r.ok) throw mkErr(j, r.status);
    return j;
  }
  var cols = {};   // name -> {map:Map(id->data), u:Map(id->u), cursor:0, loaded:false, subs:[]}
  function col(n){ return cols[n] || (cols[n] = { map: new Map(), um: new Map(), cursor: 0, loaded: false, subs: [] }) }
  function apply(c, docs){ docs.forEach(function(d){ c.map.set(d.id, d.data); c.um.set(d.id, d.u); if (d.u > c.cursor) c.cursor = d.u }) }
  function snapCol(c){ var ids = Array.from(c.map.keys()).sort(); var docs = ids.map(function(id){ return { id: id, data: function(){ return clone(c.map.get(id)) } } }); return { docs: docs, size: docs.length } }
  function snapDoc(c, id){ var has = c.map.has(id); return { id: id, exists: has, data: function(){ return has ? clone(c.map.get(id)) : undefined } } }
  function fire(c){ c.subs.slice().forEach(function(s){ try { s.fire() } catch(e){ console.error(e) } }) }
  async function pull(name, full){
    var c = col(name);
    var j = await call('GET', 'c=' + name + (full || !c.loaded ? '' : '&since=' + Math.max(0, c.cursor - 5000)));
    var before = c.map.size, changed = false;
    j.docs.forEach(function(d){ var o = c.um.get(d.id); if (o !== d.u) changed = true });
    apply(c, j.docs);
    var first = !c.loaded; c.loaded = true;
    if (changed || first || c.map.size !== before) fire(c);
    return c;
  }
  var busy = false;
  async function tick(){
    if (busy || document.hidden) return; busy = true;
    try { var names = Object.keys(cols).filter(function(n){ return cols[n].subs.length }); await Promise.all(names.map(function(n){ return pull(n).catch(function(){}) })) }
    finally { busy = false }
  }
  setInterval(tick, POLL_MS);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) tick() });
  window.addEventListener('online', tick);
  function newId(){ return 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8) }
  function split(path){ var i = path.indexOf('/'); return [path.slice(0, i), path.slice(i + 1)] }
  async function write(path, op, data){
    var p = split(path);
    var j = await call('PUT', 'p=' + encodeURIComponent(path), { op: op, data: data });
    if (j.token) setTok(j.token);
    var c = col(p[0]); c.map.set(p[1], j.data); c.um.set(p[1], j.u);
    fire(c); return j;
  }
  function docRef(path){
    var p = split(path);
    return { id: p[1], path: path,
      async get(){ var j = await call('GET', 'p=' + encodeURIComponent(path)); var c = col(p[0]);
        if (j.exists) { c.map.set(p[1], j.data); c.um.set(p[1], j.u) }
        return { id: p[1], exists: !!j.exists, data: function(){ return j.exists ? clone(j.data) : undefined } } },
      async set(d){ await write(path, 'set', d) },
      async update(d){ await write(path, 'update', d) },
      async delete(){ throw mkErr({ message: 'Không hỗ trợ xóa', error: 'denied' }, 403) },
      onSnapshot(next, err){
        var c = col(p[0]); var s = { fire: function(){ if (c.loaded) next(snapDoc(c, p[1])) } };
        c.subs.push(s); if (c.loaded) setTimeout(s.fire, 0); else pull(p[0]).catch(function(e){ if (err) err(e) });
        return function(){ c.subs = c.subs.filter(function(x){ return x !== s }) } } };
  }
  function colRef(name){
    return { path: name,
      doc: function(id){ return docRef(name + '/' + (id || newId())) },
      async add(d){ var r = docRef(name + '/' + newId()); await write(r.path, 'create', d); return r },
      async get(){ var c = await pull(name, true); return snapCol(c) },
      onSnapshot(next, err){
        var c = col(name); var s = { fire: function(){ if (c.loaded) next(snapCol(c)) } };
        c.subs.push(s); if (c.loaded) setTimeout(s.fire, 0); else pull(name).catch(function(e){ if (err) err(e) });
        return function(){ c.subs = c.subs.filter(function(x){ return x !== s }) } } };
  }
  var db = { doc: docRef, collection: colRef,
    async adminLogin(phone, hash){ var j = await call('POST', 'a=login', { phone: phone, hash: hash }); if (j.ok) { setTok(j.token); return true } return false },
    logout: function(){ setTok('') } };
  var dl = { async save(o){
    var blob = new Blob([o.data], { type: 'text/html;charset=utf-8' }); var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = o.filename || 'file.html'; document.body.appendChild(a); a.click();
    setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove() }, 1000) } };
  window.claude = { use: async function(n){ if (n === 'db') return db; if (n === 'downloads') return dl; throw new Error('unsupported') } };
})();
