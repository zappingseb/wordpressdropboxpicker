/* SEW Dropbox to NextGEN Picker -- picker page logic (vanilla JS, no jQuery). */
(function () {
  'use strict';
  if (typeof SEW_DNP === 'undefined') { return; }

  var $ = function (id) { return document.getElementById(id); };
  var el = {
    folder: $('sew-dnp-folder'), browse: $('sew-dnp-browse'), recursive: $('sew-dnp-recursive'),
    browser: $('sew-dnp-browser'), crumbs: $('sew-dnp-crumbs'), browserList: $('sew-dnp-browser-list'),
    browserClose: $('sew-dnp-browser-close'), browserUse: $('sew-dnp-browser-use'),
    from: $('sew-dnp-from'), to: $('sew-dnp-to'), columns: $('sew-dnp-columns'),
    load: $('sew-dnp-load'), loadHint: $('sew-dnp-load-hint'),
    status: $('sew-dnp-status'), gridbar: $('sew-dnp-gridbar'), count: $('sew-dnp-count'),
    selectAll: $('sew-dnp-select-all'), selectNone: $('sew-dnp-select-none'), grid: $('sew-dnp-grid'),
    selectedCount: $('sew-dnp-selected-count'), selected: $('sew-dnp-selected'),
    galleryName: $('sew-dnp-gallery-name'), slug: $('sew-dnp-slug'), create: $('sew-dnp-create'),
    suggest: $('sew-dnp-gallery-suggest'), chip: $('sew-dnp-gallery-chip'), chipLabel: $('sew-dnp-gallery-chip-label'),
    chipRemove: $('sew-dnp-gallery-chip-remove'), galleryNotice: $('sew-dnp-gallery-notice'),
    progress: $('sew-dnp-progress'), progressFill: $('sew-dnp-progress-fill'), progressText: $('sew-dnp-progress-text'),
    log: $('sew-dnp-log'), cancel: $('sew-dnp-cancel'), result: $('sew-dnp-result'),
    modal: $('sew-dnp-modal'), modalBody: $('sew-dnp-modal-body'), modalCancel: $('sew-dnp-modal-cancel'), modalOk: $('sew-dnp-modal-ok')
  };

  var state = {
    files: [],            // sorted by taken_ts
    byPath: {},           // path_lower -> file
    thumbs: {},           // path_lower -> data URI
    selected: [],         // path_lower[] in grid order
    anchor: -1,           // index of last plain click, for shift ranges
    browsePath: '',
    targetGallery: null,  // existing NGG gallery to add to, or null to create a new one
    importing: false,
    cancelRequested: false
  };

  // ------------------------------------------------------------ utilities
  function ajax(action, data) {
    var body = new FormData();
    body.append('action', 'sew_dnp_' + action);
    body.append('nonce', SEW_DNP.nonce);
    Object.keys(data || {}).forEach(function (key) {
      var value = data[key];
      if (Array.isArray(value)) { value.forEach(function (v) { body.append(key + '[]', v); }); }
      else if (value !== undefined && value !== null) { body.append(key, value); }
    });
    return fetch(SEW_DNP.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) {
        return response.text().then(function (text) {
          var json;
          try { json = JSON.parse(text); } catch (e) {
            throw new Error('Server returned no JSON (HTTP ' + response.status + '): ' + text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
          }
          if (!json || !json.success) {
            var message = json && json.data && json.data.message ? json.data.message : 'Request failed (HTTP ' + response.status + ')';
            throw new Error(message);
          }
          return json.data;
        });
      });
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function slugify(text) {
    var map = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'Ä': 'ae', 'Ö': 'oe', 'Ü': 'ue', 'ß': 'ss', 'æ': 'ae', 'ø': 'oe', 'å': 'aa', 'đ': 'd', 'ł': 'l', '&': ' und ' };
    text = String(text).replace(/[äöüÄÖÜßæøåđł&]/g, function (c) { return map[c]; });
    text = text.normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
    return text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/-{2,}/g, '-').replace(/^-+|-+$/g, '');
  }

  function formatBytes(bytes) {
    if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + ' MB'; }
    if (bytes >= 1024) { return Math.round(bytes / 1024) + ' KB'; }
    return bytes + ' B';
  }

  function setStatus(kind, html, spinner) {
    el.status.hidden = false;
    el.status.dataset.kind = kind;
    el.status.innerHTML = (spinner ? '<span class="spinner is-active"></span>' : '') + html;
  }
  function hideStatus() { el.status.hidden = true; }

  function store() {
    try {
      localStorage.setItem('sew_dnp_state', JSON.stringify({
        folder: el.folder.value, recursive: el.recursive.checked, from: el.from.value, to: el.to.value, columns: el.columns.value
      }));
    } catch (e) { /* private mode etc. */ }
  }
  function restore() {
    try {
      var saved = JSON.parse(localStorage.getItem('sew_dnp_state') || 'null');
      if (!saved) { return; }
      if (saved.folder) { el.folder.value = saved.folder; }
      el.recursive.checked = !!saved.recursive;
      if (saved.from) { el.from.value = saved.from; }
      if (saved.to) { el.to.value = saved.to; }
      if (saved.columns) { el.columns.value = saved.columns; }
    } catch (e) { /* ignore */ }
  }

  // ------------------------------------------------------------ toolbar validation
  function normalizeFolder(value) {
    value = String(value || '').trim().replace(/\\/g, '/');
    if (value === '' || value === '/') { return ''; }
    if (value[0] !== '/') { value = '/' + value; }
    return value.replace(/\/+$/, '');
  }

  function validate() {
    var folder = normalizeFolder(el.folder.value);
    var from = el.from.value, to = el.to.value;
    var problems = [];
    if (!el.folder.value.trim()) { problems.push('choose a folder'); }
    if (!from || !to) { problems.push('set both dates'); }
    else if (from > to) { problems.push('FROM must not be after TO'); }
    el.load.disabled = problems.length > 0 || !SEW_DNP.connected || state.importing;
    el.loadHint.textContent = problems.length ? 'Please ' + problems.join(', ') + '.' : (folder === '' ? 'Root folder of your Dropbox -- this can be slow.' : '');
    store();
  }
  ['input', 'change'].forEach(function (evt) {
    el.folder.addEventListener(evt, validate);
    el.from.addEventListener(evt, validate);
    el.to.addEventListener(evt, validate);
    el.recursive.addEventListener(evt, store);
  });
  el.columns.addEventListener('change', function () {
    el.grid.style.setProperty('--sew-dnp-cols', el.columns.value);
    store();
  });

  // ------------------------------------------------------------ folder browser
  function openBrowser() {
    el.browser.hidden = false;
    browseTo(normalizeFolder(el.folder.value));
  }
  function closeBrowser() { el.browser.hidden = true; }

  function renderCrumbs(path) {
    var parts = path ? path.split('/').filter(Boolean) : [];
    var html = '<a href="#" data-path="">Dropbox</a>';
    var acc = '';
    parts.forEach(function (part) {
      acc += '/' + part;
      html += ' / <a href="#" data-path="' + escapeHtml(acc) + '">' + escapeHtml(part) + '</a>';
    });
    el.crumbs.innerHTML = html;
  }

  function browseTo(path) {
    state.browsePath = path;
    renderCrumbs(path);
    el.browserList.innerHTML = '<li class="sew-dnp-muted">Loading&hellip;</li>';
    ajax('folders', { path: path }).then(function (data) {
      state.browsePath = data.path;
      if (!data.folders.length) {
        el.browserList.innerHTML = '<li class="sew-dnp-muted">No subfolders' + (data.files ? ' (' + data.files + ' files here)' : '') + '.</li>';
        return;
      }
      el.browserList.innerHTML = data.folders.map(function (folder) {
        return '<li><button type="button" data-path="' + escapeHtml(folder.path_display) + '"><span class="dashicons dashicons-category"></span>' + escapeHtml(folder.name) + '</button></li>';
      }).join('');
    }).catch(function (err) {
      el.browserList.innerHTML = '<li class="sew-dnp-muted" style="color:#d63638">' + escapeHtml(err.message) + '</li>';
    });
  }

  el.browse.addEventListener('click', function () { if (el.browser.hidden) { openBrowser(); } else { closeBrowser(); } });
  el.browserClose.addEventListener('click', closeBrowser);
  el.crumbs.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-path]');
    if (!a) { return; }
    e.preventDefault();
    browseTo(a.dataset.path);
  });
  el.browserList.addEventListener('click', function (e) {
    var button = e.target.closest('button[data-path]');
    if (button) { browseTo(button.dataset.path); }
  });
  el.browserUse.addEventListener('click', function () {
    el.folder.value = state.browsePath || '/';
    closeBrowser();
    validate();
  });
  document.addEventListener('click', function (e) {
    if (!el.browser.hidden && !el.browser.contains(e.target) && e.target !== el.browse) { closeBrowser(); }
  });

  // ------------------------------------------------------------ scanning
  el.load.addEventListener('click', loadPictures);

  function loadPictures() {
    var folder = normalizeFolder(el.folder.value);
    var from = el.from.value, to = el.to.value, recursive = el.recursive.checked ? '1' : '0';
    state.files = []; state.byPath = {}; state.thumbs = {}; state.selected = []; state.anchor = -1;
    el.grid.innerHTML = ''; el.gridbar.hidden = true; el.result.hidden = true;
    renderSelected();
    el.load.disabled = true;
    var scanned = 0, matches = [], skipped = {}, pages = 0;
    setStatus('info', 'Scanning <code>' + escapeHtml(folder || '/') + '</code>' + (recursive === '1' ? ' and subfolders' : '') + ' &hellip;', true);

    function page(cursor) {
      return ajax('scan', { path: folder, cursor: cursor || '', recursive: recursive, from: from, to: to }).then(function (data) {
        pages++;
        scanned += data.scanned;
        matches = matches.concat(data.entries);
        Object.keys(data.skipped || {}).forEach(function (ext) { skipped[ext] = (skipped[ext] || 0) + data.skipped[ext]; });
        setStatus('info', 'Dropbox search <code>' + escapeHtml(data.query || '') + '</code>: ' + scanned + ' candidates, ' + matches.length + ' photos match ' + from + ' &ndash; ' + to + ' (page ' + pages + ')&hellip;', true);
        if (data.has_more && data.cursor) { return page(data.cursor); }
      });
    }

    page('').then(function () {
      matches.sort(function (a, b) { return a.taken_ts - b.taken_ts || a.name.localeCompare(b.name); });
      state.files = matches;
      matches.forEach(function (file) { state.byPath[file.path_lower] = file; });
      var skippedNote = Object.keys(skipped).length ? ' Skipped non-image files: ' + Object.keys(skipped).sort().map(function (ext) { return ext + ' &times;' + skipped[ext]; }).join(', ') + '.' : '';
      if (!matches.length) {
        setStatus('ok', 'Scanned ' + scanned + ' files; no photos between ' + from + ' and ' + to + '.' + skippedNote, false);
        el.load.disabled = false;
        return;
      }
      var exif = matches.filter(function (f) { return f.taken_source === 'exif'; }).length;
      var dateNote = exif ? ' (' + exif + ' dated by EXIF, ' + (matches.length - exif) + ' by file time)' : ' (dated by Dropbox file time)';
      setStatus('ok', matches.length + ' photo' + (matches.length === 1 ? '' : 's') + ' between ' + from + ' and ' + to + dateNote + ', ' + scanned + ' search candidates.' + skippedNote, false);
      renderGrid();
      loadThumbs(matches.map(function (f) { return f.path_lower; }));
      el.load.disabled = false;
    }).catch(function (err) {
      setStatus('error', escapeHtml(err.message), false);
      el.load.disabled = false;
    });
  }

  function loadThumbs(paths) {
    var queue = paths.slice();
    var batchSize = SEW_DNP.thumbBatch || 25;
    var inFlight = 0, done = 0, total = paths.length;
    function next() {
      while (inFlight < 4 && queue.length) {
        var batch = queue.splice(0, batchSize);
        inFlight++;
        ajax('thumbs', { paths: batch }).then(function (data) {
          Object.keys(data.thumbs).forEach(function (path) {
            state.thumbs[path] = data.thumbs[path];
            applyThumb(path);
          });
          batch.forEach(function (path) { if (!data.thumbs[path]) { markNoThumb(path); } });
        }).catch(function (err) {
          batch.forEach(markNoThumb);
          setStatus('error', 'Thumbnails: ' + escapeHtml(err.message), false);
        }).then(function () {
          inFlight--; done += batch.length;
          updateCount(done < total ? ' &middot; loading thumbnails ' + done + '/' + total : '');
          next();
        });
      }
    }
    next();
  }

  // ------------------------------------------------------------ grid
  function renderGrid() {
    el.gridbar.hidden = false;
    el.grid.style.setProperty('--sew-dnp-cols', el.columns.value);
    el.grid.innerHTML = state.files.map(function (file, index) {
      return '<div class="sew-dnp-tile" data-index="' + index + '" data-path="' + escapeHtml(file.path_lower) + '" title="' + escapeHtml(file.path_display + '\n' + file.taken) + '">' +
        '<img class="sew-dnp-thumb is-loading" alt="" draggable="false">' +
        '<span class="sew-dnp-tick">&#10003;</span>' +
        '<div class="sew-dnp-caption">' + escapeHtml(file.name) + '<br><small>' + escapeHtml(file.taken.slice(0, 16)) + ' &middot; ' + formatBytes(file.size) + (file.width ? ' &middot; ' + file.width + '&times;' + file.height : '') + '</small></div>' +
        '</div>';
    }).join('');
    updateCount('');
  }

  function tileFor(path) { return el.grid.querySelector('.sew-dnp-tile[data-path="' + path.replace(/"/g, '\\"') + '"]'); }
  function applyThumb(path) {
    var tile = tileFor(path);
    if (!tile) { return; }
    var img = tile.querySelector('img');
    img.src = state.thumbs[path];
    img.classList.remove('is-loading');
    var row = el.selected.querySelector('li[data-path="' + path.replace(/"/g, '\\"') + '"] img');
    if (row) { row.src = state.thumbs[path]; }
  }
  function markNoThumb(path) {
    var tile = tileFor(path);
    if (!tile) { return; }
    tile.querySelector('img').classList.remove('is-loading');
    if (!tile.querySelector('.sew-dnp-nothumb')) {
      var note = document.createElement('div');
      note.className = 'sew-dnp-nothumb';
      note.textContent = 'no preview';
      tile.appendChild(note);
    }
  }
  function updateCount(extra) {
    el.count.innerHTML = '<strong>' + state.files.length + '</strong> photos, <strong>' + state.selected.length + '</strong> selected' + (extra || '');
  }

  el.grid.addEventListener('click', function (e) {
    var tile = e.target.closest('.sew-dnp-tile');
    if (!tile || state.importing) { return; }
    var index = parseInt(tile.dataset.index, 10);
    if (e.shiftKey && state.anchor >= 0) {
      var lo = Math.min(state.anchor, index), hi = Math.max(state.anchor, index);
      for (var i = lo; i <= hi; i++) { select(state.files[i].path_lower, true); }
    } else {
      toggle(state.files[index].path_lower);
      state.anchor = index;
    }
    renderSelected();
  });
  // Shift+click on images would otherwise start a text selection in some browsers.
  el.grid.addEventListener('mousedown', function (e) { if (e.shiftKey) { e.preventDefault(); } });

  function select(path, on) {
    var pos = state.selected.indexOf(path);
    if (on && pos === -1) { state.selected.push(path); }
    if (!on && pos !== -1) { state.selected.splice(pos, 1); }
    var tile = tileFor(path);
    if (tile) { tile.classList.toggle('is-selected', on); }
  }
  function toggle(path) { select(path, state.selected.indexOf(path) === -1); }

  el.selectAll.addEventListener('click', function () {
    if (state.importing) { return; }
    state.files.forEach(function (f) { select(f.path_lower, true); });
    renderSelected();
  });
  el.selectNone.addEventListener('click', function () {
    if (state.importing) { return; }
    state.selected.slice().forEach(function (p) { select(p, false); });
    renderSelected();
  });

  // ------------------------------------------------------------ right panel
  function orderedSelection() {
    var order = {};
    state.files.forEach(function (f, i) { order[f.path_lower] = i; });
    return state.selected.slice().sort(function (a, b) { return order[a] - order[b]; });
  }

  function renderSelected() {
    var items = orderedSelection();
    el.selectedCount.textContent = items.length;
    updateCount('');
    if (!items.length) {
      el.selected.innerHTML = '<li class="sew-dnp-muted">Nothing selected yet.</li>';
    } else {
      el.selected.innerHTML = items.map(function (path) {
        var file = state.byPath[path];
        return '<li data-path="' + escapeHtml(path) + '">' +
          '<img src="' + (state.thumbs[path] || '') + '" alt="">' +
          '<span class="sew-dnp-sel-meta"><span class="sew-dnp-sel-name" title="' + escapeHtml(file.path_display) + '">' + escapeHtml(file.name) + '</span>' +
          '<span class="sew-dnp-sel-date">' + escapeHtml(file.taken.slice(0, 16)) + '</span><span class="sew-dnp-sel-state"></span></span>' +
          '<button type="button" class="sew-dnp-sel-remove" title="Remove" data-path="' + escapeHtml(path) + '">&times;</button>' +
          '</li>';
      }).join('');
    }
    updateCreateButton();
  }

  el.selected.addEventListener('click', function (e) {
    var button = e.target.closest('.sew-dnp-sel-remove');
    if (!button || state.importing) { return; }
    select(button.dataset.path, false);
    renderSelected();
  });

  function updateCreateButton() {
    if (state.targetGallery) {
      el.slug.textContent = state.targetGallery.path + ' (' + state.targetGallery.images + ' pictures now)';
      el.create.textContent = 'Add to NGG gallery';
      el.create.disabled = state.importing || !state.selected.length;
      return;
    }
    var galleryName = el.galleryName.value.trim();
    var slug = slugify(galleryName);
    el.slug.textContent = SEW_DNP.galleryBase + '/' + (slug || '…');
    el.create.textContent = 'Create NGG gallery';
    el.create.disabled = state.importing || !state.selected.length || !slug;
  }

  // -- existing-gallery suggestions (type to search NextGEN galleries) --------
  var suggestTimer = null, suggestSeq = 0;
  function hideSuggest() { el.suggest.hidden = true; el.suggest.innerHTML = ''; el.galleryName.setAttribute('aria-expanded', 'false'); }
  function showSuggest(galleries, q) {
    if (!galleries.length) { hideSuggest(); return; }
    el.suggest.innerHTML = '<li class="sew-dnp-suggest-head">Existing galleries' + (q ? ' matching “' + escapeHtml(q) + '”' : '') + ' — click to add to one</li>' +
      galleries.map(function (g) {
        return '<li role="option"><button type="button" data-id="' + g.id + '">' +
          '<span class="sew-dnp-suggest-title">' + escapeHtml(g.title || g.name) + '</span>' +
          '<span class="sew-dnp-suggest-meta">' + escapeHtml(g.path) + ' · ' + g.images + ' pictures · id ' + g.id + '</span>' +
          '</button></li>';
      }).join('');
    el.suggest.hidden = false;
    el.galleryName.setAttribute('aria-expanded', 'true');
    el.suggest._galleries = galleries;
  }
  function searchGalleries() {
    var q = el.galleryName.value.trim();
    var seq = ++suggestSeq;
    ajax('galleries', { q: q }).then(function (data) {
      if (seq !== suggestSeq || document.activeElement !== el.galleryName) { return; }
      showSuggest(data.galleries || [], q);
    }).catch(function () { hideSuggest(); });
  }
  function chooseGallery(g) {
    state.targetGallery = g;
    hideSuggest();
    el.galleryName.hidden = true;
    el.chipLabel.textContent = (g.title || g.name) + ' (' + g.images + ')';
    el.chip.hidden = false;
    el.galleryNotice.hidden = false;
    updateCreateButton();
  }
  function clearGallery(focus) {
    state.targetGallery = null;
    el.chip.hidden = true;
    el.galleryNotice.hidden = true;
    el.galleryName.hidden = false;
    updateCreateButton();
    if (focus) { el.galleryName.focus(); }
  }
  el.galleryName.addEventListener('input', function () {
    updateCreateButton();
    clearTimeout(suggestTimer);
    suggestTimer = setTimeout(searchGalleries, 250);
  });
  el.galleryName.addEventListener('focus', function () { clearTimeout(suggestTimer); suggestTimer = setTimeout(searchGalleries, 150); });
  el.galleryName.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { hideSuggest(); }
    if (e.key === 'ArrowDown' && !el.suggest.hidden) { var first = el.suggest.querySelector('button'); if (first) { e.preventDefault(); first.focus(); } }
  });
  el.suggest.addEventListener('keydown', function (e) {
    var buttons = Array.prototype.slice.call(el.suggest.querySelectorAll('button'));
    var i = buttons.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' && i < buttons.length - 1) { e.preventDefault(); buttons[i + 1].focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); if (i > 0) { buttons[i - 1].focus(); } else { el.galleryName.focus(); } }
    if (e.key === 'Escape') { hideSuggest(); el.galleryName.focus(); }
  });
  el.suggest.addEventListener('click', function (e) {
    var button = e.target.closest('button[data-id]');
    if (!button || state.importing) { return; }
    var id = parseInt(button.dataset.id, 10);
    var g = (el.suggest._galleries || []).filter(function (x) { return x.id === id; })[0];
    if (g) { chooseGallery(g); }
  });
  el.chipRemove.addEventListener('click', function () { if (!state.importing) { clearGallery(true); } });
  document.addEventListener('click', function (e) {
    if (!el.suggest.hidden && !el.suggest.contains(e.target) && e.target !== el.galleryName) { hideSuggest(); }
  });

  // ------------------------------------------------------------ confirm + import
  el.create.addEventListener('click', function () {
    var items = orderedSelection();
    var target = state.targetGallery;
    var galleryName = target ? (target.title || target.name) : el.galleryName.value.trim();
    var totalBytes = items.reduce(function (sum, p) { return sum + state.byPath[p].size; }, 0);
    $('sew-dnp-modal-title').textContent = target ? 'Add to this existing gallery?' : 'Create this gallery?';
    el.modalOk.textContent = target ? 'Yes, add pictures' : 'Yes, create gallery';
    el.modalBody.innerHTML = (target ? '<p class="sew-dnp-notice"><span class="dashicons dashicons-warning"></span> You will add pictures to an existing gallery, not create a new one.</p>' : '') + '<dl>' +
      '<dt>Gallery</dt><dd><strong>' + escapeHtml(galleryName) + '</strong>' + (target ? ' (id ' + target.id + ', ' + target.images + ' pictures now)' : '') + '</dd>' +
      '<dt>Folder</dt><dd><code>' + escapeHtml(target ? target.path : SEW_DNP.galleryBase + '/' + slugify(galleryName)) + '</code></dd>' +
      '<dt>Pictures</dt><dd>' + items.length + ' (' + formatBytes(totalBytes) + ' in Dropbox)</dd>' +
      '<dt>Processing</dt><dd>download, resize to max ' + SEW_DNP.maxDim + ' px and ' + SEW_DNP.maxKb + ' KB, register in NextGEN, build thumbnails</dd>' +
      '</dl><p>This writes files to the server and ' + (target ? 'extends the NextGEN gallery' : 'creates a NextGEN gallery') + '. Continue?</p>';
    el.modal.hidden = false;
    el.modalOk.focus();
  });
  el.modalCancel.addEventListener('click', function () { el.modal.hidden = true; });
  el.modal.addEventListener('click', function (e) { if (e.target === el.modal) { el.modal.hidden = true; } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { el.modal.hidden = true; closeBrowser(); } });
  el.modalOk.addEventListener('click', function () {
    el.modal.hidden = true;
    runImport();
  });
  el.cancel.addEventListener('click', function () {
    state.cancelRequested = true;
    el.cancel.disabled = true;
    log('Cancel requested -- finishing the current image, then stopping.', 'error');
  });

  function log(text, kind) {
    var li = document.createElement('li');
    li.textContent = text;
    if (kind) { li.className = 'is-' + kind; }
    el.log.appendChild(li);
    el.log.scrollTop = el.log.scrollHeight;
  }
  function setProgress(done, total, text) {
    el.progressFill.style.width = total ? Math.round(100 * done / total) + '%' : '0%';
    el.progressText.textContent = text;
  }
  function setRowState(path, stateName, note) {
    var row = el.selected.querySelector('li[data-path="' + path.replace(/"/g, '\\"') + '"]');
    if (!row) { return; }
    row.dataset.state = stateName;
    row.querySelector('.sew-dnp-sel-state').textContent = note ? ' · ' + note : '';
  }

  function runImport() {
    var items = orderedSelection();
    var target = state.targetGallery;
    var name = target ? (target.title || target.name) : el.galleryName.value.trim();
    if (!items.length || !name) { return; }
    state.importing = true; state.cancelRequested = false;
    el.create.disabled = true; el.load.disabled = true; el.galleryName.disabled = true; el.chipRemove.disabled = true;
    el.progress.hidden = false; el.cancel.hidden = false; el.cancel.disabled = false; el.result.hidden = true;
    el.log.innerHTML = '';
    var total = items.length + 2, done = 0, failures = [], slug = null, sizeBefore = 0, sizeAfter = 0, startIndex = 0;
    setProgress(0, total, target ? 'Opening gallery folder…' : 'Creating gallery folder…');

    ajax('gallery_create', target ? { gallery_id: target.id } : { name: name }).then(function (data) {
      slug = data.slug;
      startIndex = data.start_index || 0;
      done++;
      log((data.existing ? 'Adding to existing gallery "' + data.title + '" (id ' + data.gallery_id + ', ' + data.images + ' pictures) in ' + data.path : 'Folder ' + data.path + ' created') + ' (image editor: ' + (data.editor || '?') + ').', 'ok');
      setProgress(done, total, 'Downloading and resizing 1/' + items.length + '…');

      var cursor = 0, active = 0, concurrency = 2;
      return new Promise(function (resolve) {
        function pump() {
          if (state.cancelRequested && active === 0) { return resolve(); }
          while (!state.cancelRequested && active < concurrency && cursor < items.length) {
            (function (index) {
              var path = items[index];
              var file = state.byPath[path];
              active++;
              setRowState(path, 'working', 'processing');
              ajax('gallery_add', { slug: slug, path: file.path_display, name: file.name, index: startIndex + index + 1, width: file.width, height: file.height }).then(function (meta) {
                sizeBefore += file.size; sizeAfter += meta.bytes;
                setRowState(path, 'done', meta.width + '×' + meta.height + ', ' + formatBytes(meta.bytes));
                log((index + 1) + '/' + items.length + ' ' + file.name + ' → ' + meta.file + ' ' + meta.width + 'x' + meta.height + ' ' + formatBytes(meta.bytes) + ' q' + meta.quality + (meta.source !== 'original' ? ' (via ' + meta.source + ')' : '') + (meta.over_budget ? ' OVER 500 KB' : ''));
              }).catch(function (err) {
                failures.push(file.name + ': ' + err.message);
                setRowState(path, 'failed', 'failed');
                log((index + 1) + '/' + items.length + ' ' + file.name + ' FAILED: ' + err.message, 'error');
              }).then(function () {
                active--; done++;
                setProgress(done, total, 'Downloading and resizing ' + Math.min(cursor + 1, items.length) + '/' + items.length + '…');
                if (cursor >= items.length && active === 0) { resolve(); } else { pump(); }
              });
            })(cursor);
            cursor++;
          }
          if (cursor >= items.length && active === 0) { resolve(); }
        }
        pump();
      });
    }).then(function () {
      if (state.cancelRequested) {
        log('Stopped by user. Files already processed remain in ' + SEW_DNP.galleryBase + '/' + slug + ' (not registered with NextGEN).', 'error');
        throw new Error('Import cancelled after ' + (done - 1) + ' image(s).');
      }
      if (done - 1 - failures.length === 0) {
        throw new Error('No image could be processed; nothing to register.');
      }
      setProgress(done, total, 'Registering the gallery with NextGEN and building thumbnails…');
      return ajax('gallery_finish', { slug: slug, title: name });
    }).then(function (result) {
      done++;
      setProgress(total, total, 'Done.');
      log('NextGEN gallery #' + result.gallery_id + (result.existing ? ': ' + result.added + ' added, now ' : ' with ') + result.images + ' image(s); thumbnails: ' + result.thumbnails.generated + '/' + result.thumbnails.total + ' (' + result.thumbnails.size + ').', 'ok');
      if (result.thumbnails.failed && result.thumbnails.failed.length) { log('Thumbnail problems: ' + result.thumbnails.failed.join('; '), 'error'); }
      el.result.hidden = false;
      el.result.dataset.kind = failures.length ? 'error' : 'ok';
      el.result.innerHTML = '<strong>' + (result.existing ? 'Pictures added to gallery:' : 'Gallery created:') + '</strong> ' + escapeHtml(name) + ' (id ' + result.gallery_id + ', ' + (result.existing ? result.added + ' added, now ' : '') + result.images + ' images, ' + formatBytes(sizeBefore) + ' → ' + formatBytes(sizeAfter) + ')' +
        (failures.length ? '<br><strong style="color:#d63638">' + failures.length + ' image(s) failed</strong> -- see the log.' : '') +
        '<br><a class="button button-primary" style="margin-top:8px" href="' + escapeHtml(result.manage_url) + '" target="_blank" rel="noopener">Open in NextGEN</a>' +
        '<code>' + escapeHtml(result.shortcode) + '</code>';
    }).catch(function (err) {
      log(err.message, 'error');
      el.result.hidden = false;
      el.result.dataset.kind = 'error';
      el.result.innerHTML = '<strong>Import did not complete:</strong> ' + escapeHtml(err.message) + (slug ? '<br>Files so far are in <code>' + escapeHtml(SEW_DNP.galleryBase + '/' + slug) + '</code>.' : '');
    }).then(function () {
      state.importing = false;
      el.cancel.hidden = true;
      el.galleryName.disabled = false;
      el.chipRemove.disabled = false;
      if (target) { target.images += Math.max(0, done - 1 - failures.length); el.chipLabel.textContent = (target.title || target.name) + ' (' + target.images + ')'; }
      validate();
      updateCreateButton();
    });
  }

  // ------------------------------------------------------------ boot
  restore();
  el.grid.style.setProperty('--sew-dnp-cols', el.columns.value);
  validate();
  updateCreateButton();
  if (SEW_DNP.connected) {
    ajax('status', {}).then(function (data) {
      if (data.connected) {
        setStatus('ok', 'Connected to Dropbox as <strong>' + escapeHtml(data.account) + '</strong>. Choose a folder and a date range, then load pictures.', false);
      }
    }).catch(function (err) {
      SEW_DNP.connected = false;
      validate();
      setStatus('error', 'Dropbox connection failed: ' + escapeHtml(err.message) + ' <a href="' + escapeHtml(SEW_DNP.settingsUrl) + '">Settings</a>', false);
    });
  }
})();
