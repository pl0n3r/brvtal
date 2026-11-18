/* Accessible, dependency-free progressive enhancement for Event editor datetime-local inputs. */
(function () {
  'use strict';
  if (window.BRVTALAdminDateTimePicker) return;

  const WEEKDAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
  const instances = new WeakMap();
  let observer = null;
  let active = null;
  const pad = value => String(value).padStart(2, '0');

  function parse(value) {
    const found = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(String(value || ''));
    if (!found) return null;
    const [year, month, day, hour, minute] = found.slice(1).map(Number);
    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() + 1 !== month || date.getDate() !== day
        || hour > 23 || minute > 59) return null;
    return {year, month, day, hour, minute};
  }

  function readable(value) {
    const date = parse(value);
    if (!date) return 'Elegir fecha y hora';
    const calendarDate = new Date(date.year, date.month - 1, date.day);
    const label = new Intl.DateTimeFormat('es-CO', {
      weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'
    }).format(calendarDate);
    return label + ' · ' + (date.hour % 12 || 12) + ':' + pad(date.minute)
      + ' ' + (date.hour >= 12 ? 'PM' : 'AM');
  }

  function update(input) {
    const field = instances.get(input);
    if (!field) return;
    field.button.textContent = readable(input.value);
    field.button.disabled = input.disabled;
    const label = input.getAttribute('aria-label')
      || document.querySelector('label[for="' + input.id + '"]')?.textContent?.trim()
      || input.closest('label')?.textContent?.trim() || 'fecha y hora';
    field.button.setAttribute('aria-label', 'Elegir ' + label + ': ' + readable(input.value));
  }

  function close() {
    if (!active) return;
    const previous = active;
    active = null;
    previous.overlay.remove();
    if (previous.button.isConnected && !previous.button.disabled) {
      previous.button.focus({preventScroll: true});
    }
  }

  function commit(value) {
    if (!active) return;
    const {input} = active;
    if (input.value !== value) {
      input.value = value;
      input.dispatchEvent(new Event('input', {bubbles: true}));
      input.dispatchEvent(new Event('change', {bubbles: true}));
    }
    update(input);
    close();
  }

  function renderTime() {
    const {overlay, draft} = active;
    const hour = overlay.querySelector('[data-adp-select="hour"]');
    const minutes = overlay.querySelector('[data-adp-select="minute"]');
    const period = overlay.querySelector('[data-adp-select="period"]');
    hour.replaceChildren();
    for (let value = 1; value <= 12; value++) {
      hour.add(new Option(String(value), String(value)));
    }
    minutes.replaceChildren();
    const choices = [0, 15, 30, 45];
    if (!choices.includes(draft.minute)) choices.push(draft.minute);
    choices.sort((a, b) => a - b);
    choices.forEach(value => minutes.add(new Option(pad(value), String(value))));
    hour.value = String(draft.hour % 12 || 12);
    minutes.value = String(draft.minute);
    period.value = draft.hour >= 12 ? 'PM' : 'AM';
  }

  function announce() {
    if (!active) return;
    const {draft, overlay} = active;
    const selected = new Date(draft.year, draft.month - 1, draft.day);
    const text = new Intl.DateTimeFormat('es-CO', {
      weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    }).format(selected);
    overlay.querySelector('[data-adp-live]').textContent =
      text + ', ' + (draft.hour % 12 || 12) + ':' + pad(draft.minute)
      + ' ' + (draft.hour >= 12 ? 'PM' : 'AM');
  }

  function renderCalendar() {
    if (!active) return;
    const {draft, overlay} = active;
    const heading = overlay.querySelector('[data-adp-month]');
    heading.textContent = new Intl.DateTimeFormat('es-CO', {
      month: 'long', year: 'numeric'
    }).format(new Date(draft.year, draft.month - 1, 1));
    const grid = overlay.querySelector('[data-adp-grid]');
    const first = (new Date(draft.year, draft.month - 1, 1).getDay() + 6) % 7;
    const days = new Date(draft.year, draft.month, 0).getDate();
    const fragment = document.createDocumentFragment();
    let row = null;
    for (let index = 0; index < 42; index++) {
      if (index % 7 === 0) {
        row = document.createElement('div');
        row.className = 'adp-row';
        row.setAttribute('role', 'row');
        fragment.appendChild(row);
      }
      const day = index - first + 1;
      if (day < 1 || day > days) {
        const blank = document.createElement('span');
        blank.className = 'adp-blank';
        blank.setAttribute('aria-hidden', 'true');
        row.appendChild(blank);
        continue;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'adp-day';
      button.dataset.adpDay = String(day);
      button.textContent = String(day);
      button.setAttribute('role', 'gridcell');
      button.setAttribute('aria-selected', String(day === draft.day));
      button.setAttribute('aria-label', new Intl.DateTimeFormat('es-CO', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
      }).format(new Date(draft.year, draft.month - 1, day)));
      button.tabIndex = day === draft.day ? 0 : -1;
      if (day === draft.day) button.classList.add('adp-selected');
      row.appendChild(button);
    }
    grid.replaceChildren(fragment);
    announce();
  }

  function setDate(date, focusGrid) {
    if (!active) return;
    const {draft, overlay} = active;
    draft.year = date.getFullYear();
    draft.month = date.getMonth() + 1;
    draft.day = date.getDate();
    renderCalendar();
    if (focusGrid) overlay.querySelector('[data-adp-day="' + draft.day + '"]')?.focus();
  }

  function changeMonth(amount, focusGrid) {
    if (!active) return;
    const {draft} = active;
    const first = new Date(draft.year, draft.month - 1 + amount, 1);
    const last = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
    setDate(new Date(first.getFullYear(), first.getMonth(), Math.min(draft.day, last)), focusGrid);
  }

  function keydown(event) {
    if (!active) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close();
      return;
    }
    const {overlay, draft} = active;
    if (event.key === 'Tab') {
      const buttons = [...overlay.querySelectorAll('button:not([disabled]),select:not([disabled])')]
        .filter(element => element.tabIndex >= 0);
      const first = buttons[0];
      const last = buttons[buttons.length - 1];
      if (event.shiftKey && (document.activeElement === first || document.activeElement === overlay.querySelector('[role="dialog"]'))) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
      }
      return;
    }
    if (!event.target.matches('[data-adp-day]')) return;
    const delta = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7}[event.key];
    if (delta !== undefined) {
      event.preventDefault();
      setDate(new Date(draft.year, draft.month - 1, draft.day + delta), true);
    } else if (event.key === 'PageUp' || event.key === 'PageDown') {
      event.preventDefault();
      changeMonth(event.key === 'PageUp' ? -1 : 1, true);
    }
  }

  function open(input, button) {
    if (input.disabled) return;
    if (active) close();
    const now = new Date();
    const draft = parse(input.value) || {
      year: now.getFullYear(), month: now.getMonth() + 1,
      day: now.getDate(), hour: 20, minute: 0
    };
    const overlay = document.createElement('div');
    overlay.className = 'adp-overlay';
    overlay.innerHTML = '<div class="adp-panel" role="dialog" aria-modal="true"'
      + ' aria-label="Elegir fecha y hora" tabindex="-1">'
      + '<div class="adp-header"><strong>FECHA Y HORA</strong>'
      + '<button type="button" data-adp-action="cancel" aria-label="Cerrar selector">×</button></div>'
      + '<div class="adp-month-nav"><button type="button" data-adp-action="previous" aria-label="Mes anterior">‹</button>'
      + '<h3 data-adp-month></h3>'
      + '<button type="button" data-adp-action="next" aria-label="Mes siguiente">›</button>'
      + '<button type="button" data-adp-action="today">Hoy</button></div>'
      + '<div class="adp-weekdays" aria-hidden="true"></div>'
      + '<div class="adp-grid" role="grid" aria-label="Calendario" data-adp-grid></div>'
      + '<div class="adp-time">'
      + '<label>Hora<select aria-label="Hora" data-adp-select="hour"></select></label>'
      + '<label>Minutos<select aria-label="Minutos" data-adp-select="minute"></select></label>'
      + '<label>AM / PM<select aria-label="AM o PM" data-adp-select="period">'
      + '<option>AM</option><option>PM</option></select></label></div>'
      + '<div class="adp-shortcuts"><span>HORAS FRECUENTES</span>'
      + '<button type="button" data-adp-hour="20">8 PM</button>'
      + '<button type="button" data-adp-hour="21">9 PM</button>'
      + '<button type="button" data-adp-hour="22">10 PM</button>'
      + '<button type="button" data-adp-hour="23">11 PM</button></div>'
      + '<div class="adp-live" role="status" aria-live="polite" data-adp-live></div>'
      + '<div class="adp-footer"><button type="button" data-adp-action="clear">Borrar</button>'
      + '<button type="button" data-adp-action="cancel">Cancelar</button>'
      + '<button type="button" data-adp-action="done">Listo</button></div>'
      + '</div>';
    const weekdays = overlay.querySelector('.adp-weekdays');
    WEEKDAYS.forEach(day => {
      const label = document.createElement('span');
      label.textContent = day;
      weekdays.appendChild(label);
    });
    document.body.appendChild(overlay);
    active = {input, button, draft, overlay};
    renderCalendar();
    renderTime();

    overlay.addEventListener('keydown', keydown);
    overlay.addEventListener('click', event => {
      if (event.target === overlay) {
        close();
        return;
      }
      const dayButton = event.target.closest('[data-adp-day]');
      if (dayButton) {
        draft.day = Number(dayButton.dataset.adpDay);
        renderCalendar();
        return;
      }
      const shortcut = event.target.closest('[data-adp-hour]');
      if (shortcut) {
        draft.hour = Number(shortcut.dataset.adpHour);
        draft.minute = 0;
        renderTime();
        announce();
        return;
      }
      const control = event.target.closest('[data-adp-action]');
      if (!control) return;
      switch (control.dataset.adpAction) {
        case 'cancel': close(); break;
        case 'clear': commit(''); break;
        case 'previous': changeMonth(-1, false); break;
        case 'next': changeMonth(1, false); break;
        case 'today': setDate(new Date(), false); break;
        case 'done':
          commit(String(draft.year).padStart(4, '0') + '-' + pad(draft.month)
            + '-' + pad(draft.day) + 'T' + pad(draft.hour) + ':' + pad(draft.minute));
          break;
        default: break;
      }
    });
    overlay.addEventListener('change', event => {
      const which = event.target.dataset.adpSelect;
      if (!which) return;
      if (which === 'minute') draft.minute = Number(event.target.value);
      else {
        const hour = Number(overlay.querySelector('[data-adp-select="hour"]').value);
        const period = overlay.querySelector('[data-adp-select="period"]').value;
        draft.hour = (hour % 12) + (period === 'PM' ? 12 : 0);
      }
      announce();
    });
    overlay.querySelector('[role="dialog"]').focus();
  }

  function enhance(input) {
    if (instances.has(input)) {
      update(input);
      return;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'adp-trigger';
    button.setAttribute('aria-haspopup', 'dialog');
    input.classList.add('adp-native');
    input.setAttribute('aria-hidden', 'true');
    input.tabIndex = -1;
    input.insertAdjacentElement('afterend', button);
    instances.set(input, {button});
    input.addEventListener('input', () => update(input));
    input.addEventListener('change', () => update(input));
    button.addEventListener('click', () => open(input, button));
    update(input);
  }

  function mount(root) {
    if (!root || !root.matches('[data-admin-module="content-core"]')) return;
    const editor = root.querySelector('#eventModal');
    if (!editor) return;
    if (observer) observer.disconnect();
    if (active) close();
    const sync = () => {
      if (!editor.isConnected) return;
      editor.querySelectorAll('input[type="datetime-local"]').forEach(enhance);
    };
    sync();
    observer = new MutationObserver(sync);
    observer.observe(editor, {
      subtree: true, childList: true, attributes: true, attributeFilter: ['disabled', 'class']
    });
  }

  window.BRVTALAdminDateTimePicker = {mount, readable};
})();
