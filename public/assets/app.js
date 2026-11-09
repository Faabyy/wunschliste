// Wunschliste – kleine Verbesserungen. Ohne JS funktioniert alles über normale Formulare.
(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const nativeSubmit = HTMLFormElement.prototype.submit;

  // Formulare mit data-swap: im Hintergrund absenden und nur den betroffenen Teil austauschen.
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const question = form.dataset.confirm;
    if (question && !window.confirm(question)) {
      event.preventDefault();
      return;
    }

    const targetId = form.dataset.swap;
    if (!targetId || !window.fetch || !window.DOMParser) {
      markBusy(event.submitter);
      return;
    }

    event.preventDefault();
    if (form.classList.contains('is-busy')) return;
    form.classList.add('is-busy');

    let response;
    try {
      response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' },
      });
    } catch (e) {
      form.classList.remove('is-busy');
      nativeSubmit.call(form);
      return;
    }

    const html = await response.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const fresh = doc.getElementById(targetId);
    const old = document.getElementById(targetId);
    if (!response.ok || !fresh || !old) {
      // Der Server hat geantwortet, nur passt die Seite nicht: dorthin wechseln statt erneut senden.
      window.location.assign(response.url || window.location.href);
      return;
    }

    const before = positions(old);
    const changed = form.closest('[data-id]');
    old.replaceWith(fresh);
    swapFlashes(doc);
    animate(fresh, before, changed && changed.dataset.id);

    const focusTarget = changed && fresh.querySelector(`[data-id="${changed.dataset.id}"] [data-focus], [data-id="${changed.dataset.id}"] button:not(:disabled)`);
    const selfFocus = fresh.matches('[data-id]') && fresh.querySelector('[data-focus], button:not(:disabled)');
    const next = selfFocus || focusTarget;
    if (next && document.activeElement === document.body) next.focus({ preventScroll: true });
  });

  function markBusy(button) {
    if (!button || !button.dataset.busy) return;
    button.setAttribute('aria-busy', 'true');
    button.textContent = button.dataset.busy;
    // Erst nach dem Absenden sperren, sonst geht der Klick verloren.
    setTimeout(() => { button.disabled = true; }, 0);
  }

  function positions(root) {
    const map = new Map();
    const nodes = root.matches('[data-id]') ? [root] : root.querySelectorAll('[data-id]');
    nodes.forEach((el) => map.set(el.dataset.id, el.getBoundingClientRect().top));
    return map;
  }

  function animate(root, before, changedId) {
    if (reduceMotion) return;
    const nodes = root.matches('[data-id]') ? [root] : root.querySelectorAll('[data-id]');
    nodes.forEach((el) => {
      const id = el.dataset.id;
      if (id === changedId) {
        el.classList.add('is-fresh');
        el.addEventListener('animationend', () => el.classList.remove('is-fresh'), { once: true });
      }
      if (!before.has(id)) return;
      const dy = before.get(id) - el.getBoundingClientRect().top;
      if (Math.abs(dy) < 1) return;
      el.animate(
        [{ transform: `translateY(${dy}px)` }, { transform: 'none' }],
        { duration: 320, easing: 'cubic-bezier(.2,.7,.2,1)' },
      );
    });
  }

  function swapFlashes(doc) {
    const fresh = doc.getElementById('flashes');
    const old = document.getElementById('flashes');
    if (fresh && old) {
      old.replaceWith(fresh);
      fadeFlashes();

  // Sichtbarkeit: Wer eine Person anhakt, meint „nur ausgewählte Personen“.
  document.querySelectorAll('input[data-pick]').forEach((box) => {
    box.addEventListener('change', () => {
      const radio = box.form && box.form.querySelector('[data-pick-radio]');
      if (radio && box.checked) radio.checked = true;
    });
  });

  // Bild-Upload: im Browser auf 1200 px verkleinern und als JPEG neu zeichnen. Das spart Datenvolumen
  // und entfernt Metadaten wie den GPS-Standort von Handyfotos. Ohne JS prüft und bereinigt der Server.
  document.querySelectorAll('input[type="file"][data-resize]').forEach((input) => {
    input.addEventListener('change', async () => {
      const file = input.files && input.files[0];
      if (!file) return;
      let shown = file;
      try {
        if (window.createImageBitmap && window.DataTransfer && file.type !== 'image/gif') {
          const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
          const scale = Math.min(1, 1200 / Math.max(bitmap.width, bitmap.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(bitmap.width * scale);
          canvas.height = Math.round(bitmap.height * scale);
          const ctx = canvas.getContext('2d');
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
          const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
          if (blob) {
            shown = new File([blob], 'bild.jpg', { type: 'image/jpeg' });
            const transfer = new DataTransfer();
            transfer.items.add(shown);
            input.files = transfer.files;
          }
        }
      } catch (e) { /* dann geht die Originaldatei hoch */ }
      const preview = input.form && input.form.querySelector('[data-preview]');
      if (preview) {
        const img = new Image();
        img.alt = '';
        img.src = URL.createObjectURL(shown);
        preview.classList.remove('is-empty');
        preview.replaceChildren(img);
      }
    });
  });

  // Verwaltung: Prüfen, ob data/ aus dem Web abrufbar ist (dann käme der Inhalt der Probedatei zurück).
  document.querySelectorAll('[data-probe]').forEach(async (row) => {
    const detail = row.querySelector('.check-detail');
    let open = false;
    try {
      const res = await fetch(row.dataset.probe, { cache: 'no-store', credentials: 'omit' });
      open = res.ok && (await res.text()).includes(row.dataset.probeMarker);
    } catch (e) { /* nicht erreichbar = geschützt */ }
    row.className = open ? 'check-fail' : 'check-ok';
    detail.textContent = open
      ? 'NEIN: data/ ist aus dem Web abrufbar. .htaccess-Dateien mit hochladen bzw. public/ als Web-Root einstellen.'
      : 'ja';
  });
    }
  }

  // Erfolgsmeldungen verschwinden nach ein paar Sekunden von selbst.
  function fadeFlashes() {
    document.querySelectorAll('.flash-success').forEach((el) => {
      setTimeout(() => {
        el.classList.add('is-leaving');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
        setTimeout(() => el.remove(), 800);
      }, 4000);
    });
  }

  fadeFlashes();

  // Sichtbarkeit: Wer eine Person anhakt, meint „nur ausgewählte Personen“.
  document.querySelectorAll('input[data-pick]').forEach((box) => {
    box.addEventListener('change', () => {
      const radio = box.form && box.form.querySelector('[data-pick-radio]');
      if (radio && box.checked) radio.checked = true;
    });
  });

  // Bild-Upload: im Browser auf 1200 px verkleinern und als JPEG neu zeichnen. Das spart Datenvolumen
  // und entfernt Metadaten wie den GPS-Standort von Handyfotos. Ohne JS prüft und bereinigt der Server.
  document.querySelectorAll('input[type="file"][data-resize]').forEach((input) => {
    input.addEventListener('change', async () => {
      const file = input.files && input.files[0];
      if (!file) return;
      let shown = file;
      try {
        if (window.createImageBitmap && window.DataTransfer && file.type !== 'image/gif') {
          const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
          const scale = Math.min(1, 1200 / Math.max(bitmap.width, bitmap.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(bitmap.width * scale);
          canvas.height = Math.round(bitmap.height * scale);
          const ctx = canvas.getContext('2d');
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
          const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
          if (blob) {
            shown = new File([blob], 'bild.jpg', { type: 'image/jpeg' });
            const transfer = new DataTransfer();
            transfer.items.add(shown);
            input.files = transfer.files;
          }
        }
      } catch (e) { /* dann geht die Originaldatei hoch */ }
      const preview = input.form && input.form.querySelector('[data-preview]');
      if (preview) {
        const img = new Image();
        img.alt = '';
        img.src = URL.createObjectURL(shown);
        preview.classList.remove('is-empty');
        preview.replaceChildren(img);
      }
    });
  });

  // Verwaltung: Prüfen, ob data/ aus dem Web abrufbar ist (dann käme der Inhalt der Probedatei zurück).
  document.querySelectorAll('[data-probe]').forEach(async (row) => {
    const detail = row.querySelector('.check-detail');
    let open = false;
    try {
      const res = await fetch(row.dataset.probe, { cache: 'no-store', credentials: 'omit' });
      open = res.ok && (await res.text()).includes(row.dataset.probeMarker);
    } catch (e) { /* nicht erreichbar = geschützt */ }
    row.className = open ? 'check-fail' : 'check-ok';
    detail.textContent = open
      ? 'NEIN: data/ ist aus dem Web abrufbar. .htaccess-Dateien mit hochladen bzw. public/ als Web-Root einstellen.'
      : 'ja';
  });

  // Nach dem Hinzufügen das Formular wieder zuklappen lassen, aber Fokus aufs Titelfeld beim Öffnen.
  const adder = document.querySelector('.adder');
  if (adder) {
    adder.addEventListener('toggle', () => {
      if (adder.open) {
        const title = adder.querySelector('input[name="titel"]');
        if (title) title.focus({ preventScroll: true });
      }
    });
  }
})();
