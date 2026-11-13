(function () {
  function ensurePopupRoot() {
    var root = document.getElementById('eum-popup-root');
    if (root) return root;

    root = document.createElement('div');
    root.id = 'eum-popup-root';
    root.className = 'eum-popup';
    root.innerHTML = '' +
      '<div class="eum-popup__backdrop" data-eum-popup-close="1"></div>' +
      '<div class="eum-popup__panel" role="dialog" aria-modal="true" aria-labelledby="eum-popup-root-title">' +
        '<div class="eum-popup__header">' +
          '<h3 id="eum-popup-root-title" class="eum-popup__title"></h3>' +
          '<button type="button" class="eum-popup__close" data-eum-popup-close="1" aria-label="Close popup">×</button>' +
        '</div>' +
        '<div class="eum-popup__body"></div>' +
      '</div>';
    document.body.appendChild(root);
    return root;
  }

  function closePopup() {
    var root = document.getElementById('eum-popup-root');
    if (!root) return;
    root.classList.remove('is-open');
    document.body.classList.remove('eum-popup-open');
    var body = root.querySelector('.eum-popup__body');
    if (body) body.innerHTML = '';
  }



  function ensureJobPathFilterEmptyState(list) {
    if (!list) return;
    var pills = list.querySelectorAll('[data-eum-job-filter-pill="1"]');
    var empty = list.querySelector('.eum-directory__path-filter-empty');
    if (!pills.length) {
      if (!empty) {
        empty = document.createElement('div');
        empty.className = 'eum-directory__path-filter-empty';
        empty.textContent = 'No job path filters selected yet.';
        list.appendChild(empty);
      }
      return;
    }
    if (empty) empty.remove();
  }

  function addJobPathFilterPill(list, termId, label) {
    if (!list || !termId) return;
    var existing = list.querySelector('[data-eum-job-filter-pill="1"][data-term-id="' + termId + '"]');
    if (existing) {
      var existingCheckbox = existing.querySelector('input[type="checkbox"]');
      if (existingCheckbox) existingCheckbox.checked = true;
      ensureJobPathFilterEmptyState(list);
      return;
    }
    var pill = document.createElement('div');
    pill.className = 'eum-directory__path-pill';
    pill.setAttribute('data-eum-job-filter-pill', '1');
    pill.setAttribute('data-term-id', String(termId));
    pill.innerHTML = '' +
      '<label><input type="checkbox" name="eum_job_path_filter[]" value="' + String(termId) + '" checked> <span></span></label>' +
      '<button type="button" class="eum-directory__path-pill-remove" data-eum-job-filter-remove="1" aria-label="Remove path">×</button>';
    var span = pill.querySelector('span');
    if (span) span.textContent = label || ('Job Path #' + String(termId));
    list.appendChild(pill);
    ensureJobPathFilterEmptyState(list);
  }

  function syncEditorMode(container) {
    if (!container) return;
    var checked = container.querySelector('.eum-editor-mode input[type="radio"]:checked');
    var mode = checked ? checked.value : 'visual';
    container.querySelectorAll('[data-eum-editor-panel]').forEach(function (panel) {
      var show = panel.getAttribute('data-eum-editor-panel') === mode;
      panel.hidden = !show;
    });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-eum-popup-target]');
    if (trigger) {
      event.preventDefault();
      var targetId = trigger.getAttribute('data-eum-popup-target');
      var source = document.getElementById(targetId);
      if (!source) return;
      var root = ensurePopupRoot();
      var title = trigger.getAttribute('data-eum-popup-title') || '';
      var titleEl = root.querySelector('.eum-popup__title');
      var bodyEl = root.querySelector('.eum-popup__body');
      if (titleEl) titleEl.textContent = title;
      if (bodyEl) bodyEl.innerHTML = source.innerHTML;
      if (bodyEl && window.EUMJobPathPickerInit) {
        bodyEl.querySelectorAll('.eum-job-path-picker').forEach(function (pickerRoot) {
          window.EUMJobPathPickerInit(pickerRoot);
        });
      }
      root.classList.add('is-open');
      document.body.classList.add('eum-popup-open');
      return;
    }

    var addJobPathButton = event.target.closest('[data-eum-job-filter-confirm="1"]');
    if (addJobPathButton) {
      event.preventDefault();
      var popupRoot = document.getElementById('eum-popup-root');
      if (!popupRoot) return;
      var body = popupRoot.querySelector('.eum-popup__body');
      var popup = addJobPathButton.closest('[data-eum-job-filter-popup="1"]');
      var listSelector = popup ? popup.getAttribute('data-eum-job-filter-list-selector') : '';
      var inputName = popup ? popup.getAttribute('data-eum-job-filter-input-name') : '';
      var list = listSelector ? document.querySelector(listSelector) : null;
      if (!body || !list || !inputName) return;
      var hiddenInput = body.querySelector('input[type="hidden"][name="' + inputName + '"]');
      var termId = hiddenInput ? parseInt(hiddenInput.value, 10) || 0 : 0;
      if (!termId) return;
      var summary = body.querySelector('.eum-job-path-picker__summary');
      var label = summary ? summary.textContent.replace(/^Selected:\s*/, '').trim() : '';
      if (!label || /^No path selected/i.test(label) || label === '— None —') {
        var current = body.querySelector('.eum-job-path-picker__path');
        label = current ? current.textContent.trim() : '';
      }
      if (!label || label === '/') label = 'Job Path #' + String(termId);
      if (label.charAt(0) !== '/') label = '/ ' + label.replace(/^\/+\s*/, '');
      addJobPathFilterPill(list, termId, label);
      closePopup();
      return;
    }

    var removePathButton = event.target.closest('[data-eum-job-filter-remove="1"]');
    if (removePathButton) {
      event.preventDefault();
      var pill = removePathButton.closest('[data-eum-job-filter-pill="1"]');
      var list = pill ? pill.parentElement : null;
      if (pill) pill.remove();
      ensureJobPathFilterEmptyState(list);
      return;
    }

    if (event.target.closest('[data-eum-popup-close="1"]')) {
      event.preventDefault();
      closePopup();
    }
  });

  document.addEventListener('change', function (event) {
    var pathCheckbox = event.target.closest('input[name="eum_job_path_filter[]"]');
    if (pathCheckbox && !pathCheckbox.checked) {
      var pill = pathCheckbox.closest('[data-eum-job-filter-pill="1"]');
      var list = pill ? pill.parentElement : null;
      if (pill) pill.remove();
      ensureJobPathFilterEmptyState(list);
      return;
    }

    var modeInput = event.target.closest('.eum-editor-mode input[type="radio"]');
    if (!modeInput) return;
    var scope = modeInput.closest('form') || modeInput.closest('.postbox') || document;
    syncEditorMode(scope);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closePopup();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form, .postbox').forEach(function (scope) {
      if (scope.querySelector('.eum-editor-mode')) syncEditorMode(scope);
    });
  });
})();


(function () {
  function metaRows(scope) {
    return scope ? scope.querySelector('[data-eum-meta-pairs="1"]') : null;
  }

  function metaKind(container, key) {
    var kinds = {};
    try { kinds = JSON.parse(container.getAttribute('data-eum-meta-types') || '{}') || {}; } catch (error) {}
    if (Object.prototype.hasOwnProperty.call(kinds, key)) return kinds[key];
    if (key !== 'employee_id' && /(?:^|_)(?:id|ids|user_id|term_id|post_id|attachment_id)$/i.test(key)) return 'id';
    return 'text';
  }

  function updateMetaHint(row) {
    var container = row && row.closest('[data-eum-meta-pairs="1"]');
    if (!container) return;
    var keyField = row.querySelector('[data-eum-meta-key="1"]');
    var valueField = row.querySelector('[data-eum-meta-value="1"]');
    var editor = row.closest('[data-eum-meta-editor="1"]');
    var help = editor ? editor.querySelector('[data-eum-meta-kind-help="1"]') : null;
    if (!keyField || !valueField) return;
    var kind = metaKind(container, keyField.value.trim());
    var messages = {
      id: ['Numeric ID', 'Enter the numeric ID for this field. User, term, and attachment IDs are checked when applicable.'],
      integer: ['Whole number', 'This registered field accepts a whole number.'],
      number: ['Number', 'This field accepts a number.'],
      boolean: ['true/false or 1/0', 'This field accepts true, false, 1, or 0.'],
      email: ['Email address', 'Enter a valid email address.'],
      url: ['URL', 'Enter a valid URL.'],
      date: ['YYYY-MM-DD', 'Enter a date in YYYY-MM-DD format.'],
      json: ['JSON array or object', 'Enter valid JSON for this metadata field.'],
      json_array: ['JSON array', 'Enter a valid JSON array, such as [1,2].'],
      json_object: ['JSON object', 'Enter a valid JSON object, such as {"key":"value"}.']
    };
    var item = messages[kind];
    valueField.placeholder = item ? item[0] : 'Value';
    if (help) help.textContent = item ? item[1] : 'Field suggestions appear as you type. Registered types are validated before any updates are saved.';
  }

  function syncMetaSelection(form) {
    if (!form) return;
    var selected = form.querySelectorAll('[data-eum-row-select="1"]:checked').length;
    form.querySelectorAll('[data-eum-meta-selected-count="1"]').forEach(function (node) {
      node.textContent = selected + ' selected';
    });
    var reason = form.querySelector('[name="employee_meta_reason"]');
    form.querySelectorAll('[data-eum-meta-submit="1"]').forEach(function (button) {
      button.disabled = selected < 1 || !reason || !reason.value;
    });
  }

  function initializeMetaEditor(scope) {
    if (!scope) return;
    scope.querySelectorAll('[data-eum-meta-row="1"]').forEach(updateMetaHint);
    syncMetaSelection(scope.closest('form'));
  }

  document.addEventListener('click', function (event) {
    var add = event.target.closest('[data-eum-meta-add="1"]');
    if (add) {
      var editor = add.closest('[data-eum-meta-editor="1"]');
      var container = metaRows(editor);
      var template = editor && editor.querySelector('[data-eum-meta-row-template="1"]');
      if (!container || !template || container.querySelectorAll('[data-eum-meta-row="1"]').length >= 25) return;
      var index = parseInt(container.getAttribute('data-eum-meta-next-index') || '0', 10) || 0;
      container.setAttribute('data-eum-meta-next-index', String(index + 1));
      var html = template.innerHTML.replace(/__INDEX__/g, String(index));
      var holder = document.createElement('div');
      holder.innerHTML = html;
      var row = holder.firstElementChild;
      if (!row) return;
      row.querySelectorAll('[data-eum-meta-remove="1"]').forEach(function (button) { button.hidden = false; });
      container.appendChild(row);
      container.querySelectorAll('[data-eum-meta-remove="1"]').forEach(function (button) { button.hidden = false; });
      updateMetaHint(row);
      var firstKey = row.querySelector('[data-eum-meta-key="1"]');
      if (firstKey) firstKey.focus();
      return;
    }

    var remove = event.target.closest('[data-eum-meta-remove="1"]');
    if (remove) {
      var rows = metaRows(remove.closest('[data-eum-meta-editor="1"]'));
      var row = remove.closest('[data-eum-meta-row="1"]');
      if (!rows || !row) return;
      if (rows.querySelectorAll('[data-eum-meta-row="1"]').length > 1) {
        row.remove();
        if (rows.querySelectorAll('[data-eum-meta-row="1"]').length === 1) {
          rows.querySelectorAll('[data-eum-meta-remove="1"]').forEach(function (button) { button.hidden = true; });
        }
      } else {
        row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        updateMetaHint(row);
      }
    }
  });

  document.addEventListener('input', function (event) {
    var keyField = event.target.closest('[data-eum-meta-key="1"]');
    if (keyField) updateMetaHint(keyField.closest('[data-eum-meta-row="1"]'));
  });

  document.addEventListener('change', function (event) {
    var form = event.target.closest('form');
    if (event.target.matches('[data-eum-row-select="1"], [data-eum-select-all="1"], [name="employee_meta_reason"]')) {
      syncMetaSelection(form);
    }
  });

  function initAll() {
    document.querySelectorAll('[data-eum-meta-editor="1"]').forEach(initializeMetaEditor);
    document.querySelectorAll('.eum-directory__bulk-form').forEach(syncMetaSelection);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
  else initAll();
})();
(function () {
  function syncAvailabilityScope(form) {
    if (!form) return;
    var checked = form.querySelector('input[name="availability_scope"]:checked');
    var isDefault = checked && checked.value === 'default';
    form.querySelectorAll('[data-eum-availability-week]').forEach(function (field) {
      field.hidden = !!isDefault;
    });
    form.querySelectorAll('[data-eum-availability-default]').forEach(function (field) {
      field.hidden = !isDefault;
    });
  }

  function syncAvailabilityDay(day) {
    if (!day) return;
    var enabled = day.querySelector('[data-eum-availability-day-toggle]');
    var checked = !!(enabled && enabled.checked);
    day.classList.toggle('is-enabled', checked);
    day.querySelectorAll('[data-eum-availability-block] input').forEach(function (input) {
      input.disabled = !checked;
    });
    syncAvailabilityRemoveButtons(day);
  }

  function syncAvailabilityRemoveButtons(day) {
    if (!day) return;
    var blocks = day.querySelectorAll('[data-eum-availability-block]');
    blocks.forEach(function (block) {
      var remove = block.querySelector('[data-eum-availability-remove-block]');
      if (remove) remove.hidden = blocks.length <= 1;
    });
  }

  function nextAvailabilityBlockKey(day) {
    var dayValue = day ? day.getAttribute('data-eum-availability-day-value') : '';
    if (!dayValue) {
      var dayInput = day ? day.querySelector('input[name$="[day]"]') : null;
      dayValue = dayInput ? dayInput.value : '1';
    }
    return String(dayValue) + '_' + String(Date.now()) + String(Math.floor(Math.random() * 1000));
  }

  function addAvailabilityBlock(day) {
    var template = document.getElementById('eum-availability-block-template');
    var blocks = day ? day.querySelector('[data-eum-availability-blocks]') : null;
    if (!template || !blocks || !day) return;
    var dayInput = day.querySelector('input[name$="[day]"]');
    var dayValue = dayInput ? dayInput.value : '1';
    var html = template.innerHTML
      .replace(/__DAY__/g, String(dayValue))
      .replace(/__KEY__/g, nextAvailabilityBlockKey(day));
    var holder = document.createElement('div');
    holder.innerHTML = html;
    var block = holder.firstElementChild;
    if (!block) return;
    blocks.appendChild(block);
    var toggle = day.querySelector('[data-eum-availability-day-toggle]');
    if (toggle) toggle.checked = true;
    syncAvailabilityDay(day);
  }

  function availabilityBlockValues(day) {
    if (!day) return [];
    return Array.prototype.map.call(day.querySelectorAll('[data-eum-availability-block]'), function (block) {
      var start = block.querySelector('input[name$="[start]"]');
      var end = block.querySelector('input[name$="[end]"]');
      return {
        start: start ? start.value : '09:00',
        end: end ? end.value : '17:00'
      };
    });
  }

  function createAvailabilityBlock(dayValue, values) {
    var template = document.getElementById('eum-availability-block-template');
    if (!template) return null;
    var key = String(dayValue) + '_' + String(Date.now()) + String(Math.floor(Math.random() * 1000));
    var html = template.innerHTML
      .replace(/__DAY__/g, String(dayValue))
      .replace(/__KEY__/g, key);
    var holder = document.createElement('div');
    holder.innerHTML = html;
    var block = holder.firstElementChild;
    if (!block) return null;
    var start = block.querySelector('input[name$="[start]"]');
    var end = block.querySelector('input[name$="[end]"]');
    if (start) start.value = values && values.start ? values.start : '09:00';
    if (end) end.value = values && values.end ? values.end : '17:00';
    return block;
  }

  function copyAvailabilityDay(sourceDay) {
    if (!sourceDay) return;
    var targetSelect = sourceDay.querySelector('[data-eum-availability-copy-target]');
    var targetValue = targetSelect ? targetSelect.value : '';
    if (!targetValue) return;
    var form = sourceDay.closest('.eum-availability-form') || document;
    var targetDay = form.querySelector('[data-eum-availability-day][data-eum-availability-day-value="' + targetValue + '"]');
    var targetBlocks = targetDay ? targetDay.querySelector('[data-eum-availability-blocks]') : null;
    if (!targetDay || !targetBlocks) return;

    var sourceToggle = sourceDay.querySelector('[data-eum-availability-day-toggle]');
    var targetToggle = targetDay.querySelector('[data-eum-availability-day-toggle]');
    var sourceEnabled = !!(sourceToggle && sourceToggle.checked);
    if (targetToggle) targetToggle.checked = sourceEnabled;
    targetBlocks.innerHTML = '';

    var values = availabilityBlockValues(sourceDay);
    if (!values.length) values = [{ start: '09:00', end: '17:00' }];
    values.forEach(function (value) {
      var block = createAvailabilityBlock(targetValue, value);
      if (block) targetBlocks.appendChild(block);
    });
    syncAvailabilityDay(targetDay);
  }

  function reloadAvailabilityForSelection(select) {
    if (!select || select.name !== 'target_user_id') return;
    var form = select.closest('.eum-availability-form');
    if (!form) return;
    var url = new URL(window.location.href);
    url.searchParams.set('target_user_id', select.value || '0');
    url.searchParams.delete('eum_availability_msg');
    url.searchParams.delete('eum_availability_type');
    window.location.href = url.toString();
  }

  function reloadAvailabilityForWeek(input) {
    if (!input || !input.value) return;
    var form = input.closest('.eum-availability-form');
    if (!form) return;
    var targetSelect = form.querySelector('select[name="target_user_id"]');
    var hiddenTarget = form.querySelector('input[type="hidden"][name="target_user_id"]');
    var url = new URL(window.location.href);
    if (targetSelect && targetSelect.value) url.searchParams.set('target_user_id', targetSelect.value);
    if (hiddenTarget && hiddenTarget.value) url.searchParams.set('target_user_id', hiddenTarget.value);
    url.searchParams.set('availability_week_start', input.value);
    url.searchParams.delete('eum_availability_msg');
    url.searchParams.delete('eum_availability_type');
    window.location.href = url.toString();
  }

  document.addEventListener('change', function (event) {
    var scope = event.target.closest('.eum-availability-form input[name="availability_scope"]');
    if (scope) {
      syncAvailabilityScope(scope.closest('.eum-availability-form'));
      return;
    }

    var dayToggle = event.target.closest('[data-eum-availability-day-toggle]');
    if (dayToggle) {
      syncAvailabilityDay(dayToggle.closest('[data-eum-availability-day]'));
      return;
    }

    var targetSelect = event.target.closest('.eum-availability-form select[name="target_user_id"]');
    if (targetSelect) {
      reloadAvailabilityForSelection(targetSelect);
      return;
    }

    var weekStart = event.target.closest('[data-eum-availability-week-start]');
    if (weekStart) {
      reloadAvailabilityForWeek(weekStart);
    }
  });

  document.addEventListener('click', function (event) {
    var addButton = event.target.closest('[data-eum-availability-add-block]');
    if (addButton) {
      event.preventDefault();
      addAvailabilityBlock(addButton.closest('[data-eum-availability-day]'));
      return;
    }

    var removeButton = event.target.closest('[data-eum-availability-remove-block]');
    if (removeButton) {
      event.preventDefault();
      var day = removeButton.closest('[data-eum-availability-day]');
      var block = removeButton.closest('[data-eum-availability-block]');
      if (block && day && day.querySelectorAll('[data-eum-availability-block]').length > 1) {
        block.remove();
        syncAvailabilityDay(day);
      }
      return;
    }

    var copyButton = event.target.closest('[data-eum-availability-copy-day]');
    if (copyButton) {
      event.preventDefault();
      copyAvailabilityDay(copyButton.closest('[data-eum-availability-day]'));
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.eum-availability-form').forEach(syncAvailabilityScope);
    document.querySelectorAll('[data-eum-availability-day]').forEach(syncAvailabilityDay);
  });
})();
(function () {
  function isAdminScreen() {
    return !!(document.body && document.body.classList.contains('wp-admin'));
  }

  function getViewportMeta() {
    var meta = document.querySelector('meta[name="viewport"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = 'viewport';
      document.head.appendChild(meta);
    }
    return meta;
  }

  function applyViewportMode() {
    if (isAdminScreen()) return;
    var meta = getViewportMeta();
    if (window.matchMedia && window.matchMedia('(max-width: 782px), (pointer: coarse)').matches) {
      meta.setAttribute('content', 'width=device-width, initial-scale=1, viewport-fit=cover');
      return;
    }
    var desktopWidth = 1366;
    var scale = Math.max(0.1, Math.min(1, window.innerWidth / desktopWidth)).toFixed(2);
    meta.setAttribute('content', 'width=' + desktopWidth + ', initial-scale=' + scale + ', viewport-fit=cover');
  }

  document.addEventListener('DOMContentLoaded', applyViewportMode, { once: true });
  window.addEventListener('resize', applyViewportMode, { passive: true });
  window.addEventListener('orientationchange', applyViewportMode, { passive: true });
  window.addEventListener('pageshow', applyViewportMode, { passive: true });
})();
(function () {
  function cloneDivisionControl(name, value) {
    var template = document.getElementById('eum-division-value-template');
    if (!template || !('content' in template)) {
      var input = document.createElement('input');
      input.type = 'text';
      input.name = name;
      input.className = 'regular-text';
      input.value = value || '';
      return input;
    }
    var select = template.content.firstElementChild.cloneNode(true);
    select.name = name;
    if (typeof value === 'string') select.value = value;
    return select;
  }

  function buildValueControl(name, key, value) {
    if ((key || '').trim() === 'job_company') {
      return cloneDivisionControl(name, value || '');
    }
    var input = document.createElement('input');
    input.type = 'text';
    input.name = name;
    input.className = 'regular-text';
    input.placeholder = 'Value';
    input.value = value || '';
    return input;
  }

  function syncMapRowValueControl(row) {
    if (!row) return;
    var keyInput = row.querySelector('input[name="eum_map_key[]"]');
    var valueWrap = row.querySelector('.eum-map-row__value');
    if (!keyInput || !valueWrap) return;
    var current = valueWrap.querySelector('input, select');
    var currentValue = current ? current.value : '';
    var next = buildValueControl('eum_map_value[]', keyInput.value, currentValue);
    valueWrap.innerHTML = '';
    valueWrap.appendChild(next);
  }

  function createMapRow() {
    var row = document.createElement('div');
    row.className = 'eum-map-row';
    row.setAttribute('data-eum-map-row', '1');
    row.innerHTML = '' +
      '<div><input type="text" name="eum_map_key[]" class="regular-text" list="eum-mapping-field-options" placeholder="custom:department or company_div"></div>' +
      '<div class="eum-map-row__value"></div>' +
      '<div><button type="button" class="button-link-delete" data-eum-remove-map-row="1" aria-label="Remove row">✕</button></div>';
    syncMapRowValueControl(row);
    return row;
  }

  function syncBulkValueControl(form) {
    if (!form) return;
    var keyInput = form.querySelector('input[name="eum_bulk_key"]');
    var wrap = form.querySelector('.eum-bulk-value-wrap');
    if (!keyInput || !wrap) return;
    var current = wrap.querySelector('input, select');
    var currentValue = current ? current.value : '';
    var next = buildValueControl('eum_bulk_value', keyInput.value, currentValue);
    wrap.innerHTML = '';
    wrap.appendChild(next);
  }

  function syncBulkMode(form) {
    if (!form) return;
    var mode = form.querySelector('select[name="eum_bulk_mode"]');
    var wrap = form.querySelector('.eum-bulk-value-wrap');
    if (!mode || !wrap) return;
    wrap.closest('p').style.opacity = mode.value === 'remove' ? '0.55' : '1';
    var field = wrap.querySelector('input, select');
    if (field) field.disabled = mode.value === 'remove';
  }

  document.addEventListener('click', function (event) {
    var addButton = event.target.closest('[data-eum-add-map-row="1"]');
    if (addButton) {
      event.preventDefault();
      var wrap = addButton.closest('.eum-map-builder').querySelector('[data-eum-map-rows="1"]');
      if (!wrap) return;
      wrap.appendChild(createMapRow());
      return;
    }

    var removeButton = event.target.closest('[data-eum-remove-map-row="1"]');
    if (removeButton) {
      event.preventDefault();
      var row = removeButton.closest('[data-eum-map-row="1"]');
      var wrap = row ? row.parentElement : null;
      if (!row || !wrap) return;
      if (wrap.querySelectorAll('[data-eum-map-row="1"]').length <= 1) {
        var key = row.querySelector('input[name="eum_map_key[]"]');
        if (key) key.value = '';
        var valueField = row.querySelector('input[name="eum_map_value[]"], select[name="eum_map_value[]"]');
        if (valueField) valueField.value = '';
        syncMapRowValueControl(row);
      } else {
        row.remove();
      }
      return;
    }

    var checkAll = event.target.closest('[data-eum-check-all]');
    if (checkAll) {
      var formId = checkAll.getAttribute('data-eum-check-all');
      document.querySelectorAll('[data-eum-check-item="' + formId + '"]').forEach(function (box) {
        box.checked = checkAll.checked;
      });
    }
  });

  document.addEventListener('change', function (event) {
    if (event.target.matches('input[name="eum_map_key[]"]')) {
      syncMapRowValueControl(event.target.closest('[data-eum-map-row="1"]'));
      return;
    }

    var bulkKey = event.target.closest('input[name="eum_bulk_key"]');
    if (bulkKey) {
      syncBulkValueControl(bulkKey.closest('form'));
      syncBulkMode(bulkKey.closest('form'));
      return;
    }

    var bulkMode = event.target.closest('select[name="eum_bulk_mode"]');
    if (bulkMode) {
      syncBulkMode(bulkMode.closest('form'));
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-eum-map-row="1"]').forEach(syncMapRowValueControl);
    document.querySelectorAll('#eum-term-bulk-form').forEach(function (form) {
      syncBulkValueControl(form);
      syncBulkMode(form);
    });
  });
})();
(function () {
  function setOrgPanel(panelId, sourceId, trigger) {
    var panel = document.getElementById(panelId);
    var source = document.getElementById(sourceId);
    if (!panel || !source) return;
    panel.innerHTML = source.innerHTML;
    document.querySelectorAll('[data-eum-org-panel="' + panelId + '"] .is-active').forEach(function (el) {
      el.classList.remove('is-active');
    });
    if (trigger) trigger.classList.add('is-active');
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-eum-org-profile-target]');
    if (!button) return;
    event.preventDefault();
    setOrgPanel(button.getAttribute('data-eum-org-panel'), button.getAttribute('data-eum-org-profile-target'), button);
  });
})();
(function () {
  function syncEventAccess(scope) {
    if (!scope) return;
    var access = scope.querySelector('[data-eum-event-access]');
    if (!access) return;
    var inviteOnly = access.value === 'invite';
    scope.querySelectorAll('[data-eum-event-invite-only]').forEach(function (el) {
      el.style.display = inviteOnly ? '' : 'none';
    });
  }

  document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-eum-event-access]')) return;
    syncEventAccess(event.target.closest('form') || document);
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(function (form) {
      if (form.querySelector('[data-eum-event-access]')) syncEventAccess(form);
    });
  });
})();
(function () {
  function syncVisualBulkFieldInput(scope) {
    if (!scope) return;
    var select = scope.querySelector('[data-eum-bulk-field="1"]');
    if (!select) return;
    var option = select.options[select.selectedIndex];
    var kind = option ? (option.getAttribute('data-input-kind') || 'text') : 'text';
    scope.querySelectorAll('.eum-bulk-value').forEach(function (node) {
      node.hidden = true;
    });
    var active = scope.querySelector('.eum-bulk-value--' + kind);
    if (!active) active = scope.querySelector('.eum-bulk-value--text');
    if (active) active.hidden = false;
  }

  function syncVisualBulkSelectAll(master) {
    if (!master) return;
    var form = master.closest('form') || document;
    var checked = !!master.checked;
    form.querySelectorAll('[data-eum-row-select="1"]').forEach(function (box) {
      box.checked = checked;
    });
    form.querySelectorAll('[data-eum-select-all="1"]').forEach(function (other) {
      if (other !== master) other.checked = checked;
    });
  }

  function syncRowSelections(form) {
    form = form || document;
    var rows = Array.prototype.slice.call(form.querySelectorAll('[data-eum-row-select="1"]'));
    var allChecked = rows.length > 0 && rows.every(function (box) { return box.checked; });
    form.querySelectorAll('[data-eum-select-all="1"]').forEach(function (box) {
      box.checked = allChecked;
    });
    syncTeamBulkState(form);
  }

  function syncTeamBulkState(form) {
    form = form || document;
    var rows = Array.prototype.slice.call(form.querySelectorAll('[data-eum-row-select="1"]'));
    var selected = rows.filter(function (box) { return box.checked; }).length;
    form.querySelectorAll('[data-eum-team-selected-count="1"]').forEach(function (node) {
      node.textContent = selected + ' selected';
    });
    form.querySelectorAll('[data-eum-team-selected-help="1"]').forEach(function (node) {
      node.textContent = selected >= 1 ? 'Ready to open the ghost profile editor for these users.' : 'Choose at least one visible person to enable bulk editing.';
    });
    form.querySelectorAll('[data-eum-team-bulk-open="1"]').forEach(function (button) {
      button.hidden = false;
      button.disabled = selected < 1;
    });
    form.querySelectorAll('[data-eum-meta-selected-count="1"]').forEach(function (node) {
      node.textContent = selected + ' selected';
    });
    var metaReason = form.querySelector('[name="employee_meta_reason"]');
    form.querySelectorAll('[data-eum-meta-submit="1"]').forEach(function (button) {
      button.disabled = selected < 1 || !metaReason || !metaReason.value;
    });
  }

  function setTeamBulkModalState(form, open, explicitTarget) {
    form = form || document;
    var selector = explicitTarget ? ('#' + explicitTarget) : '[data-eum-team-bulk-modal="1"]';
    var modal = form.querySelector(selector);
    if (!modal) modal = document.querySelector(selector);
    if (!modal) return;
    modal.hidden = !open;
    modal.classList.toggle('is-open', !!open);
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('eum-popup-open', !!open);
    if (open) {
      if (window.EUMJobPathPickerInit) {
        modal.querySelectorAll('.eum-job-path-picker').forEach(function (pickerRoot) {
          window.EUMJobPathPickerInit(pickerRoot);
        });
      }
      if (window.EUMTaxonomyPathPickerInit) {
        modal.querySelectorAll('.eum-taxonomy-path-picker').forEach(function (pickerRoot) {
          window.EUMTaxonomyPathPickerInit(pickerRoot);
        });
      }
    }
  }

  function setQuickEditState(userId, open) {
    var summary = document.querySelector('[data-eum-row-id="' + userId + '"]');
    var editRow = document.querySelector('[data-eum-edit-row="' + userId + '"]');
    if (!summary || !editRow) return;
    editRow.hidden = !open;
    summary.classList.toggle('is-open', !!open);
  }

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-eum-quick-edit-toggle]');
    if (toggle) {
      event.preventDefault();
      var userId = toggle.getAttribute('data-eum-quick-edit-toggle');
      var editRow = document.querySelector('[data-eum-edit-row="' + userId + '"]');
      if (!editRow) return;
      setQuickEditState(userId, !!editRow.hidden);
      return;
    }

    var cancel = event.target.closest('[data-eum-quick-edit-cancel]');
    if (cancel) {
      event.preventDefault();
      setQuickEditState(cancel.getAttribute('data-eum-quick-edit-cancel'), false);
      return;
    }

    var rowSelectToggle = event.target.closest('[data-eum-row-select-toggle]');
    if (rowSelectToggle) {
      event.preventDefault();
      var userId = rowSelectToggle.getAttribute('data-eum-row-select-toggle');
      var box = document.querySelector('[data-eum-row-id="' + userId + '"] [data-eum-row-select="1"]');
      if (!box) return;
      box.checked = !box.checked;
      syncRowSelections(box.closest('form') || document);
      return;
    }

    var bulkOpen = event.target.closest('[data-eum-team-bulk-open="1"]');
    if (bulkOpen) {
      event.preventDefault();
      var form = bulkOpen.closest('form') || document;
      if (form.querySelectorAll('[data-eum-row-select="1"]:checked').length < 1) {
        syncTeamBulkState(form);
        return;
      }
      setTeamBulkModalState(form, true, bulkOpen.getAttribute('data-eum-team-bulk-modal-target'));
      return;
    }

    var bulkClose = event.target.closest('[data-eum-team-bulk-close="1"]');
    if (bulkClose) {
      event.preventDefault();
      setTeamBulkModalState(bulkClose.closest('form') || document, false);
      return;
    }
  });

  document.addEventListener('change', function (event) {
    var field = event.target.closest('[data-eum-bulk-field="1"]');
    if (field) {
      syncVisualBulkFieldInput(field.closest('.eum-visual-bulk__bulk-bar') || field.closest('form'));
      return;
    }

    var selectAll = event.target.closest('[data-eum-select-all="1"]');
    if (selectAll) {
      syncVisualBulkSelectAll(selectAll);
      return;
    }

    var rowSelect = event.target.closest('[data-eum-row-select="1"]');
    if (rowSelect) {
      syncRowSelections(rowSelect.closest('form') || document);
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.eum-visual-bulk__bulk-bar').forEach(function (scope) {
      syncVisualBulkFieldInput(scope);
    });
    document.querySelectorAll('.eum-directory__bulk-form').forEach(function (form) {
      syncRowSelections(form);
      syncTeamBulkState(form);
    });
  });
})();


(function () {
  function filterStorageKey(form) {
    var context = form.getAttribute('data-eum-filter-context') || form.id || form.className || window.location.pathname;
    return 'eumSavedFilters::' + context;
  }

  function readFilterPresets(form) {
    try {
      var parsed = JSON.parse(window.localStorage.getItem(filterStorageKey(form)) || '{}');
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function writeFilterPresets(form, presets) {
    try {
      window.localStorage.setItem(filterStorageKey(form), JSON.stringify(presets || {}));
    } catch (e) {}
  }

  function serializeFilterForm(form) {
    var data = {};
    var formData = new FormData(form);
    formData.forEach(function (value, key) {
      if (typeof key !== 'string' || !key) return;
      if (Object.prototype.hasOwnProperty.call(data, key)) {
        if (!Array.isArray(data[key])) data[key] = [data[key]];
        data[key].push(String(value));
        return;
      }
      data[key] = String(value);
    });
    return data;
  }

  function submitFilterValues(form, values) {
    if (!form || !values || typeof values !== 'object') return;
    var method = (form.getAttribute('method') || 'get').toLowerCase();
    var action = form.getAttribute('action') || window.location.href;
    var region = form.closest('[data-eum-ajax-region]');
    if (method === 'get') {
      var url = new URL(action, window.location.href);
      url.search = '';
      Object.keys(values).forEach(function (key) {
        var raw = values[key];
        if (Array.isArray(raw)) {
          raw.forEach(function (item) { url.searchParams.append(key, item); });
          return;
        }
        url.searchParams.set(key, raw);
      });
      if (region) {
        var targets = ajaxTargetsFor(form, region);
        requestAjaxRegions(targets.regions, targets.keys, url.toString(), { method: 'GET', strictTargets: targets.strict });
        return;
      }
      window.location.assign(url.toString());
      return;
    }
    var temp = document.createElement('form');
    temp.method = method;
    temp.action = action;
    temp.style.display = 'none';
    Object.keys(values).forEach(function (key) {
      var raw = values[key];
      var list = Array.isArray(raw) ? raw : [raw];
      list.forEach(function (item) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = item;
        temp.appendChild(input);
      });
    });
    (region || document.body).appendChild(temp);
    temp.requestSubmit();
  }

  function refreshFilterPresetUI(form, shell) {
    if (!form || !shell) return;
    var body = shell.querySelector('.eum-saved-filters__body');
    if (!body) return;
    var presets = readFilterPresets(form);
    var options = ['<option value="">Select saved filter</option>'];
    Object.keys(presets).sort(function (a, b) {
      return String((presets[a] || {}).label || a).localeCompare(String((presets[b] || {}).label || b));
    }).forEach(function (key) {
      var item = presets[key] || {};
      options.push('<option value="' + key.replace(/"/g, '&quot;') + '">' + String(item.label || key).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>');
    });
    body.innerHTML = '' +
      '<select class="eum-saved-filters__select">' + options.join('') + '</select>' +
      '<button type="button" class="button" data-eum-filter-apply="1">Apply Saved</button>' +
      '<button type="button" class="button" data-eum-filter-save="1">Save Current</button>' +
      '<button type="button" class="button" data-eum-filter-delete="1">Delete</button>';
  }

  function ensureFilterPresetShell(form) {
    if (!form) return null;
    var existing = form.parentElement ? form.parentElement.querySelector('.eum-saved-filters[data-eum-filter-manager="1"]') : null;
    if (existing) {
      if (!existing.querySelector('.eum-saved-filters__body')) {
        var body = document.createElement('div');
        body.className = 'eum-saved-filters__body';
        existing.appendChild(body);
      }
      return existing;
    }
    var shell = document.createElement('div');
    shell.className = 'eum-saved-filters';
    shell.setAttribute('data-eum-filter-manager', '1');
    shell.innerHTML = '<div class="eum-saved-filters__title">Saved Filters</div><div class="eum-saved-filters__body"></div>';
    form.insertAdjacentElement('afterend', shell);
    return shell;
  }

  function initSaveableFilterForm(form) {
    if (!form || form.getAttribute('data-eum-filter-ready') === '1') return;
    form.setAttribute('data-eum-filter-ready', '1');
    var shell = ensureFilterPresetShell(form);
    refreshFilterPresetUI(form, shell);
    if (!shell) return;
    shell.addEventListener('click', function (event) {
      var action = event.target.closest('[data-eum-filter-apply],[data-eum-filter-save],[data-eum-filter-delete]');
      if (!action) return;
      var select = shell.querySelector('.eum-saved-filters__select');
      var presets = readFilterPresets(form);
      if (action.hasAttribute('data-eum-filter-apply')) {
        var selectedKey = select ? select.value : '';
        if (!selectedKey || !presets[selectedKey] || !presets[selectedKey].values) return;
        submitFilterValues(form, presets[selectedKey].values);
        return;
      }
      if (action.hasAttribute('data-eum-filter-save')) {
        var name = window.prompt('Save this filter as:', 'My Filter');
        if (!name) return;
        var key = name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || ('filter-' + Date.now());
        presets[key] = { label: name.trim(), values: serializeFilterForm(form), updated_at: Date.now() };
        writeFilterPresets(form, presets);
        refreshFilterPresetUI(form, shell);
        var updatedSelect = shell.querySelector('.eum-saved-filters__select');
        if (updatedSelect) updatedSelect.value = key;
        return;
      }
      if (action.hasAttribute('data-eum-filter-delete')) {
        var deleteKey = select ? select.value : '';
        if (!deleteKey || !presets[deleteKey]) return;
        delete presets[deleteKey];
        writeFilterPresets(form, presets);
        refreshFilterPresetUI(form, shell);
      }
    });
  }

  document.addEventListener('change', function (event) {
    var target = event.target;
    var formId = 'eum-candidate-bulk-redirect-form';
    var master = document.getElementById('eum-candidate-select-all-pending');
    var form = document.getElementById(formId);
    if (!target || !master || !form) return;

    var candidates = Array.prototype.slice.call(document.querySelectorAll(
      'input[data-eum-candidate-bulk-checkbox="1"][form="' + formId + '"]'
    ));
    if (target === master) {
      candidates.forEach(function (checkbox) { checkbox.checked = master.checked; });
      master.indeterminate = false;
      return;
    }
    if (target.getAttribute('data-eum-candidate-bulk-checkbox') !== '1' || target.form !== form) return;
    var selected = candidates.filter(function (checkbox) { return checkbox.checked; }).length;
    master.checked = candidates.length > 0 && selected === candidates.length;
    master.indeterminate = selected > 0 && selected < candidates.length;
  });

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-eum-copy-email-list="1"]');
    if (!button) return;
    var panel = button.closest('.eum-directory__email-result');
    var textarea = panel ? panel.querySelector('[data-eum-email-list-text="1"]') : null;
    var status = panel ? panel.querySelector('[data-eum-copy-email-status="1"]') : null;
    if (!textarea) return;

    function copied(ok) {
      if (status) status.textContent = ok ? 'Copied to clipboard.' : 'Copy failed. Select the text and copy it manually.';
    }
    if (navigator.clipboard && window.isSecureContext && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(textarea.value).then(function () { copied(true); }).catch(function () { copied(false); });
      return;
    }
    textarea.focus();
    textarea.select();
    try {
      copied(document.execCommand('copy'));
    } catch (error) {
      copied(false);
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-eum-save-filters="1"]').forEach(initSaveableFilterForm);
  });

  var activeAjaxRegionKeys = [];
  var ajaxRequestCount = 0;

  function regionSelector(key) {
    return '[data-eum-ajax-region="' + String(key || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]';
  }

  function showAjaxStatus(message, isError) {
    var status = document.getElementById('eum-ajax-status');
    if (!status) {
      status = document.createElement('div');
      status.id = 'eum-ajax-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.style.cssText = 'position:fixed;z-index:100000;right:16px;bottom:16px;max-width:min(440px,calc(100vw - 32px));padding:11px 15px;border-radius:8px;background:#1d2327;color:#fff;box-shadow:0 4px 18px rgba(0,0,0,.2);font:14px/1.4 sans-serif;';
      document.body.appendChild(status);
    }
    status.textContent = message;
    status.style.background = isError ? '#b32d2e' : '#1d2327';
    status.hidden = false;
    window.clearTimeout(status._eumTimer);
    if (message !== 'Updating…' && message !== 'Saving…') {
      status._eumTimer = window.setTimeout(function () { if (status.parentNode) status.parentNode.removeChild(status); }, isError ? 7000 : 2200);
    }
    return status;
  }

  function splitAjaxRegionKeys(value) {
    return String(value || '').split(',').map(function (key) { return key.trim(); }).filter(function (key, index, keys) {
      return key !== '' && keys.indexOf(key) === index;
    });
  }

  window.EUMManualCandidatePreviewInit = window.EUMManualCandidatePreviewInit || function (container) {
    if (!container || container.dataset.bound) return;
    container.dataset.bound = '1';
    var form = container.closest('form');
    if (!form) return;
    var job = form.querySelector('[name="job_id"]');
    var first = form.querySelector('[name="first_name"]');
    var last = form.querySelector('[name="last_name"]');
    if (!job || !first || !last) return;
    var jobs = {};
    try { jobs = JSON.parse(container.getAttribute('data-jobs') || '{}'); } catch (error) {}
    var setText = function (key, value) {
      var target = container.querySelector('[data-preview="' + key + '"]');
      if (target) target.textContent = value;
    };
    var update = function () {
      var selected = jobs[job.value] || null;
      var firstName = (first.value || '').trim();
      var lastName = (last.value || '').trim();
      var firstPart = firstName ? firstName.split(/\s+/)[0] : '';
      var lastParts = lastName ? lastName.split(/\s+/) : [];
      var lastPart = lastParts.length ? lastParts[lastParts.length - 1] : '';
      var username = (firstPart + lastPart).toLowerCase().replace(/[^a-z0-9._-]/g, '');
      var divisionDomain = selected && selected.division_domain ? selected.division_domain : '';
      setText('job_path', selected && selected.job_path ? selected.job_path : 'Select a posting');
      setText('job_title', selected && selected.job_title ? selected.job_title : 'Select a posting');
      setText('division', selected && selected.division_label ? selected.division_label : 'Select a posting');
      setText('division_domain', divisionDomain || 'Select a posting');
      if (divisionDomain && username) setText('company_email', username + '@' + divisionDomain);
      else if (divisionDomain) setText('company_email', 'Enter first and last name to preview');
      else setText('company_email', 'Enter a name and select a posting');
    };
    ['change', 'input'].forEach(function (eventName) {
      job.addEventListener(eventName, update);
      first.addEventListener(eventName, update);
      last.addEventListener(eventName, update);
    });
    update();
  };
  function ajaxTargetsFor(element, nearestRegion) {
    var explicit = element && element.getAttribute('data-eum-ajax-target');
    var primaryKey = nearestRegion ? nearestRegion.getAttribute('data-eum-ajax-region') : '';
    var keys = explicit ? splitAjaxRegionKeys(explicit) : (primaryKey ? [primaryKey] : []);
    var refresh = nearestRegion && nearestRegion.getAttribute('data-eum-ajax-refresh');
    if (!explicit && refresh) keys = keys.concat(splitAjaxRegionKeys(refresh));
    keys = keys.filter(function (key, index) { return keys.indexOf(key) === index; });
    var regions = keys.map(function (key) { return document.querySelector(regionSelector(key)); }).filter(Boolean);
    return { keys: keys, regions: regions, strict: !!explicit || !!refresh };
  }

  function reinitializeAjaxRegion(region) {
    if (!region) return;
    region.querySelectorAll('form[data-eum-save-filters="1"]').forEach(initSaveableFilterForm);
    if (window.EUMJobPathPickerInit) {
      if (region.matches('.eum-job-path-picker')) window.EUMJobPathPickerInit(region);
      region.querySelectorAll('.eum-job-path-picker').forEach(window.EUMJobPathPickerInit);
    }
    if (window.EUMTaxonomyPathPickerInit) {
      if (region.matches('.eum-taxonomy-path-picker')) window.EUMTaxonomyPathPickerInit(region);
      region.querySelectorAll('.eum-taxonomy-path-picker').forEach(window.EUMTaxonomyPathPickerInit);
    }
    if (region.matches('[data-eum-schedule-wrap]')) {
      var scripts = region.querySelectorAll('script:not([src])');
      scripts.forEach(function (source) {
        if (source.textContent.indexOf('data-eum-schedule-wrap') === -1) return;
        var executable = document.createElement('script');
        executable.textContent = source.textContent;
        document.body.appendChild(executable);
        executable.parentNode.removeChild(executable);
      });
    }
    if (window.EUMManualCandidatePreviewInit) {
      if (region.matches('.eum-manual-candidate-preview')) window.EUMManualCandidatePreviewInit(region);
      region.querySelectorAll('.eum-manual-candidate-preview').forEach(window.EUMManualCandidatePreviewInit);
    }
    if (window.EUMCandidateSourceToggleInit) window.EUMCandidateSourceToggleInit(region);
    var repeaters = [];
    if (region.matches('.eum-history-repeater')) repeaters.push(region);
    region.querySelectorAll('.eum-history-repeater').forEach(function (box) { repeaters.push(box); });
    repeaters.forEach(function (box) {
      if (box.dataset.bound) return;
      box.dataset.bound = '1';
      var button = box.querySelector('.eum-add-history-item');
      var list = box.querySelector('.eum-history-items');
      var template = box.querySelector('.eum-history-template');
      if (button && list && template) button.addEventListener('click', function () {
        var index = list.querySelectorAll('.eum-history-item').length;
        list.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, index));
      });
      box.addEventListener('click', function (event) {
        if (!event.target || !event.target.classList.contains('eum-remove-history-item')) return;
        event.preventDefault();
        var item = event.target.closest('.eum-history-item');
        if (item) item.remove();
      });
    });
    region.dispatchEvent(new CustomEvent('eum:region-updated', { bubbles: true }));
  }

  function buildGetUrl(form, submitter) {
    var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
    Array.prototype.forEach.call(form.elements || [], function (control) {
      if (control && control.name) url.searchParams.delete(control.name);
    });
    var data;
    try {
      data = submitter ? new FormData(form, submitter) : new FormData(form);
    } catch (error) {
      data = new FormData(form);
      if (submitter && submitter.name) data.append(submitter.name, submitter.value || '');
    }
    data.forEach(function (value, name) {
      if (typeof value === 'string') url.searchParams.append(name, value);
    });
    return url;
  }

  function buildPostData(form, submitter) {
    try {
      return submitter ? new FormData(form, submitter) : new FormData(form);
    } catch (error) {
      var data = new FormData(form);
      if (submitter && submitter.name) data.append(submitter.name, submitter.value || '');
      return data;
    }
  }

  function replaceAjaxRegions(regions, keys, html, responseUrl, options) {
    var parser = new DOMParser();
    var parsed = parser.parseFromString(html, 'text/html');
    var replacements = keys.map(function (key) { return parsed.querySelector(regionSelector(key)); });
    if (replacements.some(function (replacement) { return !replacement; })) {
      if (!(options && options.strictTargets) || (options && options.fallbackOnMissing)) window.location.assign(responseUrl || window.location.href);
      return false;
    }
    var updatedRegions = [];
    regions.forEach(function (region) {
      var key = region.getAttribute('data-eum-ajax-region');
      var replacement = replacements[keys.indexOf(key)];
      var updated = document.importNode(replacement, true);
      region.replaceWith(updated);
      updatedRegions.push(updated);
    });
    activeAjaxRegionKeys = keys.slice();
    updatedRegions.forEach(reinitializeAjaxRegion);
    if (options && options.pushState) {
      window.history.pushState({ eumAjaxRegions: keys.slice() }, '', responseUrl || window.location.href);
    }
    return true;
  }

  function requestAjaxRegions(regions, keys, url, options) {
    if (!regions.length || regions.some(function (region) { return region.getAttribute('data-eum-ajax-busy') === '1'; })) return Promise.resolve(false);
    activeAjaxRegionKeys = keys.slice();
    regions.forEach(function (region) {
      region.setAttribute('data-eum-ajax-busy', '1');
      region.setAttribute('aria-busy', 'true');
    });
    ajaxRequestCount++;
    showAjaxStatus(options && options.isPost ? 'Saving…' : 'Updating…', false);
    var fetchOptions = {
      method: options && options.method ? options.method : 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      redirect: 'follow',
      cache: 'no-store'
    };
    if (fetchOptions.method === 'POST') fetchOptions.body = options.body;
    return window.fetch(url, fetchOptions).then(function (response) {
      return response.text().then(function (html) {
        var responseDocument = new DOMParser().parseFromString(html, 'text/html');
        var notice = responseDocument.querySelector('[data-eum-job-notice="1"]');
        var currentRegions = keys.map(function (key) { return document.querySelector(regionSelector(key)); });
        if (currentRegions.some(function (region) { return !region; })) {
          if (options && options.isPost) window.location.assign(response.url || url);
          return false;
        }
        var replaced = replaceAjaxRegions(currentRegions, keys, html, response.url || url, {
          pushState: !(options && options.popstate),
          strictTargets: !!(options && options.strictTargets),
          fallbackOnMissing: !!(options && options.isPost)
        });
        if (replaced && notice) showAjaxStatus(notice.textContent.trim(), notice.getAttribute('data-eum-notice-type') === 'error');
        else if (replaced) showAjaxStatus(response.ok ? 'Updated.' : 'The server returned an error. Check the page and try again.', !response.ok);
        else if (!(options && options.isPost)) showAjaxStatus('The page responded, but the requested section could not be refreshed. Try again.', true);
        return replaced;
      });
    }).catch(function () {
      showAjaxStatus(options && options.isPost ? 'Couldn’t confirm the save. Check the listing before trying again.' : 'Couldn’t update this section. Please try again.', true);
      return false;
    }).then(function (result) {
      ajaxRequestCount = Math.max(0, ajaxRequestCount - 1);
      keys.forEach(function (key) {
        var current = document.querySelector(regionSelector(key));
        if (!current) return;
        current.removeAttribute('data-eum-ajax-busy');
        current.removeAttribute('aria-busy');
      });
      if (ajaxRequestCount === 0) {
        var status = document.getElementById('eum-ajax-status');
        if (status && (status.textContent === 'Saving…' || status.textContent === 'Updating…')) status.parentNode.removeChild(status);
      }
      return result;
    });
  }

  document.addEventListener('click', function (event) {
    var addButton = event.target.closest('[data-eum-preapproved-bulk-add-row]');
    if (addButton) {
      event.preventDefault();
      var form = addButton.closest('form');
      var rows = form ? form.querySelector('[data-eum-preapproved-bulk-rows]') : null;
      var template = form ? form.querySelector('template[data-eum-preapproved-bulk-row-template]') : null;
      if (!rows || !template) return;
      if (rows.querySelectorAll('[data-eum-preapproved-bulk-row]').length >= 50) {
        showAjaxStatus('Create no more than 50 candidate links per batch.', true);
        return;
      }
      var index = parseInt(rows.getAttribute('data-eum-next-index') || '1', 10) || 1;
      rows.setAttribute('data-eum-next-index', String(index + 1));
      rows.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(index)));
      return;
    }

    var removeButton = event.target.closest('[data-eum-preapproved-bulk-remove-row]');
    if (!removeButton) return;
    event.preventDefault();
    var row = removeButton.closest('[data-eum-preapproved-bulk-row]');
    var rowList = row ? row.parentElement : null;
    if (!row || !rowList) return;
    if (rowList.querySelectorAll('[data-eum-preapproved-bulk-row]').length <= 1) {
      row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
      return;
    }
    row.remove();
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches || !form.matches('form')) return;
    var hasExplicitTargets = !!form.getAttribute('data-eum-ajax-target');
    if (form.closest('[data-eum-ajax-ignore="1"]') && !hasExplicitTargets) return;
    var region = form.closest('[data-eum-ajax-region]');
    if ((!region && !hasExplicitTargets) || form.target && form.target !== '_self') return;
    var targets = ajaxTargetsFor(form, region);
    if (!targets.regions.length) return;
    var method = String(form.method || 'get').toUpperCase();
    if (method === 'GET') {
      var getUrl = buildGetUrl(form, event.submitter);
      if (getUrl.origin !== window.location.origin) return;
      event.preventDefault();
      requestAjaxRegions(targets.regions, targets.keys, getUrl.toString(), { method: 'GET', strictTargets: targets.strict });
      return;
    }
    if (method !== 'POST' || form.enctype === 'text/plain') return;
    var postUrl;
    // Read the attribute directly: a hidden input named "action" shadows form.action.
    var actionUrl = form.getAttribute('action') || window.location.href;
    try { postUrl = new URL(actionUrl, window.location.href); } catch (error) { return; }
    if (postUrl.origin !== window.location.origin) return;
    event.preventDefault();
    var submitter = event.submitter || null;
    if (submitter && submitter.disabled) return;
    if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') window.tinyMCE.triggerSave();
    var postData = buildPostData(form, submitter);
    if (submitter) submitter.disabled = true;
    requestAjaxRegions(targets.regions, targets.keys, postUrl.toString(), {
      method: 'POST',
      body: postData,
      isPost: true,
      strictTargets: targets.strict
    }).then(function () {
      if (submitter && submitter.isConnected) submitter.disabled = false;
    });
  }, true);

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented) return;
    var confirmMessage = link.getAttribute('data-eum-confirm');
    if (confirmMessage && !window.confirm(confirmMessage)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var hasExplicitTargets = !!link.getAttribute('data-eum-ajax-target');
    if (link.closest('[data-eum-ajax-ignore="1"]') && !hasExplicitTargets) return;
    if (link.target && link.target !== '_self' || link.hasAttribute('download')) return;
    var region = link.closest('[data-eum-ajax-region]');
    if (!region && !hasExplicitTargets) return;
    var targets = ajaxTargetsFor(link, region);
    if (!targets.regions.length) return;
    var url;
    try { url = new URL(link.href, window.location.href); } catch (error) { return; }
    if (url.origin !== window.location.origin || url.hash && url.href.split('#')[0] === window.location.href.split('#')[0]) return;
    event.preventDefault();
    requestAjaxRegions(targets.regions, targets.keys, url.toString(), { method: 'GET', strictTargets: targets.strict });
  }, true);

  window.addEventListener('popstate', function (event) {
    var keys = event.state && Array.isArray(event.state.eumAjaxRegions)
      ? event.state.eumAjaxRegions
      : (event.state && event.state.eumAjaxRegion ? [event.state.eumAjaxRegion] : activeAjaxRegionKeys);
    var regions = keys.map(function (key) { return document.querySelector(regionSelector(key)); }).filter(Boolean);
    if (!regions.length) return;
    requestAjaxRegions(regions, keys, window.location.href, { method: 'GET', popstate: true, strictTargets: true });
  });

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-eum-copy-candidate-link="1"]');
    if (!button) return;
    var card = button.closest('.eum-candidate-card');
    var input = card ? card.querySelector('.eum-candidate-card__invite-link input') : null;
    if (!input || !input.value) return;
    function report(success) {
      showAjaxStatus(success ? 'Invite link copied.' : 'Copy failed. Select the link and copy it manually.', !success);
    }
    if (navigator.clipboard && window.isSecureContext && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(input.value).then(function () { report(true); }).catch(function () { report(false); });
      return;
    }
    input.focus();
    input.select();
    try { report(document.execCommand('copy')); } catch (error) { report(false); }
  });
})();
