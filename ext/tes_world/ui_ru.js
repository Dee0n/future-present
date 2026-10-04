/*
 * Russian for a server web page (SHARMAT settings): the visible English texts are sent to
 * ui_tr.php and replaced with Russian ones. Field values (prompts, numbers) are never touched;
 * placeholders, titles and button captions are. Pages that change themselves are followed.
 */
(function () {
  var ENDPOINT = '/HerikaServer/ext/tes_world/ui_tr.php';
  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, NOSCRIPT: 1, OPTION: 0 };
  var done = new WeakSet();
  var cache = {};
  var latin = /[A-Za-z]{2,}/;

  function wanted(s) {
    var t = s.trim();
    return t.length > 1 && latin.test(t) && !/^[\w.\-\/]+\.(php|json|js|esp|esm|dll|pex)$/i.test(t) && !/^https?:/i.test(t) && !/^[A-Z0-9_]{3,}$/.test(t);
  }

  function collect(root, texts, jobs) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var n;
    while ((n = walker.nextNode())) {
      if (done.has(n)) continue;
      var p = n.parentElement;
      if (!p || SKIP[p.tagName] || p.closest('textarea,code,pre,script,style,[contenteditable=true]')) continue;
      if (wanted(n.nodeValue)) {
        texts.push(n.nodeValue.trim());
        jobs.push({ node: n, kind: 'text' });
      }
    }
    var els = (root.querySelectorAll ? root.querySelectorAll('[placeholder],[title],input[type=button],input[type=submit]') : []);
    for (var i = 0; i < els.length; i++) {
      var e = els[i];
      ['placeholder', 'title'].forEach(function (a) {
        var v = e.getAttribute(a);
        if (v && wanted(v) && !e['_tes_' + a]) { texts.push(v.trim()); jobs.push({ node: e, kind: a }); }
      });
      if ((e.type === 'button' || e.type === 'submit') && e.value && wanted(e.value) && !e._tes_value) {
        texts.push(e.value.trim()); jobs.push({ node: e, kind: 'value' });
      }
    }
  }

  function apply(jobs) {
    jobs.forEach(function (j) {
      if (j.kind === 'text') {
        var orig = j.node.nodeValue, key = orig.trim(), ru = cache[key];
        if (ru) { j.node.nodeValue = orig.replace(key, ru); done.add(j.node); }
      } else {
        var v = j.kind === 'value' ? j.node.value : j.node.getAttribute(j.kind);
        var r = cache[(v || '').trim()];
        if (r) {
          if (j.kind === 'value') j.node.value = r; else j.node.setAttribute(j.kind, r);
          j.node['_tes_' + j.kind] = 1;
        }
      }
    });
  }

  var busy = false, again = false;
  function run(root) {
    if (busy) { again = true; return; }
    var texts = [], jobs = [];
    collect(root || document.body, texts, jobs);
    var need = texts.filter(function (t, i) { return texts.indexOf(t) === i && !(t in cache); });
    if (!need.length) { apply(jobs); return; }
    busy = true;
    var batches = [];
    for (var i = 0; i < need.length; i += 300) batches.push(need.slice(i, i + 300));
    Promise.all(batches.map(function (b) {
      return fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ strings: b }) })
        .then(function (r) { return r.json(); }).catch(function () { return {}; });
    })).then(function (res) {
      res.forEach(function (m) { for (var k in m) cache[k] = m[k]; });
      need.forEach(function (t) { if (!(t in cache)) cache[t] = null; });
      apply(jobs);
      busy = false;
      if (again) { again = false; setTimeout(run, 300); }
    });
  }

  var timer = null;
  new MutationObserver(function () {
    clearTimeout(timer);
    timer = setTimeout(run, 400);
  }).observe(document.documentElement, { childList: true, subtree: true, characterData: false });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { run(); });
  else run();
})();
