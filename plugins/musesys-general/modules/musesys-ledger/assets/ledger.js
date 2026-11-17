/* MuseSys Ledger — client. LEDGER_SPEC.md §8. Vanilla JS, no dependencies. */
(function () {
  'use strict';
  var C = window.MuseLedger || {};
  var app = document.getElementById('muse-ledger-app');
  if (!app) return;

  var state = { data: null, tab: 'work', open: {}, busy: false };

  var STATE_LABEL = {
    CALL_REQUIRED: 'Call required', NEEDS_ETA: 'Needs ETA', SHIPPED_NOT_CLOSED: 'Shipped / not closed',
    ON_TRACK: 'On track', MISSING_TRX: 'Missing TRX', EXCEPTION: 'Exception', CLOSED: 'Closed'
  };
  var STATE_RANK = { CALL_REQUIRED: 0, NEEDS_ETA: 1, SHIPPED_NOT_CLOSED: 2, EXCEPTION: 3, MISSING_TRX: 4, ON_TRACK: 5 };
  var REASON = {
    eta_passed: 'ETA has passed', no_eta: 'Backordered with no ETA', not_shipped_same_day: 'In stock but not shipped same day',
    emailed_awaiting_response: 'Email sent, waiting on response', called_awaiting_eta: 'Called, waiting on ETA',
    no_response_1bd: 'No response after 1 business day', order_open: 'All qty shipped, order still open',
    missing_po: 'All qty shipped, drop-ship PO # missing', eta_future: 'ETA in the future', ships_today: 'Ships today',
    no_trx: 'No TRX # entered', replaced: 'Part replaced', obsolete: 'Part obsolete',
    unknown_logistics_status: 'Unrecognised logistics status', awaiting_confirmation: 'Awaiting confirmation'
  };
  var ACTION_LABEL = {
    email_logged: 'Email logged', call_logged: 'Call logged', note: 'Note', eta_set: 'ETA set',
    eta_changed: 'ETA changed', trx_assigned: 'TRX assigned', shipment_recorded: 'Shipment recorded',
    state_changed: 'State changed'
  };
  var AGENT_TABS = [
    ['work', 'Work queue', function (r) { return r.actionable === 1; }],
    ['coming_due', 'Coming due', function (r) { return r.coming_due === 1; }]
  ];
  var ADMIN_TABS = [
    ['work', 'Work queue', function (r) { return r.actionable === 1; }],
    ['coming_due', 'Coming due', function (r) { return r.coming_due === 1; }],
    ['on_track', 'On track', function (r) { return r.state === 'ON_TRACK'; }],
    ['missing_trx', 'Missing TRX', function (r) { return r.state === 'MISSING_TRX'; }],
    ['exception', 'Exceptions', function (r) { return r.state === 'EXCEPTION'; }]
  ];

  function currentTabs() {
    return (state.data && state.data.agent_view) ? AGENT_TABS : ADMIN_TABS;
  }

  /* ---------------- helpers ---------------- */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmt(iso, withTime) {
    if (!iso) return '';
    var o = { timeZone: C.tz, month: 'short', day: 'numeric' };
    if (withTime) { o.hour = 'numeric'; o.minute = '2-digit'; }
    return new Intl.DateTimeFormat('en-US', o).format(new Date(iso));
  }
  function fmtDate(ymd) { // a calendar date, not an instant
    if (!ymd) return '';
    return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: 'short', day: 'numeric' }).format(new Date(ymd + 'T12:00:00Z'));
  }
  function post(action, params) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', C.nonce);
    Object.keys(params || {}).forEach(function (k) { body.set(k, params[k]); });
    return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'HTTP ' + r.status } }; }); })
      .then(function (j) {
        if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'Request failed');
        return j.data;
      });
  }
  function toast(msg, bad) {
    var t = document.createElement('div');
    t.className = 'ml-toast' + (bad ? ' ml-toast-bad' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4000);
  }

  /* ---------------- load + render ---------------- */

  function load() {
    return post('musesys_ledger_list').then(function (d) { state.data = d; render(); })
      .catch(function (e) { app.innerHTML = '<h1>Ledger</h1><p class="ml-error">' + esc(e.message) + '</p>'; });
  }

  function visibleRows() {
    var rows = (state.data && state.data.rows) || [];
    var tabs = currentTabs();
    var tab = tabs.filter(function (t) { return t[0] === state.tab; })[0] || tabs[0];
    return rows.filter(tab[2]);
  }

  function groups(rows) {
    var by = {};
    rows.forEach(function (r) { (by[r.order_id] = by[r.order_id] || []).push(r); });
    var list = Object.keys(by).map(function (k) {
      var lines = by[k];
      var rank = Math.min.apply(null, lines.map(function (r) { return STATE_RANK[r.state] == null ? 9 : STATE_RANK[r.state]; }));
      var late = Math.max.apply(null, lines.map(function (r) { return r.overdue_bdays; }));
      var dues = lines.map(function (r) { return r.due_at || '9999-99-99'; }).sort();
      var earliestDue = dues[0] || '9999-99-99';
      return { order_id: +k, lines: lines, rank: rank, late: late, earliestDue: earliestDue };
    });
    if (state.tab === 'coming_due') {
      list.forEach(function (g) {
        g.lines.sort(function (a, b) {
          var da = a.due_at || '9999-99-99', db = b.due_at || '9999-99-99';
          return da < db ? -1 : (da > db ? 1 : a.id - b.id);
        });
      });
      list.sort(function (a, b) {
        if (a.earliestDue !== b.earliestDue) {
          return a.earliestDue < b.earliestDue ? -1 : 1;
        }
        return a.order_id - b.order_id;
      });
    } else {
      list.sort(function (a, b) { return a.rank - b.rank || b.late - a.late || a.order_id - b.order_id; });
    }
    return list;
  }

  function render() {
    var d = state.data, s = d.summary;
    var tabs = currentTabs();
    if (!tabs.some(function (t) { return t[0] === state.tab; })) {
      state.tab = 'work';
    }
    var h = '<h1>Ledger</h1>';
    h += '<div class="ml-strip">' +
      chip('Needs ETA', s.NEEDS_ETA, 'needs') + chip('Call required', s.CALL_REQUIRED, 'call') +
      chip('Partial', s.partial, '') + chip('Shipped / not closed', s.SHIPPED_NOT_CLOSED, '') +
      chip('2+ bd late', s.late2, 'late') + chip('Actions today', s.actions_today, '') +
      (d.agent_view ? '' : chip('Missing TRX', s.MISSING_TRX, '') + chip('Exceptions', s.EXCEPTION, '')) +
      '</div>';

    if (d.user_stats) h += userStats(d.user_stats);
    if (d.audit) h += auditPanel(d.audit);

    h += '<div class="ml-tabs">' + tabs.map(function (t) {
      return '<button type="button" class="ml-tab' + (state.tab === t[0] ? ' is-active' : '') + '" data-tab="' + t[0] + '">' + esc(t[1]) + '</button>';
    }).join('') + '</div>';

    var gs = groups(visibleRows());
    if (!gs.length) {
      var emptyMsg = state.tab === 'coming_due'
        ? 'Nothing due in the next 2 business days.'
        : 'Nothing here right now.';
      h += '<p class="ml-empty">' + esc(emptyMsg) + '</p>';
    } else {
      h += '<div class="ml-scroll"><table class="ml-table"><thead><tr>' +
        '<th>TRX</th><th>PO</th><th>Order</th><th>Store</th><th>Part</th><th>Qty</th><th>ETA</th><th>Last contact</th><th>State</th>' +
        (C.canManage ? '<th>Actions</th>' : '') + '</tr></thead>';
      gs.forEach(function (g) { h += groupHtml(g); });
      h += '</table></div>';
    }
    app.innerHTML = h;
  }

  function chip(label, n, cls) {
    return '<div class="ml-chip ' + cls + '"><span class="ml-chip-n">' + esc(n || 0) + '</span><span class="ml-chip-l">' + esc(label) + '</span></div>';
  }

  function userStats(us) {
    function table(title, rows) {
      if (!rows.length) return '<div class="ml-stat"><h3>' + esc(title) + '</h3><p class="ml-muted">No actions yet.</p></div>';
      return '<div class="ml-stat"><h3>' + esc(title) + '</h3><table><tr><th></th><th>Emails</th><th>Calls</th><th>ETAs set</th><th>Notes</th></tr>' +
        rows.map(function (r) {
          return '<tr><td>' + esc(r.name) + '</td><td>' + r.email_logged + '</td><td>' + r.call_logged + '</td><td>' + r.eta_set + '</td><td>' + r.note + '</td></tr>';
        }).join('') + '</table></div>';
    }
    return '<div class="ml-stats">' + table('Today', us.today) + table('Since ledger start (' + fmt(us.since.replace(' ', 'T') + 'Z') + ')', us.period) + '</div>';
  }

  function srcLabel(k) {
    var src = (state.data && state.data.eta_sources) || {};
    return src[k] || k;
  }

  function auditPanel(a) {
    function table(title, rows) {
      if (!rows || !rows.length) return '<div class="ml-stat"><h3>' + esc(title) + '</h3><p class="ml-muted">No ETA changes.</p></div>';
      return '<div class="ml-stat"><h3>' + esc(title) + '</h3><table><tr><th></th><th>ETA changes</th><th>Orders</th>' +
        '<th>Pushed out</th><th>Avg push (d)</th><th>Unbacked pushes</th><th>Estimates</th><th>Bulk edits</th>' +
        '<th>Cleared “Call required”</th><th>Pushed 2×+</th></tr>' +
        rows.map(function (r) {
          return '<tr><td>' + esc(r.name) + '</td><td>' + r.eta_changes + '</td><td>' + r.orders_touched + '</td><td>' +
            r.pushes + '</td><td>' + r.avg_slip + '</td><td>' + r.unevidenced_pushes + '</td><td>' + r.estimates + '</td><td>' +
            r.burst_changes + '</td><td>' + r.cleared_call_required + '</td><td>' + r.repeat_pushed + '</td></tr>';
        }).join('') + '</table></div>';
    }
    var h = '<h2>ETA audit</h2><div class="ml-strip">' +
      chip('Parked on unbacked ETA', a.parked, 'late') + chip('Chronic orders', a.chronic_total, 'call') +
      chip('Orders shipped (7d)', a.shipments_week, '') + '</div>';
    h += '<div class="ml-stats">' + table('Today', a.today) + table('Last 7 days', a.week) +
      table('Since ledger start (' + fmt(a.since.replace(' ', 'T') + 'Z') + ')', a.period) + '</div>';
    if (a.chronic && a.chronic.length) {
      h += '<div class="ml-stat"><h3>Chronic orders — pushed 3+ times or 14+ days past first ETA' +
        (a.chronic_total > a.chronic.length ? ' (top ' + a.chronic.length + ' of ' + a.chronic_total + ')' : '') + '</h3>' +
        '<div class="ml-scroll"><table><tr><th>TRX</th><th>Order</th><th>State</th><th>First ETA</th><th>Current ETA</th><th>Pushes</th><th>Days past first ETA</th></tr>' +
        a.chronic.map(function (c) {
          return '<tr><td>' + esc(c.trx || '—') + '</td><td>#' + c.order_id + '</td><td>' + esc(STATE_LABEL[c.state] || c.state || '') + '</td><td>' +
            esc(fmtDate(c.first_eta)) + '</td><td>' + esc(fmtDate(c.eta)) + '</td><td>' + c.pushes + '</td><td>' + c.days_past_first + '</td></tr>';
        }).join('') + '</table></div></div>';
    }
    return h;
  }

  function groupHtml(g) {
    var first = g.lines[0];
    var cols = C.canManage ? 10 : 9;
    var h = '<tbody class="ml-group" data-order="' + g.order_id + '">';
    g.lines.forEach(function (r, i) {
      var open = !!state.open[r.id];
      h += '<tr class="ml-row state-' + esc(r.state) + (open ? ' is-open' : '') + '" data-id="' + r.id + '">';
      if (i === 0) {
        var orderCell = r.edit_url ? '<a href="' + esc(r.edit_url) + '" target="_blank" rel="noopener">#' + r.order_id + '</a>' : '#' + r.order_id;
        var rs = ' rowspan="' + g.lines.length + '"';
        h += '<td' + rs + ' class="ml-trx">' + esc(r.trx || '—') + '</td>' +
             '<td' + rs + '>' + esc(r.po_number || (r.is_drop_ship ? '⚠ missing' : '—')) + '</td>' +
             '<td' + rs + '>' + orderCell + '</td>' +
             '<td' + rs + '>' + esc(r.store || '—') + '</td>';
      }
      var remaining = r.qty_ordered - r.qty_shipped;
      h += '<td class="ml-part"><button type="button" class="ml-expand" data-id="' + r.id + '" aria-expanded="' + open + '">' + (open ? '▾' : '▸') + '</button> ' + esc(r.part_label || '') + '</td>';
      h += '<td class="ml-qty">' + r.qty_shipped + ' / ' + r.qty_ordered + ' shipped' +
           (remaining > 0 && r.qty_shipped > 0 ? '<br><span class="ml-muted">' + remaining + ' remaining</span>' : '') +
           (r.tracking_count ? '<br><span class="ml-muted">' + r.tracking_count + ' tracking #</span>' : '') + '</td>';
      if (i === 0) {
        var rs2 = ' rowspan="' + g.lines.length + '"';
        h += '<td' + rs2 + '>' + etaCell(r) + '</td><td' + rs2 + '>' + contactCell(r) + '</td>';
      }
      h += '<td>' + stateCell(r) + '</td>';
      if (C.canManage && i === 0) {
        h += '<td rowspan="' + g.lines.length + '" class="ml-actions">' +
          '<button type="button" class="button" data-act="email" data-order="' + g.order_id + '">Log Email</button>' +
          '<button type="button" class="button" data-act="call" data-order="' + g.order_id + '">Log Call</button>' +
          '<button type="button" class="button button-primary" data-act="eta" data-order="' + g.order_id + '" data-id="' + first.id + '">Set ETA</button>' +
          '<button type="button" class="button" data-act="note" data-order="' + g.order_id + '">Note</button></td>';
      }
      h += '</tr>';
      if (open) h += '<tr class="ml-detail"><td colspan="' + cols + '"><div class="ml-history" data-for="' + r.id + '"><p class="ml-muted">Loading history…</p></div></td></tr>';
    });
    return h + '</tbody>';
  }

  function etaCell(r) {
    var h = '';
    if (r.eta) h += esc(fmtDate(r.eta));
    else h += r.logistics_status === 'backordered' ? '<span class="ml-warn">No ETA</span>' : '<span class="ml-muted">In stock</span>';
    if (r.overdue_bdays > 0 && r.actionable) h += '<br><span class="ml-late">' + r.overdue_bdays + ' bd overdue</span>';
    if (r.data_flags.indexOf('instock_future_date') >= 0) h += '<br><span class="ml-warn">Marked in stock but has a future date</span>';
    if (r.confirmed_at && r.confirmed_at_source === 'backfill_approx') {
      h += '<br><span class="ml-muted" title="Real confirmation time unknown — estimated from order creation (LEDGER_SPEC §4.2)">confirmed ≈ ' + esc(fmt(r.confirmed_at)) + ' (estimated)</span>';
    }
    return h;
  }

  function contactCell(r) {
    var p = [];
    if (r.last_email_at) p.push('Email ' + esc(fmt(r.last_email_at)));
    if (r.last_call_at) p.push('Call ' + esc(fmt(r.last_call_at)));
    return p.length ? p.join('<br>') : '<span class="ml-muted">—</span>';
  }

  function stateCell(r) {
    var h = '<span class="ml-badge b-' + esc(r.state) + '">' + esc(STATE_LABEL[r.state] || r.state) + '</span>';
    var reason = REASON[r.state_reason] || r.state_reason || '';
    if (r.state === 'CALL_REQUIRED' && r.last_email_at) reason = 'Email sent ' + fmt(r.last_email_at, true) + ' · no response after 1 business day';
    if (reason) h += '<div class="ml-reason">' + esc(reason) + '</div>';
    return h;
  }

  /* ---------------- history ---------------- */

  function loadHistory(id) {
    post('musesys_ledger_history', { ledger_id: id }).then(function (d) {
      var box = app.querySelector('.ml-history[data-for="' + id + '"]');
      if (box) box.innerHTML = historyHtml(d, id);
    }).catch(function (e) {
      var box = app.querySelector('.ml-history[data-for="' + id + '"]');
      if (box) box.innerHTML = '<p class="ml-error">' + esc(e.message) + '</p>';
    });
  }

  function historyHtml(d, id) {
    var h = '<div class="ml-hist-grid"><div><h4>Shipments on this line</h4>';
    if (!d.shipments.length) h += '<p class="ml-muted">None recorded.</p>';
    else h += '<ul class="ml-ship">' + d.shipments.map(function (s) {
      var carrier = s.carrier === 'OTHER' && s.other_carrier ? s.other_carrier : s.carrier;
      return '<li><strong>' + s.qty + '×</strong> ' + esc(carrier || 'No carrier') +
        (s.tracking_numbers.length ? ' · ' + esc(s.tracking_numbers.join(', ')) : '') +
        (s.date ? ' <span class="ml-muted">(' + esc(s.date) + ')</span>' : '') + '</li>';
    }).join('') + '</ul>';
    if (C.canManage) h += '<button type="button" class="button" data-act="line-note" data-id="' + id + '">Add note to this line</button>';
    h += '</div><div><h4>History</h4>';
    if (!d.events.length) h += '<p class="ml-muted">No events yet.</p>';
    else h += '<ol class="ml-events">' + d.events.map(eventHtml).join('') + '</ol>';
    return h + '</div></div>';
  }

  function eventHtml(e) {
    var p = e.payload || {}, detail = '';
    switch (e.action) {
      case 'email_logged': case 'call_logged': case 'note':
        detail = esc(p.text || '') + (p.outcome ? ' <span class="ml-muted">(' + esc(p.outcome) + ')</span>' : ''); break;
      case 'eta_set': case 'eta_changed':
        detail = esc((p.previous_eta ? fmtDate(p.previous_eta) : (p.previous_status || 'none'))) + ' → ' +
                 esc(p.new_eta ? fmtDate(p.new_eta) : (p.new_status || '')) +
                 (e.action === 'eta_set' && p.sc_expected ? ' · Service Channel update sent' : '') +
                 (p.notified_customer ? ' · customer emailed' : '') +
                 (p.eta_source ? '<br><span class="ml-muted">Basis: ' + esc(srcLabel(p.eta_source)) +
                   (p.eta_contact ? ' — spoke with ' + esc(p.eta_contact) : '') +
                   (p.eta_note ? ' — ' + esc(p.eta_note) : '') + '</span>' : ''); break;
      case 'trx_assigned':
        detail = esc(p.previous_trx || '—') + ' → ' + esc(p.new_trx || '—'); break;
      case 'shipment_recorded':
        detail = esc((p.qty ? p.qty + '× ' : '') + (p.carrier || '') + (p.tracking_numbers && p.tracking_numbers.length ? ' · ' + p.tracking_numbers.join(', ') : '')); break;
      case 'state_changed':
        detail = esc((p.from ? (STATE_LABEL[p.from] || p.from) + ' → ' : 'Entered ledger: ') + (STATE_LABEL[p.to] || p.to)) +
                 (p.reason ? ' <span class="ml-muted">' + esc(REASON[p.reason] || p.reason) + '</span>' : '') +
                 (p.cause === 'resolver_upgrade' ? ' <span class="ml-muted">(rule update)</span>' : '') +
                 (p.confirmed_at_approx ? ' <span class="ml-muted">(based on estimated confirmation time)</span>' : ''); break;
    }
    return '<li class="ev-' + esc(e.action) + '"><div class="ml-ev-head"><span>' + esc(fmt(e.created_at, true)) + '</span> <strong>' + esc(e.actor) + '</strong>' +
      (e.scope === 'order' ? ' <span class="ml-muted">order</span>' : '') + '</div>' +
      '<div class="ml-ev-title">' + esc(ACTION_LABEL[e.action] || e.action) + '</div>' + (detail ? '<div>' + detail + '</div>' : '') + '</li>';
  }

  /* ---------------- dialogs ---------------- */

  function dialog(html, onSubmit) {
    var wrap = document.createElement('div');
    wrap.className = 'ml-modal-wrap';
    wrap.innerHTML = '<form class="ml-modal" novalidate>' + html +
      '<div class="ml-modal-foot"><button type="button" class="button ml-cancel">Cancel</button> <button type="submit" class="button button-primary ml-submit">Save</button></div>' +
      '<p class="ml-error ml-modal-err" hidden></p></form>';
    document.body.appendChild(wrap);
    var form = wrap.querySelector('form');
    function close() { wrap.remove(); }
    wrap.querySelector('.ml-cancel').addEventListener('click', close);
    wrap.addEventListener('click', function (ev) { if (ev.target === wrap) close(); });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('.ml-submit');
      if (btn.disabled) return;
      btn.disabled = true; // double-click protection for human events (§3.3)
      onSubmit(form).then(function () {
        close();
        load(); // refresh rows and counts; the write response already reflects the re-resolved order
      }).catch(function (e) {
        btn.disabled = false;
        var err = form.querySelector('.ml-modal-err');
        err.hidden = false; err.textContent = e.message;
      });
    });
    var f = form.querySelector('textarea, input'); if (f) f.focus();
    return form;
  }

  function textDialog(kind, orderId, ledgerId) {
    var titles = { email: 'Log Email', call: 'Log Call', note: 'Note', 'line-note': 'Note on this line' };
    var helps = {
      email: 'Records that you emailed the vendor. Nothing is sent from here.',
      call: 'Records a vendor call. A call after the latest email clears “Call required”.',
      note: 'Notes never change the state of a line.', 'line-note': 'Notes never change the state of a line.'
    };
    var html = '<h2>' + esc(titles[kind]) + ' — Order #' + orderId + '</h2><p class="ml-muted">' + esc(helps[kind]) + '</p>' +
      (kind === 'call' ? '<label>Outcome <input type="text" name="outcome" maxlength="100" placeholder="e.g. left voicemail"></label>' : '') +
      '<label>' + (kind === 'email' ? 'Summary (optional)' : kind === 'call' ? 'Notes (optional)' : 'Note') +
      '<textarea name="text" rows="4" maxlength="2000"' + (kind.indexOf('note') >= 0 ? ' required' : '') + '></textarea></label>';
    var action = { email: 'musesys_ledger_log_email', call: 'musesys_ledger_log_call', note: 'musesys_ledger_note', 'line-note': 'musesys_ledger_note' }[kind];
    dialog(html, function (form) {
      var p = { order_id: orderId, text: form.text.value };
      if (form.outcome) p.outcome = form.outcome.value;
      if (ledgerId) p.ledger_id = ledgerId;
      return post(action, p).then(function (d) { toast(titles[kind] + ' saved'); return d; });
    });
  }

  function etaDialog(orderId, ledgerId) {
    post('musesys_ledger_history', { ledger_id: ledgerId }).then(function (d) {
      var sc = d.sc;
      var html = '<h2>Set ETA — Order #' + orderId + '</h2>' +
        '<label>New ETA <input type="date" name="eta" min="' + esc(C.today) + '" required></label>' +
        '<label>Where did this date come from? <select name="eta_source" required><option value="">Select…</option>' +
          Object.keys((state.data && state.data.eta_sources) || {}).map(function (k) {
            return '<option value="' + esc(k) + '">' + esc(srcLabel(k)) + '</option>';
          }).join('') + '</select></label>' +
        '<label class="ml-basis-contact" hidden>Spoke with <input type="text" name="eta_contact" maxlength="100" placeholder="Name at the vendor"></label>' +
        '<label>Note <span class="ml-muted ml-basis-hint">(optional)</span><textarea name="eta_note" rows="2" maxlength="500"></textarea></label>' +
        '<p class="ml-muted">Applies to all open lines on this order (' + d.open_lines + ' line' + (d.open_lines === 1 ? '' : 's') + ').</p>' +
        '<div class="ml-effects"><strong>What happens when you save</strong><ul class="ml-sc"></ul></div>' +
        '<label class="ml-check"><input type="checkbox" name="notify_customer" value="1"> Also email the customer contact (billing email) about this update</label>';
      var form = dialog(html, function (f) {
        return post('musesys_ledger_set_eta', {
          order_id: orderId, eta: f.eta.value, notify_customer: f.notify_customer.checked ? '1' : '0',
          eta_source: f.eta_source.value, eta_contact: f.eta_contact.value, eta_note: f.eta_note.value
        })
          .then(function (r) { toast('ETA updated'); return r; });
      });
      form.querySelector('.ml-submit').textContent = 'Update ETA';
      function effects() {
        var date = form.eta.value, ul = form.querySelector('.ml-sc');
        if (!sc.has_sc_work_order) {
          ul.innerHTML = '<li>No Service Channel work order on this order, so no Service Channel update will be sent.</li>';
        } else if (date && (sc.sc_hash === 'backordered|' + date || (sc.logistics_status === 'backordered' && sc.logistics_date === date))) {
          ul.innerHTML = '<li>Service Channel already has this date, so no new Service Channel update will be sent.</li>';
        } else {
          ul.innerHTML = '<li>Service Channel will be sent a backorder update with this date.<br><span class="ml-muted">Automatic for orders with a Service Channel work order. Same as UACP.</span></li>';
        }
      }
      function basis() {
        var v = form.eta_source.value;
        form.querySelector('.ml-basis-contact').hidden = v !== 'vendor_call';
        form.querySelector('.ml-basis-hint').textContent = v === 'estimate' ? '(required — what is this based on?)' : '(optional)';
      }
      form.eta_source.addEventListener('change', basis);
      form.eta.addEventListener('input', effects);
      effects();
    }).catch(function (e) { toast(e.message, true); });
  }

  /* ---------------- events ---------------- */

  app.addEventListener('click', function (ev) {
    var t = ev.target.closest('button');
    if (!t) return;
    if (t.dataset.tab) { state.tab = t.dataset.tab; render(); return; }
    if (t.classList.contains('ml-expand')) {
      var id = +t.dataset.id;
      state.open[id] = !state.open[id];
      render(); // also loads history for every open row
      return;
    }
    var act = t.dataset.act;
    if (!act) return;
    var oid = +t.dataset.order;
    if (act === 'eta') etaDialog(oid, +t.dataset.id);
    else if (act === 'line-note') {
      var row = state.data.rows.filter(function (r) { return r.id === +t.dataset.id; })[0];
      if (row) textDialog('line-note', row.order_id, row.id);
    } else textDialog(act, oid);
  });

  // Re-render open histories after a reload so they stay expanded.
  var _render = render;
  render = function () {
    _render();
    Object.keys(state.open).forEach(function (id) { if (state.open[id]) loadHistory(+id); });
  };

  load();
  setInterval(function () { if (!document.querySelector('.ml-modal-wrap')) load(); }, 5 * 60 * 1000);
})();
