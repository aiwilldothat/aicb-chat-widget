// AiCB chat widget for WordPress — talks to this site's REST API
// (aicb-chat/v1), which runs the AI + WooCommerce tools server-side.
// Visual style matches the Caimunicate/AiCB embeddable widget.
(function () {
  var CFG = window.AICBC_CFG || {};
  var REST = (CFG.rest || '').replace(/\/$/, '');
  if (!REST) return;
  var TITLE = CFG.title || 'Chat';
  var GREET = CFG.greeting || 'Hi! How can I help?';
  var threadId = null, open = false, busy = false, leadOpen = false, lastQuestion = '';
  // Live human chat state.
  var humanAvail = false, humanName = 'the team', waitMs = 30000;
  var humanMode = false, humanReplied = false, forwarded = false;
  var visitorName = '', visitorEmail = '', pollTimer = null, humanTimer = null, cardOpen = false;
  // Cross-page persistence (Pro): the server opts in via `persist` on /start,
  // then the thread id is kept in localStorage and restored with /resume.
  var persistOn = false, booted = false;
  var PERSIST_KEY = 'aicbc_thread', PERSIST_TTL = 24 * 3600 * 1000;
  function savedThread() {
    try {
      var raw = localStorage.getItem(PERSIST_KEY);
      if (!raw) return null;
      var d = JSON.parse(raw);
      if (!d || !d.id || (Date.now() - (d.ts || 0)) > PERSIST_TTL) { clearThread(); return null; }
      return d.id;
    } catch (e) { return null; }
  }
  function saveThread() {
    if (!persistOn || !threadId) return;
    try { localStorage.setItem(PERSIST_KEY, JSON.stringify({ id: threadId, ts: Date.now() })); } catch (e) {}
  }
  function clearThread() {
    try { localStorage.removeItem(PERSIST_KEY); } catch (e) {}
  }

  var V = '#6E56F7', T = '#19C8C0';
  function elc(tag, css, html) { var e = document.createElement(tag); if (css) e.style.cssText = css; if (html != null) e.innerHTML = html; return e; }

  // Icons are painted as data-URI background images: emoji get rewritten by
  // wp-emoji, and even inline <svg> can be hidden by aggressive theme /
  // optimizer CSS (svg{...} rules). A background-image on a plain span is
  // immune to both — nothing on the page targets it.
  function iconHtml(svg, size) {
    return '<span aria-hidden="true" style="display:block;width:' + size + 'px;height:' + size + 'px;pointer-events:none;' +
      "background-image:url('data:image/svg+xml," + encodeURIComponent(svg) + "');" +
      'background-size:contain;background-repeat:no-repeat;background-position:center"></span>';
  }
  var SVG_CHAT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#fff" d="M12 3C7 3 3 6.4 3 10.6c0 2.3 1.2 4.3 3.1 5.7-.1 1-.5 2.1-1.5 3.1-.2.2 0 .6.3.6 1.9-.1 3.4-.8 4.4-1.5.9.2 1.8.3 2.7.3 5 0 9-3.4 9-7.6S17 3 12 3z"/></svg>';
  var SVG_CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path stroke="#fff" stroke-width="2.6" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>';
  var SVG_PERSON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#fff" d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4.4 0-8 2.2-8 5v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1c0-2.8-3.6-5-8-5z"/></svg>';
  var ICON_CHAT = iconHtml(SVG_CHAT, 27);
  var ICON_CLOSE = iconHtml(SVG_CLOSE, 20);
  var ICON_CHAT_SM = iconHtml(SVG_CHAT, 19);

  var launcher = elc('button', 'position:fixed;right:20px;bottom:20px;width:58px;height:58px;border-radius:50%;border:none;cursor:pointer;z-index:2147483000;box-shadow:0 8px 24px rgba(20,21,46,.28);background:linear-gradient(135deg,' + V + ',' + T + ');color:#fff;display:flex;align-items:center;justify-content:center;padding:0;line-height:0', ICON_CHAT);
  launcher.setAttribute('aria-label', 'Open chat');

  var panel = elc('div', 'position:fixed;right:20px;bottom:88px;width:360px;max-width:calc(100vw - 40px);height:520px;max-height:calc(100vh - 120px);background:#fff;border-radius:18px;overflow:hidden;z-index:2147483000;box-shadow:0 20px 60px rgba(20,21,46,.3);display:none;flex-direction:column;font-family:Segoe UI,system-ui,sans-serif');
  panel.innerHTML =
    '<div style="background:linear-gradient(135deg,' + V + ',' + T + ');color:#fff;padding:14px 16px;font-weight:700;display:flex;align-items:center;gap:8px">' +
      '<span style="line-height:0">' + ICON_CHAT_SM + '</span><span id="cm-title">Chat</span>' +
      '<button id="cm-human" title="Leave your details for a human" style="margin-left:auto;border:1px solid rgba(255,255,255,.55);background:rgba(255,255,255,.16);color:#fff;border-radius:8px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:4px">' +
        iconHtml(SVG_PERSON, 11) + 'Human</button></div>' +
    '<div id="cm-msgs" style="flex:1;overflow-y:auto;padding:14px;background:#F6F7FB;display:flex;flex-direction:column;gap:8px"></div>' +
    '<div style="display:flex;gap:8px;padding:10px;border-top:1px solid #E6E8F0;background:#fff">' +
      '<input id="cm-in" placeholder="Type a message…" style="flex:1;border:1px solid #E6E8F0;border-radius:10px;padding:9px 11px;outline:none;font-size:14px"/>' +
      '<button id="cm-send" style="border:none;border-radius:10px;padding:0 14px;background:linear-gradient(135deg,' + V + ',' + T + ');color:#fff;font-weight:700;cursor:pointer">Send</button></div>';

  document.body.appendChild(launcher);
  document.body.appendChild(panel);
  var msgsEl = panel.querySelector('#cm-msgs'), input = panel.querySelector('#cm-in');
  panel.querySelector('#cm-title').textContent = TITLE;

  // Keep the panel inside the visible screen when the mobile keyboard pops up.
  function fitPanel() {
    if (!open) return;
    var vv = window.visualViewport;
    if (vv && window.innerWidth < 560 && (window.innerHeight - vv.height - vv.offsetTop) > 90) {
      var m = 8;
      panel.style.top = (vv.offsetTop + m) + 'px';
      panel.style.bottom = 'auto';
      panel.style.left = m + 'px';
      panel.style.right = m + 'px';
      panel.style.width = 'auto';
      panel.style.maxWidth = 'none';
      panel.style.height = Math.max(200, vv.height - m * 2) + 'px';
      panel.style.maxHeight = 'none';
      msgsEl.scrollTop = msgsEl.scrollHeight;
      return;
    }
    panel.style.top = 'auto';
    panel.style.bottom = '88px';
    panel.style.left = 'auto';
    panel.style.right = '20px';
    panel.style.width = '360px';
    panel.style.maxWidth = 'calc(100vw - 40px)';
    panel.style.height = '520px';
    panel.style.maxHeight = 'calc(100vh - 120px)';
  }
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', fitPanel);
    window.visualViewport.addEventListener('scroll', fitPanel);
  }
  input.addEventListener('focus', function () { setTimeout(fitPanel, 100); setTimeout(fitPanel, 350); });
  input.addEventListener('blur', function () { setTimeout(fitPanel, 150); });

  // Render assistant text with clickable links. Handles markdown [label](url)
  // and bare http(s) URLs. XSS-safe: only text nodes + <a> elements are created.
  function appendRich(el, text) {
    var re = /\[([^\]\n]{1,150})\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<>"')\]]+)/g;
    var last = 0, m;
    while ((m = re.exec(text))) {
      if (m.index > last) el.appendChild(document.createTextNode(text.slice(last, m.index)));
      var url = m[2] || m[3], label = m[1] || '', trail = '';
      if (!label) {
        // Bare URL: keep trailing punctuation out of the link.
        var t = url.match(/[.,;:!?]+$/);
        if (t) { trail = t[0]; url = url.slice(0, -trail.length); }
        label = url.replace(/^https?:\/\//, '');
        if (label.length > 60) label = label.slice(0, 57) + '…';
      }
      var a = document.createElement('a');
      a.href = url; a.textContent = label;
      a.target = '_blank'; a.rel = 'noopener noreferrer';
      a.style.cssText = 'color:' + V + ';font-weight:600;word-break:break-all';
      el.appendChild(a);
      if (trail) el.appendChild(document.createTextNode(trail));
      last = re.lastIndex;
    }
    if (last < text.length) el.appendChild(document.createTextNode(text.slice(last)));
  }
  function bubble(text, mine) {
    var b = elc('div', 'max-width:80%;padding:8px 12px;border-radius:13px;font-size:14px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;' +
      (mine ? 'align-self:flex-end;background:linear-gradient(135deg,' + V + ',' + T + ');color:#fff' : 'align-self:flex-start;background:#fff;border:1px solid #E6E8F0;color:#14152E'));
    if (mine) { b.textContent = text; } else { appendRich(b, String(text)); }
    msgsEl.appendChild(b); msgsEl.scrollTop = msgsEl.scrollHeight;
  }
  function api(path, body) {
    return fetch(REST + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); });
  }
  // Inline "leave your details" card — shown when the AI can't help or the visitor taps ✋ Human.
  function showLead(prefill) {
    if (leadOpen || !threadId) return; leadOpen = true;
    var inS = 'border:1px solid #E6E8F0;border-radius:9px;padding:8px 10px;font-size:13px;outline:none;font-family:inherit;width:100%;box-sizing:border-box';
    var card = elc('div', 'align-self:stretch;background:#fff;border:1px solid #E6E8F0;border-radius:13px;padding:12px;display:flex;flex-direction:column;gap:7px');
    card.innerHTML =
      '<div style="font-weight:700;color:#14152E;font-size:13px">Leave your details</div>' +
      '<div style="font-size:12px;color:#6B6B80;margin:-2px 0 2px">We’ll pass this to the team and get back to you.</div>' +
      '<input id="cm-l-name" placeholder="Your name" style="' + inS + '"/>' +
      '<input id="cm-l-email" placeholder="Email" type="email" style="' + inS + '"/>' +
      '<input id="cm-l-phone" placeholder="Phone (optional)" style="' + inS + '"/>' +
      '<textarea id="cm-l-msg" placeholder="How can we help?" rows="2" style="' + inS + ';resize:vertical"></textarea>' +
      '<div style="display:flex;gap:8px"><button id="cm-l-send" style="flex:1;border:none;border-radius:9px;padding:9px;background:linear-gradient(135deg,' + V + ',' + T + ');color:#fff;font-weight:700;cursor:pointer">Send details</button>' +
      '<button id="cm-l-cancel" style="border:1px solid #E6E8F0;background:#fff;border-radius:9px;padding:9px 12px;cursor:pointer;color:#6B6B80">Cancel</button></div>';
    msgsEl.appendChild(card); msgsEl.scrollTop = msgsEl.scrollHeight;
    if (prefill) card.querySelector('#cm-l-msg').value = prefill;
    card.querySelector('#cm-l-cancel').addEventListener('click', function () { card.remove(); leadOpen = false; });
    card.querySelector('#cm-l-send').addEventListener('click', function () {
      var name = card.querySelector('#cm-l-name').value.trim();
      var email = card.querySelector('#cm-l-email').value.trim();
      var phone = card.querySelector('#cm-l-phone').value.trim();
      var msg = card.querySelector('#cm-l-msg').value.trim();
      if (!name) { alert('Please add your name.'); return; }
      if (!email && !phone) { alert('Please add an email or phone so we can reply.'); return; }
      var btn = card.querySelector('#cm-l-send'); btn.disabled = true; btn.textContent = 'Sending…';
      api('/lead', { threadId: threadId, name: name, email: email, phone: phone, message: msg }).then(function (d) {
        card.remove(); leadOpen = false;
        if (d && d.error) { bubble('Could not send: ' + d.error, false); return; }
        bubble((d && d.message) || 'Thanks — we’ll be in touch soon.', false);
      }).catch(function () { btn.disabled = false; btn.textContent = 'Send details'; bubble('Could not send — please try again.', false); });
    });
  }
  // ---- Live human chat -------------------------------------------------
  // "Chat now" card: offered when the AI escalates or the visitor taps ✋.
  function offerHuman(prefill) {
    if (humanMode || forwarded) return;
    if (!humanAvail) { showLead(prefill); return; }
    if (cardOpen) return; cardOpen = true;
    var card = elc('div', 'align-self:stretch;background:#fff;border:1px solid #E6E8F0;border-radius:13px;padding:12px;display:flex;flex-direction:column;gap:7px');
    card.innerHTML =
      '<div style="font-weight:700;color:#14152E;font-size:13px">Talk to a person</div>' +
      '<div style="font-size:12px;color:#6B6B80;margin:-2px 0 2px">Chat with ' + humanName + ' live, right here — or leave your details.</div>' +
      '<button id="cm-h-now" style="border:none;border-radius:9px;padding:9px;background:linear-gradient(135deg,' + V + ',' + T + ');color:#fff;font-weight:700;cursor:pointer">💬 Chat with ' + humanName + ' now</button>' +
      '<button id="cm-h-lead" style="border:1px solid #E6E8F0;background:#fff;border-radius:9px;padding:9px;cursor:pointer;color:#14152E">Leave your details instead</button>';
    msgsEl.appendChild(card); msgsEl.scrollTop = msgsEl.scrollHeight;
    card.querySelector('#cm-h-now').addEventListener('click', function () { card.remove(); cardOpen = false; humanForm(prefill); });
    card.querySelector('#cm-h-lead').addEventListener('click', function () { card.remove(); cardOpen = false; showLead(prefill); });
  }
  // Name + email are collected BEFORE connecting, so a no-show can be followed up.
  function humanForm(prefill) {
    if (cardOpen) return; cardOpen = true;
    var inS = 'border:1px solid #E6E8F0;border-radius:9px;padding:8px 10px;font-size:13px;outline:none;font-family:inherit;width:100%;box-sizing:border-box';
    var card = elc('div', 'align-self:stretch;background:#fff;border:1px solid #E6E8F0;border-radius:13px;padding:12px;display:flex;flex-direction:column;gap:7px');
    card.innerHTML =
      '<div style="font-weight:700;color:#14152E;font-size:13px">Before we connect you…</div>' +
      '<div style="font-size:12px;color:#6B6B80;margin:-2px 0 2px">Your name and email, in case we get cut off.</div>' +
      '<input id="cm-h-name" placeholder="Your name" style="' + inS + '"/>' +
      '<input id="cm-h-email" placeholder="Email" type="email" style="' + inS + '"/>' +
      '<div id="cm-h-err" style="color:#b32d2e;font-size:12px;display:none"></div>' +
      '<div style="display:flex;gap:8px"><button id="cm-h-go" style="flex:1;border:none;border-radius:9px;padding:9px;background:linear-gradient(135deg,' + V + ',' + T + ');color:#fff;font-weight:700;cursor:pointer">Connect</button>' +
      '<button id="cm-h-cancel" style="border:1px solid #E6E8F0;background:#fff;border-radius:9px;padding:9px 12px;cursor:pointer;color:#6B6B80">Cancel</button></div>';
    msgsEl.appendChild(card); msgsEl.scrollTop = msgsEl.scrollHeight;
    card.querySelector('#cm-h-cancel').addEventListener('click', function () { card.remove(); cardOpen = false; });
    card.querySelector('#cm-h-go').addEventListener('click', function () {
      var name = card.querySelector('#cm-h-name').value.trim();
      var email = card.querySelector('#cm-h-email').value.trim();
      var err = card.querySelector('#cm-h-err');
      if (!name) { err.textContent = 'Please enter your name.'; err.style.display = 'block'; return; }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { err.textContent = 'Please enter a valid email address.'; err.style.display = 'block'; return; }
      var btn = card.querySelector('#cm-h-go'); btn.disabled = true; btn.textContent = 'Connecting…';
      api('/human', { threadId: threadId, name: name, email: email }).then(function (d) {
        card.remove(); cardOpen = false;
        if (d && d.live) {
          humanMode = true; visitorName = name; visitorEmail = email;
          saveThread();
          bubble(d.greet || ('You’re connected to ' + humanName + '.'), false);
          startPoll(); armHumanTimer();
          if (prefill) { input.value = prefill; }
        } else {
          bubble('Live chat isn’t available right now — please leave your details instead.', false);
          showLead(prefill);
        }
      }).catch(function () { btn.disabled = false; btn.textContent = 'Connect'; err.textContent = 'Could not connect — please try again.'; err.style.display = 'block'; });
    });
  }
  // Surface the team member's replies (they answer from their Caimunicate app).
  function startPoll() {
    if (pollTimer) return;
    pollTimer = setInterval(function () {
      if (!threadId || !humanMode) return;
      api('/poll', { threadId: threadId }).then(function (d) {
        var msgs = (d && d.messages) || [];
        if (msgs.length) {
          humanReplied = true;
          if (humanTimer) { clearTimeout(humanTimer); humanTimer = null; }
          msgs.forEach(function (m) { bubble(m, false); });
        }
      }).catch(function () {});
    }, 4000);
  }
  function armHumanTimer() {
    if (forwarded) return;
    if (humanTimer) clearTimeout(humanTimer);
    humanReplied = false;
    humanTimer = setTimeout(humanTimeout, waitMs);
  }
  function humanTimeout() {
    if (humanReplied || forwarded) return;
    forwarded = true;
    bubble(humanName + ' doesn’t appear to be available right now — sorry about that. I’ve forwarded your message and the team will get back to you by email.', false);
    api('/lead', { threadId: threadId, name: visitorName, email: visitorEmail, phone: '', message: 'Live chat — no reply within the wait window. Last message: ' + lastQuestion }).catch(function () {});
    // Keep polling: a late reply still shows up in the widget.
  }
  // ------------------------------------------------------------------------

  function applyMeta(d) {
    humanAvail = !!d.human;
    if (d.humanName) humanName = d.humanName;
    if (d.waitMs) waitMs = d.waitMs;
    persistOn = !!d.persist;
    panel.querySelector('#cm-title').textContent = d.businessName || TITLE;
  }
  function start() {
    api('/start', { page: window.location.href }).then(function (d) {
      if (d.error) { bubble('Chat unavailable: ' + d.error); return; }
      threadId = d.threadId;
      applyMeta(d);
      saveThread();
      bubble((d.greeting && d.greeting.text) || GREET, false);
    }).catch(function () { bubble('Chat unavailable right now.', false); });
  }
  // First open on a page: resume a persisted conversation (Pro) or start fresh.
  function boot() {
    var saved = savedThread();
    if (!saved) { start(); return; }
    api('/resume', { threadId: saved }).then(function (d) {
      if (!d || !d.ok) { clearThread(); start(); return; }
      threadId = saved;
      applyMeta(d);
      saveThread();
      (d.messages || []).forEach(function (m) { bubble(m.text, m.role === 'visitor'); });
      if (!(d.messages || []).length) { bubble(GREET, false); }
      if (d.mode === 'human') {
        humanMode = true;
        visitorName = d.visitorName || '';
        startPoll();
      }
    }).catch(function () { clearThread(); start(); });
  }
  function send() {
    var text = input.value.trim(); if (!text || busy || !threadId) return;
    lastQuestion = text;
    input.value = ''; bubble(text, true); busy = true;
    saveThread();
    if (humanMode) {
      api('/message', { threadId: threadId, text: text }).then(function (d) {
        busy = false;
        if (d && d.reply && d.reply.text) { bubble(d.reply.text, false); showLead(lastQuestion); return; }
        armHumanTimer();
      }).catch(function () { busy = false; bubble('Network error.'); });
      return;
    }
    var t = elc('div', 'align-self:flex-start;color:#6B6B80;font-size:12px', '…'); msgsEl.appendChild(t);
    api('/message', { threadId: threadId, text: text }).then(function (d) {
      t.remove(); busy = false;
      if (d.error) { bubble('Error: ' + d.error); return; }
      bubble((d.reply && d.reply.text) || '(no reply)', false);
      if (d.offerHandoff) offerHuman(lastQuestion);
    }).catch(function () { t.remove(); busy = false; bubble('Network error.'); });
  }
  launcher.addEventListener('click', function () {
    open = !open; panel.style.display = open ? 'flex' : 'none'; launcher.innerHTML = open ? ICON_CLOSE : ICON_CHAT;
    if (open && !booted) { booted = true; boot(); }
    if (open) { fitPanel(); input.focus(); }
  });
  panel.querySelector('#cm-send').addEventListener('click', send);
  panel.querySelector('#cm-human').addEventListener('click', function () { offerHuman(''); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); });
})();
