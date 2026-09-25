/* Portfolio QR : interface de gestion. JavaScript sans dépendance hors SortableJS et qrcode-generator. */
(() => {
  'use strict';

  const app = document.getElementById('app');
  const BASE = app.dataset.base || '';
  const API = BASE + '/admin/api';
  const MAX_EDGE = 3000;       // redimensionnement dans le navigateur avant envoi
  const JPEG_QUALITY = 0.92;

  let state = null;            // { store, urls, maxUpload }
  let sortables = [];

  /* ---------- Outils ---------- */

  function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === false || v == null) continue;
      if (k === 'class') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'text') el.textContent = v;
      else if (v === true) el.setAttribute(k, '');
      else el.setAttribute(k, v);
    }
    for (const c of children.flat()) {
      if (c == null || c === false) continue;
      el.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return el;
  }

  function toast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show' + (isError ? ' error' : '');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => (t.className = ''), isError ? 5000 : 2200);
  }

  async function api(action, body, { form = false, silent = false } = {}) {
    const opts = { method: body === undefined ? 'GET' : 'POST', headers: { 'X-Portfolio': '1' }, credentials: 'same-origin' };
    if (body !== undefined) {
      if (form) opts.body = body;
      else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
    }
    let res, data;
    try {
      res = await fetch(`${API}?action=${encodeURIComponent(action)}`, opts);
      data = await res.json();
    } catch {
      throw new Error('Connexion impossible. Vérifie le réseau.');
    }
    if (res.status === 401 && !['login', 'setup'].includes(action)) {
      state = null;
      route();
    }
    if (!res.ok) throw new Error(data.error || 'Erreur');
    if (data.store) state = data;
    return data;
  }

  async function run(action, body, okMsg) {
    try {
      await api(action, body);
      if (okMsg) toast(okMsg);
      render();
      return true;
    } catch (e) {
      toast(e.message, true);
      return false;
    }
  }

  const photoCount = (n) => (n === 0 ? 'aucune photo' : n === 1 ? '1 photo' : `${n} photos`);
  const seriesById = (id) => state.store.series.find((s) => s.id === id);
  const photo = (id) => state.store.photos[id];
  const coverOf = (s) => (s.cover && s.photos.includes(s.cover) ? s.cover : s.photos[0]);

  /* ---------- Routage ---------- */

  function currentRoute() {
    const hash = location.hash.replace(/^#\/?/, '');
    const [view, id] = hash.split('/');
    return { view: view || 'series', id };
  }

  async function route() {
    sortables.forEach((s) => s.destroy());
    sortables = [];
    if (!state) {
      try {
        const st = await api('status');
        if (!st.configured) return renderAuth('setup');
        if (!st.loggedIn) return renderAuth('login');
        await api('state');
      } catch (e) {
        app.replaceChildren(h('p', { class: 'loading' }, e.message));
        return;
      }
    }
    render();
  }

  function render() {
    if (!state) return route();
    sortables.forEach((s) => s.destroy());
    sortables = [];
    const r = currentRoute();
    let view;
    if (r.view === 's' && seriesById(r.id)) view = viewSeries(seriesById(r.id));
    else if (r.view === 'adresse') view = viewSettings();
    else view = viewSeriesList();
    app.replaceChildren(topbar(r.view), view);
  }

  window.addEventListener('hashchange', () => {
    closeSheet();
    render();
    window.scrollTo(0, 0);
  });

  /* ---------- Connexion ---------- */

  function renderAuth(mode) {
    const input = h('input', { type: 'password', autocomplete: mode === 'setup' ? 'new-password' : 'current-password', required: true, minlength: mode === 'setup' ? 8 : null, placeholder: 'Mot de passe' });
    const confirm = mode === 'setup' ? h('input', { type: 'password', autocomplete: 'new-password', required: true, placeholder: 'Confirmer' }) : null;
    const name = mode === 'setup' ? h('input', { type: 'text', required: true, maxlength: 40, placeholder: 'Nom affiché (ex. MIKEY)', autocapitalize: 'characters' }) : null;
    const err = h('p', { class: 'error' });
    const btn = h('button', { class: 'btn primary', type: 'submit' }, mode === 'setup' ? 'Commencer' : 'Entrer');
    const form = h('form', {
      class: 'auth',
      onsubmit: async (ev) => {
        ev.preventDefault();
        err.textContent = '';
        if (confirm && input.value !== confirm.value) {
          err.textContent = 'Les deux mots de passe ne correspondent pas.';
          return;
        }
        btn.disabled = true;
        try {
          await api(mode, mode === 'setup' ? { password: input.value, title: name.value.trim() } : { password: input.value });
          await api('state');
          location.hash = '#/';
          render();
        } catch (e) {
          err.textContent = e.message;
        } finally {
          btn.disabled = false;
        }
      },
    },
      h('div', { class: 'auth-brand' }, document.title.split(' · ')[0] || 'Portfolio'),
      mode === 'setup' ? h('p', { class: 'hint' }, 'Première connexion : indique le nom à afficher (modifiable ensuite) et choisis le mot de passe de gestion, 8 caractères minimum.') : null,
      name, input, confirm, btn, err,
    );
    app.replaceChildren(form);
    (name || input).focus();
  }

  /* ---------- Barre du haut ---------- */

  function topbar(active) {
    const tab = (href, label, on) => h('a', { href, class: on ? 'on' : '' }, label);
    return h('header', { class: 'topbar' },
      h('span', { class: 'topbar-brand' }, state.store.site.title || 'Portfolio'),
      h('nav', {},
        tab('#/', 'Séries', active === 'series' || active === 's'),
        tab('#/adresse', 'Adresse & QR', active === 'adresse'),
        h('a', { href: state.urls.code + '?apercu', target: '_blank', rel: 'noopener' }, 'Aperçu ↗'),
      ),
    );
  }

  /* ---------- Liste des séries ---------- */

  function viewSeriesList() {
    const list = h('ul', { class: 'series-list' },
      state.store.series.map((s) => {
        const c = coverOf(s);
        return h('li', { 'data-id': s.id },
          h('span', { class: 'handle', title: 'Glisser pour réordonner', 'aria-hidden': 'true' }, '⋮⋮'),
          h('a', { href: `#/s/${s.id}`, class: 'series-row' },
            c ? h('img', { src: photo(c).thumb, alt: '', loading: 'lazy' }) : h('span', { class: 'thumb-empty' }),
            h('span', { class: 'series-meta' },
              h('strong', {}, s.title),
              h('small', {}, photoCount(s.photos.length), s.visible ? '' : ' · masquée'),
            ),
          ),
        );
      }),
    );

    const nameInput = h('input', { type: 'text', placeholder: 'Nom de la nouvelle série', maxlength: 80 });
    const addForm = h('form', {
      class: 'inline-form',
      onsubmit: async (ev) => {
        ev.preventDefault();
        const title = nameInput.value.trim();
        if (!title) return nameInput.focus();
        if (await run('series.create', { title })) {
          const created = state.store.series[state.store.series.length - 1];
          location.hash = `#/s/${created.id}`;
        }
      },
    }, nameInput, h('button', { class: 'btn', type: 'submit' }, 'Créer'));

    queueMicrotask(() => {
      sortables.push(Sortable.create(list, {
        handle: '.handle', animation: 160, ghostClass: 'ghost',
        onEnd: () => run('series.reorder', { ids: [...list.children].map((li) => li.dataset.id) }, 'Ordre enregistré'),
      }));
    });

    return h('main', { class: 'page' },
      h('h1', {}, 'Séries'),
      state.store.series.length ? list : h('p', { class: 'hint' }, 'Aucune série pour l\'instant.'),
      state.store.series.length > 1 ? h('p', { class: 'hint' }, 'Glisse ⋮⋮ pour changer l\'ordre d\'affichage.') : null,
      addForm,
    );
  }

  /* ---------- Détail d'une série ---------- */

  function viewSeries(s) {
    const title = h('input', {
      class: 'title-input', type: 'text', value: s.title, maxlength: 80, 'aria-label': 'Nom de la série',
      onchange: (ev) => {
        const v = ev.target.value.trim();
        if (v && v !== s.title) run('series.update', { id: s.id, title: v }, 'Nom enregistré');
      },
      onkeydown: (ev) => { if (ev.key === 'Enter') ev.target.blur(); },
    });

    const visible = h('label', { class: 'switch' },
      h('input', { type: 'checkbox', checked: s.visible, onchange: (ev) => run('series.update', { id: s.id, visible: ev.target.checked }, ev.target.checked ? 'Série visible' : 'Série masquée') }),
      h('span', {}, 'Visible sur le portfolio'),
    );

    const fileInput = h('input', { type: 'file', accept: 'image/*', multiple: true, hidden: true, onchange: (ev) => uploadFiles(s.id, [...ev.target.files]) });
    const uploadBtn = h('button', { class: 'btn primary block', type: 'button', onclick: () => fileInput.click() }, '+ Ajouter des photos');
    const progress = h('div', { id: 'upload-progress' });

    const cover = coverOf(s);
    const grid = h('div', { class: 'photo-grid' },
      s.photos.map((pid) => h('button', { type: 'button', class: 'photo-tile', 'data-id': pid, onclick: () => openPhotoSheet(s, pid) },
        h('img', { src: photo(pid).thumb, alt: photo(pid).caption || '', loading: 'lazy' }),
        pid === cover ? h('span', { class: 'badge' }, 'Couverture') : null,
        photo(pid).caption ? h('span', { class: 'has-caption', title: 'Légende' }, 'Aa') : null,
      )),
    );

    queueMicrotask(() => {
      sortables.push(Sortable.create(grid, {
        animation: 160, ghostClass: 'ghost', delay: 220, delayOnTouchOnly: true, touchStartThreshold: 6,
        onEnd: (ev) => {
          if (ev.oldIndex === ev.newIndex) return;
          run('photos.reorder', { series: s.id, ids: [...grid.children].map((el) => el.dataset.id) }, 'Ordre enregistré');
        },
      }));
    });

    const del = h('button', {
      class: 'btn danger-link', type: 'button',
      onclick: async () => {
        const n = s.photos.length;
        const msg = n ? `Supprimer « ${s.title} » et ses ${photoCount(n)} ? C'est définitif.` : `Supprimer la série « ${s.title} » ?`;
        if (!confirm(msg)) return;
        if (await run('series.delete', { id: s.id }, 'Série supprimée')) location.hash = '#/';
      },
    }, 'Supprimer la série');

    return h('main', { class: 'page' },
      h('a', { href: '#/', class: 'back' }, 'Séries'),
      title,
      h('div', { class: 'row-between' }, visible, h('small', { class: 'muted' }, photoCount(s.photos.length))),
      uploadBtn, fileInput, progress,
      s.photos.length ? h('p', { class: 'hint' }, 'Touche une photo pour la légende, la couverture ou la déplacer. Appui long puis glisser pour réordonner.') : null,
      grid,
      h('div', { class: 'page-foot' }, del),
    );
  }

  /* ---------- Fiche photo (panneau du bas) ---------- */

  let sheetEl = null;

  function closeSheet() {
    if (sheetEl) {
      sheetEl.remove();
      sheetEl = null;
      document.body.classList.remove('no-scroll');
    }
  }

  function openPhotoSheet(s, pid) {
    closeSheet();
    const p = photo(pid);
    const caption = h('textarea', { rows: 2, maxlength: 160, placeholder: 'Légende (facultative)' });
    caption.value = p.caption || '';
    const others = state.store.series.filter((x) => x.id !== s.id);
    const move = h('select', { 'aria-label': 'Déplacer vers' },
      h('option', { value: '' }, 'Déplacer vers…'),
      others.map((x) => h('option', { value: x.id }, x.title)),
    );
    const isCover = coverOf(s) === pid;

    const save = async () => {
      if (caption.value.trim() !== (p.caption || '')) await run('photo.update', { id: pid, caption: caption.value.trim() }, 'Légende enregistrée');
    };

    sheetEl = h('div', { class: 'sheet-backdrop', onclick: async (ev) => { if (ev.target === sheetEl) { await save(); closeSheet(); } } },
      h('div', { class: 'sheet', role: 'dialog', 'aria-modal': 'true' },
        h('img', { class: 'sheet-img', src: p.large, alt: '' }),
        h('label', { class: 'field' }, h('span', {}, 'Légende'), caption),
        h('div', { class: 'sheet-actions' },
          h('button', { class: 'btn', type: 'button', disabled: isCover, onclick: async () => { await save(); if (await run('series.update', { id: s.id, cover: pid }, 'Couverture choisie')) closeSheet(); } }, isCover ? 'Photo de couverture' : 'Utiliser comme couverture'),
          others.length ? move : null,
          h('button', { class: 'btn danger', type: 'button', onclick: async () => { if (!confirm('Supprimer cette photo ? C\'est définitif.')) return; if (await run('photo.delete', { id: pid }, 'Photo supprimée')) closeSheet(); } }, 'Supprimer'),
        ),
        h('button', { class: 'btn primary block', type: 'button', onclick: async () => { await save(); closeSheet(); } }, 'Terminé'),
      ),
    );
    move.addEventListener('change', async () => {
      if (!move.value) return;
      await save();
      const dest = seriesById(move.value).title;
      if (await run('photo.move', { id: pid, to: move.value }, `Déplacée vers « ${dest} »`)) closeSheet();
    });
    document.body.append(sheetEl);
    document.body.classList.add('no-scroll');
  }

  /* ---------- Envoi des photos ---------- */

  async function prepareFile(file) {
    // Redresse et réduit dans le navigateur : envoi plus rapide en 4G, métadonnées supprimées dès le téléphone.
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, MAX_EDGE / Math.max(bmp.width, bmp.height));
      const w = Math.round(bmp.width * scale);
      const hgt = Math.round(bmp.height * scale);
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = hgt;
      const ctx = canvas.getContext('2d');
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(bmp, 0, 0, w, hgt);
      bmp.close?.();
      const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', JPEG_QUALITY));
      if (blob) return new File([blob], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch { /* navigateur incapable de décoder : on envoie l'original, le serveur tentera */ }
    return file;
  }

  async function uploadFiles(seriesId, files) {
    if (!files.length) return;
    const box = document.getElementById('upload-progress');
    const bar = h('div', { class: 'bar' }, h('span'));
    const label = h('p', { class: 'hint' });
    box.replaceChildren(label, bar);
    window.addEventListener('beforeunload', warnLeave);
    let done = 0;
    const errors = [];
    for (const f of files) {
      label.textContent = `Envoi ${done + 1} sur ${files.length}… garde cette page ouverte.`;
      try {
        const ready = await prepareFile(f);
        if (state.maxUpload && ready.size > state.maxUpload) throw new Error('fichier trop lourd');
        const fd = new FormData();
        fd.append('series', seriesId);
        fd.append('photo', ready);
        await api('upload', fd, { form: true });
      } catch (e) {
        errors.push(`${f.name} : ${e.message}`);
      }
      done++;
      bar.firstChild.style.width = `${Math.round((done / files.length) * 100)}%`;
    }
    window.removeEventListener('beforeunload', warnLeave);
    render();
    const ok = files.length - errors.length;
    if (errors.length) toast(`${ok} ajoutée(s), ${errors.length} en échec. ${errors[0]}`, true);
    else toast(ok === 1 ? 'Photo ajoutée' : `${ok} photos ajoutées`);
  }

  function warnLeave(ev) {
    ev.preventDefault();
    ev.returnValue = '';
  }

  /* ---------- Adresse, QR code, réglages ---------- */

  function viewSettings() {
    const { store, urls } = state;
    const a = store.access;

    const title = h('input', { type: 'text', value: store.site.title, maxlength: 40, required: true });
    const subtitle = h('input', { type: 'text', value: store.site.subtitle || '', maxlength: 60, placeholder: 'Facultatif' });
    const slug = h('input', { type: 'text', value: a.slug, maxlength: 40, placeholder: 'ex. mikey-walsh', autocapitalize: 'off', autocorrect: 'off', spellcheck: 'false' });
    const qrCode = h('input', { type: 'radio', name: 'qr', value: 'code', checked: a.qr !== 'slug' });
    const qrSlug = h('input', { type: 'radio', name: 'qr', value: 'slug', checked: a.qr === 'slug', disabled: !a.slug });
    const origin = urls.code.slice(0, urls.code.lastIndexOf('/') + 1);

    slug.addEventListener('input', () => {
      slug.value = slug.value.toLowerCase().replace(/[^a-z0-9-]/g, '-');
      qrSlug.disabled = !slug.value;
      if (!slug.value) qrCode.checked = true;
    });

    const form = h('form', {
      class: 'stack',
      onsubmit: async (ev) => {
        ev.preventDefault();
        const next = slug.value.replace(/^-+|-+$/g, '');
        const willChangeQr = urls.qr !== (qrSlug.checked && next ? origin + next : urls.code);
        if (willChangeQr && !confirm('L\'adresse encodée dans le QR code va changer. Si un QR est déjà imprimé, il continuera de fonctionner, mais le nouveau sera différent. Continuer ?')) return;
        run('settings', { title: title.value, subtitle: subtitle.value, slug: next, qr: qrSlug.checked ? 'slug' : 'code' }, 'Réglages enregistrés');
      },
    },
      h('h2', {}, 'Affichage'),
      h('label', { class: 'field' }, h('span', {}, 'Nom'), title),
      h('label', { class: 'field' }, h('span', {}, 'Sous-titre'), subtitle),

      h('h2', {}, 'Adresse publique'),
      h('div', { class: 'field' },
        h('span', {}, 'Adresse secrète (définitive)'),
        copyLine(urls.code),
        h('small', { class: 'muted' }, 'Impossible à deviner. Elle fonctionnera toujours.'),
      ),
      h('label', { class: 'field' },
        h('span', {}, 'Adresse lisible (facultative)'),
        h('div', { class: 'prefixed' }, h('span', {}, origin.replace(/^https?:\/\//, '')), slug),
        h('small', { class: 'muted' }, 'Plus jolie, mais plus facile à deviner. Si tu la changes, l\'ancienne continue de marcher.'),
      ),
      a.oldSlugs.length ? h('div', { class: 'field' },
        h('span', {}, 'Anciennes adresses encore actives'),
        a.oldSlugs.map((o) => h('div', { class: 'old-slug' },
          h('code', {}, origin + o),
          h('button', { type: 'button', class: 'btn small', onclick: () => {
            if (confirm(`Désactiver ${origin + o} ? Tout QR code imprimé avec cette adresse cessera de fonctionner.`)) run('slug.forget', { slug: o }, 'Adresse désactivée');
          } }, 'Désactiver'),
        )),
      ) : null,
      h('fieldset', { class: 'field radios' },
        h('legend', {}, 'Le QR code ouvre'),
        h('label', {}, qrCode, ' l\'adresse secrète'),
        h('label', {}, qrSlug, ' l\'adresse lisible'),
      ),
      h('button', { class: 'btn primary', type: 'submit' }, 'Enregistrer'),
    );

    return h('main', { class: 'page' },
      h('h1', {}, 'Adresse & QR code'),
      qrPanel(urls.qr),
      form,
      passwordForm(),
      h('div', { class: 'page-foot' },
        h('button', { class: 'btn danger-link', type: 'button', onclick: async () => { await api('logout', {}); state = null; location.hash = '#/'; route(); } }, 'Se déconnecter'),
      ),
    );
  }

  function copyLine(url) {
    return h('div', { class: 'copy-line' },
      h('code', {}, url),
      h('button', { type: 'button', class: 'btn small', onclick: async () => {
        try { await navigator.clipboard.writeText(url); toast('Adresse copiée'); } catch { toast('Copie impossible', true); }
      } }, 'Copier'),
    );
  }

  function makeQr(url) {
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    return qr;
  }

  function qrSvg(url, cell = 10, margin = 4) {
    const qr = makeQr(url);
    const n = qr.getModuleCount();
    const size = (n + margin * 2) * cell;
    let path = '';
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
      if (qr.isDark(r, c)) path += `M${(c + margin) * cell} ${(r + margin) * cell}h${cell}v${cell}h-${cell}z`;
    }
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="${path}" fill="#000"/></svg>`;
  }

  function download(name, blob) {
    const a = h('a', { href: URL.createObjectURL(blob), download: name });
    document.body.append(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  function qrPanel(url) {
    const svg = qrSvg(url);
    const holder = h('div', { class: 'qr-img' });
    holder.innerHTML = svg; // SVG généré localement, sans donnée utilisateur hors l'URL déjà validée
    const png = () => {
      const qr = makeQr(url);
      const n = qr.getModuleCount();
      const margin = 4;
      const cell = Math.max(8, Math.ceil(2000 / (n + margin * 2)));
      const size = (n + margin * 2) * cell;
      const c = document.createElement('canvas');
      c.width = c.height = size;
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, size, size);
      ctx.fillStyle = '#000';
      for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (qr.isDark(r, col)) ctx.fillRect((col + margin) * cell, (r + margin) * cell, cell, cell);
      c.toBlob((b) => download('qr-portfolio.png', b), 'image/png');
    };
    return h('section', { class: 'qr' },
      holder,
      h('div', { class: 'qr-side' },
        h('p', { class: 'hint' }, 'Ce QR code ouvre :'),
        copyLine(url),
        h('div', { class: 'qr-actions' },
          h('button', { class: 'btn', type: 'button', onclick: () => download('qr-portfolio.svg', new Blob([svg], { type: 'image/svg+xml' })) }, 'Télécharger SVG'),
          h('button', { class: 'btn', type: 'button', onclick: png }, 'Télécharger PNG'),
        ),
        h('small', { class: 'muted' }, 'SVG pour l\'impression (net à toute taille). Imprime-le à 2 cm de côté minimum, et scanne-le avant d\'imprimer le book.'),
      ),
    );
  }

  function passwordForm() {
    const cur = h('input', { type: 'password', autocomplete: 'current-password', required: true });
    const next = h('input', { type: 'password', autocomplete: 'new-password', required: true, minlength: 8 });
    return h('form', {
      class: 'stack',
      onsubmit: async (ev) => {
        ev.preventDefault();
        if (await run('password', { current: cur.value, next: next.value }, 'Mot de passe changé')) { cur.value = ''; next.value = ''; }
      },
    },
      h('h2', {}, 'Mot de passe'),
      h('label', { class: 'field' }, h('span', {}, 'Actuel'), cur),
      h('label', { class: 'field' }, h('span', {}, 'Nouveau (8 caractères minimum)'), next),
      h('button', { class: 'btn', type: 'submit' }, 'Changer le mot de passe'),
    );
  }

  route();
})();
