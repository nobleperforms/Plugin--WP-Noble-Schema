(function () {
  'use strict';

  // Flash notices are delivered via query args; drop them after first paint
  // so a refresh does not re-show the same success/error banner.
  clearFlashNoticeParams();
  initDialog();
  initRegenModal();
  initWizard();
  initSchemaTools();
  initSitewideAnalytics();
  initBatchRegen();
  initStorefront();
  initTransfer();
  initDataRemoval();
  initSchemaSettings();
  initLogs();
  initOnboarding();

  function clearFlashNoticeParams() {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('ns_notice') && !url.searchParams.has('ns_message')) {
      return;
    }
    url.searchParams.delete('ns_notice');
    url.searchParams.delete('ns_message');
    window.history.replaceState({}, '', url.toString());
  }

  function initSchemaSettings() {
    const page = document.getElementById('ns-schema-settings');
    if (!page) {
      return;
    }

    initMultiSelects(page);

    const updatesToggle = document.getElementById('ns-enable-updates');
    const ghRows = page.querySelectorAll('.ns-gh-settings-row');
    const updateStatus = document.getElementById('ns-gh-update-status');
    if (updatesToggle && (ghRows.length || updateStatus)) {
      const syncGh = () => {
        ghRows.forEach((row) => {
          row.hidden = !updatesToggle.checked;
        });
        if (updateStatus) {
          updateStatus.hidden = !updatesToggle.checked;
        }
      };
      updatesToggle.addEventListener('change', syncGh);
      syncGh();
    }

    const checkBtn = document.getElementById('ns-gh-check-updates');
    const statusMessage = document.getElementById('ns-gh-update-status-message');
    const statusMeta = document.getElementById('ns-gh-update-status-meta');
    const detailsWrap = document.getElementById('ns-gh-update-status-details');
    const detailsLink = document.getElementById('ns-gh-update-details-link');
    if (checkBtn) {
      const setUpdateDetailsLink = (available, status = {}) => {
        const cfg = window.nsNobleSchemaAdmin || {};
        const i18n = cfg.i18n || {};
        if (!detailsWrap || !detailsLink) {
          return;
        }
        const releaseUrl = String(status.release_url || '').trim();
        const latest = String(status.latest || '').trim();
        if (!available || !releaseUrl) {
          detailsWrap.hidden = true;
          detailsLink.href = '#';
          return;
        }
        detailsLink.href = releaseUrl;
        detailsLink.target = '_blank';
        detailsLink.rel = 'noopener noreferrer';
        detailsLink.textContent = latest
          ? (i18n.updatesViewDetails || 'View version %s details').replace('%s', latest)
          : (i18n.updatesViewDetails || 'View version %s details').replace(' %s', '').replace('%s', '');
        detailsWrap.hidden = false;
      };

      const setUpdateButtonMode = (available, status = {}) => {
        const cfg = window.nsNobleSchemaAdmin || {};
        const i18n = cfg.i18n || {};
        if (available) {
          checkBtn.dataset.mode = 'update';
          checkBtn.classList.add('button-primary');
          checkBtn.textContent = i18n.updatesUpdateNow || 'Update Now';
        } else {
          checkBtn.dataset.mode = 'check';
          checkBtn.classList.remove('button-primary');
          checkBtn.textContent = i18n.updatesCheckLabel || 'Check for updates';
        }
        setUpdateDetailsLink(available, status);
      };

      checkBtn.addEventListener('click', async () => {
        const cfg = window.nsNobleSchemaAdmin || {};
        const i18n = cfg.i18n || {};

        if (checkBtn.dataset.mode === 'update') {
          const url = cfg.pluginUpdateUrl || '';
          if (url) {
            window.location.href = url;
          }
          return;
        }

        checkBtn.disabled = true;
        if (statusMessage) {
          statusMessage.classList.remove('ns-gh-update-status__message--ok', 'ns-gh-update-status__message--error');
          statusMessage.textContent = i18n.updatesChecking || 'Checking GitHub for updates…';
        }
        try {
          const body = new URLSearchParams();
          body.set('action', 'ns_schema_settings_check_updates');
          body.set('nonce', cfg.settingsNonce || '');
          const res = await fetch(cfg.ajaxUrl || '', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
          });
          const json = await res.json();
          const payload = json?.data || {};
          const status = payload.status || {};
          const message = payload.message || i18n.updatesCheckFailed || 'Could not check for updates.';
          if (statusMessage) {
            statusMessage.textContent = message;
            statusMessage.classList.toggle('ns-gh-update-status__message--ok', !!json?.success);
            statusMessage.classList.toggle('ns-gh-update-status__message--error', !json?.success);
          }
          if (statusMeta) {
            statusMeta.textContent = new Date().toLocaleString();
          }
          const available = !!json?.success && status.code === 'update_available';
          setUpdateButtonMode(available, status);
        } catch (err) {
          if (statusMessage) {
            statusMessage.textContent = err?.message || i18n.updatesCheckFailed || 'Could not check for updates.';
            statusMessage.classList.remove('ns-gh-update-status__message--ok');
            statusMessage.classList.add('ns-gh-update-status__message--error');
          }
          setUpdateButtonMode(false);
        } finally {
          checkBtn.disabled = false;
        }
      });
    }

    const restrictToggle = document.getElementById('ns-restrict-schema-admin');
    const accessRows = page.querySelectorAll('.ns-access-settings-row');
    const admins = document.getElementById('ns-schema-admins');
    const cfg = window.nsNobleSchemaAdmin || {};
    const currentUserId = String(cfg.currentUserId || '');
    if (restrictToggle && accessRows.length) {
      const syncAccess = () => {
        accessRows.forEach((row) => {
          row.hidden = !restrictToggle.checked;
        });
        if (restrictToggle.checked && admins && typeof admins._nsSetLockedValues === 'function') {
          admins._nsSetLockedValues(currentUserId ? [currentUserId] : []);
        } else if (!restrictToggle.checked && admins && typeof admins._nsSetLockedValues === 'function') {
          admins._nsSetLockedValues([]);
        }
      };
      restrictToggle.addEventListener('change', syncAccess);
      syncAccess();
    }

    const loggingToggle = document.getElementById('ns-logging-enabled');
    const loggingSettings = document.getElementById('ns-logging-settings');
    const retentionSelect = document.getElementById('ns-logging-retention');
    const settingsForm = page.querySelector('.ns-settings-form');
    if (loggingToggle && loggingSettings) {
      const wasEnabledOnLoad = loggingToggle.getAttribute('data-was-enabled') === '1'
        || loggingToggle.defaultChecked;
      // Shorter rank = shorter retention (must match Log::retention_seconds order).
      const retentionRank = { '1h': 1, '1d': 2, '1w': 3, '1m': 4, '3m': 5 };

      const syncLogging = () => {
        const on = !!loggingToggle.checked;
        loggingSettings.hidden = !on;
        // form-table rows need an explicit display value (UA table-row overrides [hidden]).
        loggingSettings.style.display = on ? 'table-row' : 'none';
        if (on && retentionSelect && !retentionSelect.value) {
          retentionSelect.value = '3m';
        }
      };

      loggingToggle.addEventListener('change', () => {
        if (loggingToggle.checked && retentionSelect) {
          retentionSelect.value = '3m';
        }
        syncLogging();
      });

      const isLowerRetention = (next, previous) => {
        const nextRank = retentionRank[next] || 0;
        const prevRank = retentionRank[previous] || 0;
        return nextRank > 0 && prevRank > 0 && nextRank < prevRank;
      };

      // Confirm destructive logging changes on save (not on toggle), so UI can still preview.
      if (settingsForm) {
        settingsForm.addEventListener('submit', async (e) => {
          if (settingsForm.dataset.nsLoggingSaveConfirmed === '1') {
            return;
          }

          const disabling = wasEnabledOnLoad && !loggingToggle.checked;
          const initialRetention = retentionSelect?.dataset.initial || retentionSelect?.value || '3m';
          const nextRetention = retentionSelect?.value || initialRetention;
          const lowering = !disabling
            && !!loggingToggle.checked
            && !!retentionSelect
            && isLowerRetention(nextRetention, initialRetention);

          if (!disabling && !lowering) {
            return;
          }

          e.preventDefault();

          let ok = false;
          if (disabling) {
            ok = await window.nsConfirm(
              (cfg.i18n && cfg.i18n.loggingDisableBody)
                || 'All stored system logs will be deleted permanently. This cannot be undone.',
              {
                title: (cfg.i18n && cfg.i18n.loggingDisableTitle) || 'Turn off system logs?',
                confirmLabel: (cfg.i18n && cfg.i18n.loggingDisableConfirm) || 'Turn off & delete logs',
                destructive: true,
              }
            );
            if (!ok) {
              loggingToggle.checked = true;
              syncLogging();
              return;
            }
          } else {
            ok = await window.nsConfirm(
              (cfg.i18n && cfg.i18n.loggingRetentionBody)
                || 'Existing logs outside the shorter retention period may be removed when you save.',
              {
                title: (cfg.i18n && cfg.i18n.loggingRetentionTitle) || 'Lower log retention?',
                confirmLabel: (cfg.i18n && cfg.i18n.loggingRetentionConfirm) || 'Save & apply retention',
                destructive: true,
              }
            );
            if (!ok) {
              retentionSelect.value = initialRetention;
              return;
            }
          }

          settingsForm.dataset.nsLoggingSaveConfirmed = '1';
          if (typeof settingsForm.requestSubmit === 'function') {
            settingsForm.requestSubmit();
          } else {
            settingsForm.submit();
          }
        });
      }

      syncLogging();
    }
  }

  function initDialog() {
    const modal = document.getElementById('ns-dialog');
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    if (!modal) {
      window.nsAlert = (message) => {
        window.alert(message);
        return Promise.resolve(true);
      };
      window.nsConfirm = (message) => Promise.resolve(window.confirm(message));
      return;
    }

    const titleEl = modal.querySelector('.ns-dialog__title');
    const messageEl = modal.querySelector('.ns-dialog__message');
    const detailsEl = modal.querySelector('#ns-dialog-details');
    const phraseWrap = modal.querySelector('#ns-dialog-confirm-phrase');
    const phraseInput = modal.querySelector('#ns-dialog-confirm-input');
    const phraseValueEl = modal.querySelector('#ns-dialog-confirm-phrase-value');
    const cancelBtn = modal.querySelector('[data-ns-dialog-cancel]');
    const confirmBtn = modal.querySelector('[data-ns-dialog-confirm]');
    let resolver = null;
    let previousFocus = null;
    let requirePhrase = '';
    let dismissible = true;

    function escapeHtml(str) {
      return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function syncPhraseGate() {
      if (!requirePhrase) {
        confirmBtn.disabled = false;
        return;
      }
      confirmBtn.disabled = (phraseInput?.value || '') !== requirePhrase;
    }

    function finish(result) {
      modal.hidden = true;
      document.body.classList.remove('ns-dialog-open');
      confirmBtn.classList.remove('is-destructive');
      confirmBtn.disabled = false;
      requirePhrase = '';
      dismissible = true;
      modal.classList.remove('ns-dialog--blocking');
      if (detailsEl) {
        detailsEl.hidden = true;
        detailsEl.innerHTML = '';
      }
      if (phraseWrap) {
        phraseWrap.hidden = true;
      }
      if (phraseInput) {
        phraseInput.value = '';
      }
      const resolve = resolver;
      resolver = null;
      if (previousFocus && typeof previousFocus.focus === 'function') {
        previousFocus.focus({ preventScroll: true });
      }
      previousFocus = null;
      if (resolve) {
        resolve(result);
      }
    }

    function openDialog(options = {}) {
      const isConfirm = !!options.confirm;
      return new Promise((resolve) => {
        if (resolver) {
          finish(false);
        }
        resolver = resolve;
        previousFocus = document.activeElement;

        const title = options.title || (isConfirm
          ? (i18n.confirmTitle || 'Please confirm')
          : (i18n.alertTitle || 'Notice'));
        titleEl.textContent = title;
        titleEl.hidden = !title;
        messageEl.textContent = options.message || '';

        const details = Array.isArray(options.details) ? options.details.filter(Boolean) : [];
        if (detailsEl) {
          if (details.length) {
            detailsEl.innerHTML = details.map((item) => `<li>${escapeHtml(item)}</li>`).join('');
            detailsEl.hidden = false;
          } else {
            detailsEl.innerHTML = '';
            detailsEl.hidden = true;
          }
        }

        requirePhrase = isConfirm && options.requirePhrase
          ? String(options.requirePhrase)
          : '';
        if (phraseWrap && phraseInput) {
          if (requirePhrase) {
            phraseWrap.hidden = false;
            phraseInput.value = '';
            if (phraseValueEl) {
              phraseValueEl.textContent = requirePhrase;
            }
          } else {
            phraseWrap.hidden = true;
            phraseInput.value = '';
          }
        }

        dismissible = options.dismissible !== false;
        modal.classList.toggle('ns-dialog--blocking', !dismissible);

        cancelBtn.textContent = options.cancelLabel || i18n.cancel || 'Cancel';
        cancelBtn.hidden = !isConfirm;
        confirmBtn.textContent = options.confirmLabel
          || (isConfirm ? (i18n.ok || 'OK') : (i18n.ok || 'OK'));
        confirmBtn.classList.toggle('is-destructive', !!(isConfirm && options.destructive));
        syncPhraseGate();

        modal.hidden = false;
        document.body.classList.add('ns-dialog-open');
        if (requirePhrase && phraseInput) {
          phraseInput.focus({ preventScroll: true });
        } else {
          (isConfirm ? cancelBtn : confirmBtn).focus({ preventScroll: true });
        }
      });
    }

    confirmBtn.addEventListener('click', () => {
      if (requirePhrase && (phraseInput?.value || '') !== requirePhrase) {
        return;
      }
      finish(true);
    });
    cancelBtn.addEventListener('click', () => finish(false));
    phraseInput?.addEventListener('input', syncPhraseGate);
    phraseInput?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (!confirmBtn.disabled) {
          finish(true);
        }
      }
    });
    modal.querySelector('[data-ns-dialog-dismiss]')?.addEventListener('click', () => {
      if (!dismissible) {
        return;
      }
      finish(cancelBtn.hidden ? true : false);
    });

    document.addEventListener('keydown', (e) => {
      if (modal.hidden || e.key !== 'Escape' || !dismissible) {
        return;
      }
      e.preventDefault();
      finish(cancelBtn.hidden ? true : false);
    });

    window.nsAlert = (message, options = {}) => openDialog({
      message,
      title: options.title,
      confirmLabel: options.okLabel || i18n.ok || 'OK',
      details: options.details,
      dismissible: options.dismissible,
      confirm: false,
    });

    window.nsConfirm = (message, options = {}) => openDialog({
      message,
      title: options.title,
      confirmLabel: options.confirmLabel || i18n.ok || 'OK',
      cancelLabel: options.cancelLabel || i18n.cancel || 'Cancel',
      destructive: !!options.destructive,
      details: options.details,
      requirePhrase: options.requirePhrase || '',
      confirm: true,
    });

    document.addEventListener('click', async (e) => {
      const link = e.target.closest('a.ns-confirm-link');
      if (!link) {
        return;
      }
      e.preventDefault();
      const ok = await window.nsConfirm(
        link.dataset.nsConfirmMessage || i18n.deleteConfirm || 'Are you sure?',
        {
          title: link.dataset.nsConfirmTitle || i18n.deleteTitle || i18n.confirmTitle,
          confirmLabel: link.dataset.nsConfirmOk || i18n.delete || 'Delete',
          cancelLabel: link.dataset.nsConfirmCancel || i18n.cancel || 'Cancel',
          destructive: link.dataset.nsConfirmDestructive === '1',
        }
      );
      if (ok && link.href) {
        window.location.href = link.href;
      }
    });
  }

  function initRegenModal() {
    const cfg = window.nsNobleSchemaAdmin;
    const modal = document.getElementById('ns-regen-modal');
    if (!cfg || !modal) {
      return;
    }

    const titleEl = modal.querySelector('.ns-regen-modal__title');
    const descEl = modal.querySelector('.ns-regen-modal__desc');
    const statusEl = modal.querySelector('.ns-regen-modal__status');
    const rescanEl = modal.querySelector('.ns-regen-modal__rescan');
    const barEl = modal.querySelector('.ns-regen-modal__bar');
    const fillEl = modal.querySelector('.ns-regen-modal__bar-fill');
    const actionsEl = modal.querySelector('.ns-regen-modal__actions');
    const closeBtn = modal.querySelector('.ns-regen-modal__close');
    const retryBtn = modal.querySelector('.ns-regen-modal__retry');
    const cancelBtn = modal.querySelector('.ns-regen-modal__cancel');
    let running = false;
    let cancelledLocally = false;
    let sawCancelled = false;
    let redirectAfter = null;

    function formatProgress(status) {
      const total = status.total || 0;
      const processed = status.processed || 0;
      const formatCount = (n) => Number(n).toLocaleString();
      return (cfg.i18n.progress || 'Updating %1$d of %2$d posts…')
        .replace('%1$d', formatCount(processed))
        .replace('%2$d', formatCount(total));
    }

    function updateRescanNote(status) {
      if (!rescanEl) {
        return;
      }
      const extra = Number(status?.rescan_extra || 0);
      if (extra > 0 && !status?.done && !status?.cancelled) {
        rescanEl.textContent = (
          cfg.i18n.rescanExtra
          || '%s additional posts required rescan as a result of your changes'
        ).replace('%s', extra.toLocaleString());
        rescanEl.hidden = false;
        return;
      }
      rescanEl.textContent = '';
      rescanEl.hidden = true;
    }

    function setActionVisibility({ showCancel = false, showRetry = false, showClose = false } = {}) {
      if (actionsEl) {
        actionsEl.hidden = !(showCancel || showRetry || showClose);
      }
      if (cancelBtn) {
        cancelBtn.hidden = !showCancel;
        cancelBtn.disabled = false;
      }
      if (retryBtn) {
        retryBtn.hidden = !showRetry;
      }
      if (closeBtn) {
        closeBtn.hidden = !showClose;
      }
    }

    function setErrorState(message) {
      modal.classList.remove('is-complete', 'is-cancelled');
      titleEl.textContent = cfg.i18n.title || 'Updating schema on posts';
      descEl.textContent = '';
      statusEl.textContent = message || cfg.i18n.error;
      updateRescanNote(null);
      setActionVisibility({ showCancel: true, showRetry: true, showClose: false });
      if (retryBtn) {
        retryBtn.focus({ preventScroll: true });
      }
    }

    function setCancelledState(status, { local = false } = {}) {
      sawCancelled = true;
      modal.classList.remove('is-complete');
      modal.classList.add('is-cancelled');
      titleEl.textContent = cfg.i18n.regenCancelled || 'Schema update cancelled.';
      descEl.textContent = '';
      statusEl.textContent = local
        ? (cfg.i18n.regenCancelledLocal || 'Update cancelled successfully.')
        : (cfg.i18n.regenCancelledRemote
          || 'This action was cancelled by another user/window.');
      updateRescanNote({ ...status, cancelled: true });
      const processed = Number(status?.processed || 0);
      const total = Number(status?.total || 0);
      const percent = status?.percent ?? (total > 0 ? Math.round((processed / total) * 100) : 0);
      fillEl.style.width = `${Math.max(percent, 8)}%`;
      barEl.setAttribute('aria-valuenow', String(percent));
      setActionVisibility({ showCancel: false, showRetry: false, showClose: true });
      closeBtn?.focus({ preventScroll: true });
    }

    function updateUI(status) {
      // Cancel wins over completion. In-flight batch responses can arrive after
      // cancel was already applied (locally or remotely) and must not overwrite.
      if (status?.cancelled || sawCancelled || cancelledLocally) {
        setCancelledState(status, { local: cancelledLocally });
        return true;
      }

      modal.classList.remove('is-cancelled');
      const percent = status.done ? 100 : (status.percent || 0);
      fillEl.style.width = `${percent}%`;
      barEl.setAttribute('aria-valuenow', String(percent));
      modal.classList.toggle('is-complete', !!status.done);

      if (status.done) {
        titleEl.textContent = cfg.i18n.complete || 'Schema update complete';
        descEl.textContent = '';
        statusEl.textContent = cfg.i18n.done || 'All posts updated.';
        updateRescanNote(status);
        setActionVisibility({ showCancel: false, showRetry: false, showClose: true });
        closeBtn?.focus({ preventScroll: true });
      } else {
        titleEl.textContent = cfg.i18n.title || 'Updating schema on posts';
        descEl.textContent = cfg.i18n.working || '';
        statusEl.textContent = formatProgress(status);
        updateRescanNote(status);
        setActionVisibility({ showCancel: true, showRetry: false, showClose: false });
      }
      return false;
    }

    function openModal(status, afterUrl) {
      redirectAfter = afterUrl || null;
      cancelledLocally = false;
      sawCancelled = false;
      modal.classList.remove('is-cancelled', 'is-complete');
      modal.hidden = false;
      document.body.classList.add('ns-regen-modal-open');
      if (status?.cancelled) {
        setCancelledState(status);
        return;
      }
      updateUI(status || { total: 0, processed: 0, percent: 0, done: false });
      if (!status || !status.done) {
        runBatches();
      }
    }

    async function closeModal() {
      if (sawCancelled) {
        try {
          await postAction('ns_regen_ack_cancelled');
        } catch (err) {
          // Non-blocking: notice expires server-side anyway.
        }
      }
      modal.hidden = true;
      document.body.classList.remove('ns-regen-modal-open');
      running = false;
      cancelledLocally = false;
      sawCancelled = false;
      if (redirectAfter) {
        window.location.href = redirectAfter;
      } else {
        const url = new URL(window.location.href);
        url.searchParams.delete('ns_regen_modal');
        window.history.replaceState({}, '', url.toString());
      }
    }

    async function postAction(action) {
      const body = new URLSearchParams();
      body.set('action', action);
      body.set('nonce', cfg.regenNonce);
      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      });

      const contentType = res.headers.get('content-type') || '';
      if (!res.ok) {
        const template = cfg.i18n.httpError || 'Server error (%d). You can retry from where you left off.';
        throw new Error(template.replace('%d', String(res.status)));
      }

      if (!contentType.includes('application/json')) {
        throw new Error(cfg.i18n.error || 'Unexpected server response.');
      }

      const json = await res.json();
      if (!json || !json.success) {
        throw new Error((json && json.data && json.data.message) || cfg.i18n.error);
      }
      return json.data;
    }

    async function runBatches() {
      if (running || sawCancelled || cancelledLocally) {
        return;
      }
      running = true;
      setActionVisibility({ showCancel: true, showRetry: false, showClose: false });
      try {
        let status = await postAction('ns_regen_status');
        if (updateUI(status)) {
          return;
        }
        while (!status.done && !sawCancelled && !cancelledLocally) {
          status = await postAction('ns_regen_process_batch');
          if (updateUI(status)) {
            break;
          }
        }
      } catch (err) {
        if (!sawCancelled && !cancelledLocally) {
          setErrorState(err.message || cfg.i18n.error);
        }
      } finally {
        running = false;
      }
    }

    async function requestCancel() {
      if (cancelBtn?.disabled || sawCancelled) {
        return;
      }
      const ok = await window.nsConfirm(
        cfg.i18n.regenCancelConfirm
          || 'Stop updating schema on remaining posts? Posts already updated will keep their new schema.',
        {
          title: cfg.i18n.regenCancelTitle || 'Cancel schema update?',
          confirmLabel: cfg.i18n.cancel || 'Cancel',
          cancelLabel: cfg.i18n.regenCancelKeep || 'Keep updating',
          destructive: true,
        }
      );
      if (!ok) {
        return;
      }

      cancelledLocally = true;
      if (cancelBtn) {
        cancelBtn.disabled = true;
      }

      try {
        // Persist cancel immediately so other windows / in-flight batches see it.
        const status = await postAction('ns_regen_cancel');
        redirectAfter = null;
        setCancelledState(status, { local: true });

        // Wait for a local in-flight batch loop to exit.
        const waitStart = Date.now();
        while (running && Date.now() - waitStart < 120000) {
          await new Promise((resolve) => setTimeout(resolve, 50));
        }
      } catch (err) {
        cancelledLocally = false;
        setErrorState(err.message || cfg.i18n.error);
      }
    }

    closeBtn?.addEventListener('click', () => {
      closeModal();
    });
    cancelBtn?.addEventListener('click', () => {
      requestCancel();
    });
    retryBtn?.addEventListener('click', () => {
      if (running || sawCancelled) {
        return;
      }
      runBatches();
    });

    window.nsStartRegenModal = function (status, afterUrl) {
      if (status?.cancelled) {
        openModal(status, afterUrl);
        return;
      }
      if (!status || status.done || !(status.remaining > 0 || status.total > 0)) {
        if (afterUrl) {
          window.location.href = afterUrl;
        }
        return;
      }
      openModal(status, afterUrl);
    };

    if (cfg.autoStart && cfg.status) {
      if (cfg.status.cancelled) {
        openModal(cfg.status, null);
      } else if (!cfg.status.done && cfg.status.remaining > 0) {
        openModal(cfg.status, null);
      }
    }
  }


  function nsEscapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  function parseJsonScript(el) {
    if (!el) {
      return [];
    }
    try {
      const parsed = JSON.parse(el.textContent || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
      return [];
    }
  }

  function getMultiSelectValues(rootId) {
    const root = document.getElementById(rootId);
    if (!root) {
      return [];
    }
    return Array.from(root.querySelectorAll('.ns-multiselect__values input'))
      .map((input) => input.value)
      .filter(Boolean);
  }

  function getMultiSelectLabels(rootId) {
    const root = document.getElementById(rootId);
    if (!root) {
      return [];
    }
    return Array.from(root.querySelectorAll('.ns-multiselect__pill'))
      .map((pill) => pill.dataset.label || pill.dataset.value || '')
      .filter(Boolean);
  }

    function initMultiSelect(root) {
    const input = root.querySelector('.ns-multiselect__input');
    const dropdown = root.querySelector('.ns-multiselect__dropdown');
    const pills = root.querySelector('.ns-multiselect__pills');
    const valuesEl = root.querySelector('.ns-multiselect__values');
    const name = root.dataset.name || '';
    const allowCustom = root.dataset.allowCustom === '1';
    const remote = root.dataset.remote || '';
    let options = parseJsonScript(root.querySelector('.ns-multiselect__options'));
    const selected = parseJsonScript(root.querySelector('.ns-multiselect__selected'));
    const locked = new Set(
      parseJsonScript(root.querySelector('.ns-multiselect__locked')).map((v) => String(v))
    );
    const labelByValue = new Map(options.map((opt) => [String(opt.value), String(opt.label || opt.value)]));
    let activeIndex = -1;
    let hydrating = true;
    let debounceTimer = null;
    let requestId = 0;
    let remoteOptions = options.slice();

    selected.forEach((value) => {
      if (!labelByValue.has(String(value))) {
        labelByValue.set(String(value), String(value));
      }
    });

    function currentValues() {
      return Array.from(valuesEl.querySelectorAll('input')).map((el) => el.value);
    }

    function syncHiddenInputs(values) {
      valuesEl.innerHTML = '';
      values.forEach((value) => {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = name;
        hidden.value = value;
        valuesEl.appendChild(hidden);
      });
    }

    function renderPills() {
      const values = currentValues();
      pills.innerHTML = '';
      pills.hidden = values.length === 0;
      values.forEach((value) => {
        const label = labelByValue.get(value) || value;
        const pill = document.createElement('span');
        pill.className = 'ns-multiselect__pill';
        pill.dataset.value = value;
        pill.dataset.label = label;
        if (locked.has(value)) {
          pill.classList.add('is-locked');
          pill.innerHTML = `<span class="ns-multiselect__pill-label">${nsEscapeHtml(label)}</span>`;
        } else {
          pill.innerHTML = `<span class="ns-multiselect__pill-label">${nsEscapeHtml(label)}</span>`
            + `<button type="button" class="ns-multiselect__pill-remove" aria-label="${nsEscapeHtml(`Remove ${label}`)}">&times;</button>`;
        }
        pills.appendChild(pill);
      });
    }

    function setValues(values) {
      const unique = [];
      values.forEach((value) => {
        const v = String(value || '').trim();
        if (v && !unique.includes(v)) {
          unique.push(v);
        }
      });
      locked.forEach((value) => {
        if (value && !unique.includes(value)) {
          unique.unshift(value);
        }
      });
      syncHiddenInputs(unique);
      renderPills();
      if (remote !== 'posts') {
        renderDropdown(input.value);
      }
      const hooks = root._nsHooks || {};
      if (typeof hooks.clearErrors === 'function') {
        hooks.clearErrors(root);
      }
      if (!hydrating && typeof hooks.onChange === 'function') {
        hooks.onChange();
      }
    }

    function addValue(value, label) {
      const v = String(value || '').trim();
      if (!v) {
        return;
      }
      if (label) {
        labelByValue.set(v, String(label));
      } else if (!labelByValue.has(v)) {
        labelByValue.set(v, v);
      }
      const next = currentValues();
      if (!next.includes(v)) {
        next.push(v);
        setValues(next);
      }
      input.value = '';
      activeIndex = -1;
    }

    function removeValue(value) {
      if (locked.has(String(value))) {
        return;
      }
      setValues(currentValues().filter((v) => v !== value));
    }

    function setLockedValues(values) {
      locked.clear();
      (values || []).forEach((value) => {
        const v = String(value || '').trim();
        if (v) {
          locked.add(v);
        }
      });
      setValues(currentValues());
    }

    root._nsSetLockedValues = setLockedValues;

    function filteredOptions(query) {
      const q = String(query || '').trim().toLowerCase();
      const selectedSet = new Set(currentValues());
      const source = remote === 'posts' ? remoteOptions : options;
      return source.filter((opt) => {
        const value = String(opt.value);
        if (selectedSet.has(value)) {
          return false;
        }
        if (!q || remote === 'posts') {
          return true;
        }
        const label = String(opt.label || opt.value).toLowerCase();
        return label.includes(q) || value.toLowerCase().includes(q);
      });
    }

    async function fetchRemotePosts(query) {
      const cfg = window.nsNobleSchemaAdmin || {};
      const params = new URLSearchParams({
        action: 'ns_search_posts',
        nonce: cfg.postsNonce || '',
      });
      if (query) {
        params.set('search', query);
      }
      const id = ++requestId;
      try {
        const res = await fetch(`${cfg.ajaxUrl}?${params.toString()}`, {
          credentials: 'same-origin',
        });
        const json = await res.json();
        if (id !== requestId) {
          return;
        }
        const posts = json?.data?.posts || [];
        remoteOptions = posts;
        posts.forEach((opt) => {
          labelByValue.set(String(opt.value), String(opt.label || opt.value));
        });
        renderDropdown(query);
      } catch (err) {
        if (id === requestId) {
          remoteOptions = [];
          renderDropdown(query);
        }
      }
    }

    function closeDropdown() {
      dropdown.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      activeIndex = -1;
    }

    function openDropdown() {
      if (remote === 'posts') {
        dropdown.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        fetchRemotePosts(input.value);
        return;
      }
      renderDropdown(input.value);
      dropdown.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function renderDropdown(query) {
      const items = filteredOptions(query);
      dropdown.innerHTML = '';

      if (items.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'ns-multiselect__empty';
        empty.textContent = allowCustom && String(query || '').trim()
          ? 'Press Enter to add this value'
          : (remote === 'posts' && !String(query || '').trim() ? 'Type to search posts…' : 'No matches');
        dropdown.appendChild(empty);
        activeIndex = -1;
        return;
      }

      items.forEach((opt, index) => {
        const li = document.createElement('li');
        li.className = 'ns-multiselect__option';
        li.setAttribute('role', 'option');
        li.dataset.value = opt.value;
        li.dataset.label = opt.label || opt.value;
        li.textContent = opt.label || opt.value;
        if (index === activeIndex) {
          li.classList.add('is-active');
        }
        dropdown.appendChild(li);
      });
    }

    pills.addEventListener('click', (e) => {
      const btn = e.target.closest('.ns-multiselect__pill-remove');
      if (!btn) {
        return;
      }
      const pill = btn.closest('.ns-multiselect__pill');
      if (pill?.dataset.value) {
        removeValue(pill.dataset.value);
      }
    });

    input.addEventListener('focus', openDropdown);
    input.addEventListener('click', openDropdown);

    input.addEventListener('input', () => {
      activeIndex = -1;
      if (remote === 'posts') {
        dropdown.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => fetchRemotePosts(input.value), 200);
        return;
      }
      openDropdown();
    });

    input.addEventListener('keydown', (e) => {
      const items = Array.from(dropdown.querySelectorAll('.ns-multiselect__option'));
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (remote !== 'posts') {
          openDropdown();
        } else {
          dropdown.hidden = false;
          input.setAttribute('aria-expanded', 'true');
        }
        activeIndex = Math.min(activeIndex + 1, Math.max(items.length - 1, 0));
        items.forEach((el, i) => el.classList.toggle('is-active', i === activeIndex));
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        items.forEach((el, i) => el.classList.toggle('is-active', i === activeIndex));
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        const refreshed = Array.from(dropdown.querySelectorAll('.ns-multiselect__option'));
        if (activeIndex >= 0 && refreshed[activeIndex]) {
          addValue(refreshed[activeIndex].dataset.value, refreshed[activeIndex].dataset.label);
          if (remote === 'posts') {
            fetchRemotePosts('');
          } else {
            openDropdown();
          }
          return;
        }
        if (allowCustom && input.value.trim()) {
          addValue(input.value.trim());
          openDropdown();
        }
        return;
      }
      if (e.key === 'Escape') {
        closeDropdown();
        return;
      }
      if (e.key === 'Backspace' && !input.value) {
        const values = currentValues();
        if (values.length) {
          removeValue(values[values.length - 1]);
        }
      }
    });

    dropdown.addEventListener('mousedown', (e) => {
      const option = e.target.closest('.ns-multiselect__option');
      if (!option) {
        return;
      }
      e.preventDefault();
      addValue(option.dataset.value, option.dataset.label);
      if (remote === 'posts') {
        fetchRemotePosts('');
      } else {
        openDropdown();
      }
      input.focus();
    });

    document.addEventListener('click', (e) => {
      if (!root.contains(e.target)) {
        closeDropdown();
      }
    });

    setValues(selected);
    hydrating = false;
  }

  function initMultiSelects(scope) {
    const rootEl = scope && scope.querySelectorAll ? scope : document;
    rootEl.querySelectorAll('.ns-multiselect').forEach((root) => {
      if (root.dataset.nsMultiselectReady === '1') {
        return;
      }
      root.dataset.nsMultiselectReady = '1';
      initMultiSelect(root);
    });
  }


  function initWizard() {
  const dataEl = document.getElementById('ns-wizard-data');
  if (!dataEl) {
    return;
  }

  const data = JSON.parse(dataEl.textContent || '{}');

  // Configs are stored once for the whole site, so a translated admin view has
  // to switch back to the default language before anything can be edited.
  const wpmlNotice = data.wpmlNotice;
  if (wpmlNotice && wpmlNotice.switchUrl && typeof window.nsAlert === 'function') {
    window.nsAlert(wpmlNotice.message, {
      title: wpmlNotice.title,
      okLabel: wpmlNotice.button,
      dismissible: false,
    }).then(() => {
      window.location.href = wpmlNotice.switchUrl;
    });
  }

  const form = document.getElementById('ns-wizard-form');
  const panels = Array.from(document.querySelectorAll('.ns-wizard__panel'));
  const steps = Array.from(document.querySelectorAll('.ns-wizard__step'));
  const continueBtn = document.querySelector('.ns-wizard__continue');
  const backBtn = document.querySelector('.ns-wizard__back');
  const saveBtn = document.querySelector('.ns-wizard__save');
  const enabledToggle = document.getElementById('ns-config-enabled');
  const placementRadios = Array.from(document.querySelectorAll('input[name="placement"]'));
  const placementTargets = document.getElementById('ns-placement-targets');
  const additionalFilters = document.getElementById('ns-additional-filters');
  const templateFieldset = document.getElementById('ns-template-fieldset');
  const templateRadios = Array.from(document.querySelectorAll('input[name="template_slug"]'));
  const fieldMappingsEl = document.getElementById('ns-field-mappings');
  const summaryChips = document.querySelector('.ns-target-summary__chips');
  const summaryCountEl = document.getElementById('ns-target-summary-count');
  const ruleGroupsEl = document.getElementById('ns-rule-groups');
  const filterValuesEl = document.getElementById('ns-filter-values');
  const configNameInput = document.getElementById('ns-config-name');
  const cfg = window.nsNobleSchemaAdmin || {};
  const adminI18n = cfg.i18n || {};

  let currentStep = 1;
  let renderedTemplateSlug = null;
  let isDirty = false;
  let saving = false;
  let hasSaved = !!(data.configId > 0);
  let nameManuallyEdited = !!(data.configName && String(data.configName).trim());
  let summaryPreviewTimer = null;
  let summaryPreviewRequestId = 0;

  function markDirty() {
    isDirty = true;
    setStepStatus(currentStep, null);
    updateStepTabs();
  }

  /**
   * @param {number} step
   * @param {'valid'|'error'|null} status
   */
  function setStepStatus(step, status) {
    const el = document.querySelector(`.ns-wizard__step[data-step="${step}"]`);
    if (!el) {
      return;
    }
    const badge = el.querySelector('.ns-wizard__step-status');
    el.classList.remove('is-valid', 'is-error');
    if (!badge) {
      return;
    }
    if (!status) {
      badge.hidden = true;
      badge.className = 'ns-wizard__step-status';
      badge.removeAttribute('aria-label');
      return;
    }
    el.classList.add(status === 'valid' ? 'is-valid' : 'is-error');
    badge.hidden = false;
    badge.className = `ns-wizard__step-status ns-wizard__step-status--${status}`;
    badge.setAttribute(
      'aria-label',
      status === 'valid' ? 'Step is valid' : 'Step has errors'
    );
  }

  function focusFirstStepError(step) {
    const panel = document.querySelector(`.ns-wizard__panel[data-panel="${step}"]`);
    if (!panel) {
      return;
    }
    const invalid = panel.querySelector('[aria-invalid="true"]');
    if (invalid && typeof invalid.focus === 'function') {
      invalid.focus({ preventScroll: true });
      return;
    }
    const errorEl = panel.querySelector('.ns-field-error:not([hidden])');
    if (errorEl) {
      const relatedId = errorEl.id ? errorEl.id.replace(/-error$/, '') : '';
      const related = relatedId ? document.getElementById(relatedId) : null;
      (related || errorEl).focus?.({ preventScroll: true });
    }
  }

  /**
   * Silent validity check (no field errors / focus). Used for badges on edit load.
   * @param {number} step
   * @returns {boolean}
   */
  function isStepValid(step) {
    if (step === 1) {
      return step1Configured();
    }
    if (step === 2) {
      return selectedPlacementMode() !== 'targeted' || placementConfigured();
    }
    if (step === 3) {
      return fieldsConfigured();
    }
    return true;
  }

  /** Edit only: show valid/error badges for each step without changing the active tab. */
  function paintInitialStepStatuses() {
    if (!(data.configId > 0)) {
      return;
    }
    for (let step = 1; step <= 3; step++) {
      setStepStatus(step, isStepValid(step) ? 'valid' : 'error');
    }
  }

  /**
   * Validate steps 1→3, paint status badges, and jump to the first invalid tab.
   * @returns {boolean}
   */
  function validateAllStepsAndFocus() {
    // Field mapping inputs only exist after step 3 has rendered.
    renderFieldMappings();

    let firstInvalid = null;
    for (let step = 1; step <= 3; step++) {
      const ok = validateStep(step);
      setStepStatus(step, ok ? 'valid' : 'error');
      if (!ok && firstInvalid === null) {
        firstInvalid = step;
      }
    }

    if (firstInvalid !== null) {
      setStep(firstInvalid);
      focusFirstStepError(firstInvalid);
      return false;
    }

    return true;
  }

  function showError(errorId, message, inputEl) {
    const errorEl = document.getElementById(errorId);
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    }
    if (inputEl) {
      inputEl.setAttribute('aria-invalid', 'true');
      if (errorEl) {
        inputEl.setAttribute('aria-describedby', errorId);
      }
    }
  }

  function clearFieldError(errorId, inputEl) {
    const errorEl = document.getElementById(errorId);
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
    if (inputEl) {
      inputEl.removeAttribute('aria-invalid');
      inputEl.removeAttribute('aria-describedby');
    }
  }

  function clearStepErrors(step) {
    document.querySelectorAll(`.ns-wizard__panel[data-panel="${step}"] .ns-field-error`).forEach((el) => {
      el.hidden = true;
      el.textContent = '';
    });
    document.querySelectorAll(`.ns-wizard__panel[data-panel="${step}"] [aria-invalid]`).forEach((el) => {
      el.removeAttribute('aria-invalid');
      el.removeAttribute('aria-describedby');
    });
    if (step === 1 && templateFieldset) {
      templateFieldset.classList.remove('has-error');
    }
  }

  function setStep(step) {
    currentStep = step;
    panels.forEach((panel) => {
      const n = Number(panel.dataset.panel);
      const active = n === step;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
    steps.forEach((el) => {
      const n = Number(el.dataset.step);
      const isActive = n === step;
      el.classList.toggle('is-active', isActive);
      el.classList.remove('is-complete');
      const btn = el.querySelector('.ns-wizard__step-btn');
      if (btn) {
        if (isActive) {
          btn.setAttribute('aria-current', 'step');
        } else {
          btn.removeAttribute('aria-current');
        }
      }
    });

    backBtn.disabled = step === 1;
    continueBtn.disabled = step === 3;
    updateStepTabs();
    syncStepToUrl(step);

    if (step === 2) {
      updateTargetSummary();
    }
    if (step === 3) {
      renderFieldMappings();
    }
  }

  function syncStepToUrl(step) {
    const url = new URL(window.location.href);
    if (step <= 1) {
      url.searchParams.delete('ns_step');
    } else {
      url.searchParams.set('ns_step', String(step));
    }
    window.history.replaceState({}, '', url.toString());
  }

  function withStepParam(urlString, step) {
    try {
      const url = new URL(urlString, window.location.origin);
      if (step <= 1) {
        url.searchParams.delete('ns_step');
      } else {
        url.searchParams.set('ns_step', String(step));
      }
      return url.toString();
    } catch (err) {
      return urlString;
    }
  }

  function readInitialStep() {
    const params = new URLSearchParams(window.location.search);
    const fromUrl = Number(params.get('ns_step'));
    if (fromUrl >= 1 && fromUrl <= 3 && canVisitStep(fromUrl)) {
      return fromUrl;
    }
    return 1;
  }

  function canVisitStep(step) {
    if (step === 1) {
      return true;
    }
    return step1Configured();
  }

  function updateStepTabs() {
    steps.forEach((el) => {
      const n = Number(el.dataset.step);
      const btn = el.querySelector('.ns-wizard__step-btn');
      if (!btn) {
        return;
      }
      btn.disabled = !canVisitStep(n);
    });
  }

  function selectedTemplateSlug() {
    const checked = templateRadios.find((r) => r.checked);
    return checked ? checked.value : '';
  }

  function defaultConfigName(slug) {
    const template = data.templates && data.templates[slug];
    const label = template && template.label ? String(template.label).trim() : '';
    if (!label) {
      return '';
    }
    return `${label} Schema`;
  }

  function maybeAutofillConfigName(slug = selectedTemplateSlug()) {
    if (!configNameInput || nameManuallyEdited || !slug) {
      return;
    }
    const nextName = defaultConfigName(slug);
    if (!nextName) {
      return;
    }
    configNameInput.value = nextName;
    clearFieldError('ns-config-name-error', configNameInput);
    updateStepTabs();
  }

  templateRadios.forEach((radio) => {
    radio.addEventListener('change', () => {
      document.querySelectorAll('.ns-template-card').forEach((card) => {
        card.classList.toggle('is-selected', card.querySelector('input').checked);
      });
      renderedTemplateSlug = null;
      maybeAutofillConfigName(radio.value);
      renderFieldMappings();
      clearFieldError('ns-template-error');
      if (templateFieldset) {
        templateFieldset.classList.remove('has-error');
      }
      markDirty();
    });
  });

  function selectedPlacementMode() {
    const checked = placementRadios.find((r) => r.checked);
    return checked ? checked.value : 'global';
  }

  function syncPlacementMode() {
    const mode = selectedPlacementMode();
    if (placementTargets) {
      placementTargets.hidden = mode !== 'targeted';
    }
    if (additionalFilters) {
      additionalFilters.hidden = mode === 'sitewide';
    }
    syncFilterHiddenInputs();
    syncSourceTypeOptions();
    updateTargetSummary();
  }

  placementRadios.forEach((radio) => {
    radio.addEventListener('change', () => {
      clearFieldError('ns-placement-error');
      clearStepErrors(2);
      syncPlacementMode();
      markDirty();
    });
  });

  function createRuleRow() {
    const tpl = document.getElementById('ns-rule-row-template');
    const row = tpl.content.firstElementChild.cloneNode(true);
    initSearchSelectsInRow(row);
    return row;
  }

  function blockSearchOptions() {
    const blocks = Array.isArray(data.blockTypes) ? data.blockTypes : [];
    return blocks.map((name) => ({ value: String(name), label: String(name) }));
  }

  function blockAttrSearchOptions(blockName) {
    const key = String(blockName || '').trim();
    if (!key) {
      return [];
    }
    const map = data.blockAttributes && typeof data.blockAttributes === 'object'
      ? data.blockAttributes
      : {};
    const attrs = Array.isArray(map[key]) ? map[key] : [];
    return attrs.map((name) => ({ value: String(name), label: String(name) }));
  }

  function initSearchSelect(root, config = {}) {
    if (!root || root.dataset.searchReady === '1') {
      return;
    }
    root.dataset.searchReady = '1';

    const input = root.querySelector('.ns-search-select__input');
    const dropdown = root.querySelector('.ns-search-select__dropdown');
    const clearBtn = root.querySelector('.ns-search-select__clear');
    const hidden = root.querySelector('input[type="hidden"]');
    const mode = root.dataset.searchMode || config.mode || 'static';
    const allowCustom = root.dataset.allowCustom === '1' || !!config.allowCustom;
    let options = Array.isArray(config.options) ? config.options.slice() : [];
    let selectedLabel = '';
    let activeIndex = -1;
    let debounceTimer = null;
    let requestId = 0;
    let isOpen = false;

    function getTaxonomy() {
      return root.closest('.ns-rule-row')?.querySelector('.ns-rule-taxonomy')?.value || '';
    }

    function getRelatedBlockName() {
      const scope = root.closest('.ns-source-config')
        || root.closest('.ns-field-mapping')
        || root.closest('.ns-rule-row');
      if (!scope) {
        return '';
      }
      return scope.querySelector('.ns-search-select[data-search-mode="blocks"] input[type="hidden"]')?.value?.trim() || '';
    }

    function currentValue() {
      return hidden?.value || '';
    }

    function syncDisplay() {
      const has = !!currentValue();
      input.classList.toggle('has-value', has);
      if (clearBtn) {
        clearBtn.hidden = !has || input.disabled;
      }
      if (!isOpen) {
        input.value = has ? (selectedLabel || currentValue()) : '';
      }
    }

    let hydrating = true;

    function setValue(value, label, opts = {}) {
      const v = String(value || '').trim();
      if (hidden) {
        hidden.value = v;
        if (v) {
          hidden.dataset.label = String(label || v);
        } else {
          delete hidden.dataset.label;
        }
      }
      selectedLabel = v ? String(label || v) : '';
      isOpen = false;
      syncDisplay();
      closeDropdown();
      if (!opts.silent && !hydrating) {
        if (typeof config.onChange === 'function') {
          config.onChange(v, root);
        } else {
          refreshRuleGroupsUi();
        }
        markDirty();
      }
    }

    function clearValue(opts = {}) {
      setValue('', '', opts);
    }

    function closeDropdown() {
      dropdown.hidden = true;
      activeIndex = -1;
      isOpen = false;
    }

    function openDropdown() {
      dropdown.hidden = false;
      isOpen = true;
    }

    function renderDropdown(items, query) {
      dropdown.innerHTML = '';
      if (!items.length) {
        const empty = document.createElement('li');
        empty.className = 'ns-search-select__empty';
        if (mode === 'terms' && !getTaxonomy()) {
          empty.textContent = 'Select a taxonomy first';
        } else if (mode === 'block_attrs' && !getRelatedBlockName()) {
          empty.textContent = 'Select a block first';
        } else if (allowCustom && String(query || '').trim()) {
          empty.textContent = 'Press Enter to use this value';
        } else {
          empty.textContent = 'No matches';
        }
        dropdown.appendChild(empty);
        activeIndex = -1;
        return;
      }

      items.forEach((opt, index) => {
        const li = document.createElement('li');
        li.className = 'ns-search-select__option';
        li.setAttribute('role', 'option');
        li.dataset.value = opt.value;
        li.dataset.label = opt.label || opt.value;
        li.textContent = opt.label || opt.value;
        if (String(opt.value) === currentValue()) {
          li.classList.add('is-selected');
        }
        if (index === activeIndex) {
          li.classList.add('is-active');
        }
        dropdown.appendChild(li);
      });
    }

    function filterStatic(query) {
      const q = String(query || '').trim().toLowerCase();
      return options.filter((opt) => {
        if (!q) {
          return true;
        }
        return String(opt.label || '').toLowerCase().includes(q)
          || String(opt.value || '').toLowerCase().includes(q);
      });
    }

    async function fetchTerms(query, exactTermId) {
      const taxonomy = getTaxonomy();
      if (!taxonomy) {
        renderDropdown([], query);
        return;
      }

      const cfg = window.nsNobleSchemaAdmin || {};
      const params = new URLSearchParams({
        action: 'ns_search_terms',
        nonce: cfg.termsNonce || '',
        taxonomy,
      });
      if (exactTermId) {
        params.set('term_id', exactTermId);
      } else if (query) {
        params.set('search', query);
      }

      const id = ++requestId;
      try {
        const res = await fetch(`${cfg.ajaxUrl}?${params.toString()}`, {
          credentials: 'same-origin',
        });
        const json = await res.json();
        if (id !== requestId) {
          return;
        }
        const terms = json?.data?.terms || [];
        options = terms;
        if (exactTermId && terms[0]) {
          if (hidden.value === terms[0].value) {
            selectedLabel = terms[0].label;
            if (!isOpen) {
              syncDisplay();
            }
          }
          return;
        }
        renderDropdown(filterStatic(query), query);
      } catch (err) {
        if (id === requestId) {
          renderDropdown([], query);
        }
      }
    }

    async function fetchMetaKeys(query) {
      const cfg = window.nsNobleSchemaAdmin || {};
      const params = new URLSearchParams({
        action: 'ns_search_meta_keys',
        nonce: cfg.metaKeysNonce || '',
      });
      if (query) {
        params.set('search', query);
      }

      const id = ++requestId;
      try {
        const res = await fetch(`${cfg.ajaxUrl}?${params.toString()}`, {
          credentials: 'same-origin',
        });
        const json = await res.json();
        if (id !== requestId) {
          return;
        }
        options = json?.data?.keys || [];
        renderDropdown(filterStatic(query), query);
      } catch (err) {
        if (id === requestId) {
          renderDropdown([], query);
        }
      }
    }

    async function fetchAcfFields(query) {
      const cfg = window.nsNobleSchemaAdmin || {};
      const params = new URLSearchParams({
        action: 'ns_search_acf_fields',
        nonce: cfg.acfFieldsNonce || '',
      });
      if (query) {
        params.set('search', query);
      }

      const id = ++requestId;
      try {
        const res = await fetch(`${cfg.ajaxUrl}?${params.toString()}`, {
          credentials: 'same-origin',
        });
        const json = await res.json();
        if (id !== requestId) {
          return;
        }
        options = json?.data?.fields || [];
        if (currentValue() && !selectedLabel) {
          const match = options.find((opt) => String(opt.value) === currentValue());
          if (match) {
            selectedLabel = match.label || match.value;
            if (!isOpen) {
              syncDisplay();
            }
          }
        }
        renderDropdown(filterStatic(query), query);
      } catch (err) {
        if (id === requestId) {
          renderDropdown([], query);
        }
      }
    }

    function refreshOptions(query = '') {
      if (mode === 'terms') {
        fetchTerms(query);
        return;
      }
      if (mode === 'meta_keys') {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => fetchMetaKeys(query), 200);
        return;
      }
      if (mode === 'acf_fields') {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => fetchAcfFields(query), 200);
        return;
      }
      if (mode === 'block_attrs') {
        options = blockAttrSearchOptions(getRelatedBlockName());
        renderDropdown(filterStatic(query), query);
        return;
      }
      if (mode === 'blocks') {
        options = blockSearchOptions();
        renderDropdown(filterStatic(query), query);
        return;
      }
      options = blockSearchOptions();
      renderDropdown(filterStatic(query), query);
    }

    function beginSearch(selectAll = false) {
      openDropdown();
      const query = currentValue() && input.value === (selectedLabel || currentValue())
        ? ''
        : input.value;
      refreshOptions(query);
      if (selectAll && currentValue()) {
        requestAnimationFrame(() => input.select());
      }
    }

    clearBtn?.addEventListener('mousedown', (e) => {
      e.preventDefault();
      clearValue();
      input.focus();
      beginSearch();
    });

    input.addEventListener('focus', () => {
      beginSearch(true);
    });

    input.addEventListener('click', () => {
      beginSearch(false);
    });

    input.addEventListener('input', () => {
      activeIndex = -1;
      openDropdown();
      if (mode === 'terms' || mode === 'meta_keys' || mode === 'acf_fields') {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          if (mode === 'terms') {
            fetchTerms(input.value);
          } else if (mode === 'meta_keys') {
            fetchMetaKeys(input.value);
          } else {
            fetchAcfFields(input.value);
          }
        }, 200);
      } else {
        refreshOptions(input.value);
      }
    });

    input.addEventListener('keydown', (e) => {
      const items = Array.from(dropdown.querySelectorAll('.ns-search-select__option'));
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        openDropdown();
        activeIndex = Math.min(activeIndex + 1, Math.max(items.length - 1, 0));
        items.forEach((el, i) => el.classList.toggle('is-active', i === activeIndex));
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        items.forEach((el, i) => el.classList.toggle('is-active', i === activeIndex));
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIndex >= 0 && items[activeIndex]) {
          setValue(items[activeIndex].dataset.value, items[activeIndex].dataset.label);
          return;
        }
        if (allowCustom && input.value.trim()) {
          setValue(input.value.trim(), input.value.trim());
        }
        return;
      }
      if (e.key === 'Escape') {
        syncDisplay();
        closeDropdown();
        input.blur();
      }
      if (e.key === 'Backspace' && currentValue() && input.selectionStart === 0 && input.selectionEnd === input.value.length) {
        // Selecting all + backspace clears the single selection
        e.preventDefault();
        clearValue();
        beginSearch();
      }
    });

    input.addEventListener('blur', () => {
      setTimeout(() => {
        if (root.contains(document.activeElement)) {
          return;
        }
        syncDisplay();
        closeDropdown();
      }, 120);
    });

    dropdown.addEventListener('mousedown', (e) => {
      const option = e.target.closest('.ns-search-select__option');
      if (!option) {
        return;
      }
      e.preventDefault();
      setValue(option.dataset.value, option.dataset.label);
    });

    root._nsSetSearchValue = setValue;
    root._nsClearSearchValue = clearValue;
    root._nsSetEnabled = (enabled) => {
      input.disabled = !enabled;
      if (!enabled) {
        if (currentValue()) {
          setValue('', '', { silent: true });
        } else {
          syncDisplay();
        }
        closeDropdown();
      } else {
        syncDisplay();
      }
    };
    root._nsHydrateLabel = () => {
      if (mode === 'terms' && currentValue() && getTaxonomy()) {
        fetchTerms('', currentValue());
      }
    };

    if (currentValue()) {
      const presetLabel = hidden.dataset.label || currentValue();
      setValue(currentValue(), presetLabel, { silent: true });
      if (mode === 'terms') {
        root._nsHydrateLabel();
      } else if (mode === 'acf_fields') {
        fetchAcfFields(currentValue());
      }
    } else {
      syncDisplay();
    }
    hydrating = false;
  }

  function initSearchSelectsInRow(row) {
    row.querySelectorAll('.ns-search-select').forEach((el) => {
      initSearchSelect(el, {
        mode: el.dataset.searchMode,
        allowCustom: el.dataset.allowCustom === '1',
        options: el.dataset.searchMode === 'blocks' ? blockSearchOptions() : [],
      });
    });
    syncTermSelectEnabled(row);
  }

  function syncTermSelectEnabled(row) {
    const taxonomy = row.querySelector('.ns-rule-taxonomy')?.value || '';
    const termSelect = row.querySelector('.ns-search-select[data-search-mode="terms"]');
    if (!termSelect) {
      return;
    }
    if (typeof termSelect._nsSetEnabled === 'function') {
      const wasEnabled = !termSelect.querySelector('.ns-search-select__input')?.disabled;
      termSelect._nsSetEnabled(!!taxonomy);
      if (taxonomy && !wasEnabled) {
        // keep empty until user picks
      }
    } else {
      const input = termSelect.querySelector('.ns-search-select__input');
      if (input) {
        input.disabled = !taxonomy;
      }
    }
  }

  const META_NO_VALUE_OPS = ['exists', 'not_exists', 'is_empty', 'is_not_empty'];

  function syncMetaValueVisibility(row) {
    const valueInput = row.querySelector('.ns-rule-meta-value');
    const operator = row.querySelector('.ns-rule-meta-operator')?.value || 'is';
    if (!valueInput) {
      return;
    }
    valueInput.hidden = META_NO_VALUE_OPS.includes(operator);
  }

  function syncRuleTypeFields(row) {
    const type = row.querySelector('.ns-rule-type')?.value || 'taxonomy';
    row.dataset.ruleType = type;
    row.querySelectorAll('.ns-rule-fields').forEach((el) => {
      el.hidden = !el.classList.contains(`ns-rule-fields--${type}`);
    });
    syncTermSelectEnabled(row);
    if (type === 'post_meta') {
      syncMetaValueVisibility(row);
    }
  }

  function collectFilterGroups() {
    if (!ruleGroupsEl) {
      return [];
    }
    const groups = [];
    ruleGroupsEl.querySelectorAll('.ns-rule-group').forEach((groupEl) => {
      const rules = [];
      groupEl.querySelectorAll('.ns-rule-row').forEach((row) => {
        const type = row.querySelector('.ns-rule-type')?.value || 'taxonomy';
        if (type === 'taxonomy') {
          const taxonomy = row.querySelector('.ns-rule-taxonomy')?.value || '';
          const termId = row.querySelector('.ns-rule-term')?.value.trim() || '';
          const operator = row.querySelector('.ns-rule-operator')?.value || 'is';
          const termLabel = row.querySelector('.ns-search-select[data-search-mode="terms"] .ns-search-select__input')?.value.trim()
            || row.querySelector('.ns-rule-term')?.dataset.label
            || termId;
          if (taxonomy && termId) {
            rules.push({
              type: 'taxonomy',
              taxonomy,
              term_id: termId,
              term_label: termLabel,
              operator,
            });
          }
          return;
        }
        if (type === 'has_block') {
          const block = row.querySelector('.ns-rule-block')?.value.trim() || '';
          if (block) {
            rules.push({ type: 'has_block', block });
          }
          return;
        }
        if (type === 'post_meta') {
          const key = row.querySelector('.ns-rule-meta-key')?.value.trim() || '';
          const operator = row.querySelector('.ns-rule-meta-operator')?.value || 'is';
          const value = row.querySelector('.ns-rule-meta-value')?.value.trim() || '';
          if (!key) {
            return;
          }
          if (!META_NO_VALUE_OPS.includes(operator) && !value) {
            return;
          }
          rules.push({
            type: 'post_meta',
            key,
            operator,
            value: META_NO_VALUE_OPS.includes(operator) ? '' : value,
          });
          return;
        }
        if (type === 'language') {
          const language = row.querySelector('.ns-rule-language')?.value.trim() || '';
          const operator = row.querySelector('.ns-rule-language-operator')?.value || 'is';
          if (language) {
            rules.push({ type: 'language', language, operator });
          }
        }
      });
      if (rules.length) {
        groups.push(rules);
      }
    });
    return groups;
  }

  function syncFilterHiddenInputs() {
    if (!filterValuesEl) {
      return;
    }
    filterValuesEl.innerHTML = '';
    if (selectedPlacementMode() === 'sitewide') {
      return;
    }
    collectFilterGroups().forEach((group, gIndex) => {
      group.forEach((rule, rIndex) => {
        Object.entries(rule).forEach(([key, value]) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = `filter_groups[${gIndex}][${rIndex}][${key}]`;
          input.value = value;
          filterValuesEl.appendChild(input);
        });
      });
    });
  }

  function refreshRuleGroupsUi() {
    if (!ruleGroupsEl) {
      return;
    }
    const groups = Array.from(ruleGroupsEl.querySelectorAll('.ns-rule-group'));
    ruleGroupsEl.querySelectorAll('.ns-rule-groups__or').forEach((el) => el.remove());
    groups.forEach((group, index) => {
      group.dataset.groupIndex = String(index);
      if (index > 0) {
        const or = document.createElement('p');
        or.className = 'ns-rule-groups__or';
        or.textContent = 'or';
        ruleGroupsEl.insertBefore(or, group);
      }
      const rows = group.querySelectorAll('.ns-rule-row');
      rows.forEach((row, rowIndex) => {
        const removeBtn = row.querySelector('.ns-rule-remove');
        if (removeBtn) {
          removeBtn.hidden = groups.length === 1 && rows.length === 1;
        }
        const andBtn = row.querySelector('.ns-rule-and');
        if (andBtn) {
          andBtn.hidden = rowIndex !== rows.length - 1;
        }
      });
    });
    syncFilterHiddenInputs();
    updateTargetSummary();
  }

  function initRuleGroups() {
    if (!ruleGroupsEl) {
      return;
    }

    ruleGroupsEl.querySelectorAll('.ns-rule-row').forEach((row) => {
      initSearchSelectsInRow(row);
      syncRuleTypeFields(row);
    });

    ruleGroupsEl.addEventListener('click', (e) => {
      const andBtn = e.target.closest('.ns-rule-and');
      if (andBtn) {
        const group = andBtn.closest('.ns-rule-group');
        const rulesEl = group?.querySelector('.ns-rule-group__rules');
        if (rulesEl) {
          const row = createRuleRow();
          syncRuleTypeFields(row);
          rulesEl.appendChild(row);
          refreshRuleGroupsUi();
          markDirty();
        }
        return;
      }

      const removeBtn = e.target.closest('.ns-rule-remove');
      if (removeBtn) {
        const row = removeBtn.closest('.ns-rule-row');
        const group = removeBtn.closest('.ns-rule-group');
        const rulesEl = group?.querySelector('.ns-rule-group__rules');
        if (!row || !group || !rulesEl) {
          return;
        }
        if (rulesEl.querySelectorAll('.ns-rule-row').length > 1) {
          row.remove();
        } else if (ruleGroupsEl.querySelectorAll('.ns-rule-group').length > 1) {
          const orPrev = group.previousElementSibling;
          if (orPrev?.classList.contains('ns-rule-groups__or')) {
            orPrev.remove();
          }
          group.remove();
        } else {
          row.querySelectorAll('select').forEach((el) => {
            el.selectedIndex = 0;
          });
          row.querySelectorAll('.ns-search-select').forEach((el) => {
            if (el._nsClearSearchValue) {
              el._nsClearSearchValue();
            }
          });
          syncRuleTypeFields(row);
        }
        refreshRuleGroupsUi();
        markDirty();
      }
    });

    ruleGroupsEl.addEventListener('change', (e) => {
      const row = e.target.closest('.ns-rule-row');
      if (!row) {
        refreshRuleGroupsUi();
        markDirty();
        return;
      }
      if (e.target.classList.contains('ns-rule-type')) {
        syncRuleTypeFields(row);
      }
      if (e.target.classList.contains('ns-rule-taxonomy')) {
        const termSelect = row.querySelector('.ns-search-select[data-search-mode="terms"]');
        if (termSelect?._nsClearSearchValue) {
          termSelect._nsClearSearchValue();
        }
        syncTermSelectEnabled(row);
      }
      if (e.target.classList.contains('ns-rule-meta-operator')) {
        syncMetaValueVisibility(row);
      }
      refreshRuleGroupsUi();
      markDirty();
    });

    ruleGroupsEl.addEventListener('input', () => {
      refreshRuleGroupsUi();
      markDirty();
    });

    document.getElementById('ns-add-rule-group')?.addEventListener('click', () => {
      const group = document.createElement('div');
      group.className = 'ns-rule-group';
      const rules = document.createElement('div');
      rules.className = 'ns-rule-group__rules';
      const row = createRuleRow();
      syncRuleTypeFields(row);
      rules.appendChild(row);
      group.appendChild(rules);
      ruleGroupsEl.appendChild(group);
      refreshRuleGroupsUi();
      markDirty();
    });

    refreshRuleGroupsUi();
  }

  function formatTargetCountLabel(posts, archives, specialties, languages) {
    const postLabel = Number(posts || 0).toLocaleString();
    const archiveLabel = Number(archives || 0).toLocaleString();
    const specialtyLabel = Number(specialties || 0).toLocaleString();
    const languageCount = Number(languages || 0);
    const languageLabel = languageCount.toLocaleString();
    if (posts <= 0 && archives <= 0 && specialties <= 0) {
      return adminI18n.batchCountEmpty || '0 posts selected';
    }
    const acrossLanguages = languageCount > 0
      ? (languageCount === 1
        ? (adminI18n.batchCountAcrossLanguage || 'across %s language')
        : (adminI18n.batchCountAcrossLanguages || 'across %s languages')).replace('%s', languageLabel)
      : '';
    if (archives <= 0 && specialties <= 0) {
      if (acrossLanguages) {
        return (adminI18n.batchCountPostsAcross || '%1$s posts selected %2$s')
          .replace('%1$s', postLabel)
          .replace('%2$s', acrossLanguages);
      }
      return (adminI18n.batchCountPosts || '%s posts selected').replace('%s', postLabel);
    }
    const parts = [`${postLabel} posts`];
    if (archives > 0) {
      parts.push(`${archiveLabel} archives`);
    }
    if (specialties > 0) {
      parts.push(`${specialtyLabel} specialty`);
    }
    return acrossLanguages ? `${parts.join(' · ')} ${acrossLanguages}` : parts.join(' · ');
  }

  function showTargetSummaryCountLoading() {
    if (!summaryCountEl) {
      return;
    }
    summaryCountEl.textContent = adminI18n.batchCountLoading || 'Loading post count…';
    summaryCountEl.classList.add('is-loading');
    summaryCountEl.hidden = false;
  }

  function setTargetSummaryCountLabel(label) {
    if (!summaryCountEl) {
      return;
    }
    summaryCountEl.textContent = label;
    summaryCountEl.classList.remove('is-loading');
    summaryCountEl.hidden = false;
  }

  function scheduleTargetSummaryCount() {
    clearTimeout(summaryPreviewTimer);
    const placement = selectedPlacementMode();
    const hasTargets = placement === 'global'
      || placement === 'sitewide'
      || getMultiSelectValues('ns-post-types').length > 0
      || getMultiSelectValues('ns-page-templates').length > 0
      || getMultiSelectValues('ns-posts').length > 0
      || getMultiSelectValues('ns-archives').length > 0
      || getMultiSelectValues('ns-specialties').length > 0;
    if (hasTargets && placement !== 'sitewide') {
      showTargetSummaryCountLoading();
    }
    summaryPreviewTimer = setTimeout(refreshTargetSummaryCount, 250);
  }

  async function refreshTargetSummaryCount() {
    if (!summaryCountEl) {
      return;
    }

    const placement = selectedPlacementMode();
    if (placement === 'sitewide') {
      setTargetSummaryCountLabel(adminI18n.sitewideNoRegen || 'Shared sitewide schema — no per-post regeneration');
      return;
    }

    const postTypes = getMultiSelectValues('ns-post-types');
    const pageTemplates = getMultiSelectValues('ns-page-templates');
    const posts = getMultiSelectValues('ns-posts');
    const archives = getMultiSelectValues('ns-archives');
    const specialties = getMultiSelectValues('ns-specialties');
    const id = ++summaryPreviewRequestId;

    if (
      placement === 'targeted'
      && !postTypes.length
      && !pageTemplates.length
      && !posts.length
      && !archives.length
      && !specialties.length
    ) {
      setTargetSummaryCountLabel(formatTargetCountLabel(0, 0, 0));
      return;
    }

    showTargetSummaryCountLoading();

    try {
      const body = new URLSearchParams();
      body.set('action', 'ns_schema_tools_batch_preview');
      body.set('nonce', cfg.toolsNonce || '');
      body.set('placement', placement);
      postTypes.forEach((v) => body.append('post_types[]', v));
      pageTemplates.forEach((v) => body.append('page_templates[]', v));
      posts.forEach((v) => body.append('posts[]', v));
      archives.forEach((v) => body.append('archives[]', v));
      specialties.forEach((v) => body.append('specialties[]', v));
      collectFilterGroups().forEach((group, gIndex) => {
        group.forEach((rule, rIndex) => {
          Object.entries(rule).forEach(([key, value]) => {
            body.append(`filter_groups[${gIndex}][${rIndex}][${key}]`, value);
          });
        });
      });

      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      });
      const json = await res.json();
      if (id !== summaryPreviewRequestId || !json?.success) {
        return;
      }
      setTargetSummaryCountLabel(formatTargetCountLabel(
        json.data.posts,
        json.data.archives,
        json.data.specialties,
        json.data.languages
      ));
    } catch (err) {
      if (id === summaryPreviewRequestId) {
        setTargetSummaryCountLabel(formatTargetCountLabel(0, 0, 0));
      }
    }
  }

  function updateTargetSummary() {
    const chips = [];
    const placement = selectedPlacementMode();

    if (placement === 'sitewide') {
      chips.push('Sitewide');
    } else if (placement === 'global') {
      chips.push('All pages');
    } else {
      const groups = [];
      const postLabels = getMultiSelectLabels('ns-post-types');
      if (postLabels.length) {
        groups.push(`Post type: ${postLabels.join(', ')}`);
      }
      const templateLabels = getMultiSelectLabels('ns-page-templates');
      if (templateLabels.length) {
        groups.push(`Template: ${templateLabels.join(', ')}`);
      }
      const archives = getMultiSelectLabels('ns-archives');
      if (archives.length) {
        groups.push(`Archive: ${archives.join(', ')}`);
      }
      const specialties = getMultiSelectLabels('ns-specialties');
      if (specialties.length) {
        groups.push(`Specialty: ${specialties.join(', ')}`);
      }
      const posts = getMultiSelectLabels('ns-posts').map((label) => (
        String(label).replace(/\s+\([^)]*·\s*#\d+\)\s*$/, '')
      ));
      if (posts.length) {
        groups.push(`Post: ${posts.join(', ')}`);
      }
      if (groups.length) {
        chips.push(groups.join(' · OR · '));
      } else {
        chips.push('Targeted (choose at least one)');
      }
    }

    const filterGroups = selectedPlacementMode() === 'sitewide' ? [] : collectFilterGroups();
    if (filterGroups.length) {
      const metaOpLabel = {
        is: '=',
        is_not: '≠',
        contains: 'contains',
        not_contains: '↛',
        exists: 'exists',
        not_exists: 'missing',
        is_empty: 'empty',
        is_not_empty: 'not empty',
        gt: '>',
        gte: '≥',
        lt: '<',
        lte: '≤',
      };
      const groupLabels = filterGroups.map((group) => group.map((rule) => {
        if (rule.type === 'taxonomy') {
          const label = data.taxonomyLabels[rule.taxonomy] || rule.taxonomy;
          const termLabel = rule.term_label || rule.term_id || rule.term;
          return rule.operator === 'is_not'
            ? `${label} ≠ ${termLabel}`
            : `${label} = ${termLabel}`;
        }
        if (rule.type === 'post_meta') {
          const op = metaOpLabel[rule.operator] || rule.operator;
          if (META_NO_VALUE_OPS.includes(rule.operator)) {
            return `Meta ${rule.key} ${op}`;
          }
          return `Meta ${rule.key} ${op} ${rule.value}`;
        }
        if (rule.type === 'language') {
          const langLabel = (data.wpmlLanguages && data.wpmlLanguages[rule.language])
            || rule.language;
          return rule.operator === 'is_not'
            ? `Language ≠ ${langLabel}`
            : `Language = ${langLabel}`;
        }
        return `Has block ${rule.block}`;
      }).join(' AND '));
      chips.push(groupLabels.join(' OR '));
    }

    if (summaryChips) {
      summaryChips.innerHTML = chips.length
        ? chips.map((c) => `<span class="ns-chip">${escapeHtml(c)}</span>`).join('')
        : '<span class="ns-chip">No filters yet</span>';
    }
    scheduleTargetSummaryCount();
  }

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function fieldIdFromPath(path) {
    return `ns-source-${path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}`;
  }

  function schemaDocsUrl(template) {
    if (!template) {
      return '';
    }
    const schemaType = String(template.schema_type || '').trim();
    const stored = String(template.schema_url || '').trim();
    const schemaOrgPattern = /^https:\/\/schema\.org\/[A-Za-z][A-Za-z0-9]*$/;
    if (schemaType && (!stored || schemaOrgPattern.test(stored))) {
      if (/^[A-Za-z][A-Za-z0-9]*$/.test(schemaType)) {
        return `https://schema.org/${schemaType}`;
      }
    }
    return stored;
  }

  function updateSchemaDocsLink() {
    const wrap = document.getElementById('ns-schema-docs');
    const link = document.getElementById('ns-schema-docs-link');
    if (!wrap || !link) {
      return;
    }
    const slug = selectedTemplateSlug();
    const template = data.templates && data.templates[slug];
    if (!template) {
      wrap.hidden = true;
      link.removeAttribute('href');
      link.textContent = '';
      return;
    }
    const typeName = String(template.schema_type || '').trim();
    const url = schemaDocsUrl(template);
    if (!url || !typeName) {
      wrap.hidden = true;
      link.removeAttribute('href');
      link.textContent = '';
      return;
    }
    link.href = url;
    link.textContent = (adminI18n.openSchemaDocs || 'Open %s schema documentation').replace('%s', typeName);
    wrap.hidden = false;
  }

  function renderFieldMappings() {
    const slug = selectedTemplateSlug();
    const template = data.templates[slug];
    if (!template || !Array.isArray(template.fields)) {
      fieldMappingsEl.innerHTML = '<p class="ns-field-mappings__empty">Select a template in step 1 to configure field mappings.</p>';
      renderedTemplateSlug = null;
      updateSchemaDocsLink();
      return;
    }

    updateSchemaDocsLink();

    if (
      renderedTemplateSlug === slug
      && fieldMappingsEl.querySelector('.ns-field-mapping')
    ) {
      return;
    }

    renderedTemplateSlug = slug;
    const tpl = document.getElementById('ns-field-row-template');
    fieldMappingsEl.innerHTML = '';

    const fields = [...template.fields].sort((a, b) => {
      const aRequired = a.required ? 1 : 0;
      const bRequired = b.required ? 1 : 0;
      return bRequired - aRequired;
    });

    fields.forEach((field) => {
      const row = tpl.content.firstElementChild.cloneNode(true);
      row.dataset.fieldPath = field.path;
      row.querySelector('.ns-field-mapping__name').textContent = field.label || field.path;
      row.querySelector('.ns-field-mapping__type').textContent = field.value_type || '';
      row.querySelector('.ns-field-mapping__help').textContent = field.help || '';

      const requiredBadge = row.querySelector('.ns-field-mapping__required');
      const recommendedBadge = row.querySelector('.ns-field-mapping__recommended');
      const optionalBadge = row.querySelector('.ns-field-mapping__optional');
      const isRequired = !!field.required;
      const isRecommended = !isRequired && !!field.recommended;
      if (requiredBadge) {
        requiredBadge.hidden = !isRequired;
      }
      if (recommendedBadge) {
        recommendedBadge.hidden = !isRecommended;
      }
      if (optionalBadge) {
        optionalBadge.hidden = isRequired || isRecommended;
      }

      const sourceSelect = row.querySelector('.ns-source-type');
      const sourceId = fieldIdFromPath(field.path);
      sourceSelect.id = sourceId;
      sourceSelect.name = `fields[${field.path}][source_type]`;

      const sourceLabel = row.querySelector('.ns-field-row__label label');
      sourceLabel.setAttribute('for', sourceId);

      const existing = data.fieldMaps[field.path] || {};
      syncSourceTypeOptionsForSelect(sourceSelect);
      const allowed = availableSourceTypes();
      if (existing.source_type && allowed[existing.source_type]) {
        sourceSelect.value = existing.source_type;
      } else if (existing.source_type && !allowed[existing.source_type]) {
        sourceSelect.value = '';
      }

      renderSourceConfig(row, field.path, sourceSelect.value, existing.source_config || {});

      sourceSelect.addEventListener('change', () => {
        clearFieldMappingError(row);
        renderSourceConfig(row, field.path, sourceSelect.value, {});
        markDirty();
      });

      row.addEventListener('input', () => {
        clearFieldMappingError(row);
        markDirty();
      });
      row.addEventListener('change', () => {
        clearFieldMappingError(row);
        markDirty();
      });

      fieldMappingsEl.appendChild(row);
    });
  }

  const SITEWIDE_ALLOWED_SOURCES = ['site_info', 'static'];

  function availableSourceTypes() {
    const all = (data.sourceTypes && typeof data.sourceTypes === 'object')
      ? data.sourceTypes
      : {};
    if (selectedPlacementMode() !== 'sitewide') {
      return all;
    }
    const filtered = {};
    SITEWIDE_ALLOWED_SOURCES.forEach((key) => {
      if (all[key]) {
        filtered[key] = all[key];
      }
    });
    return filtered;
  }

  function syncSourceTypeOptionsForSelect(select) {
    if (!select) {
      return;
    }
    const types = availableSourceTypes();
    const prev = select.value;
    const emptyLabel = select.querySelector('option[value=""]')?.textContent
      || 'Select source…';
    select.innerHTML = '';
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = emptyLabel;
    select.appendChild(empty);
    Object.entries(types).forEach(([value, label]) => {
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = String(label);
      select.appendChild(opt);
    });
    if (prev && types[prev]) {
      select.value = prev;
    } else {
      select.value = '';
    }
  }

  function syncSourceTypeOptions() {
    if (!fieldMappingsEl) {
      return;
    }
    const leftover = document.getElementById('ns-placement-source-hint');
    if (leftover) {
      leftover.remove();
    }
    fieldMappingsEl.querySelectorAll('.ns-field-mapping').forEach((row) => {
      const select = row.querySelector('.ns-source-type');
      if (!select) {
        return;
      }
      const prev = select.value;
      syncSourceTypeOptionsForSelect(select);
      if (prev && select.value !== prev) {
        renderSourceConfig(row, row.dataset.fieldPath || '', '', {});
        markDirty();
      }
    });
  }

  function renderSourceConfig(row, fieldPath, sourceType, config) {
    const container = row.querySelector('.ns-source-config');
    container.innerHTML = '';

    if (!sourceType) {
      return;
    }

    const prefix = `fields[${fieldPath}][source_config]`;
    let inputIndex = 0;

    const addRow = (label, inputHtml, inputId) => {
      const id = inputId || `${fieldIdFromPath(fieldPath)}-cfg-${inputIndex}`;
      inputIndex += 1;
      const wrap = document.createElement('div');
      wrap.className = 'ns-field-row';
      wrap.innerHTML = `
        <div class="ns-field-row__label"><label for="${escapeHtml(id)}">${escapeHtml(label)}</label></div>
        <div class="ns-field-row__control">${inputHtml.replace(/__INPUT_ID__/g, id)}</div>`;
      container.appendChild(wrap);
    };

    switch (sourceType) {
      case 'core': {
        const opts = Object.entries(data.coreFields || {})
          .map(([k, v]) => `<option value="${escapeHtml(k)}"${config.field === k ? ' selected' : ''}>${escapeHtml(v)}</option>`)
          .join('');
        addRow('Core field', `<select id="__INPUT_ID__" name="${prefix}[field]"><option value="">Select…</option>${opts}</select>`);
        break;
      }
      case 'site_info': {
        const opts = Object.entries(data.siteInfoFields || {})
          .map(([k, v]) => `<option value="${escapeHtml(k)}"${config.field === k ? ' selected' : ''}>${escapeHtml(v)}</option>`)
          .join('');
        addRow('Site info', `<select id="__INPUT_ID__" name="${prefix}[field]"><option value="">Select…</option>${opts}</select>`);
        break;
      }
      case 'yoast': {
        const opts = Object.entries(data.yoastFields || {})
          .map(([k, v]) => `<option value="${escapeHtml(k)}"${config.field === k ? ' selected' : ''}>${escapeHtml(v)}</option>`)
          .join('');
        addRow('Yoast field', `<select id="__INPUT_ID__" name="${prefix}[field]"><option value="">Select…</option>${opts}</select>`);
        break;
      }
      case 'events_calendar': {
        const opts = Object.entries(data.eventsCalendarFields || {})
          .map(([k, v]) => `<option value="${escapeHtml(k)}"${config.field === k ? ' selected' : ''}>${escapeHtml(v)}</option>`)
          .join('');
        addRow('Events Calendar field', `<select id="__INPUT_ID__" name="${prefix}[field]"><option value="">Select…</option>${opts}</select>`);
        break;
      }
      case 'post_meta':
        addRow('Meta key', `<div class="ns-search-select" data-search-mode="meta_keys" data-allow-custom="1">
          <div class="ns-search-select__control">
            <input type="text" id="__INPUT_ID__" class="ns-search-select__input regular-text" autocomplete="off" placeholder="Search meta key…">
            <button type="button" class="ns-search-select__clear" hidden aria-label="Clear selection">&times;</button>
            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
          </div>
          <input type="hidden" class="ns-rule-meta-key" name="${prefix}[key]" value="${escapeHtml(config.key || '')}">
        </div>`);
        break;
      case 'acf':
        addRow('Field name', `<div class="ns-search-select" data-search-mode="acf_fields" data-allow-custom="1">
          <div class="ns-search-select__control">
            <input type="text" id="__INPUT_ID__" class="ns-search-select__input regular-text" autocomplete="off" placeholder="Search ACF field…">
            <button type="button" class="ns-search-select__clear" hidden aria-label="Clear selection">&times;</button>
            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
          </div>
          <input type="hidden" name="${prefix}[name]" value="${escapeHtml(config.name || '')}">
        </div>`);
        break;
      case 'static':
        addRow('Value', `<textarea id="__INPUT_ID__" name="${prefix}[value]" rows="3" class="large-text">${escapeHtml(config.value || '')}</textarea>`);
        break;
      case 'manual_post':
        addRow('Field path', `<input type="text" id="__INPUT_ID__" class="regular-text" name="${prefix}[field_path]" value="${escapeHtml(config.field_path || fieldPath)}">`);
        break;
      case 'manual_block':
        addRow('Block name', `<div class="ns-search-select" data-search-mode="blocks" data-allow-custom="1">
          <div class="ns-search-select__control">
            <input type="text" id="__INPUT_ID__" class="ns-search-select__input regular-text" autocomplete="off" placeholder="Search block name…">
            <button type="button" class="ns-search-select__clear" hidden aria-label="Clear selection">&times;</button>
            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
          </div>
          <input type="hidden" name="${prefix}[block_name]" value="${escapeHtml(config.block_name || '')}">
        </div>`);
        addRow('Field path', `<input type="text" id="__INPUT_ID__" class="regular-text" name="${prefix}[field_path]" value="${escapeHtml(config.field_path || fieldPath)}">`);
        break;
      case 'taxonomy': {
        const taxOpts = Object.entries(data.taxonomyLabels)
          .map(([k, v]) => `<option value="${escapeHtml(k)}"${config.taxonomy === k ? ' selected' : ''}>${escapeHtml(v)}</option>`)
          .join('');
        addRow('Taxonomy', `<select id="__INPUT_ID__" name="${prefix}[taxonomy]"><option value="">Select…</option>${taxOpts}</select>`);
        addRow('Return as', `<select id="__INPUT_ID__" name="${prefix}[return]">
          <option value="names"${config.return === 'names' ? ' selected' : ''}>Names</option>
          <option value="slugs"${config.return === 'slugs' ? ' selected' : ''}>Slugs</option>
        </select>`);
        break;
      }
      case 'block_attr':
        addRow('Block name', `<div class="ns-search-select" data-search-mode="blocks" data-allow-custom="1">
          <div class="ns-search-select__control">
            <input type="text" id="__INPUT_ID__" class="ns-search-select__input regular-text" autocomplete="off" placeholder="Search block name…">
            <button type="button" class="ns-search-select__clear" hidden aria-label="Clear selection">&times;</button>
            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
          </div>
          <input type="hidden" name="${prefix}[block_name]" value="${escapeHtml(config.block_name || '')}">
        </div>`);
        addRow('Attribute', `<div class="ns-search-select" data-search-mode="block_attrs" data-allow-custom="1">
          <div class="ns-search-select__control">
            <input type="text" id="__INPUT_ID__" class="ns-search-select__input regular-text" autocomplete="off" placeholder="Search attribute…"${!config.block_name ? ' disabled' : ''}>
            <button type="button" class="ns-search-select__clear" hidden aria-label="Clear selection">&times;</button>
            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
          </div>
          <input type="hidden" name="${prefix}[attr]" value="${escapeHtml(config.attr || '')}">
        </div>`);
        break;
      case 'block_content':
        addRow('Block name', `<div class="ns-search-select" data-search-mode="blocks" data-allow-custom="1">
          <div class="ns-search-select__control">
            <input type="text" id="__INPUT_ID__" class="ns-search-select__input regular-text" autocomplete="off" placeholder="Search block name…">
            <button type="button" class="ns-search-select__clear" hidden aria-label="Clear selection">&times;</button>
            <ul class="ns-search-select__dropdown" role="listbox" hidden></ul>
          </div>
          <input type="hidden" name="${prefix}[block_name]" value="${escapeHtml(config.block_name || '')}">
        </div>`);
        addRow('Mode', `<select id="__INPUT_ID__" name="${prefix}[mode]">
          <option value="faq_from_inner_blocks"${config.mode === 'faq_from_inner_blocks' || !config.mode ? ' selected' : ''}>FAQ from inner blocks</option>
        </select>`);
        break;
      case 'callback': {
        const slugs = data.callbackSlugs || [];
        const opts = slugs
          .map((s) => `<option value="${escapeHtml(s)}"${config.slug === s ? ' selected' : ''}>${escapeHtml(s)}</option>`)
          .join('');
        addRow('Callback slug', `<select id="__INPUT_ID__" name="${prefix}[slug]"><option value="">Select…</option>${opts}</select>`);
        break;
      }
      default:
        break;
    }

    const metaSelect = container.querySelector('.ns-search-select[data-search-mode="meta_keys"]');
    if (metaSelect) {
      initSearchSelect(metaSelect, {
        mode: 'meta_keys',
        allowCustom: true,
        onChange: () => clearFieldMappingError(row),
      });
    }

    const acfSelect = container.querySelector('.ns-search-select[data-search-mode="acf_fields"]');
    if (acfSelect) {
      initSearchSelect(acfSelect, {
        mode: 'acf_fields',
        allowCustom: true,
        onChange: () => clearFieldMappingError(row),
      });
    }

    const blockSelect = container.querySelector('.ns-search-select[data-search-mode="blocks"]');
    const blockAttrSelect = container.querySelector('.ns-search-select[data-search-mode="block_attrs"]');
    if (blockSelect) {
      initSearchSelect(blockSelect, {
        mode: 'blocks',
        allowCustom: true,
        options: blockSearchOptions(),
        onChange: (blockName) => {
          clearFieldMappingError(row);
          syncFieldMappingBlockAttrs(container, blockName);
        },
      });
    }
    if (blockAttrSelect) {
      const initialBlock = container.querySelector('.ns-search-select[data-search-mode="blocks"] input[type="hidden"]')?.value?.trim() || '';
      initSearchSelect(blockAttrSelect, {
        mode: 'block_attrs',
        allowCustom: true,
        options: blockAttrSearchOptions(initialBlock),
        onChange: () => clearFieldMappingError(row),
      });
      syncFieldMappingBlockAttrs(container, initialBlock, { silent: true });
    }
  }

  function syncFieldMappingBlockAttrs(scope, blockName, opts = {}) {
    const attrSelect = scope.querySelector('.ns-search-select[data-search-mode="block_attrs"]');
    if (!attrSelect) {
      return;
    }
    const name = String(blockName || '').trim();
    const hidden = attrSelect.querySelector('input[type="hidden"]');
    const current = hidden?.value?.trim() || '';
    const allowed = blockAttrSearchOptions(name).map((opt) => opt.value);

    if (!name) {
      if (typeof attrSelect._nsSetEnabled === 'function') {
        attrSelect._nsSetEnabled(false);
      }
      return;
    }

    if (typeof attrSelect._nsSetEnabled === 'function') {
      attrSelect._nsSetEnabled(true);
    }

    if (current && !allowed.includes(current)) {
      if (typeof attrSelect._nsClearSearchValue === 'function') {
        attrSelect._nsClearSearchValue({ silent: !!opts.silent });
      } else if (hidden) {
        hidden.value = '';
      }
    }
  }

  function sourceConfigComplete(sourceType, row) {
    const getVal = (sel) => row.querySelector(sel)?.value?.trim() || '';

    switch (sourceType) {
      case 'core':
      case 'site_info':
      case 'yoast':
      case 'events_calendar':
        return getVal('[name*="[field]"]') !== '';
      case 'post_meta':
        return getVal('[name*="[key]"]') !== '';
      case 'acf':
        return getVal('[name*="[name]"]') !== '';
      case 'static':
        return getVal('textarea[name*="[value]"], input[name*="[value]"]') !== '';
      case 'manual_post':
        return getVal('[name*="[field_path]"]') !== '';
      case 'manual_block':
        return getVal('[name*="[block_name]"]') !== '' && getVal('[name*="[field_path]"]') !== '';
      case 'taxonomy':
        return getVal('[name*="[taxonomy]"]') !== '';
      case 'block_attr':
        return getVal('[name*="[block_name]"]') !== '' && getVal('[name*="[attr]"]') !== '';
      case 'block_content':
        return getVal('[name*="[block_name]"]') !== '';
      case 'callback':
        return getVal('[name*="[slug]"]') !== '';
      default:
        return false;
    }
  }

  function savedSourceConfigComplete(sourceType, config) {
    const cfg = config || {};
    switch (sourceType) {
      case 'core':
      case 'site_info':
      case 'yoast':
      case 'events_calendar':
        return String(cfg.field || '').trim() !== '';
      case 'post_meta':
        return String(cfg.key || '').trim() !== '';
      case 'acf':
        return String(cfg.name || '').trim() !== '';
      case 'static':
        return String(cfg.value || '').trim() !== '';
      case 'manual_post':
        return String(cfg.field_path || '').trim() !== '';
      case 'manual_block':
        return String(cfg.block_name || '').trim() !== ''
          && String(cfg.field_path || '').trim() !== '';
      case 'taxonomy':
        return String(cfg.taxonomy || '').trim() !== '';
      case 'block_attr':
        return String(cfg.block_name || '').trim() !== '' && String(cfg.attr || '').trim() !== '';
      case 'block_content':
        return String(cfg.block_name || '').trim() !== '';
      case 'callback':
        return String(cfg.slug || '').trim() !== '';
      default:
        return false;
    }
  }

  function incompleteSourceMessage(sourceType) {
    switch (sourceType) {
      case 'static':
        return 'Enter a value for this required field.';
      case 'core':
      case 'site_info':
      case 'yoast':
      case 'events_calendar':
        return 'Select a field for this required mapping.';
      case 'post_meta':
        return 'Enter a meta key for this required field.';
      case 'acf':
        return 'Enter an ACF field name for this required field.';
      case 'taxonomy':
        return 'Select a taxonomy for this required field.';
      case 'callback':
        return 'Select a callback for this required field.';
      case 'block_attr':
        return 'Enter a block name and attribute for this required field.';
      case 'block_content':
        return 'Enter a block name for this required field.';
      case 'manual_post':
        return 'Enter a field path for this required field.';
      case 'manual_block':
        return 'Select a block name and enter a field path for this required field.';
      default:
        return 'Complete the source configuration for this required field.';
    }
  }

  function primarySourceInput(row, sourceType) {
    switch (sourceType) {
      case 'static':
        return row.querySelector('textarea[name*="[value]"], input[name*="[value]"]');
      case 'core':
      case 'site_info':
      case 'yoast':
      case 'events_calendar':
        return row.querySelector('[name*="[field]"]');
      case 'post_meta':
        return row.querySelector('[name*="[key]"]');
      case 'acf':
        return row.querySelector('[name*="[name]"]');
      case 'taxonomy':
        return row.querySelector('[name*="[taxonomy]"]');
      case 'callback':
        return row.querySelector('[name*="[slug]"]');
      case 'block_attr':
        return row.querySelector('[name*="[block_name]"]');
      case 'block_content':
        return row.querySelector('[name*="[block_name]"]');
      case 'manual_post':
        return row.querySelector('[name*="[field_path]"]');
      case 'manual_block':
        return row.querySelector('[name*="[block_name]"]');
      default:
        return row.querySelector('.ns-source-type');
    }
  }

  function clearFieldMappingError(row) {
    const errorEl = row.querySelector('.ns-field-mapping__error');
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
    row.querySelectorAll('[aria-invalid]').forEach((el) => {
      el.removeAttribute('aria-invalid');
      el.removeAttribute('aria-describedby');
    });
  }

  function showFieldMappingError(row, message, inputEl) {
    const errorEl = row.querySelector('.ns-field-mapping__error');
    if (errorEl) {
      if (!errorEl.id) {
        errorEl.id = `ns-field-error-${(row.dataset.fieldPath || 'field').replace(/[^a-z0-9]+/gi, '-')}`;
      }
      errorEl.textContent = message;
      errorEl.hidden = false;
    }
    if (inputEl) {
      inputEl.setAttribute('aria-invalid', 'true');
      if (errorEl?.id) {
        inputEl.setAttribute('aria-describedby', errorEl.id);
      }
    }
  }

  /**
   * Required fields must have a source selected and a complete source config.
   * An unselected source is treated as empty.
   *
   * @returns {boolean}
   */
  function validateRequiredFieldMappings() {
    const slug = selectedTemplateSlug();
    const template = data.templates[slug];
    if (!template || !Array.isArray(template.fields)) {
      return false;
    }

    let valid = true;
    let firstInvalid = null;

    template.fields.forEach((field) => {
      if (!field.required) {
        return;
      }

      const row = fieldMappingsEl.querySelector(`[data-field-path="${CSS.escape(field.path)}"]`);
      if (!row) {
        const existing = data.fieldMaps[field.path];
        if (!existing?.source_type || !savedSourceConfigComplete(existing.source_type, existing.source_config || {})) {
          valid = false;
        }
        return;
      }

      clearFieldMappingError(row);
      const sourceSelect = row.querySelector('.ns-source-type');
      const sourceType = sourceSelect?.value || '';
      if (!sourceType) {
        showFieldMappingError(row, 'Select a source for this required field.', sourceSelect);
        valid = false;
        if (!firstInvalid) {
          firstInvalid = sourceSelect;
        }
        return;
      }

      if (!sourceConfigComplete(sourceType, row)) {
        const input = primarySourceInput(row, sourceType) || sourceSelect;
        showFieldMappingError(row, incompleteSourceMessage(sourceType), input);
        valid = false;
        if (!firstInvalid) {
          firstInvalid = input;
        }
      }
    });

    if (!valid && firstInvalid && currentStep === 3) {
      firstInvalid.focus({ preventScroll: true });
    }

    return valid;
  }

  function placementConfigured() {
    if (selectedPlacementMode() === 'global' || selectedPlacementMode() === 'sitewide') {
      return true;
    }
    return getMultiSelectValues('ns-post-types').length > 0
      || getMultiSelectValues('ns-page-templates').length > 0
      || getMultiSelectValues('ns-archives').length > 0
      || getMultiSelectValues('ns-specialties').length > 0
      || getMultiSelectValues('ns-posts').length > 0;
  }

  function step1Configured() {
    return configNameInput.value.trim() !== '' && selectedTemplateSlug() !== '';
  }

  function fieldsConfigured() {
    const slug = selectedTemplateSlug();
    const template = data.templates[slug];
    if (!template || !Array.isArray(template.fields)) {
      return false;
    }

    // Field mapping UI only exists after visiting step 3.
    if (!fieldMappingsEl.querySelector('.ns-field-mapping')) {
      // Fall back to saved maps when editing an existing config.
      if (!hasSaved) {
        return false;
      }
      const allowed = availableSourceTypes();
      return template.fields.every((field) => {
        if (!field.required) {
          return true;
        }
        const existing = data.fieldMaps[field.path];
        return !!(existing && existing.source_type
          && allowed[existing.source_type]
          && savedSourceConfigComplete(existing.source_type, existing.source_config || {}));
      });
    }

    return template.fields.every((field) => {
      if (!field.required) {
        return true;
      }
      const row = fieldMappingsEl.querySelector(`[data-field-path="${CSS.escape(field.path)}"]`);
      if (!row) {
        return false;
      }
      const sourceType = row.querySelector('.ns-source-type')?.value || '';
      return !!sourceType && sourceConfigComplete(sourceType, row);
    });
  }

  function isFullyConfigured() {
    return step1Configured() && placementConfigured() && fieldsConfigured();
  }

  function validateStep(step) {
    clearStepErrors(step);

    if (step === 1) {
      let valid = true;
      const name = configNameInput.value.trim();
      if (!name) {
        showError('ns-config-name-error', 'Please enter a config name.', configNameInput);
        valid = false;
      }
      if (!selectedTemplateSlug()) {
        showError('ns-template-error', 'Please select a schema template.');
        if (templateFieldset) {
          templateFieldset.classList.add('has-error');
        }
        valid = false;
      }
      if (!valid) {
        const firstError = document.querySelector(`.ns-wizard__panel[data-panel="1"] .ns-field-error:not([hidden])`);
        if (firstError) {
          const related = document.getElementById(firstError.id.replace('-error', ''));
          (related || firstError).focus({ preventScroll: true });
        }
      }
      return valid;
    }

    if (step === 2) {
      let valid = true;
      if (selectedPlacementMode() === 'targeted' && !placementConfigured()) {
        showError('ns-placement-error', 'Select at least one post type, page template, archive, specialty page, or post.');
        valid = false;
      }
      if (!valid) {
        const firstError = document.querySelector(`.ns-wizard__panel[data-panel="2"] .ns-field-error:not([hidden])`);
        if (firstError) {
          const modeInput = document.querySelector('input[name="placement"]:checked');
          (modeInput || firstError).focus({ preventScroll: true });
        }
      }
      return valid;
    }

    if (step === 3) {
      return validateRequiredFieldMappings();
    }

    return true;
  }

  continueBtn.addEventListener('click', () => {
    if (continueBtn.disabled || currentStep >= 3) {
      return;
    }
    if (!validateStep(currentStep)) {
      setStepStatus(currentStep, 'error');
      focusFirstStepError(currentStep);
      return;
    }
    setStepStatus(currentStep, 'valid');
    setStep(currentStep + 1);
  });

  backBtn.addEventListener('click', () => {
    if (backBtn.disabled || currentStep <= 1) {
      return;
    }
    clearStepErrors(currentStep);
    setStep(currentStep - 1);
  });

  steps.forEach((el) => {
    const btn = el.querySelector('.ns-wizard__step-btn');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', () => {
      const target = Number(el.dataset.step);
      if (!target || target === currentStep || !canVisitStep(target)) {
        return;
      }
      clearStepErrors(currentStep);
      setStep(target);
    });
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    syncFilterHiddenInputs();
    if (saving || !saveBtn) {
      return;
    }

    const incompleteMessage = () => {
      const i18n = window.nsNobleSchemaAdmin?.i18n || {};
      if (data.configId > 0 || hasSaved) {
        return i18n.updateIncomplete
          || 'Complete all required steps before updating this schema config.';
      }
      return i18n.enableIncomplete
        || 'Complete all required steps before enabling this schema config.';
    };

    if (!validateAllStepsAndFocus()) {
      if (enabledToggle?.checked && !isFullyConfigured()) {
        await window.nsAlert(incompleteMessage());
      }
      return;
    }

    if (enabledToggle?.checked && !isFullyConfigured()) {
      await window.nsAlert(incompleteMessage());
      validateAllStepsAndFocus();
      return;
    }

    saving = true;
    isDirty = false;

    try {
      const body = new FormData(form);
      body.set('action', 'ns_save_config');
      if (enabledToggle?.checked) {
        body.set('enabled', '1');
      } else {
        body.delete('enabled');
      }

      saveBtn.disabled = true;
      if (enabledToggle) {
        enabledToggle.disabled = true;
      }

      const res = await fetch((window.nsNobleSchemaAdmin && window.nsNobleSchemaAdmin.ajaxUrl) || form.action, {
        method: 'POST',
        credentials: 'same-origin',
        body,
      });
      const json = await res.json();
      const payload = json && json.data ? json.data : {};

      if (!json || !json.success) {
        if (enabledToggle && payload.status === 'disabled') {
          enabledToggle.checked = false;
        }
        const editUrl = payload.edit_url
          || (payload.config_id
            ? `options-general.php?page=schema-config&action=edit&config_id=${payload.config_id}&ns_notice=error&ns_message=${encodeURIComponent(payload.message || 'Save failed.')}`
            : null);
        if (editUrl) {
          const absolute = editUrl.includes('http') ? editUrl : `${window.location.origin}/wp-admin/${editUrl}`;
          window.location.href = withStepParam(absolute, currentStep);
        } else {
          await window.nsAlert(payload.message || (window.nsNobleSchemaAdmin?.i18n?.saveFailed) || 'Save failed.');
        }
        return;
      }

      hasSaved = true;
      isDirty = false;

      if (enabledToggle && payload.status) {
        enabledToggle.checked = payload.status === 'active';
      }

      const regen = payload.regen || {};
      const editUrl = payload.edit_url
        || `options-general.php?page=schema-config&action=edit&config_id=${payload.config_id}&ns_notice=success&ns_message=${encodeURIComponent(payload.message || '')}`;
      const absoluteEdit = withStepParam(
        editUrl.includes('http') ? editUrl : `${window.location.origin}/wp-admin/${editUrl.replace(/^\//, '')}`,
        currentStep
      );

      if (regen.remaining > 0 && typeof window.nsStartRegenModal === 'function') {
        const noticeUrl = absoluteEdit.includes('ns_notice=')
          ? absoluteEdit
          : `${absoluteEdit}${absoluteEdit.includes('?') ? '&' : '?'}ns_notice=success&ns_message=${encodeURIComponent(payload.message || '')}`;
        window.nsStartRegenModal(regen, noticeUrl);
      } else {
        window.location.href = absoluteEdit.includes('ns_notice=')
          ? absoluteEdit
          : `${absoluteEdit}${absoluteEdit.includes('?') ? '&' : '?'}ns_notice=success&ns_message=${encodeURIComponent(payload.message || '')}`;
      }
    } catch (err) {
      await window.nsAlert(err.message || (window.nsNobleSchemaAdmin?.i18n?.saveFailed) || 'Save failed.');
      isDirty = true;
    } finally {
      saving = false;
      saveBtn.disabled = false;
      if (enabledToggle) {
        enabledToggle.disabled = false;
      }
    }
  });

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);

  window.addEventListener('beforeunload', (e) => {
    if (!isDirty || saving) {
      return;
    }
    e.preventDefault();
    e.returnValue = '';
  });

  configNameInput?.addEventListener('input', () => {
    nameManuallyEdited = true;
    clearFieldError('ns-config-name-error', configNameInput);
  });

  document.getElementById('ns-post-types-input')?.addEventListener('input', () => {
    clearFieldError('ns-post-types-error');
  });
  document.getElementById('ns-page-templates-input')?.addEventListener('input', () => {
    clearFieldError('ns-page-templates-error');
  });

  document.getElementById('ns-clear-archive-cache')?.addEventListener('click', async () => {
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    const ok = await window.nsConfirm(
      i18n.clearArchiveConfirm || 'Clear cached schema output for archive pages targeted by this config?',
      {
        title: i18n.clearArchiveTitle || 'Clear archive cache?',
        confirmLabel: i18n.clearArchive || 'Clear cache',
        cancelLabel: i18n.cancel || 'Cancel',
        destructive: false,
      }
    );
    if (!ok) {
      return;
    }

    try {
      const body = new FormData();
      body.set('action', 'ns_clear_archive_cache');
      body.set('nonce', cfg.clearArchiveNonce || '');
      body.set('config_id', String(data.configId || 0));
      const res = await fetch(cfg.ajaxUrl || '', {
        method: 'POST',
        credentials: 'same-origin',
        body,
      });
      const json = await res.json();
      const message = json?.data?.message
        || i18n.clearArchiveDone
        || 'Archive cache cleared.';
      const removed = Number(json?.data?.removed || 0);
      if (removed > 0) {
        const btn = document.getElementById('ns-clear-archive-cache');
        const actions = btn?.closest('.ns-regen-actions');
        btn?.remove();
        if (actions && !actions.querySelector('a, button')) {
          actions.remove();
        }
      }
      await window.nsAlert(message, { title: i18n.alertTitle || 'Notice' });
    } catch (err) {
      await window.nsAlert(err.message || 'Could not clear archive cache.');
    }
  });

  document.querySelectorAll('#ns-wizard-form .ns-multiselect').forEach((root) => {
    root._nsHooks = {
      clearErrors: (r) => {
        clearFieldError(`${r.id}-error`);
        clearFieldError('ns-placement-error');
      },
      onChange: () => {
        updateTargetSummary();
        markDirty();
      },
    };
  });
  initMultiSelects(document.getElementById('ns-wizard-form'));
  initRuleGroups();
  syncPlacementMode();
  setStep(readInitialStep());
  paintInitialStepStatuses();
  renderFieldMappings();
  } // end initWizard

  function initSchemaTools() {
    const root = document.getElementById('ns-schema-tools');
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    if (!root) {
      return;
    }

    const searchInput = document.getElementById('ns-tools-post-search');
    const resultsEl = document.getElementById('ns-tools-post-results');
    const selectedEl = document.getElementById('ns-tools-selected');
    const reportEl = document.getElementById('ns-tools-report');
    const statusEl = document.getElementById('ns-tools-status');
    const diffEl = document.getElementById('ns-tools-diff');
    const actionsEl = document.getElementById('ns-tools-actions');
    const refreshBtn = document.getElementById('ns-tools-refresh');
    const regenBtn = document.getElementById('ns-tools-regen');
    const advancedToggle = document.getElementById('ns-tools-advanced');
    const simpleBody = document.getElementById('ns-tools-simple');
    const advancedBody = document.getElementById('ns-tools-advanced-body');
    const toolbarEl = document.getElementById('ns-viewer-toolbar');
    const validationEl = document.getElementById('ns-viewer-validation');
    const metaEl = document.getElementById('ns-viewer-meta');
    const jsonWrapEl = document.getElementById('ns-viewer-json-wrap');
    const jsonEl = document.getElementById('ns-viewer-json');
    const externalWrap = document.getElementById('ns-viewer-external');
    const schemaOrgLink = document.getElementById('ns-viewer-schema-org');
    const richResultsLink = document.getElementById('ns-viewer-rich-results');
    const sourceBtns = Array.from(root.querySelectorAll('.ns-schema-viewer__source'));

    const ADVANCED_COOKIE = 'ns_page_debugger_advanced';
    let selectedTarget = null;
    let selectedPostId = 0;
    let selectedLabel = '';
    let requestId = 0;
    let debounceTimer = null;
    let advanced = readAdvancedCookie();
    let source = 'stored';
    let viewerCache = { stored: null, live: null };
    let reportCache = null;
    let viewerLoadId = 0;
    let reportLoadId = 0;
    const scriptsEl = document.getElementById('ns-viewer-scripts');
    const homeHost = String(root.dataset.homeHost || '').replace(/^www\./i, '').toLowerCase();

    function looksLikeUrl(value) {
      const raw = String(value || '').trim();
      if (!raw) {
        return false;
      }
      // Absolute URLs, protocol-relative, or root/query-relative paths.
      if (/^https?:\/\//i.test(raw) || raw.startsWith('//') || raw.startsWith('/') || raw.startsWith('?')) {
        return true;
      }
      // host.tld-style input without a scheme (but not a plain numeric ID or title text).
      return /^[a-z0-9-]+(\.[a-z0-9-]+)+(\/|$)/i.test(raw) && !/\s/.test(raw);
    }

    function hasSelection() {
      return !!selectedTarget;
    }

    function isSingularSelection() {
      return selectedTarget && selectedTarget.type === 'singular' && Number(selectedTarget.post_id) > 0;
    }

    function readAdvancedCookie() {
      const match = document.cookie.match(/(?:^|;\s*)ns_page_debugger_advanced=([01])/);
      return match ? match[1] === '1' : false;
    }

    function writeAdvancedCookie(on) {
      const maxAge = 60 * 60 * 24 * 365;
      document.cookie = `${ADVANCED_COOKIE}=${on ? '1' : '0'}; path=/wp-admin; max-age=${maxAge}; SameSite=Lax`;
    }

    function clearCaches() {
      viewerCache = { stored: null, live: null };
      reportCache = null;
    }

    function clearViewerResults() {
      clearCaches();
      selectedTarget = null;
      selectedPostId = 0;
      selectedLabel = '';
      viewerLoadId += 1;
      reportLoadId += 1;
      if (selectedEl) {
        selectedEl.hidden = true;
        selectedEl.innerHTML = '';
      }
      if (metaEl) {
        metaEl.hidden = true;
        metaEl.innerHTML = '';
      }
      if (validationEl) {
        validationEl.hidden = true;
        validationEl.innerHTML = '';
      }
      if (scriptsEl) {
        scriptsEl.hidden = true;
        scriptsEl.innerHTML = '';
      }
      if (diffEl) {
        diffEl.hidden = true;
        diffEl.innerHTML = '';
      }
      if (reportEl) {
        reportEl.innerHTML = '';
      }
      renderViewerJson(null);
      syncExternalLinks('');
      setActionButtons(false, false);
      if (simpleBody) {
        simpleBody.hidden = true;
      }
      if (advancedBody) {
        advancedBody.hidden = true;
      }
    }

    function setActionButtons(visible, enabled) {
      if (actionsEl) {
        actionsEl.hidden = !visible;
      }
      if (toolbarEl) {
        toolbarEl.hidden = !visible || advanced;
      }
      if (refreshBtn) {
        refreshBtn.disabled = !enabled;
      }
      if (regenBtn) {
        regenBtn.disabled = !enabled || !isSingularSelection();
        regenBtn.hidden = hasSelection() && !isSingularSelection();
      }
    }

    function esc(str) {
      const d = document.createElement('div');
      d.textContent = str == null ? '' : String(str);
      return d.innerHTML;
    }

    function setStatus(message, isError) {
      if (!statusEl) {
        return;
      }
      if (!message) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = message;
      statusEl.classList.toggle('is-error', !!isError);
    }

    function prettyJson(raw) {
      if (!raw) {
        return '';
      }
      try {
        return JSON.stringify(JSON.parse(raw), null, 2);
      } catch (err) {
        return String(raw);
      }
    }

    function formatCount(n) {
      const num = Number(n);
      return Number.isFinite(num) ? num.toLocaleString() : '0';
    }

    function configEditUrl(id) {
      const base = String(cfg.configEditUrl || '');
      const num = Number(id) || 0;
      if (!base || num <= 0) {
        return '';
      }
      try {
        const url = new URL(base, window.location.origin);
        url.searchParams.set('config_id', String(num));
        return url.toString();
      } catch (err) {
        return base.replace(/([?&]config_id=)\d+/, `$1${num}`);
      }
    }

    function appliedConfigsMarkup(configs, fallbackIds) {
      const rows = Array.isArray(configs) ? configs : [];
      if (rows.length) {
        return rows.map((row) => {
          const id = Number(row.id) || 0;
          const name = String(row.name || (id ? `#${id}` : '')).trim() || (id ? `#${id}` : '—');
          const href = String(row.edit_url || configEditUrl(id) || '');
          if (!href) {
            return esc(name);
          }
          return `<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">${esc(name)}</a>`;
        }).join(', ');
      }
      const list = Array.isArray(fallbackIds) ? fallbackIds.filter((id) => Number(id) > 0) : [];
      if (!list.length) {
        return '—';
      }
      return list.map((id) => {
        const href = configEditUrl(id);
        const label = `#${id}`;
        if (!href) {
          return esc(label);
        }
        return `<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">${esc(label)}</a>`;
      }).join(', ');
    }

    function decodeEntities(str) {
      const d = document.createElement('textarea');
      d.innerHTML = str == null ? '' : String(str);
      return d.value;
    }

    /** Mirror the search endpoint's "Title (Type · #id)" label. */
    function postLabel(post) {
      if (!post || !post.id) {
        return '';
      }
      const title = decodeEntities(post.title || '').trim() || `#${post.id}`;
      const type = decodeEntities(post.post_type_label || post.post_type || '').trim();
      return type ? `${title} (${type} · #${post.id})` : `${title} (#${post.id})`;
    }

    function syncSelectedLabel(post) {
      const label = postLabel(post);
      if (!label) {
        return;
      }
      selectedLabel = label;
      if (selectedEl) {
        selectedEl.hidden = false;
        selectedEl.innerHTML = `<strong>${esc(selectedLabel)}</strong>`;
      }
    }

    function displayJson(raw) {
      return prettyJson(raw);
    }

    function jsonBlock(summaryLabel, raw) {
      return `
        <details class="ns-tools-json">
          <summary>${esc(summaryLabel)}</summary>
          <div class="ns-tools-json__body">
            <button type="button" class="ns-json-copy" aria-label="${esc(i18n.toolsCopyJson || 'Copy JSON')}" title="${esc(i18n.toolsCopyJson || 'Copy JSON')}">
              <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
            </button>
            <pre>${esc(prettyJson(raw))}</pre>
          </div>
        </details>`;
    }

    function validationMarkup(validation) {
      if (!validation) {
        return '';
      }
      const ok = !!validation.ok;
      const summary = (i18n.viewerValidationSummary || '%1$s errors · %2$s warnings')
        .replace('%1$s', formatCount(validation.error_count))
        .replace('%2$s', formatCount(validation.warning_count));
      const title = ok
        ? (i18n.viewerValidationOk || 'Passed structural checks')
        : (i18n.viewerValidationFail || 'Structural issues found');

      const issues = Array.isArray(validation.issues) ? validation.issues : [];
      const list = issues.length
        ? `<ul class="ns-schema-viewer__issues">${issues.map((issue) => `
            <li class="ns-schema-viewer__issue is-${esc(issue.severity || 'error')}">
              <span class="ns-schema-viewer__issue-sev">${esc(issue.severity || 'error')}</span>
              <span class="ns-schema-viewer__issue-msg">${esc(issue.message || '')}</span>
              ${issue.path ? `<code>${esc(issue.path)}</code>` : ''}
            </li>
          `).join('')}</ul>`
        : '';

      return `
        <div class="ns-schema-viewer__validation-head">
          <span class="ns-tools-badge ${ok ? 'ns-tools-badge--ok' : 'ns-tools-badge--no'}">${esc(title)}</span>
          <span class="ns-schema-viewer__validation-summary">${esc(summary)}</span>
        </div>
        ${list}`;
    }

    function validatorLinks(permalink) {
      const url = String(permalink || '').trim();
      if (!/^https?:\/\//i.test(url)) {
        return { ok: false, schemaOrg: '#', richResults: '#' };
      }
      return {
        ok: true,
        schemaOrg: `https://validator.schema.org/#url=${encodeURIComponent(url)}`,
        richResults: `https://search.google.com/test/rich-results?url=${encodeURIComponent(url)}`,
      };
    }

    function renderViewerJson(graphJson) {
      if (!jsonWrapEl || !jsonEl) {
        return;
      }
      if (!graphJson) {
        jsonWrapEl.hidden = true;
        jsonEl.textContent = '';
        return;
      }
      jsonWrapEl.hidden = false;
      jsonEl.textContent = displayJson(graphJson);
    }

    function renderViewerValidation(validation) {
      if (!validationEl) {
        return;
      }
      const markup = validationMarkup(validation);
      validationEl.hidden = !markup;
      validationEl.innerHTML = markup;
    }

    function otherLanguagesMarkup(post, { showCodes = false } = {}) {
      const others = Array.isArray(post.other_languages) ? post.other_languages : [];
      if (!others.length) {
        return '';
      }
      const links = others.map((lang) => {
        const label = showCodes && lang.language_code
          ? `${lang.language_label || lang.language_code} (${lang.language_code})`
          : (lang.language_label || lang.language_code || `#${lang.id}`);
        return `<button type="button" class="button-link ns-debugger-lang" data-post-id="${esc(lang.id)}" data-label="${esc(postLabel(lang))}">${esc(label)}</button>`;
      }).join(' · ');
      return `<div><dt>Other Languages</dt><dd>${links}</dd></div>`;
    }

    function renderViewerMeta(data) {
      if (!metaEl) {
        return;
      }
      const ctx = data.context || {};
      const post = data.post || {};
      const sourceLabel = data.source === 'live'
        ? (i18n.viewerSourceLive || 'Showing live generate preview (not saved)')
        : (i18n.viewerSourceStored || 'Showing stored snapshot');
      const linkParts = [];
      if (post.edit_url) {
        linkParts.push(`<a href="${esc(post.edit_url)}" target="_blank" rel="noopener noreferrer">Edit</a>`);
      }
      const viewUrl = post.permalink || ctx.url || '';
      if (viewUrl) {
        linkParts.push(`<a href="${esc(viewUrl)}" target="_blank" rel="noopener noreferrer">View</a>`);
      }
      const typeLabel = ctx.type === 'archive'
        ? (i18n.viewerContextArchive || 'Archive')
        : (ctx.type === 'specialty'
          ? (i18n.viewerContextSpecialty || 'Specialty')
          : (i18n.viewerContextPage || 'Page'));
      const title = ctx.label || post.title || selectedLabel || '—';
      metaEl.hidden = false;
      metaEl.innerHTML = `
        <dl class="ns-tools-dl">
          <div><dt>Context</dt><dd>${esc(typeLabel)} · ${esc(title)}</dd></div>
          ${post.id ? `<div><dt>Post</dt><dd>${esc(post.title || '')} (#${esc(post.id)})</dd></div>` : ''}
          ${post.post_type || post.post_type_label
            ? `<div><dt>Post Type</dt><dd>${esc(post.post_type_label || post.post_type || '—')}</dd></div>`
            : ''}
          ${post.language_label
            ? `<div><dt>Language</dt><dd>${esc(post.language_label)}</dd></div>`
            : ''}
          ${otherLanguagesMarkup(post)}
          ${linkParts.length ? `<div><dt>Links</dt><dd>${linkParts.join(' · ')}</dd></div>` : ''}
          <div><dt>Source</dt><dd>${esc(sourceLabel)}</dd></div>
          <div><dt>Applied Configs</dt><dd>${appliedConfigsMarkup(data.applied_configs, data.config_ids)}</dd></div>
          ${data.generated_at ? `<div><dt>Generated</dt><dd>${esc(data.generated_at)}</dd></div>` : ''}
        </dl>`;
    }

    function renderViewerScripts(scripts) {
      if (!scriptsEl) {
        return;
      }
      const list = Array.isArray(scripts) ? scripts : [];
      if (!list.length) {
        scriptsEl.hidden = true;
        scriptsEl.innerHTML = '';
        if (jsonWrapEl) {
          jsonWrapEl.hidden = true;
        }
        return;
      }
      // Prefer multi-script view when sitewide + page (or multiple) are present.
      if (list.length === 1 && jsonWrapEl && jsonEl) {
        scriptsEl.hidden = true;
        scriptsEl.innerHTML = '';
        renderViewerJson(list[0].graph_json || null);
        return;
      }
      if (jsonWrapEl) {
        jsonWrapEl.hidden = true;
      }
      scriptsEl.hidden = false;
      scriptsEl.innerHTML = list.map((script, index) => {
        const tag = esc(script.label || script.tag || 'Schema');
        const name = script.config_name ? ` · ${esc(script.config_name)}` : '';
        const json = prettyJson(script.graph_json || '');
        return `
          <section class="ns-schema-viewer__script">
            <h3 class="ns-schema-viewer__script-title">
              <span class="ns-chip">${tag}</span>${name}
            </h3>
            <div class="ns-tools-json">
              <div class="ns-tools-json__body">
                <button type="button" class="ns-json-copy" data-copy-index="${index}" aria-label="Copy JSON" title="Copy JSON">
                  <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                </button>
                <pre>${esc(json)}</pre>
              </div>
            </div>
          </section>`;
      }).join('');
      scriptsEl.querySelectorAll('.ns-json-copy').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const idx = Number(btn.dataset.copyIndex) || 0;
          const raw = list[idx]?.graph_json || '';
          try {
            await navigator.clipboard.writeText(prettyJson(raw));
          } catch (err) {
            // ignore
          }
        });
      });
    }

    function syncExternalLinks(permalink) {
      const links = validatorLinks(permalink);
      if (externalWrap) {
        externalWrap.hidden = !links.ok;
      }
      if (schemaOrgLink) {
        schemaOrgLink.href = links.schemaOrg;
      }
      if (richResultsLink) {
        richResultsLink.href = links.richResults;
      }
    }

    function renderViewer(data) {
      const ctx = data.context || {};
      selectedLabel = ctx.label || data.post?.title || selectedLabel;
      if (selectedEl) {
        selectedEl.hidden = false;
        selectedEl.innerHTML = `<strong>${esc(selectedLabel)}</strong>`;
      }
      renderViewerMeta(data);
      renderViewerValidation(data.validation);
      renderViewerScripts(data.scripts || []);
      if ((!data.scripts || data.scripts.length <= 1) && data.graph_json) {
        renderViewerJson(data.graph_json);
      }
      syncExternalLinks(data.post?.permalink || ctx.url || '');
      setStatus(
        data.present || data.graph_json || (data.scripts && data.scripts.length)
          ? ''
          : (i18n.viewerNoSchema || 'No schema available for this target.'),
        !(data.present || data.graph_json || (data.scripts && data.scripts.length))
      );
    }

    function appendTargetParams(body) {
      if (!selectedTarget) {
        return;
      }
      body.set('context', selectedTarget.type || '');
      if (selectedTarget.post_id) {
        body.set('post_id', String(selectedTarget.post_id));
      }
      if (selectedTarget.archive_key) {
        body.set('archive_key', String(selectedTarget.archive_key));
      }
      if (selectedTarget.specialty_key) {
        body.set('specialty_key', String(selectedTarget.specialty_key));
      }
      if (selectedTarget.url) {
        body.set('url', String(selectedTarget.url));
      }
    }

    async function loadViewer(force) {
      if (!hasSelection()) {
        return;
      }
      if (!force && viewerCache[source]) {
        renderViewer(viewerCache[source]);
        return;
      }

      const id = ++viewerLoadId;
      setStatus(i18n.viewerLoading || 'Loading schema…', false);

      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_viewer');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('source', source);
        appendTargetParams(body);
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (id !== viewerLoadId) {
          return;
        }
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.viewerLoadError || 'Could not load schema.');
        }
        viewerCache[source] = json.data;
        renderViewer(json.data);
      } catch (err) {
        if (id !== viewerLoadId) {
          return;
        }
        renderViewerJson(null);
        renderViewerValidation(null);
        if (scriptsEl) {
          scriptsEl.hidden = true;
          scriptsEl.innerHTML = '';
        }
        syncExternalLinks('');
        if (metaEl) {
          metaEl.hidden = true;
          metaEl.innerHTML = '';
        }
        setStatus(err.message || i18n.viewerLoadError || 'Could not load schema.', true);
      }
    }

    function snapshotSummary(snap) {
      if (!snap || !snap.present) {
        return `<p class="ns-tools-muted">${esc(i18n.toolsMissingSnapshot || 'No snapshot stored')}</p>`;
      }
      return `
        <dl class="ns-tools-dl">
          <div><dt>Complete</dt><dd>${snap.is_complete ? 'yes' : 'no'}</dd></div>
          <div><dt>Generated</dt><dd>${esc(snap.generated_at || '—')}</dd></div>
          <div><dt>Hash</dt><dd><code>${esc(snap.content_hash || '—')}</code></dd></div>
          <div><dt>Applied Configs</dt><dd>${appliedConfigsMarkup(snap.applied_configs, snap.config_ids)}</dd></div>
        </dl>`;
    }

    function renderDiff(before, after) {
      if (!diffEl) {
        return;
      }
      diffEl.hidden = false;
      diffEl.innerHTML = `
        <h3>${esc(i18n.toolsBefore || 'Before')} / ${esc(i18n.toolsAfter || 'After')}</h3>
        <div class="ns-tools-diff__grid">
          <section>
            <h4>${esc(i18n.toolsBefore || 'Before')}</h4>
            ${snapshotSummary(before)}
            ${jsonBlock('graph_json', before?.graph_json)}
          </section>
          <section>
            <h4>${esc(i18n.toolsAfter || 'After')}</h4>
            ${snapshotSummary(after)}
            ${jsonBlock('graph_json', after?.graph_json)}
          </section>
        </div>`;
    }

    function reportValidatorLinks(permalink) {
      const links = validatorLinks(permalink);
      const hint = `<p class="ns-tools-muted">${esc(i18n.viewerExternalHint || 'Validates the published page URL (what crawlers see), not the Live generate preview.')}</p>`;
      if (!links.ok) {
        return hint;
      }
      return `
        <div class="ns-schema-viewer__external">
          <a class="button" href="${esc(links.schemaOrg)}" target="_blank" rel="noopener noreferrer">
            ${esc(i18n.viewerSchemaOrg || 'Schema.org Validator')}
            <span class="dashicons dashicons-external" aria-hidden="true"></span>
          </a>
          <a class="button" href="${esc(links.richResults)}" target="_blank" rel="noopener noreferrer">
            ${esc(i18n.viewerRichResults || 'Rich Results Test')}
            <span class="dashicons dashicons-external" aria-hidden="true"></span>
          </a>
        </div>
        ${hint}`;
    }

    function renderReport(report) {
      if (!reportEl || !report) {
        return;
      }
      const post = report.post || {};
      const validation = report.validation || null;
      syncSelectedLabel(post);
      const context = report.context || {};
      const configs = report.configs || [];
      const generation = report.generation || {};
      const snap = report.snapshot || {};
      const frontend = report.frontend || {};
      const manual = report.manual_values || [];
      const regen = report.regen || {};
      const conflicts = report.conflicts || [];
      const logs = report.logs || {};
      const logRows = Array.isArray(logs.rows) ? logs.rows : [];

      const taxLines = Object.keys(context.taxonomies || {}).map((tax) => {
        const terms = (context.taxonomies[tax] || []).join(', ');
        return `<li><strong>${esc(tax)}</strong>: ${esc(terms || '—')}</li>`;
      }).join('');

      const configRows = configs.map((c) => `
        <tr class="${c.matched ? 'is-matched' : 'is-unmatched'}">
          <td><a href="${esc(c.edit_url)}" target="_blank" rel="noopener noreferrer">${esc(c.name || ('#' + c.id))}</a></td>
          <td>${esc(c.schema_type)}</td>
          <td>${esc(c.placement)}</td>
          <td>${esc(c.status)}</td>
          <td>${c.matched
            ? `<span class="ns-tools-badge ns-tools-badge--ok">${esc(i18n.toolsMatched || 'Matched')}</span>`
            : `<span class="ns-tools-badge ns-tools-badge--no">${esc(i18n.toolsNotMatched || 'Not matched')}</span>`}</td>
          <td>${esc(c.reason || '—')}</td>
        </tr>`).join('');

      const generationConfigs = Array.isArray(generation.configs) ? generation.configs : [];
      const generationCards = generationConfigs.map((c) => {
        const missing = Array.isArray(c.missing_required) ? c.missing_required : [];
        const fields = Array.isArray(c.fields) ? c.fields : [];
        const badgeClass = c.included
          ? 'ns-tools-badge--ok'
          : (c.outcome === 'missing_required' ? 'ns-tools-badge--warn' : 'ns-tools-badge--no');
        const missingList = missing.length
          ? `<ul class="ns-tools-list">${missing.map((m) => `
              <li>
                <strong>${esc(m.label || m.path)}</strong>
                <code>${esc(m.path)}</code>
                <span class="ns-tools-muted">· ${esc(m.source_type || '')}</span>
              </li>`).join('')}</ul>`
          : '';
        const fieldRows = fields.length
          ? `<details class="ns-tools-json">
              <summary>${esc(i18n.toolsGenerationFields || 'Resolved fields')} (${fields.length})</summary>
              <div class="ns-tools-table-wrap">
                <table class="widefat striped ns-tools-table">
                  <thead>
                    <tr>
                      <th>${esc('Field')}</th>
                      <th>${esc('Required')}</th>
                      <th>${esc('Source')}</th>
                      <th>${esc('Resolved')}</th>
                      <th>${esc('Preview')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${fields.map((f) => `
                      <tr>
                        <td><strong>${esc(f.label || f.path)}</strong><br><code>${esc(f.path)}</code></td>
                        <td>${f.required ? esc('yes') : esc('no')}</td>
                        <td><code>${esc(f.source_type || '')}</code></td>
                        <td>${f.resolved
                          ? `<span class="ns-tools-badge ns-tools-badge--ok">${esc('yes')}</span>`
                          : `<span class="ns-tools-badge ns-tools-badge--no">${esc('empty')}</span>`}</td>
                        <td><code>${esc(f.value_preview == null ? '—' : f.value_preview)}</code></td>
                      </tr>`).join('')}
                  </tbody>
                </table>
              </div>
            </details>`
          : '';

        return `
          <div class="ns-tools-generation-card">
            <div class="ns-tools-generation-card__head">
              <a href="${esc(c.edit_url || '#')}" target="_blank" rel="noopener noreferrer"><strong>${esc(c.name || ('#' + c.id))}</strong></a>
              <span class="ns-tools-badge ${badgeClass}">${esc(c.outcome_label || c.outcome || '')}</span>
            </div>
            <p class="ns-tools-muted">${esc(c.reason || '')}</p>
            ${missingList}
            ${fieldRows}
          </div>`;
      }).join('');

      const manualRows = manual.length
        ? manual.map((m) => `
            <tr>
              <td>${esc(m.config_id)}</td>
              <td><code>${esc(m.field_path)}</code></td>
              <td>${esc(m.value)}</td>
            </tr>`).join('')
        : `<tr><td colspan="3">${esc('None')}</td></tr>`;

      const conflictList = conflicts.length
        ? `<ul>${conflicts.map((c) => `<li>${esc(c.message)}</li>`).join('')}</ul>`
        : `<p class="ns-tools-muted">None detected.</p>`;

      const levelBadgeClass = (level) => {
        if (level === 'error') {
          return 'ns-tools-badge--no';
        }
        if (level === 'warning') {
          return 'ns-tools-badge--warn';
        }
        return 'ns-tools-badge--ok';
      };

      let logsBody = '';
      if (!logs.enabled) {
        logsBody = `<p class="ns-tools-muted">${esc(i18n.toolsLogsDisabled || 'System logging is off.')} ${
          logs.logs_url
            ? `<a href="${esc(logs.logs_url)}">${esc(i18n.toolsLogsOpenSettings || 'Open Logs')}</a>`
            : ''
        }</p>`;
      } else if (!logRows.length) {
        logsBody = `<p class="ns-tools-muted">${esc(i18n.toolsLogsEmpty || 'No log entries for this page.')}</p>`;
      } else {
        logsBody = `<div class="ns-tools-logs">${logRows.map((row) => {
          const detail = (() => {
            try {
              return JSON.stringify(row.context || {}, null, 2);
            } catch (e) {
              return '';
            }
          })();
          const detailBlock = detail && detail !== '{}'
            ? `<details class="ns-tools-logs__details"><summary>${esc(i18n.logsDetail || 'Details')}</summary><pre>${esc(detail)}</pre></details>`
            : '';
          return `<article class="ns-tools-logs__row">
            <div class="ns-tools-logs__meta">
              <time>${esc(row.created_at || '')}</time>
              <span class="ns-tools-badge ${levelBadgeClass(row.level)}">${esc(row.level || 'info')}</span>
              <span class="ns-tools-logs__category">${esc(row.category || 'system')}</span>
            </div>
            <p class="ns-tools-logs__message">${esc(row.message || '')}</p>
            ${detailBlock}
          </article>`;
        }).join('')}</div>
        ${logs.logs_url ? `<p class="ns-tools-muted"><a href="${esc(logs.logs_url)}">${esc(i18n.toolsLogsViewAll || 'View all logs')}</a></p>` : ''}`;
      }

      reportEl.hidden = false;
      reportEl.innerHTML = `
        <section class="ns-tools-section">
          <h3>Post</h3>
          <dl class="ns-tools-dl">
            <div><dt>Title</dt><dd>${esc(post.title)}</dd></div>
            <div><dt>ID</dt><dd>${esc(post.id)}</dd></div>
            <div><dt>Type</dt><dd>${esc(post.post_type_label)} <code>${esc(post.post_type)}</code></dd></div>
            ${post.language_label
              ? `<div><dt>Language</dt><dd>${esc(post.language_label)}${post.language_code ? ` <code>${esc(post.language_code)}</code>` : ''}</dd></div>`
              : ''}
            ${otherLanguagesMarkup(post, { showCodes: true })}
            <div><dt>Status</dt><dd>${esc(post.status)}</dd></div>
            <div><dt>Template</dt><dd>${esc(post.page_template || '—')}</dd></div>
            <div><dt>Content size</dt><dd>${esc(Number(post.content_bytes || 0).toLocaleString())} bytes</dd></div>
            <div><dt>Links</dt><dd>
              ${post.edit_url ? `<a href="${esc(post.edit_url)}" target="_blank" rel="noopener noreferrer">Edit</a>` : ''}
              ${post.permalink ? ` · <a href="${esc(post.permalink)}" target="_blank" rel="noopener noreferrer">View</a>` : ''}
            </dd></div>
          </dl>
        </section>

        <section class="ns-tools-section">
          <h3>Matcher context</h3>
          <dl class="ns-tools-dl">
            <div><dt>Post type</dt><dd>${esc(context.post_type)}</dd></div>
            <div><dt>Page template</dt><dd>${esc(context.page_template || '—')}</dd></div>
            <div><dt>Blocks</dt><dd>${esc((context.block_types || []).join(', ') || '—')}</dd></div>
          </dl>
          ${taxLines ? `<ul class="ns-tools-list">${taxLines}</ul>` : '<p class="ns-tools-muted">No taxonomy terms.</p>'}
        </section>

        <section class="ns-tools-section">
          <h3>Matching configs</h3>
          <div class="ns-tools-table-wrap">
            <table class="widefat striped ns-tools-table">
              <thead>
                <tr>
                  <th>Name</th><th>Schema</th><th>Placement</th><th>Status</th><th>Match</th><th>Reason</th>
                </tr>
              </thead>
              <tbody>${configRows || '<tr><td colspan="6">No configs.</td></tr>'}</tbody>
            </table>
          </div>
        </section>

        <section class="ns-tools-section">
          <h3>${esc(i18n.toolsGenerationTitle || 'Generation')}</h3>
          <p>
            <span class="ns-tools-badge ${generation.would_generate ? 'ns-tools-badge--ok' : 'ns-tools-badge--no'}">
              ${esc(generation.would_generate
                ? (i18n.toolsGenerationYes || 'Would generate schema')
                : (i18n.toolsGenerationNo || 'Would not generate schema'))}
            </span>
          </p>
          <p class="ns-tools-muted">${esc(generation.summary || '')}</p>
          ${generationCards || `<p class="ns-tools-muted">${esc(i18n.toolsGenerationEmpty || 'No configs to evaluate.')}</p>`}
        </section>

        <section class="ns-tools-section">
          <h3>${esc(i18n.toolsValidationTitle || 'Validation')}</h3>
          ${validationMarkup(validation)
            || `<p class="ns-tools-muted">${esc(i18n.viewerNoSchema || 'No schema available for this post.')}</p>`}
          ${reportValidatorLinks(post.permalink || '')}
        </section>

        <section class="ns-tools-section">
          <h3>Stored snapshot</h3>
          ${snapshotSummary(snap)}
          ${snap.present ? jsonBlock('graph_json', snap.graph_json) : ''}
        </section>

        <section class="ns-tools-section">
          <h3>Frontend</h3>
          <p>
            <span class="ns-tools-badge ${frontend.would_print ? 'ns-tools-badge--ok' : 'ns-tools-badge--no'}">
              ${esc(frontend.would_print
                ? (i18n.toolsWouldPrint || 'Would print on frontend')
                : (i18n.toolsWouldNotPrint || 'Would not print on frontend'))}
            </span>
          </p>
          <p class="ns-tools-muted">${esc(frontend.reason || '')}</p>
        </section>

        <section class="ns-tools-section">
          <h3>Manual values</h3>
          <div class="ns-tools-table-wrap">
            <table class="widefat striped ns-tools-table">
              <thead><tr><th>Config</th><th>Field</th><th>Value</th></tr></thead>
              <tbody>${manualRows}</tbody>
            </table>
          </div>
        </section>

        <section class="ns-tools-section">
          <h3>Regen queue</h3>
          <p>
            <span class="ns-tools-badge ${regen.pending ? 'ns-tools-badge--warn' : 'ns-tools-badge--ok'}">
              ${esc(regen.pending
                ? (i18n.toolsPendingRegen || 'Pending in regen queue')
                : (i18n.toolsNotPendingRegen || 'Not pending in regen queue'))}
            </span>
          </p>
          <p class="ns-tools-muted">${esc(regen.note || '')}</p>
          <dl class="ns-tools-dl">
            <div><dt>Processed</dt><dd>${esc(regen.status?.processed ?? 0)} / ${esc(regen.status?.total ?? 0)}</dd></div>
            <div><dt>Done</dt><dd>${regen.status?.done ? 'yes' : 'no'}</dd></div>
            <div><dt>Cancelled</dt><dd>${regen.status?.cancelled ? 'yes' : 'no'}</dd></div>
          </dl>
        </section>

        <section class="ns-tools-section">
          <h3>${esc(i18n.toolsLogsTitle || 'System logs')}</h3>
          ${logsBody}
        </section>

        <section class="ns-tools-section">
          <h3>Plugin conflicts</h3>
          ${conflictList}
        </section>`;
    }

    async function loadReport(force) {
      if (!selectedPostId) {
        return;
      }
      if (!force && reportCache) {
        renderReport(reportCache);
        setStatus('', false);
        return;
      }

      const id = ++reportLoadId;
      setActionButtons(true, false);
      setStatus(i18n.toolsLoading || 'Loading…', false);

      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_inspect');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('post_id', String(selectedPostId));
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (id !== reportLoadId) {
          return;
        }
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.toolsLoadError || 'Could not load schema report.');
        }
        setStatus('', false);
        reportCache = json.data.report;
        renderReport(reportCache);
        setActionButtons(true, true);
      } catch (err) {
        if (id !== reportLoadId) {
          return;
        }
        setStatus(err.message || i18n.toolsLoadError || 'Could not load schema report.', true);
        if (reportEl) {
          reportEl.hidden = true;
          reportEl.innerHTML = '';
        }
        // Keep actions visible so Refresh can be retried.
        setActionButtons(true, true);
      }
    }

    function applyMode() {
      if (simpleBody) {
        simpleBody.hidden = advanced;
      }
      if (advancedBody) {
        advancedBody.hidden = !advanced;
      }
      if (advancedToggle) {
        advancedToggle.checked = advanced;
      }
      if (toolbarEl) {
        toolbarEl.hidden = !hasSelection() || advanced;
      }
      if (!hasSelection()) {
        return;
      }
      if (advanced && isSingularSelection()) {
        loadReport(false);
      } else {
        if (advanced && !isSingularSelection()) {
          setStatus(
            i18n.viewerAdvancedSingularOnly
              || 'Advanced mode is available for posts. Showing the combined head view for this target.',
            false
          );
          if (simpleBody) {
            simpleBody.hidden = false;
          }
          if (advancedBody) {
            advancedBody.hidden = true;
          }
        }
        loadViewer(false);
      }
    }

    function syncHistoryFromTarget() {
      const url = new URL(window.location.href);
      url.searchParams.set('tool', 'checker');
      ['post_id', 'url', 'specialty_key', 'archive_key'].forEach((key) => url.searchParams.delete(key));
      if (!selectedTarget) {
        window.history.replaceState({}, '', url.toString());
        return;
      }
      if (selectedTarget.type === 'singular' && selectedTarget.post_id) {
        url.searchParams.set('post_id', String(selectedTarget.post_id));
      } else if (selectedTarget.type === 'archive' && selectedTarget.archive_key) {
        url.searchParams.set('archive_key', String(selectedTarget.archive_key));
      } else if (selectedTarget.type === 'specialty' && selectedTarget.specialty_key) {
        url.searchParams.set('specialty_key', String(selectedTarget.specialty_key));
      }
      if (selectedTarget.url) {
        url.searchParams.set('url', String(selectedTarget.url));
      }
      window.history.replaceState({}, '', url.toString());
    }

    function selectTarget(target, force) {
      if (!target || !target.type) {
        return;
      }
      const nextKey = JSON.stringify(target);
      const prevKey = selectedTarget ? JSON.stringify(selectedTarget) : '';
      if (nextKey !== prevKey || force) {
        clearCaches();
      }
      selectedTarget = target;
      selectedPostId = Number(target.post_id) || 0;
      selectedLabel = target.label || selectedLabel || '';

      if (selectedEl) {
        selectedEl.hidden = false;
        selectedEl.innerHTML = `<strong>${esc(selectedLabel || target.type)}</strong>`;
      }
      if (diffEl) {
        diffEl.hidden = true;
        diffEl.innerHTML = '';
      }
      setActionButtons(true, true);
      syncHistoryFromTarget();
      applyMode();
    }

    function selectPost(postId, label, force) {
      const nextId = Number(postId) || 0;
      if (!nextId) {
        return;
      }
      selectTarget({
        type: 'singular',
        post_id: nextId,
        label: label || (`#${nextId}`),
      }, force);
    }

    function clientUrlLooksOffSite(raw) {
      const value = String(raw || '').trim();
      if (!value || !homeHost || value.startsWith('/') || value.startsWith('?')) {
        return false;
      }
      try {
        const parsed = new URL(value, window.location.origin);
        const host = String(parsed.host || '').replace(/^www\./i, '').toLowerCase();
        return host !== '' && host !== homeHost;
      } catch (err) {
        return false;
      }
    }

    async function debugFromUrl(raw) {
      clearViewerResults();
      const value = String(raw || '').trim();
      if (!value) {
        setStatus(i18n.viewerUrlRequired || 'Enter a URL or path to debug.', true);
        return;
      }
      if (clientUrlLooksOffSite(value)) {
        setStatus(i18n.viewerUrlOffSite || 'Only URLs on this site can be debugged.', true);
        return;
      }
      setStatus(i18n.viewerLoading || 'Loading schema…', false);
      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_resolve');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('url', value);
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.viewerUrlResolveError || 'Could not resolve that URL.');
        }
        selectTarget(json.data.context, true);
      } catch (err) {
        clearViewerResults();
        setStatus(err.message || i18n.viewerUrlResolveError || 'Could not resolve that URL.', true);
      }
    }

    async function searchPosts(query) {
      const params = new URLSearchParams({
        action: 'ns_search_posts',
        nonce: cfg.postsNonce || '',
      });
      if (query) {
        params.set('search', query);
      }
      const id = ++requestId;
      const res = await fetch(`${cfg.ajaxUrl}?${params.toString()}`, { credentials: 'same-origin' });
      const json = await res.json();
      if (id !== requestId) {
        return;
      }
      const posts = json?.success ? (json.data.posts || []) : [];
      if (!resultsEl) {
        return;
      }
      if (!posts.length) {
        resultsEl.hidden = false;
        resultsEl.innerHTML = `<div class="ns-tools-checker__empty">${esc(i18n.toolsNoResults || 'No posts found.')}</div>`;
        return;
      }
      resultsEl.hidden = false;
      resultsEl.innerHTML = posts.map((p) => (
        `<button type="button" class="ns-tools-checker__result" data-value="${esc(p.value)}" data-label="${esc(p.label)}">${esc(p.label)}</button>`
      )).join('');
    }

    function hideResults() {
      if (resultsEl) {
        resultsEl.hidden = true;
        resultsEl.innerHTML = '';
      }
    }

    searchInput?.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      const value = searchInput.value.trim();
      if (looksLikeUrl(value)) {
        hideResults();
        return;
      }
      debounceTimer = setTimeout(() => {
        searchPosts(value);
      }, 250);
    });

    searchInput?.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') {
        return;
      }
      const value = searchInput.value.trim();
      if (looksLikeUrl(value)) {
        e.preventDefault();
        clearTimeout(debounceTimer);
        hideResults();
        debugFromUrl(value);
      }
    });

    searchInput?.addEventListener('focus', () => {
      if (resultsEl && !resultsEl.hidden) {
        return;
      }
      const value = searchInput.value.trim();
      if (looksLikeUrl(value)) {
        return;
      }
      searchPosts(value);
    });

    resultsEl?.addEventListener('click', (e) => {
      const btn = e.target.closest('.ns-tools-checker__result');
      if (!btn) {
        return;
      }
      resultsEl.hidden = true;
      if (searchInput) {
        searchInput.value = '';
      }
      selectPost(btn.dataset.value, btn.dataset.label, false);
    });

    function onOtherLanguageClick(e) {
      const btn = e.target.closest('.ns-debugger-lang');
      if (!btn) {
        return;
      }
      e.preventDefault();
      selectPost(btn.dataset.postId, btn.dataset.label || '', false);
    }
    metaEl?.addEventListener('click', onOtherLanguageClick);
    reportEl?.addEventListener('click', onOtherLanguageClick);

    advancedToggle?.addEventListener('change', () => {
      advanced = !!advancedToggle.checked;
      writeAdvancedCookie(advanced);
      applyMode();
    });

    sourceBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        source = btn.dataset.source === 'live' ? 'live' : 'stored';
        sourceBtns.forEach((b) => {
          b.classList.toggle('is-active', b.dataset.source === source);
        });
        loadViewer(false);
      });
    });

    root.addEventListener('click', async (e) => {
      const btn = e.target.closest('.ns-json-copy');
      if (!btn) {
        return;
      }
      e.preventDefault();
      const pre = btn.parentElement?.querySelector('pre');
      const text = pre ? pre.textContent : '';
      if (!text) {
        return;
      }
      try {
        await navigator.clipboard.writeText(text);
        btn.classList.add('is-copied');
        btn.title = i18n.toolsCopied || 'Copied';
        setTimeout(() => {
          btn.classList.remove('is-copied');
          btn.title = i18n.toolsCopyJson || 'Copy JSON';
        }, 1500);
      } catch (err) {
        await window.nsAlert(err.message || i18n.toolsCopyFailed || 'Could not copy.');
      }
    });

    document.addEventListener('click', (e) => {
      if (!resultsEl || resultsEl.hidden) {
        return;
      }
      if (searchInput?.contains(e.target) || resultsEl.contains(e.target)) {
        return;
      }
      resultsEl.hidden = true;
    });

    refreshBtn?.addEventListener('click', () => {
      if (!hasSelection() || refreshBtn.disabled) {
        return;
      }
      clearCaches();
      applyMode();
    });

    regenBtn?.addEventListener('click', async () => {
      if (!isSingularSelection() || regenBtn.disabled) {
        return;
      }
      const ok = await window.nsConfirm(
        i18n.toolsRegenConfirm || 'Regenerate schema for this post? The stored snapshot will be replaced.',
        {
          title: i18n.toolsRegenTitle || 'Regenerate schema?',
          confirmLabel: i18n.toolsRegen || 'Regenerate schema',
          cancelLabel: i18n.cancel || 'Cancel',
          destructive: false,
        }
      );
      if (!ok) {
        return;
      }

      setActionButtons(true, false);
      setStatus(i18n.toolsLoading || 'Loading…', false);
      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_regenerate');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('post_id', String(selectedPostId));
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.toolsLoadError || 'Could not load schema report.');
        }

        clearCaches();
        reportCache = json.data.report;
        renderReport(reportCache);
        if (json.data.changed) {
          setStatus(json.data.message || i18n.toolsRegenChanged || 'Schema regenerated. Changes detected.', false);
          renderDiff(json.data.before, json.data.after);
        } else {
          setStatus(json.data.message || i18n.toolsRegenUnchanged || 'Schema unchanged after regenerate.', false);
          if (diffEl) {
            diffEl.hidden = true;
            diffEl.innerHTML = '';
          }
        }
      } catch (err) {
        setStatus(err.message || i18n.toolsLoadError || 'Could not load schema report.', true);
      } finally {
        setActionButtons(true, true);
      }
    });

    applyMode();

    root.querySelectorAll('.ns-tools-checker__chip[data-specialty]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const key = btn.dataset.specialty || '';
        if (!key) {
          return;
        }
        setStatus(i18n.viewerLoading || 'Loading schema…', false);
        try {
          const body = new URLSearchParams();
          body.set('action', 'ns_schema_tools_resolve');
          body.set('nonce', cfg.toolsNonce || '');
          body.set('context', 'specialty');
          body.set('specialty_key', key);
          const res = await fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
          });
          const json = await res.json();
          if (!json?.success) {
            throw new Error(json?.data?.message || i18n.viewerUrlResolveError || 'Could not resolve that page.');
          }
          const ctx = json.data.context;
          if (ctx && !ctx.label) {
            ctx.label = btn.textContent?.trim() || key;
          }
          selectTarget(ctx, true);
        } catch (err) {
          setStatus(err.message || i18n.viewerUrlResolveError || 'Could not resolve that page.', true);
        }
      });
    });

    const initialId = Number(root.dataset.initialPostId || 0);
    const initialUrl = String(root.dataset.initialUrl || '').trim();
    const initialSpecialty = String(root.dataset.initialSpecialty || '').trim();
    const initialArchive = String(root.dataset.initialArchive || '').trim();
    if (initialUrl) {
      if (searchInput) {
        searchInput.value = initialUrl;
      }
      debugFromUrl(initialUrl);
    } else if (initialSpecialty) {
      selectTarget({
        type: 'specialty',
        specialty_key: initialSpecialty,
        label: initialSpecialty,
      }, true);
    } else if (initialArchive) {
      selectTarget({
        type: 'archive',
        archive_key: initialArchive,
        label: initialArchive,
      }, true);
    } else if (initialId > 0) {
      selectPost(initialId, `#${initialId}`, false);
    } else {
      setActionButtons(false, false);
      setStatus(i18n.toolsSelectPost || 'Select a post, paste a URL, or use a quick pick.', false);
    }
  }

  function initSitewideAnalytics() {
    const root = document.getElementById('ns-sitewide-analytics');
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    if (!root) {
      return;
    }

    const siteStatusEl = document.getElementById('ns-viewer-site-status');
    const siteLoadingEl = document.getElementById('ns-viewer-site-loading');
    const siteScoreEl = document.getElementById('ns-viewer-site-score');
    const sitePercentEl = document.getElementById('ns-viewer-site-percent');
    const siteRingEl = document.getElementById('ns-viewer-site-ring');
    const siteRingValueEl = document.getElementById('ns-viewer-site-ring-value');
    const siteOfEl = document.getElementById('ns-viewer-site-of');
    const siteCalloutEl = document.getElementById('ns-viewer-site-callout');
    const siteCalloutHelpBtn = document.getElementById('ns-viewer-site-callout-help');
    const siteTiersEl = document.getElementById('ns-viewer-site-tiers');
    const siteTiersRow = document.getElementById('ns-viewer-site-tiers-row');
    const siteTiersHelpBtn = document.getElementById('ns-viewer-site-tiers-help');
    const siteArchivesEl = document.getElementById('ns-viewer-site-archives');
    const siteArchivesBody = document.getElementById('ns-viewer-site-archives-body');
    const siteArchivesCount = document.getElementById('ns-viewer-site-archives-count');
    const siteFiltersEl = document.getElementById('ns-viewer-site-filters');
    const siteTableWrap = document.getElementById('ns-viewer-site-table-wrap');
    const siteTbody = document.getElementById('ns-viewer-site-tbody');
    const sitePager = document.getElementById('ns-viewer-site-pager');
    const siteActionsEl = document.getElementById('ns-viewer-site-actions');
    const siteScanBtn = document.getElementById('ns-viewer-site-scan');
    const siteScanCancelBtn = document.getElementById('ns-viewer-site-scan-cancel');
    const siteScanMeta = document.getElementById('ns-viewer-site-scan-meta');
    const siteProgressEl = document.getElementById('ns-viewer-site-progress');
    const siteProgressBar = document.getElementById('ns-viewer-site-progress-bar');
    const siteProgressFill = document.getElementById('ns-viewer-site-progress-fill');
    const siteProgressNote = document.getElementById('ns-viewer-site-progress-note');
    const filterTier = document.getElementById('ns-viewer-site-filter-tier');
    const filterType = document.getElementById('ns-viewer-site-filter-type');
    const filterInvalid = document.getElementById('ns-viewer-site-filter-invalid');
    const filterReason = document.getElementById('ns-viewer-site-filter-reason');
    const filterReasonWrap = document.getElementById('ns-viewer-site-filter-reason-wrap');

    let sitePage = 1;
    let siteLoaded = false;
    let scanTimer = null;
    let tierLabels = i18n.viewerSiteTier || {};

    function esc(str) {
      const d = document.createElement('div');
      d.textContent = str == null ? '' : String(str);
      return d.innerHTML;
    }

    function formatNumber(n) {
      const num = Number(n);
      return Number.isFinite(num) ? num.toLocaleString() : '0';
    }

    function setSiteStatus(message, isError) {
      if (!siteStatusEl) return;
      if (!message) {
        siteStatusEl.hidden = true;
        siteStatusEl.textContent = '';
        siteStatusEl.classList.remove('is-error');
        return;
      }
      siteStatusEl.hidden = false;
      siteStatusEl.textContent = message;
      siteStatusEl.classList.toggle('is-error', !!isError);
    }

    function tierLabel(tier) {
      return tierLabels[tier] || tier;
    }

    function renderSiteSummary(summary) {
      if (!summary) return;
      siteLoaded = true;
      const pct = summary.coverage_percent != null ? summary.coverage_percent : 0;
      const band = pct < 50 ? 'is-low' : (pct < 75 ? 'is-mid' : 'is-high');
      if (sitePercentEl) {
        sitePercentEl.textContent = `${formatNumber(pct)}%`;
        sitePercentEl.classList.remove('is-low', 'is-mid', 'is-high');
        sitePercentEl.classList.add(band);
      }
      if (siteRingEl) {
        siteRingEl.classList.remove('is-low', 'is-mid', 'is-high');
        siteRingEl.classList.add(band);
      }
      if (siteRingValueEl) {
        // Circumference of the r=18 ring used in the markup.
        const circumference = 2 * Math.PI * 18;
        const filled = (Math.max(0, Math.min(100, pct)) / 100) * circumference;
        siteRingValueEl.setAttribute('stroke-dasharray', `${filled.toFixed(2)} ${(circumference - filled).toFixed(2)}`);
      }
      if (siteOfEl) {
        siteOfEl.textContent = (i18n.viewerSiteOf || '%1$s of %2$s posts have page-specific schema')
          .replace('%1$s', formatNumber(summary.page_schema_posts || 0))
          .replace('%2$s', formatNumber(summary.published_total || 0));
      }
      if (siteCalloutEl) {
        const callout = summary.sitewide_callout || {};
        siteCalloutEl.textContent = `${formatNumber(callout.posts || 0)} posts`;
      }
      if (siteScoreEl) siteScoreEl.hidden = false;

      const counts = summary.tier_counts || {};
      if (siteTiersEl) {
        siteTiersEl.innerHTML = Object.keys(counts).map((tier) => {
          const count = counts[tier] || 0;
          return `<span class="ns-site-coverage__tier">${esc(tierLabel(tier))}: <strong>${esc(formatNumber(count))}</strong></span>`;
        }).join('');
      }
      if (siteTiersRow) siteTiersRow.hidden = false;

      if (filterTier && filterTier.options.length <= 1) {
        Object.keys(counts).forEach((tier) => {
          const opt = document.createElement('option');
          opt.value = tier;
          opt.textContent = tierLabel(tier);
          filterTier.appendChild(opt);
        });
      }
      if (filterType) {
        const types = summary.post_types || {};
        const current = filterType.value;
        filterType.innerHTML = `<option value="">${esc(i18n.viewerSiteAllTypes || 'All post types')}</option>`;
        Object.keys(types).sort().forEach((pt) => {
          const opt = document.createElement('option');
          opt.value = pt;
          opt.textContent = `${pt} (${formatNumber(types[pt])})`;
          filterType.appendChild(opt);
        });
        filterType.value = current;
      }

      if (filterReason) {
        const reasons = summary.issue_reasons || {};
        const labels = summary.issue_reason_labels || {};
        const current = filterReason.value;
        filterReason.innerHTML = `<option value="">${esc(i18n.viewerSiteAllReasons || 'All issue types')}</option>`;
        Object.keys(reasons).forEach((reason) => {
          const opt = document.createElement('option');
          opt.value = reason;
          const label = labels[reason] || reason;
          opt.textContent = `${label} (${formatNumber(reasons[reason])})`;
          filterReason.appendChild(opt);
        });
        filterReason.value = Object.prototype.hasOwnProperty.call(reasons, current) ? current : '';
      }
      syncIssueReasonFilter();

      const archives = summary.archives || [];
      if (siteArchivesEl && siteArchivesBody) {
        if (!archives.length) {
          siteArchivesEl.hidden = true;
          siteArchivesBody.innerHTML = '';
          if (siteArchivesCount) siteArchivesCount.textContent = '';
        } else {
          siteArchivesEl.hidden = false;
          if (siteArchivesCount) {
            siteArchivesCount.textContent = `(${formatNumber(archives.length)})`;
          }
          siteArchivesBody.innerHTML = `<ul class="ns-site-coverage__archive-list">${archives.map((a) => {
            const types = (a.types || []).map(esc).join(', ');
            const suffix = types ? ` · ${types}` : '';
            return `<li><strong>${esc(a.label || a.archive_key)}</strong> — ${esc(tierLabel(a.tier))}${suffix}</li>`;
          }).join('')}</ul>`;
        }
      }

      if (siteFiltersEl) siteFiltersEl.hidden = false;
      if (siteTableWrap) siteTableWrap.hidden = false;

      const triage = summary.triage || {};
      const rows = triage.rows || [];
      if (siteTbody) {
        if (!rows.length) {
          siteTbody.innerHTML = `<tr><td colspan="6">${esc(i18n.viewerSiteNoRows || 'No posts match these filters.')}</td></tr>`;
        } else {
          siteTbody.innerHTML = rows.map((row) => {
            const title = row.title || (`#${row.post_id}`);
            const types = (row.types || []).join(', ') || '—';
            const issues = row.issue_summary || '—';
            return `
            <tr class="${row.invalid ? 'is-invalid' : ''}">
              <td title="${esc(title)}">${esc(title)}</td>
              <td>${esc(row.post_type || '')}</td>
              <td>${esc(tierLabel(row.tier))}${row.invalid ? ' · ⚠' : ''}</td>
              <td title="${esc(types)}">${esc(types)}</td>
              <td title="${esc(issues)}">${esc(issues)}</td>
              <td class="ns-site-coverage__cell-actions"><a class="ns-site-coverage__view-link" href="${esc(row.viewer_url || '#')}" target="_blank" rel="noopener noreferrer" title="${esc(i18n.viewerSiteOpenPage || 'Open in Page Debugger')}" aria-label="${esc(i18n.viewerSiteOpenPage || 'Open in Page Debugger')}"><span class="dashicons dashicons-search" aria-hidden="true"></span></a></td>
            </tr>
          `;
          }).join('');
        }
      }

      if (sitePager) {
        const total = triage.total || 0;
        const per = triage.per_page || 25;
        const page = triage.page || 1;
        const pages = Math.max(1, Math.ceil(total / per));
        sitePager.innerHTML = pages <= 1 ? '' : `
          <button type="button" class="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹</button>
          <span>${formatNumber(page)} / ${formatNumber(pages)}</span>
          <button type="button" class="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>›</button>
        `;
      }

      const scan = summary.scan || {};
      updateScanUi(scan);
    }

    function updateScanUi(scan) {
      const running = !!(scan && scan.status === 'running');
      if (siteActionsEl) siteActionsEl.hidden = !siteLoaded;
      if (siteScanBtn) siteScanBtn.hidden = running;
      if (siteScanCancelBtn) siteScanCancelBtn.hidden = !running;
      // The triage table reflects pre-scan data, so keep it out of reach mid-scan.
      if (siteFiltersEl && siteLoaded) siteFiltersEl.hidden = running;
      if (siteTableWrap && siteLoaded) siteTableWrap.hidden = running;
      const percent = Math.max(0, Math.min(100, Number(scan?.percent) || 0));
      if (siteProgressEl) siteProgressEl.hidden = !running;
      if (running) {
        if (siteProgressFill) siteProgressFill.style.width = `${percent}%`;
        if (siteProgressBar) siteProgressBar.setAttribute('aria-valuenow', String(percent));
        if (siteProgressNote) {
          siteProgressNote.textContent = (i18n.viewerSiteScanning || 'Scanning…')
            + ` ${formatNumber(scan.processed || 0)} / ${formatNumber(scan.total || 0)} (${formatNumber(percent)}%)`;
        }
      }
      if (siteScanMeta) {
        if (running) {
          siteScanMeta.textContent = '';
        } else if (scan.completed_at_display) {
          siteScanMeta.textContent = (i18n.viewerSiteLastScan || 'Last scan: %s')
            .replace('%s', scan.completed_at_display);
        } else {
          siteScanMeta.textContent = '';
        }
      }
    }

    async function loadSiteCoverage() {
      // First paint gets the centered spinner; filter/page changes clear the
      // table and show the same spinner inline so the header stays put.
      if (!siteLoaded && siteLoadingEl) {
        siteLoadingEl.hidden = false;
        setSiteStatus('', false);
      } else {
        setSiteStatus('', false);
        showTableLoading();
      }
      const params = new URLSearchParams({
        action: 'ns_schema_tools_viewer_site',
        nonce: cfg.toolsNonce || '',
        page_num: String(sitePage),
        per_page: '25',
        tier: filterTier?.value || '',
        post_type: filterType?.value || '',
        invalid_only: filterInvalid?.checked ? '1' : '',
        issue_reason: filterInvalid?.checked ? (filterReason?.value || '') : '',
      });
      try {
        const res = await fetch(`${cfg.ajaxUrl}?${params.toString()}`, { credentials: 'same-origin' });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.viewerSiteLoadError || 'Could not load site coverage.');
        }
        setSiteStatus('', false);
        renderSiteSummary(json.data.summary || {});
      } catch (err) {
        setSiteStatus(err.message || i18n.viewerSiteLoadError || 'Could not load site coverage.', true);
      } finally {
        if (siteLoadingEl) siteLoadingEl.hidden = true;
      }
    }

    function showTableLoading() {
      if (siteTableWrap) siteTableWrap.hidden = false;
      if (sitePager) sitePager.innerHTML = '';
      if (!siteTbody) return;
      const label = esc(i18n.toolsLoading || 'Loading…');
      siteTbody.innerHTML = `<tr class="ns-site-coverage__table-loading">
        <td colspan="6">
          <span class="ns-site-coverage__table-loading-inner" role="status">
            <span class="ns-site-coverage__spinner" aria-hidden="true"></span>
            <span>${label}</span>
          </span>
        </td>
      </tr>`;
    }

    function syncIssueReasonFilter() {
      const on = !!(filterInvalid && filterInvalid.checked);
      if (filterReasonWrap) filterReasonWrap.hidden = !on;
      if (!on && filterReason) filterReason.value = '';
    }

    async function processScanLoop() {
      const params = new URLSearchParams({
        action: 'ns_schema_tools_viewer_scan_process',
        nonce: cfg.toolsNonce || '',
      });
      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
      });
      const json = await res.json();
      const scan = json?.data?.scan || {};
      updateScanUi(scan);
      if (scan.status === 'running') {
        scanTimer = setTimeout(processScanLoop, 250);
      } else {
        await loadSiteCoverage();
      }
    }

    async function startScan() {
      clearTimeout(scanTimer);
      const params = new URLSearchParams({
        action: 'ns_schema_tools_viewer_scan_start',
        nonce: cfg.toolsNonce || '',
      });
      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
      });
      const json = await res.json();
      updateScanUi(json?.data?.scan || {});
      processScanLoop();
    }

    async function cancelScan() {
      clearTimeout(scanTimer);
      const params = new URLSearchParams({
        action: 'ns_schema_tools_viewer_scan_cancel',
        nonce: cfg.toolsNonce || '',
      });
      await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
      });
      await loadSiteCoverage();
    }

    filterTier?.addEventListener('change', () => { sitePage = 1; loadSiteCoverage(); });
    filterType?.addEventListener('change', () => { sitePage = 1; loadSiteCoverage(); });
    filterInvalid?.addEventListener('change', () => {
      syncIssueReasonFilter();
      sitePage = 1;
      loadSiteCoverage();
    });
    filterReason?.addEventListener('change', () => { sitePage = 1; loadSiteCoverage(); });
    siteTiersHelpBtn?.addEventListener('click', () => {
      const details = Array.isArray(i18n.viewerSiteTiersHelp) ? i18n.viewerSiteTiersHelp : [];
      window.nsAlert(
        i18n.viewerSiteTiersHelpIntro
          || 'Tiers describe how much page-specific schema a URL has—not just whether any JSON-LD exists. Sitewide-only schema does not count toward coverage %.',
        {
          title: i18n.viewerSiteTiersHelpTitle || 'Coverage tiers',
          details,
        }
      );
    });
    siteCalloutHelpBtn?.addEventListener('click', () => {
      const details = Array.isArray(i18n.viewerSiteSitewideHelp) ? i18n.viewerSiteSitewideHelp : [];
      window.nsAlert(
        i18n.viewerSiteSitewideHelpIntro
          || 'Sitewide scripts are schema that apply broadly across the site. They are listed separately so they do not inflate page-specific coverage %.',
        {
          title: i18n.viewerSiteSitewideHelpTitle || i18n.viewerSiteSitewide || 'Sitewide scripts',
          details,
        }
      );
    });
    sitePager?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-page]');
      if (!btn || btn.disabled) return;
      sitePage = Math.max(1, Number(btn.dataset.page) || 1);
      loadSiteCoverage();
    });
    siteScanBtn?.addEventListener('click', () => startScan());
    siteScanCancelBtn?.addEventListener('click', () => cancelScan());

    loadSiteCoverage();
  }

  function initBatchRegen() {
    const form = document.getElementById('ns-batch-regen-form');
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    if (!form) {
      return;
    }

    const targetsEl = document.getElementById('ns-batch-placement-targets');
    const errorEl = document.getElementById('ns-batch-placement-error');

    function syncBatchPlacement() {
      const targeted = form.querySelector('input[name="placement"]:checked')?.value === 'targeted';
      if (targetsEl) {
        targetsEl.hidden = !targeted;
      }
    }

    const countEl = document.getElementById('ns-batch-regen-count');
    let previewTimer = null;
    let previewRequestId = 0;

    function valuesFor(id) {
      return getMultiSelectValues(id);
    }

    function currentTargeting() {
      return {
        placement: form.querySelector('input[name="placement"]:checked')?.value || 'targeted',
        postTypes: valuesFor('ns-batch-post-types'),
        pageTemplates: valuesFor('ns-batch-page-templates'),
        posts: valuesFor('ns-batch-posts'),
        archives: valuesFor('ns-batch-archives'),
        specialties: valuesFor('ns-batch-specialties'),
      };
    }

    function formatCountLabel(posts, archives, specialties, languages) {
      const postLabel = Number(posts || 0).toLocaleString();
      const archiveLabel = Number(archives || 0).toLocaleString();
      const specialtyLabel = Number(specialties || 0).toLocaleString();
      const languageCount = Number(languages || 0);
      const languageLabel = languageCount.toLocaleString();
      if (posts <= 0 && archives <= 0 && specialties <= 0) {
        return i18n.batchCountEmpty || '0 posts selected';
      }
      const acrossLanguages = languageCount > 0
        ? (languageCount === 1
          ? (i18n.batchCountAcrossLanguage || 'across %s language')
          : (i18n.batchCountAcrossLanguages || 'across %s languages')).replace('%s', languageLabel)
        : '';
      const parts = [`${postLabel} posts`];
      if (archives > 0) {
        parts.push(`${archiveLabel} archives`);
      }
      if (specialties > 0) {
        parts.push(`${specialtyLabel} specialty`);
      }
      if (archives > 0 || specialties > 0) {
        return acrossLanguages ? `${parts.join(' · ')} ${acrossLanguages}` : parts.join(' · ');
      }
      if (acrossLanguages) {
        return (i18n.batchCountPostsAcross || '%1$s posts selected %2$s')
          .replace('%1$s', postLabel)
          .replace('%2$s', acrossLanguages);
      }
      return (i18n.batchCountPosts || '%s posts selected').replace('%s', postLabel);
    }

    function schedulePreviewCount() {
      clearTimeout(previewTimer);
      previewTimer = setTimeout(refreshPreviewCount, 250);
    }

    async function refreshPreviewCount() {
      const targeting = currentTargeting();
      const id = ++previewRequestId;
      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_batch_preview');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('placement', targeting.placement);
        targeting.postTypes.forEach((v) => body.append('post_types[]', v));
        targeting.pageTemplates.forEach((v) => body.append('page_templates[]', v));
        targeting.posts.forEach((v) => body.append('posts[]', v));
        targeting.archives.forEach((v) => body.append('archives[]', v));
        targeting.specialties.forEach((v) => body.append('specialties[]', v));

        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (id !== previewRequestId || !json?.success) {
          return null;
        }
        const posts = Number(json.data.posts || 0);
        const archives = Number(json.data.archives || 0);
        const specialties = Number(json.data.specialties || 0);
        const languages = Number(json.data.languages || 0);
        if (countEl) {
          countEl.textContent = formatCountLabel(posts, archives, specialties, languages);
        }
        return { posts, archives, specialties, languages };
      } catch (err) {
        if (id === previewRequestId && countEl) {
          countEl.textContent = i18n.batchCountEmpty || '0 posts selected';
        }
        return null;
      }
    }

    form.querySelectorAll('input[name="placement"]').forEach((input) => {
      input.addEventListener('change', () => {
        syncBatchPlacement();
        schedulePreviewCount();
      });
    });
    syncBatchPlacement();

    form.querySelectorAll('.ns-multiselect').forEach((root) => {
      root._nsHooks = {
        clearErrors: () => {
          if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = '';
          }
        },
        onChange: () => {
          schedulePreviewCount();
        },
      };
    });
    initMultiSelects(form);
    schedulePreviewCount();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const placement = form.querySelector('input[name="placement"]:checked')?.value || 'targeted';
      const postTypes = valuesFor('ns-batch-post-types');
      const pageTemplates = valuesFor('ns-batch-page-templates');
      const posts = valuesFor('ns-batch-posts');
      const archives = valuesFor('ns-batch-archives');
      const specialties = valuesFor('ns-batch-specialties');

      if (
        placement === 'targeted'
        && !postTypes.length
        && !pageTemplates.length
        && !posts.length
        && !archives.length
        && !specialties.length
      ) {
        if (errorEl) {
          errorEl.hidden = false;
          errorEl.textContent = i18n.batchTargetRequired || 'Select at least one target, or choose Global.';
        }
        return;
      }

      clearTimeout(previewTimer);
      const preview = await refreshPreviewCount();
      const postCount = Number(preview?.posts || 0).toLocaleString();
      const confirmMessage = (
        i18n.batchConfirm || 'Regenerate schema for the selected targets? This will update %s posts.'
      ).replace('%s', postCount);

      const ok = await window.nsConfirm(confirmMessage, {
        title: i18n.batchConfirmTitle || 'Start batch regeneration?',
        confirmLabel: i18n.batchStart || 'Start regeneration',
        cancelLabel: i18n.cancel || 'Cancel',
      });
      if (!ok) {
        return;
      }

      const submitBtn = document.getElementById('ns-batch-regen-submit');
      if (submitBtn) {
        submitBtn.disabled = true;
      }

      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_batch_regen');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('placement', placement);
        postTypes.forEach((v) => body.append('post_types[]', v));
        pageTemplates.forEach((v) => body.append('page_templates[]', v));
        posts.forEach((v) => body.append('posts[]', v));
        archives.forEach((v) => body.append('archives[]', v));
        specialties.forEach((v) => body.append('specialties[]', v));

        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.error || 'Regeneration failed.');
        }

        const regen = json.data.regen || {};
        if (regen.remaining > 0 && typeof window.nsStartRegenModal === 'function') {
          window.nsStartRegenModal(regen, null);
        } else {
          await window.nsAlert(json.data.message || i18n.batchStarted || 'Regeneration started.');
        }
      } catch (err) {
        await window.nsAlert(err.message || i18n.error || 'Regeneration failed.');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
        }
      }
    });
  }

  function initStorefront() {
    const root = document.getElementById('ns-schema-storefront');
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    if (!root) {
      return;
    }

    const statusEl = document.getElementById('ns-storefront-status');
    const refreshBtn = document.getElementById('ns-storefront-refresh');
    const searchEl = document.getElementById('ns-storefront-search');
    const listEl = document.getElementById('ns-storefront-type-list');
    const packEl = document.getElementById('ns-storefront-pack');
    const emptyEl = document.getElementById('ns-storefront-empty');
    const headerEl = document.getElementById('ns-storefront-type-header');
    const propsEl = document.getElementById('ns-storefront-properties');
    const labelEl = document.getElementById('ns-storefront-label');
    const slugEl = document.getElementById('ns-storefront-slug');
    const saveBtn = document.getElementById('ns-storefront-save');
    const selectAllBtn = document.getElementById('ns-storefront-select-all');
    const selectNoneBtn = document.getElementById('ns-storefront-select-none');
    const manageBtn = document.getElementById('ns-storefront-manage');
    const manageModal = document.getElementById('ns-storefront-manage-modal');
    const manageBody = document.getElementById('ns-storefront-manage-body');

    const STARTER = new Set(['name', 'description', 'url', 'image']);
    let types = [];
    let activeTypeId = '';
    let activeType = null;
    let managePreviousFocus = null;
    const customWritable = root.dataset.customWritable !== '0';

    function esc(str) {
      const d = document.createElement('div');
      d.textContent = str == null ? '' : String(str);
      return d.innerHTML;
    }

    async function postAction(action, fields = {}) {
      const body = new URLSearchParams();
      body.set('action', action);
      body.set('nonce', cfg.toolsNonce || '');
      Object.entries(fields).forEach(([key, value]) => {
        if (Array.isArray(value)) {
          value.forEach((v) => body.append(`${key}[]`, String(v)));
        } else if (value !== undefined && value !== null) {
          body.set(key, String(value));
        }
      });
      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      });
      const json = await res.json();
      return { ok: res.ok, status: res.status, json };
    }

    function formatStatus(status) {
      if (!status?.loaded) {
        return i18n.storefrontVocabEmpty || 'Type not loaded yet.';
      }
      const count = Number(status.type_count || 0).toLocaleString();
      const when = status.fetched_at
        ? new Date(status.fetched_at * 1000).toLocaleString()
        : '—';
      return (i18n.storefrontVocabReady || 'Cached · %1$s types · refreshed %2$s')
        .replace('%1$s', count)
        .replace('%2$s', when);
    }

    function renderTypeList(filter = '') {
      if (!listEl) {
        return;
      }
      const q = filter.trim().toLowerCase();
      const matched = types.filter((t) => {
        if (!q) {
          return true;
        }
        return t.label.toLowerCase().includes(q) || t.id.toLowerCase().includes(q);
      }).slice(0, 200);

      if (!matched.length) {
        listEl.innerHTML = `<li class="ns-storefront__type-empty">${esc(i18n.storefrontNoTypes || 'No types match your search.')}</li>`;
        return;
      }

      listEl.innerHTML = matched.map((t) => `
        <li>
          <button
            type="button"
            class="ns-storefront__type-btn${t.id === activeTypeId ? ' is-selected' : ''}"
            data-type-id="${esc(t.id)}"
            role="option"
            aria-selected="${t.id === activeTypeId ? 'true' : 'false'}"
          >
            <span class="ns-storefront__type-label">${esc(t.label)}</span>
            <span class="ns-storefront__type-id">${esc(t.id)}</span>
          </button>
        </li>
      `).join('');
    }

    function selectedPropertyIds() {
      if (!propsEl) {
        return [];
      }
      return Array.from(propsEl.querySelectorAll('input[type="checkbox"][name="ns_storefront_prop"]:checked'))
        .map((input) => input.value)
        .filter(Boolean);
    }

    function renderProperties(type) {
      if (!propsEl) {
        return;
      }
      const props = Array.isArray(type.properties) ? type.properties : [];
      const own = props.filter((p) => !p.inherited);
      const inherited = props.filter((p) => p.inherited);

      function groupHtml(title, items) {
        if (!items.length) {
          return '';
        }
        return `
          <div class="ns-storefront__prop-group">
            <h4 class="ns-storefront__prop-group-title">${esc(title)}</h4>
            <ul class="ns-storefront__prop-list">
              ${items.map((p) => {
                const checked = STARTER.has(p.id) ? ' checked' : '';
                const disabled = customWritable ? '' : ' disabled';
                const ranges = (p.ranges || []).join(', ');
                return `
                  <li class="ns-storefront__prop">
                    <label>
                      <input type="checkbox" name="ns_storefront_prop" value="${esc(p.id)}"${checked}${disabled}>
                      <span class="ns-storefront__prop-body">
                        <span class="ns-storefront__prop-name">${esc(p.label || p.id)} <code>${esc(p.id)}</code></span>
                        ${ranges ? `<span class="ns-storefront__prop-range">${esc(ranges)}</span>` : ''}
                        ${p.comment ? `<span class="ns-storefront__prop-help">${esc(p.comment)}</span>` : ''}
                      </span>
                    </label>
                  </li>
                `;
              }).join('')}
            </ul>
          </div>
        `;
      }

      propsEl.innerHTML = groupHtml(i18n.storefrontPropsOwn || 'Defined on this type', own)
        + groupHtml(i18n.storefrontPropsInherited || 'Inherited', inherited);
    }

    async function loadVocab({ refresh = false } = {}) {
      if (statusEl) {
        statusEl.textContent = refresh
          ? (i18n.storefrontRefreshing || 'Refreshing types')
          : (i18n.storefrontLoadingVocab || 'Loading schema.org types');
      }
      if (refreshBtn) {
        refreshBtn.disabled = true;
      }
      if (searchEl) {
        searchEl.disabled = true;
      }

      try {
        const { json } = await postAction(
          refresh ? 'ns_schema_tools_storefront_refresh' : 'ns_schema_tools_storefront_vocab'
        );
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.storefrontLoadError || 'Could not load schema.org types.');
        }
        types = Array.isArray(json.data.types) ? json.data.types : [];
        if (statusEl) {
          statusEl.textContent = formatStatus(json.data.status);
        }
        if (searchEl) {
          searchEl.disabled = false;
        }
        renderTypeList(searchEl?.value || '');
      } catch (err) {
        if (statusEl) {
          statusEl.textContent = err.message || i18n.storefrontLoadError || 'Could not load schema.org types.';
        }
        await window.nsAlert(err.message || i18n.storefrontLoadError || 'Could not load schema.org types.');
      } finally {
        if (refreshBtn) {
          refreshBtn.disabled = false;
        }
      }
    }

    async function selectType(typeId) {
      activeTypeId = typeId;
      renderTypeList(searchEl?.value || '');
      if (packEl) {
        packEl.hidden = true;
      }
      if (emptyEl) {
        emptyEl.hidden = false;
        emptyEl.innerHTML = `<p>${esc(i18n.storefrontLoadingType || 'Loading type…')}</p>`;
      }

      try {
        const { json } = await postAction('ns_schema_tools_storefront_type', { type_id: typeId });
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.storefrontLoadError || 'Could not load type.');
        }
        activeType = json.data.type;
        if (labelEl) {
          labelEl.value = activeType.label || activeType.id;
        }
        if (slugEl) {
          slugEl.value = json.data.default_slug || '';
        }
        if (headerEl) {
          const ancestors = (activeType.ancestors || []).join(' → ');
          headerEl.innerHTML = `
            <h2 class="ns-storefront__type-title">${esc(activeType.label)} <code>${esc(activeType.id)}</code></h2>
            ${activeType.comment ? `<p class="ns-storefront__type-comment">${esc(activeType.comment)}</p>` : ''}
            ${ancestors ? `<p class="ns-storefront__type-ancestors"><span>${esc('Extends')}</span> ${esc(ancestors)}</p>` : ''}
          `;
        }
        renderProperties(activeType);
        if (emptyEl) {
          emptyEl.hidden = true;
        }
        if (packEl) {
          packEl.hidden = false;
        }
      } catch (err) {
        activeType = null;
        if (emptyEl) {
          emptyEl.hidden = false;
          emptyEl.innerHTML = `<p>${esc(err.message || 'Could not load type.')}</p>`;
        }
      }
    }

    async function saveTemplate(confirmOverwrite = false) {
      if (!activeType) {
        return;
      }
      if (!customWritable) {
        await window.nsAlert(
          i18n.storefrontNotWritable
          || 'Custom templates can’t be saved because the uploads templates directory is not writable on this environment.'
        );
        return;
      }
      const props = selectedPropertyIds();
      if (!props.length) {
        await window.nsAlert(i18n.storefrontNeedProps || 'Select at least one property.');
        return;
      }

      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = i18n.storefrontSaving || 'Saving…';
      }

      try {
        const { status, json } = await postAction('ns_schema_tools_storefront_save', {
          type_id: activeType.id,
          slug: slugEl?.value || '',
          label: labelEl?.value || '',
          properties: props,
          confirm_overwrite: confirmOverwrite ? '1' : '',
        });

        if (!json?.success && json?.data?.code === 'needs_confirm') {
          const title = json.data.custom_exists
            ? (i18n.storefrontOverwriteTitle || 'Overwrite template?')
            : (i18n.storefrontShadowTitle || 'Shadow built-in template?');
          const confirmLabel = json.data.custom_exists
            ? (i18n.storefrontOverwrite || 'Overwrite')
            : (i18n.storefrontContinue || 'Continue');
          const ok = await window.nsConfirm(json.data.message || '', {
            title,
            confirmLabel,
            cancelLabel: i18n.cancel || 'Cancel',
          });
          if (ok) {
            await saveTemplate(true);
          }
          return;
        }

        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.error || 'Could not save template.');
        }

        const addUrl = json.data.add_url || root.dataset.addConfigUrl || '';
        const goAdd = await window.nsConfirm(
          json.data.message || 'Template saved.',
          {
            title: i18n.storefrontSavedTitle || 'Template saved',
            confirmLabel: i18n.storefrontAddConfig || 'Add Schema Config',
            cancelLabel: i18n.close || 'Close',
          }
        );
        if (goAdd && addUrl) {
          window.location.href = addUrl;
        }
      } catch (err) {
        await window.nsAlert(err.message || i18n.error || 'Could not save template.');
      } finally {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = i18n.storefrontSave || 'Save template';
        }
      }
    }

    refreshBtn?.addEventListener('click', () => {
      loadVocab({ refresh: true });
    });
    searchEl?.addEventListener('input', () => {
      renderTypeList(searchEl.value || '');
    });
    listEl?.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-type-id]');
      if (!btn) {
        return;
      }
      selectType(btn.getAttribute('data-type-id'));
    });
    selectAllBtn?.addEventListener('click', () => {
      propsEl?.querySelectorAll('input[type="checkbox"][name="ns_storefront_prop"]').forEach((input) => {
        input.checked = true;
      });
    });
    selectNoneBtn?.addEventListener('click', () => {
      propsEl?.querySelectorAll('input[type="checkbox"][name="ns_storefront_prop"]').forEach((input) => {
        input.checked = false;
      });
    });
    saveBtn?.addEventListener('click', () => {
      saveTemplate(false);
    });

    function closeManageModal() {
      if (!manageModal || manageModal.hidden) {
        return;
      }
      manageModal.hidden = true;
      document.body.classList.remove('ns-storefront-manage-open');
      if (managePreviousFocus && typeof managePreviousFocus.focus === 'function') {
        managePreviousFocus.focus({ preventScroll: true });
      }
      managePreviousFocus = null;
    }

    function renderManageList(templates, writable = customWritable) {
      if (!manageBody) {
        return;
      }
      if (!templates.length) {
        manageBody.innerHTML = `<p class="ns-storefront-manage-modal__empty">${esc(i18n.storefrontManageEmpty || 'No custom templates yet.')}</p>`;
        return;
      }
      manageBody.innerHTML = `
        <ul class="ns-storefront-manage-modal__list">
          ${templates.map((t) => `
            <li class="ns-storefront-manage-modal__item">
              <div class="ns-storefront-manage-modal__meta">
                <span class="ns-storefront-manage-modal__label">${esc(t.label || t.slug)}</span>
                <span class="ns-storefront-manage-modal__sub">
                  <code>${esc(t.slug)}</code>
                  ${t.schema_type ? ` · ${esc(t.schema_type)}` : ''}
                </span>
              </div>
              ${writable ? `
              <button
                type="button"
                class="ns-icon-delete ns-storefront-manage-modal__delete"
                data-slug="${esc(t.slug)}"
                data-label="${esc(t.label || t.slug)}"
                aria-label="${esc((i18n.storefrontDelete || 'Delete') + ': ' + (t.label || t.slug))}"
                title="${esc(i18n.storefrontDelete || 'Delete')}"
              >
                <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                  <path fill="currentColor" d="M6.5 3.5A1.5 1.5 0 0 1 8 2h4a1.5 1.5 0 0 1 1.5 1.5V4H16a1 1 0 1 1 0 2h-.5l-.7 10.1A2 2 0 0 1 12.8 18H7.2a2 2 0 0 1-2-1.9L4.5 6H4a1 1 0 0 1 0-2h2.5V3.5ZM8 4h4V3.5a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5V4Zm-.4 3.1a1 1 0 0 1 1 .9l.3 7a1 1 0 1 1-2 .1l-.3-7a1 1 0 0 1 1-1Zm4.8 0a1 1 0 0 1 1 1l-.3 7a1 1 0 1 1-2-.1l.3-7a1 1 0 0 1 1-.9Z"/>
                </svg>
              </button>
              ` : ''}
            </li>
          `).join('')}
        </ul>
      `;
    }

    async function loadManageList() {
      if (!manageBody) {
        return;
      }
      manageBody.innerHTML = `<p class="ns-storefront-manage-modal__loading">${esc(i18n.storefrontManageLoading || i18n.toolsLoading || 'Loading…')}</p>`;
      try {
        const { json } = await postAction('ns_schema_tools_storefront_list_custom');
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.storefrontManageLoadError || 'Could not load custom templates.');
        }
        const writable = typeof json.data.writable === 'boolean' ? json.data.writable : customWritable;
        renderManageList(Array.isArray(json.data.templates) ? json.data.templates : [], writable);
      } catch (err) {
        manageBody.innerHTML = `<p class="ns-storefront-manage-modal__empty">${esc(err.message || i18n.storefrontManageLoadError || 'Could not load custom templates.')}</p>`;
      }
    }

    async function openManageModal() {
      if (!manageModal) {
        return;
      }
      managePreviousFocus = document.activeElement;
      manageModal.hidden = false;
      document.body.classList.add('ns-storefront-manage-open');
      manageModal.querySelector('[data-ns-storefront-manage-dismiss].button')?.focus({ preventScroll: true });
      await loadManageList();
    }

    async function deleteCustomTemplate(slug, label, button) {
      if (!customWritable) {
        await window.nsAlert(
          i18n.storefrontNotWritableDelete
          || 'Custom templates can’t be deleted because the uploads templates directory is not writable on this environment.'
        );
        return;
      }
      const confirmMessage = (i18n.storefrontDeleteConfirm || 'Delete “%s”? This cannot be undone.')
        .replace('%s', label || slug);
      const ok = await window.nsConfirm(confirmMessage, {
        title: i18n.storefrontDeleteTitle || 'Delete custom template?',
        confirmLabel: i18n.storefrontDelete || 'Delete',
        cancelLabel: i18n.cancel || 'Cancel',
        destructive: true,
      });
      if (!ok) {
        return;
      }

      if (button) {
        button.disabled = true;
        button.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');
      }

      try {
        const { json } = await postAction('ns_schema_tools_storefront_delete_custom', { slug });
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.error || 'Could not delete template.');
        }
        await loadManageList();
      } catch (err) {
        await window.nsAlert(err.message || i18n.error || 'Could not delete template.');
        if (button) {
          button.disabled = false;
          button.classList.remove('is-busy');
          button.removeAttribute('aria-busy');
        }
      }
    }

    manageBtn?.addEventListener('click', () => {
      openManageModal();
    });
    manageModal?.addEventListener('click', (event) => {
      if (event.target.closest('[data-ns-storefront-manage-dismiss]')) {
        closeManageModal();
        return;
      }
      const deleteBtn = event.target.closest('[data-slug].ns-storefront-manage-modal__delete');
      if (!deleteBtn) {
        return;
      }
      deleteCustomTemplate(
        deleteBtn.getAttribute('data-slug') || '',
        deleteBtn.getAttribute('data-label') || '',
        deleteBtn
      );
    });
    document.addEventListener('keydown', (e) => {
      if (!manageModal || manageModal.hidden || e.key !== 'Escape') {
        return;
      }
      const dialog = document.getElementById('ns-dialog');
      if (dialog && !dialog.hidden) {
        return;
      }
      e.preventDefault();
      closeManageModal();
    });

    loadVocab();
  }

  function initTransfer() {
    const root = document.getElementById('ns-schema-transfer');
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    if (!root) {
      return;
    }

    const tabBtns = Array.from(root.querySelectorAll('[data-transfer-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-transfer-panel]'));
    const scopeInputs = Array.from(root.querySelectorAll('input[name="ns_transfer_scope"]'));
    const configPick = document.getElementById('ns-transfer-config-pick');
    const configSelect = document.getElementById('ns-transfer-config-id');
    const exportBtn = document.getElementById('ns-transfer-export-btn');
    const exportStatus = document.getElementById('ns-transfer-export-status');
    const fileInput = document.getElementById('ns-transfer-file');
    const previewBtn = document.getElementById('ns-transfer-preview-btn');
    const applyBtn = document.getElementById('ns-transfer-apply-btn');
    const backBtn = document.getElementById('ns-transfer-import-back');
    const stepUpload = document.getElementById('ns-transfer-import-step-upload');
    const stepPreview = document.getElementById('ns-transfer-import-step-preview');
    const previewEl = document.getElementById('ns-transfer-preview');
    const importStatus = document.getElementById('ns-transfer-import-status');

    let importToken = '';

    const initialTab = (cfg.transferTab || new URL(window.location.href).searchParams.get('transfer_tab') || '').toLowerCase();
    if (initialTab === 'import' || initialTab === 'export') {
      showTab(initialTab);
    }

    function esc(str) {
      const d = document.createElement('div');
      d.textContent = str == null ? '' : String(str);
      return d.innerHTML;
    }

    function setStatus(el, message, isError) {
      if (!el) {
        return;
      }
      if (!message) {
        el.hidden = true;
        el.textContent = '';
        el.classList.remove('is-error');
        return;
      }
      el.hidden = false;
      el.textContent = message;
      el.classList.toggle('is-error', !!isError);
    }

    function selectedScope() {
      const checked = scopeInputs.find((input) => input.checked);
      return checked ? checked.value : 'full';
    }

    function syncScope() {
      const isConfig = selectedScope() === 'config';
      if (configPick) {
        configPick.hidden = !isConfig;
      }
    }

    function showTab(name) {
      tabBtns.forEach((btn) => {
        const active = btn.dataset.transferTab === name;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.transferPanel !== name;
      });
    }

    function decisionSelect(name, conflict) {
      const defaultAction = conflict ? 'skip' : 'create';
      const options = conflict
        ? [
          ['overwrite', i18n.transferOverwrite || 'Overwrite'],
          ['skip', i18n.transferSkip || 'Skip'],
          ['create', i18n.transferCreate || 'Import as new'],
        ]
        : [
          ['create', i18n.transferCreate || 'Import as new'],
          ['skip', i18n.transferSkip || 'Skip'],
        ];
      return `
        <select class="ns-transfer-decision" name="${esc(name)}" data-default="${esc(defaultAction)}">
          ${options.map(([value, label]) => `
            <option value="${esc(value)}"${value === defaultAction ? ' selected' : ''}>${esc(label)}</option>
          `).join('')}
        </select>
      `;
    }

    function renderPreview(preview) {
      if (!previewEl) {
        return;
      }
      const warnings = Array.isArray(preview.warnings) ? preview.warnings : [];
      const templates = Array.isArray(preview.custom_templates) ? preview.custom_templates : [];
      const configs = Array.isArray(preview.configs) ? preview.configs : [];
      const settings = preview.settings || {};
      const settingItems = Array.isArray(settings.items) ? settings.items : [];

      if (!templates.length && !configs.length && !(settings.count > 0)) {
        previewEl.innerHTML = `<p class="description">${esc(i18n.transferNoItems || 'Nothing to import in this pack.')}</p>`;
        return;
      }

      previewEl.innerHTML = `
        ${warnings.map((w) => `<div class="notice notice-warning inline"><p>${esc(w)}</p></div>`).join('')}
        <section class="ns-transfer-preview__section">
          <h3>${esc(i18n.transferSettings || 'Plugin settings')} (${settings.count || 0})</h3>
          ${settingItems.length ? `
            <ul class="ns-transfer-preview__list">
              ${settingItems.map((item) => `
                <li class="ns-transfer-preview__item">
                  <div class="ns-transfer-preview__meta">
                    <strong>${esc(item.label || item.key || '')}</strong>
                    <code>${esc(item.value || '')}</code>
                  </div>
                </li>
              `).join('')}
            </ul>
          ` : `<p class="description">${esc(settings.note || 'No project settings in this pack yet.')}</p>`}
        </section>
        <section class="ns-transfer-preview__section">
          <h3>${esc(i18n.transferTemplates || 'Custom templates')} (${templates.length})</h3>
          ${templates.length ? `
            <ul class="ns-transfer-preview__list">
              ${templates.map((t) => `
                <li class="ns-transfer-preview__item">
                  <div class="ns-transfer-preview__meta">
                    <strong>${esc(t.label || t.slug)}</strong>
                    <code>${esc(t.slug)}</code>
                    ${t.conflict ? `<span class="ns-tools-badge ns-tools-badge--no">${esc(i18n.transferConflict || 'Conflict')}</span>` : ''}
                  </div>
                  ${decisionSelect(`template:${t.slug}`, !!t.conflict)}
                </li>
              `).join('')}
            </ul>
          ` : `<p class="description">${esc('None')}</p>`}
        </section>
        <section class="ns-transfer-preview__section">
          <h3>${esc(i18n.transferConfigs || 'Schema configs')} (${configs.length})</h3>
          ${configs.length ? `
            <ul class="ns-transfer-preview__list">
              ${configs.map((c) => `
                <li class="ns-transfer-preview__item">
                  <div class="ns-transfer-preview__meta">
                    <strong>${esc(c.name || ('#' + c.index))}</strong>
                    <span class="description">${esc(c.schema_type || '')}${c.template_slug ? ` · ${esc(c.template_slug)}` : ''}${c.status ? ` · ${esc(c.status)}` : ''}</span>
                    ${c.conflict ? `<span class="ns-tools-badge ns-tools-badge--no">${esc(i18n.transferConflict || 'Conflict')}</span>` : ''}
                  </div>
                  ${decisionSelect(`config:${c.index}`, !!c.conflict)}
                </li>
              `).join('')}
            </ul>
          ` : `<p class="description">${esc('None')}</p>`}
        </section>
      `;
    }

    function collectDecisions() {
      const templates = {};
      const configs = {};
      previewEl?.querySelectorAll('.ns-transfer-decision').forEach((select) => {
        const name = select.getAttribute('name') || '';
        if (name.startsWith('template:')) {
          templates[name.slice('template:'.length)] = select.value;
        } else if (name.startsWith('config:')) {
          configs[name.slice('config:'.length)] = select.value;
        }
      });
      return { templates, configs };
    }

    function downloadJson(filename, text) {
      const blob = new Blob([text], { type: 'application/json;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename || 'noble-schema-export.json';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    }

    tabBtns.forEach((btn) => {
      btn.addEventListener('click', () => showTab(btn.dataset.transferTab));
    });
    scopeInputs.forEach((input) => {
      input.addEventListener('change', syncScope);
    });
    syncScope();

    exportBtn?.addEventListener('click', async () => {
      const scope = selectedScope();
      if (scope === 'config' && !configSelect?.value) {
        setStatus(exportStatus, i18n.transferNeedConfig || 'Select a schema config to export.', true);
        return;
      }
      exportBtn.disabled = true;
      setStatus(exportStatus, i18n.transferExporting || 'Preparing export…', false);
      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_transfer_export');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('scope', scope);
        if (scope === 'config') {
          body.set('config_id', configSelect.value);
        }
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.transferExportError || 'Export failed.');
        }
        downloadJson(json.data.filename, json.data.json);
        setStatus(exportStatus, i18n.transferExportReady || 'Download started.', false);
      } catch (err) {
        setStatus(exportStatus, err.message || i18n.transferExportError || 'Export failed.', true);
      } finally {
        exportBtn.disabled = false;
      }
    });

    fileInput?.addEventListener('change', () => {
      if (previewBtn) {
        previewBtn.disabled = !(fileInput.files && fileInput.files[0]);
      }
    });

    previewBtn?.addEventListener('click', async () => {
      const file = fileInput?.files && fileInput.files[0];
      if (!file) {
        setStatus(importStatus, i18n.transferNeedFile || 'Choose an export file first.', true);
        return;
      }
      previewBtn.disabled = true;
      setStatus(importStatus, i18n.transferPreviewing || 'Reading export…', false);
      try {
        const body = new FormData();
        body.set('action', 'ns_schema_tools_transfer_preview');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('file', file);
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body,
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.transferPreviewError || 'Could not read export file.');
        }
        importToken = json.data.token || '';
        renderPreview(json.data.preview || {});
        if (stepUpload) {
          stepUpload.hidden = true;
        }
        if (stepPreview) {
          stepPreview.hidden = false;
        }
        setStatus(importStatus, '', false);
      } catch (err) {
        setStatus(importStatus, err.message || i18n.transferPreviewError || 'Could not read export file.', true);
      } finally {
        previewBtn.disabled = !(fileInput?.files && fileInput.files[0]);
      }
    });

    backBtn?.addEventListener('click', () => {
      importToken = '';
      if (stepPreview) {
        stepPreview.hidden = true;
      }
      if (stepUpload) {
        stepUpload.hidden = false;
      }
      if (previewEl) {
        previewEl.innerHTML = '';
      }
      setStatus(importStatus, '', false);
    });

    applyBtn?.addEventListener('click', async () => {
      if (!importToken) {
        setStatus(importStatus, i18n.transferPreviewError || 'Could not read export file.', true);
        return;
      }
      const ok = await window.nsConfirm(
        i18n.transferConfirm || 'Apply the selected import actions? This may overwrite existing configs or custom templates.',
        {
          title: i18n.transferConfirmTitle || 'Confirm import?',
          confirmLabel: i18n.ok || 'OK',
          cancelLabel: i18n.cancel || 'Cancel',
        }
      );
      if (!ok) {
        return;
      }
      applyBtn.disabled = true;
      setStatus(importStatus, i18n.transferApplying || 'Importing…', false);
      try {
        const body = new URLSearchParams();
        body.set('action', 'ns_schema_tools_transfer_apply');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('token', importToken);
        body.set('decisions', JSON.stringify(collectDecisions()));
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.transferApplyError || 'Import failed.');
        }
        const result = json.data.result || {};
        const imported = result.imported || {};
        const importedConfigs = Number(imported.configs || 0);
        const importedTemplates = Number(imported.templates || 0);
        const summary = [
          json.data.message || 'Import complete.',
          `Templates: ${importedTemplates}`,
          `Configs: ${importedConfigs}`,
        ].join(' ');
        setStatus(importStatus, summary, false);
        importToken = '';
        if (json.data.onboarding) {
          document.dispatchEvent(new CustomEvent('ns-onboarding-sync', {
            detail: json.data.onboarding,
          }));
        }
        if (stepPreview) {
          stepPreview.hidden = true;
        }
        if (stepUpload) {
          stepUpload.hidden = false;
        }
        if (fileInput) {
          fileInput.value = '';
        }
        if (previewBtn) {
          previewBtn.disabled = true;
        }

        if (
          (importedConfigs > 0 || importedTemplates > 0)
          && typeof window.nsConfirm === 'function'
        ) {
          const goBatch = await window.nsConfirm(
            i18n.transferBatchPrompt
              || 'Import finished. Would you like to open Batch Regenerate to rewrite schema for matching posts?',
            {
              title: i18n.transferBatchPromptTitle || 'Run batch regenerate?',
              confirmLabel: i18n.transferBatchPromptYes || 'Open Batch Regenerate',
              cancelLabel: i18n.transferBatchPromptNo || 'Not now',
            }
          );
          if (goBatch) {
            window.location.href = cfg.batchRegenUrl
              || 'tools.php?page=schema-tools&tool=batch-regen';
            return;
          }
        }
      } catch (err) {
        setStatus(importStatus, err.message || i18n.transferApplyError || 'Import failed.', true);
      } finally {
        applyBtn.disabled = false;
      }
    });
  }

    function initDataRemoval() {
    const root = document.getElementById('ns-schema-data-removal');
    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    if (!root) {
      return;
    }

    const btn = document.getElementById('ns-data-removal-btn');
    const statusEl = document.getElementById('ns-data-removal-status');
    const scopeCopy = i18n.dataRemovalScopes || {};
    const customWritable = root.dataset.customWritable !== '0';

    function selectedScope() {
      const input = root.querySelector('input[name="ns_data_removal_scope"]:checked');
      return input ? input.value : 'everything';
    }

    function confirmCopyForScope(scope) {
      if (scope === 'everything' && !customWritable && scopeCopy.everything_no_templates) {
        return scopeCopy.everything_no_templates;
      }
      return scopeCopy[scope] || {};
    }

    function setStatus(message, isError) {
      if (!statusEl) {
        return;
      }
      statusEl.hidden = !message;
      statusEl.textContent = message || '';
      statusEl.classList.toggle('is-error', !!isError);
    }

    btn?.addEventListener('click', async () => {
      const scope = selectedScope();
      if (scope === 'templates' && !customWritable) {
        await window.nsAlert(
          i18n.dataRemovalNotWritable
            || i18n.storefrontNotWritableDelete
            || 'Custom templates can’t be deleted because the uploads templates directory is not writable on this environment.'
        );
        return;
      }

      const copy = confirmCopyForScope(scope);
      const phrase = i18n.dataRemovalConfirmPhrase || 'DELETE';
      const ok = await window.nsConfirm(copy.message || '', {
        title: copy.title || (i18n.confirmTitle || 'Please confirm'),
        confirmLabel: i18n.dataRemovalDelete || 'Delete',
        cancelLabel: i18n.cancel || 'Cancel',
        destructive: true,
        details: Array.isArray(copy.details) ? copy.details : [],
        requirePhrase: phrase,
      });
      if (!ok) {
        return;
      }

      btn.disabled = true;
      setStatus(i18n.dataRemovalDeleting || 'Deleting…', false);
      try {
        const body = new FormData();
        body.set('action', 'ns_schema_tools_data_removal');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('scope', scope);
        body.set('confirm', phrase);
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body,
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.dataRemovalError || 'Data removal failed.');
        }
        setStatus(json.data.message || 'Done.', false);
      } catch (err) {
        setStatus(err.message || i18n.dataRemovalError || 'Data removal failed.', true);
        await window.nsAlert(err.message || i18n.dataRemovalError || 'Data removal failed.');
      } finally {
        btn.disabled = false;
      }
    });

    const offboardBtn = document.getElementById('ns-offboard-btn');
    const pluginsWritable = root.dataset.pluginsWritable !== '0';
    offboardBtn?.addEventListener('click', async () => {
      if (!pluginsWritable || offboardBtn.disabled) {
        await window.nsAlert(
          i18n.offboardNotWritable
            || 'Offboarding requires a writable wp-content/plugins directory.'
        );
        return;
      }

      const phrase = i18n.offboardConfirmPhrase || 'OFFBOARD';
      const ok = await window.nsConfirm(i18n.offboardMessage || '', {
        title: i18n.offboardTitle || 'Perform offboarding?',
        confirmLabel: i18n.offboardConfirmLabel || 'Start offboarding',
        cancelLabel: i18n.cancel || 'Cancel',
        destructive: true,
        details: Array.isArray(i18n.offboardDetails) ? i18n.offboardDetails : [],
        requirePhrase: phrase,
      });
      if (!ok) {
        return;
      }

      offboardBtn.disabled = true;
      if (btn) {
        btn.disabled = true;
      }
      setStatus(i18n.offboardStarting || 'Installing Schema Lite…', false);
      try {
        const body = new FormData();
        body.set('action', 'ns_schema_tools_offboard_start');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('confirm', phrase);
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body,
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.offboardError || 'Offboarding failed.');
        }
        setStatus(json.data.message || 'Redirecting…', false);
        if (json.data.redirect) {
          window.location.href = json.data.redirect;
          return;
        }
      } catch (err) {
        setStatus(err.message || i18n.offboardError || 'Offboarding failed.', true);
        await window.nsAlert(err.message || i18n.offboardError || 'Offboarding failed.');
        offboardBtn.disabled = false;
        if (btn) {
          btn.disabled = false;
        }
      }
    });
  }

  function initLogs() {
    const root = document.getElementById('ns-schema-logs');
    if (!root || root.dataset.loggingEnabled !== '1') {
      return;
    }

    const cfg = window.nsNobleSchemaAdmin || {};
    const i18n = cfg.i18n || {};
    const listEl = document.getElementById('ns-logs-list');
    const loadingEl = document.getElementById('ns-logs-loading');
    const emptyEl = document.getElementById('ns-logs-empty');
    const moreWrap = document.getElementById('ns-logs-more');
    const loadMoreBtn = document.getElementById('ns-logs-load-more');
    const liveEl = document.getElementById('ns-logs-live');
    const liveLabel = document.getElementById('ns-logs-live-label');
    const liveToggle = document.getElementById('ns-logs-live-toggle');
    const levelSel = document.getElementById('ns-logs-filter-level');
    const categorySel = document.getElementById('ns-logs-filter-category');
    const fromInput = document.getElementById('ns-logs-filter-from');
    const toInput = document.getElementById('ns-logs-filter-to');
    const purgeBtn = document.getElementById('ns-logs-purge');
    if (!listEl) {
      return;
    }

    const PAGE_SIZE = 50;
    let latestId = 0;
    let oldestId = 0;
    let hasMore = false;
    let hasAnyLogs = false;
    let pollTimer = null;
    let loading = false;
    let pendingReplace = false;
    let paused = false;
    const knownIds = new Set();

    function escapeHtml(str) {
      return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function levelClass(level) {
      if (level === 'error') {
        return 'ns-tools-badge--no';
      }
      if (level === 'warning') {
        return 'ns-tools-badge--warn';
      }
      return 'ns-tools-badge--ok';
    }

    function formatContext(ctx) {
      try {
        return JSON.stringify(ctx || {}, null, 2);
      } catch (e) {
        return '';
      }
    }

    function rowSignature(row) {
      return [
        row.message || '',
        row.level || '',
        row.category || '',
        row.created_at || '',
        row.post_id || '',
        row.post_title || '',
        row.post_edit_url || '',
        formatContext(row.context),
      ].join('\0');
    }

    function isRegenRunning(row) {
      const ctx = row?.context && typeof row.context === 'object' ? row.context : {};
      return (row?.category || '') === 'regen_started' && ctx.status === 'running';
    }

    function spinnerHtml(row) {
      if (!isRegenRunning(row)) {
        return '';
      }
      return `<span class="ns-logs-row__spinner" role="status" aria-label="${escapeHtml(i18n.logsInProgress || 'In progress')}"></span>`;
    }

    function syncSpinner(el, row) {
      const msg = el.querySelector('.ns-logs-row__message');
      if (!msg) {
        return;
      }
      const spinner = msg.querySelector('.ns-logs-row__spinner');
      const running = isRegenRunning(row);
      el.classList.toggle('ns-logs-row--running', running);
      if (running && !spinner) {
        msg.insertAdjacentHTML('afterbegin', spinnerHtml(row));
      } else if (!running && spinner) {
        spinner.remove();
      }
    }

    function postHtml(row) {
      if (!row.post_id) {
        return '';
      }
      const title = escapeHtml(row.post_title || (`#${row.post_id}`));
      if (row.post_edit_url) {
        return `<a class="ns-logs-row__post" href="${escapeHtml(row.post_edit_url)}" target="_blank" rel="noopener noreferrer">${title}</a>`;
      }
      return `<span class="ns-logs-row__post">${title}</span>`;
    }

    function rowHtml(row) {
      const id = Number(row.id || 0);
      const level = escapeHtml(row.level || 'info');
      const category = escapeHtml(row.category || 'system');
      const message = escapeHtml(row.message || '');
      const created = escapeHtml(row.created_at || '');
      const running = isRegenRunning(row);
      const detail = formatContext(row.context);
      const detailBlock = detail && detail !== '{}'
        ? `<details class="ns-logs-row__details"><summary>${escapeHtml(i18n.logsDetail || 'Details')}</summary><pre>${escapeHtml(detail)}</pre></details>`
        : '';

      return `<article class="ns-logs-row${running ? ' ns-logs-row--running' : ''}" data-log-id="${id}">
        <div class="ns-logs-row__meta">
          <time datetime="${created}">${created}</time>
          <span class="ns-tools-badge ${levelClass(row.level)}">${level}</span>
          <span class="ns-logs-row__category">${category}</span>
          ${postHtml(row)}
        </div>
        <p class="ns-logs-row__message">${spinnerHtml(row)}<span class="ns-logs-row__message-text">${message}</span></p>
        ${detailBlock}
      </article>`;
    }

    /**
     * Update an existing row without replacing the node, so open Details + pre scroll stay put.
     */
    function patchRow(el, row) {
      const sig = rowSignature(row);
      if (el.dataset.nsSig === sig) {
        return;
      }
      el.dataset.nsSig = sig;

      const created = String(row.created_at || '');
      const time = el.querySelector('time');
      if (time) {
        time.setAttribute('datetime', created);
        time.textContent = created;
      }

      const badge = el.querySelector('.ns-tools-badge');
      if (badge) {
        badge.className = `ns-tools-badge ${levelClass(row.level)}`;
        badge.textContent = row.level || 'info';
      }

      const category = el.querySelector('.ns-logs-row__category');
      if (category) {
        category.textContent = row.category || 'system';
      }

      const meta = el.querySelector('.ns-logs-row__meta');
      if (meta) {
        const existingPost = meta.querySelector('.ns-logs-row__post');
        const nextPost = postHtml(row);
        if (existingPost && nextPost) {
          const wrap = document.createElement('div');
          wrap.innerHTML = nextPost;
          const node = wrap.firstElementChild;
          if (node) {
            existingPost.replaceWith(node);
          }
        } else if (existingPost && !nextPost) {
          existingPost.remove();
        } else if (!existingPost && nextPost) {
          meta.insertAdjacentHTML('beforeend', nextPost);
        }
      }

      const messageEl = el.querySelector('.ns-logs-row__message');
      if (messageEl) {
        let textEl = messageEl.querySelector('.ns-logs-row__message-text');
        if (!textEl) {
          textEl = document.createElement('span');
          textEl.className = 'ns-logs-row__message-text';
          textEl.textContent = messageEl.textContent;
          messageEl.textContent = '';
          messageEl.appendChild(textEl);
        }
        textEl.textContent = row.message || '';
      }
      syncSpinner(el, row);

      const detail = formatContext(row.context);
      let details = el.querySelector('details.ns-logs-row__details');
      if (detail && detail !== '{}') {
        if (!details) {
          el.insertAdjacentHTML(
            'beforeend',
            `<details class="ns-logs-row__details"><summary>${escapeHtml(i18n.logsDetail || 'Details')}</summary><pre></pre></details>`
          );
          details = el.querySelector('details.ns-logs-row__details');
        }
        const pre = details?.querySelector('pre');
        if (pre && pre.textContent !== detail) {
          const scrollTop = pre.scrollTop;
          pre.textContent = detail;
          pre.scrollTop = scrollTop;
        }
      } else if (details) {
        details.remove();
      }
    }

    function setLiveState(state, message) {
      if (!liveEl) {
        return;
      }
      liveEl.dataset.state = state;
      if (liveLabel) {
        if (message) {
          liveLabel.textContent = message;
        } else if (state === 'paused') {
          liveLabel.textContent = i18n.logsPaused || 'Paused';
        } else if (state === 'error') {
          liveLabel.textContent = i18n.logsError || 'Could not load logs.';
        } else {
          liveLabel.textContent = i18n.logsPolling || 'Live';
        }
      }
      if (liveToggle) {
        const isPaused = state === 'paused';
        const label = isPaused
          ? (i18n.logsResume || 'Resume')
          : (i18n.logsPause || 'Pause');
        liveToggle.setAttribute('aria-pressed', isPaused ? 'true' : 'false');
        liveToggle.setAttribute('aria-label', label);
        liveToggle.setAttribute('title', label);
      }
    }

    function fillSelect(select, items, allLabel, valueKey, labelKey) {
      if (!select) {
        return;
      }
      const current = select.value;
      const opts = [`<option value="">${escapeHtml(allLabel)}</option>`];
      const values = [];
      (items || []).forEach((item) => {
        if (typeof item === 'string') {
          values.push(item);
          opts.push(`<option value="${escapeHtml(item)}">${escapeHtml(item)}</option>`);
        } else {
          const value = String(item[valueKey] ?? '');
          const label = String(item[labelKey] ?? value);
          values.push(value);
          opts.push(`<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`);
        }
      });
      const nextSig = `${allLabel}\0${values.join('\0')}`;
      if (select.dataset.nsFilterSig === nextSig) {
        // Options unchanged — keep DOM stable (avoids jitter / closed dropdowns).
        if ([...select.options].some((o) => o.value === current)) {
          select.value = current;
        }
        return;
      }
      select.dataset.nsFilterSig = nextSig;
      select.innerHTML = opts.join('');
      if ([...select.options].some((o) => o.value === current)) {
        select.value = current;
      }
    }

    function applyFilters(filters) {
      fillSelect(levelSel, filters.levels || [], 'All levels', null, null);
      fillSelect(categorySel, filters.categories || [], 'All categories', null, null);
    }

    function setListLoading(show) {
      if (loadingEl) {
        loadingEl.hidden = !show;
      }
      if (show && emptyEl) {
        emptyEl.hidden = true;
      }
    }

    function syncEmpty() {
      if (!emptyEl) {
        return;
      }
      const empty = listEl.children.length === 0;
      const isLoading = !!(loadingEl && !loadingEl.hidden);
      emptyEl.hidden = !empty || isLoading;
      if (empty && !isLoading) {
        emptyEl.textContent = hasAnyLogs
          ? (i18n.logsEmptyFiltered || 'No log entries match the current filters.')
          : (i18n.logsEmpty || 'No log entries exist yet.');
      }
    }

    function syncLoadMore() {
      if (!moreWrap || !loadMoreBtn) {
        return;
      }
      moreWrap.hidden = !hasMore || listEl.children.length === 0;
      loadMoreBtn.disabled = false;
      loadMoreBtn.textContent = i18n.logsLoadMore || 'Load more';
    }

    function recomputeIdBounds() {
      latestId = 0;
      oldestId = 0;
      let oldestNonRunning = 0;
      listEl.querySelectorAll('.ns-logs-row').forEach((el) => {
        const id = Number(el.getAttribute('data-log-id') || 0);
        if (!id) {
          return;
        }
        if (id > latestId) {
          latestId = id;
        }
        if (el.classList.contains('ns-logs-row--running')) {
          return;
        }
        if (!oldestNonRunning || id < oldestNonRunning) {
          oldestNonRunning = id;
        }
      });
      oldestId = oldestNonRunning;
      if (!oldestId) {
        listEl.querySelectorAll('.ns-logs-row').forEach((el) => {
          const id = Number(el.getAttribute('data-log-id') || 0);
          if (id && (!oldestId || id < oldestId)) {
            oldestId = id;
          }
        });
      }
    }

    /** Keep in-progress regen rows pinned above newer sync noise. */
    function pinRunningRowsToTop() {
      const running = [...listEl.querySelectorAll('.ns-logs-row--running')];
      running
        .slice()
        .reverse()
        .forEach((el) => {
          listEl.insertAdjacentElement('afterbegin', el);
        });
    }

    function insertHtmlAfterPins(html) {
      const pins = listEl.querySelectorAll('.ns-logs-row--running');
      const lastPin = pins[pins.length - 1];
      if (lastPin) {
        lastPin.insertAdjacentHTML('afterend', html);
      } else {
        listEl.insertAdjacentHTML('afterbegin', html);
      }
    }

    /**
     * @param {{ replace?: boolean, append?: boolean, poll?: boolean }} opts
     */
    async function fetchLogs({ replace = false, append = false, poll = false } = {}) {
      if (loading) {
        // Don't drop filter changes that race a live poll / load-more.
        if (replace) {
          pendingReplace = true;
        }
        return;
      }
      loading = true;
      const showListLoading = replace && listEl.children.length === 0;
      if (showListLoading) {
        setListLoading(true);
      }
      if (append && loadMoreBtn) {
        loadMoreBtn.disabled = true;
        loadMoreBtn.textContent = i18n.logsLoadingMore || 'Loading…';
      }
      try {
        const body = new FormData();
        body.set('action', 'ns_schema_tools_logs_query');
        body.set('nonce', cfg.toolsNonce || '');
        body.set('level', levelSel?.value || '');
        body.set('category', categorySel?.value || '');
        body.set('date_from', fromInput?.value || '');
        body.set('date_to', toInput?.value || '');
        body.set('limit', String(PAGE_SIZE));
        body.set('after_id', '0');
        body.set('before_id', append ? String(oldestId || 0) : '0');

        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body,
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.logsError || 'Could not load logs.');
        }
        if (!json.data.enabled) {
          window.location.reload();
          return;
        }
        // Only refresh filter option lists on full reload — not on live poll.
        if (replace) {
          applyFilters(json.data.filters || {});
        }
        const rows = Array.isArray(json.data.rows) ? json.data.rows : [];
        if (typeof json.data.has_any === 'boolean') {
          hasAnyLogs = json.data.has_any;
        }

        if (replace) {
          knownIds.clear();
          latestId = 0;
          oldestId = 0;
          hasMore = !!json.data.has_more;
          listEl.innerHTML = rows.map((row) => {
            const id = Number(row.id || 0);
            knownIds.add(id);
            return rowHtml(row);
          }).join('');
          pinRunningRowsToTop();
          recomputeIdBounds();
        } else if (append) {
          hasMore = !!json.data.has_more;
          const fresh = [];
          rows.forEach((row) => {
            const id = Number(row.id || 0);
            if (!id || knownIds.has(id)) {
              return;
            }
            knownIds.add(id);
            fresh.push(row);
          });
          if (fresh.length) {
            listEl.insertAdjacentHTML('beforeend', fresh.map(rowHtml).join(''));
          }
          recomputeIdBounds();
        } else if (poll) {
          const fresh = [];
          const prevLatest = latestId;
          rows.forEach((row) => {
            const id = Number(row.id || 0);
            if (!id) {
              return;
            }
            const existing = listEl.querySelector(`[data-log-id="${id}"]`);
            if (existing) {
              patchRow(existing, row);
              knownIds.add(id);
              return;
            }
            if (knownIds.has(id) || (prevLatest > 0 && id <= prevLatest)) {
              return;
            }
            knownIds.add(id);
            fresh.push(row);
          });
          if (fresh.length) {
            insertHtmlAfterPins(fresh.slice().reverse().map(rowHtml).join(''));
          }
          pinRunningRowsToTop();
          recomputeIdBounds();
        }

        syncEmpty();
        if (!paused) {
          setLiveState('live');
        }
      } catch (err) {
        setLiveState('error', err.message || i18n.logsError || 'Could not load logs.');
      } finally {
        loading = false;
        if (showListLoading) {
          setListLoading(false);
        }
        syncEmpty();
        syncLoadMore();
        if (pendingReplace) {
          pendingReplace = false;
          fetchLogs({ replace: true });
        }
      }
    }

    function startPolling() {
      stopPolling();
      pollTimer = window.setInterval(() => {
        if (paused || document.hidden) {
          return;
        }
        // Fetch recent rows so updated regen events refresh in place.
        fetchLogs({ poll: true });
      }, 4000);
    }

    function stopPolling() {
      if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
      }
    }

    function onFilterChange() {
      fetchLogs({ replace: true });
    }

    liveToggle?.addEventListener('click', () => {
      paused = !paused;
      if (paused) {
        stopPolling();
        setLiveState('paused');
      } else {
        setLiveState('live');
        fetchLogs({ poll: true });
        startPolling();
      }
    });

    levelSel?.addEventListener('change', onFilterChange);
    categorySel?.addEventListener('change', onFilterChange);
    fromInput?.addEventListener('change', onFilterChange);
    toInput?.addEventListener('change', onFilterChange);

    purgeBtn?.addEventListener('click', async () => {
      if (purgeBtn.disabled || purgeBtn.classList.contains('is-busy')) {
        return;
      }

      const ok = await window.nsConfirm(
        i18n.logsClearBody
          || 'This permanently deletes all stored system log entries. Logging stays enabled.',
        {
          title: i18n.logsClearTitle || 'Delete all logs?',
          confirmLabel: i18n.logsClearConfirm || 'Delete all logs',
          cancelLabel: i18n.cancel || 'Cancel',
          destructive: true,
        }
      );
      if (!ok) {
        return;
      }

      purgeBtn.disabled = true;
      purgeBtn.classList.add('is-busy');
      try {
        const body = new FormData();
        body.set('action', 'ns_schema_tools_logs_purge');
        body.set('nonce', cfg.toolsNonce || '');
        const res = await fetch(cfg.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body,
        });
        const json = await res.json();
        if (!json?.success) {
          throw new Error(json?.data?.message || i18n.logsClearError || 'Could not delete logs.');
        }
        await fetchLogs({ replace: true });
      } catch (err) {
        await window.nsAlert(err.message || i18n.logsClearError || 'Could not delete logs.');
      } finally {
        purgeBtn.disabled = false;
        purgeBtn.classList.remove('is-busy');
      }
    });

    loadMoreBtn?.addEventListener('click', () => {
      if (!hasMore || loading) {
        return;
      }
      fetchLogs({ append: true });
    });

    setLiveState('live');
    fetchLogs({ replace: true });
    startPolling();

    window.addEventListener('beforeunload', () => {
      stopPolling();
    });
  }

  function initOnboarding() {
    const cfg = window.nsNobleSchemaAdmin || {};
    const ob = cfg.onboarding || {};
    const root = document.getElementById('ns-onboarding');
    if (!root) {
      return;
    }

    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = ob.nonce || '';
    const i18n = ob.i18n || {};

    async function post(action, extra) {
      const body = new URLSearchParams();
      body.set('action', action);
      body.set('nonce', nonce);
      if (extra) {
        Object.keys(extra).forEach((key) => {
          body.set(key, extra[key]);
        });
      }
      const res = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
      });
      const json = await res.json();
      if (!json?.success) {
        throw new Error(json?.data?.message || 'Request failed');
      }
      return json.data || {};
    }

    function applyState(data) {
      const state = data.state;
      if (!root) {
        return;
      }
      if (!data.activeUi) {
        root.remove();
        return;
      }

      const status = state?.status || 'active';
      const path = state?.path ?? null;
      const celebrating = status === 'celebrating';
      const minimized = !celebrating && status === 'minimized';
      root.classList.toggle('is-minimized', minimized);
      root.dataset.status = status;
      root.dataset.path = path || '';

      const pill = document.getElementById('ns-onboarding-restore');
      const panel = root.querySelector('.ns-onboarding__panel');
      if (pill) {
        pill.hidden = !minimized;
      }
      if (panel) {
        panel.hidden = minimized;
      }

      root.querySelectorAll('[data-ns-onboarding-section]').forEach((section) => {
        const name = section.getAttribute('data-ns-onboarding-section');
        let show = false;
        if (celebrating) {
          show = name === 'complete';
        } else if (name === 'question') {
          show = path === null || path === '';
        } else if (name === 'import') {
          show = path === 'import';
        } else if (name === 'checklist') {
          show = path === 'checklist';
        } else if (name === 'complete') {
          show = false;
        }
        section.hidden = !show;
      });

      const backBtn = document.getElementById('ns-onboarding-back');
      if (backBtn) {
        backBtn.hidden = celebrating || (path !== 'import' && path !== 'checklist');
      }

      const steps = state?.steps || {};
      const keys = ['settings', 'templates', 'configs', 'viewer'];
      let completed = 0;
      keys.forEach((key) => {
        if (steps[key]?.completed) {
          completed += 1;
        }
      });

      const progress = document.getElementById('ns-onboarding-progress');
      if (progress) {
        progress.textContent = String(completed) + ' of ' + keys.length + ' complete';
      }
      if (pill) {
        const label = pill.querySelector('.ns-onboarding__pill-label');
        if (label) {
          label.textContent = (i18n.setup || 'Setup') + ' · ' + completed + '/' + keys.length;
        }
      }

      keys.forEach((key) => {
        const item = root.querySelector('.ns-onboarding__item[data-step="' + key + '"]');
        if (!item) {
          return;
        }
        const done = !!steps[key]?.completed;
        item.classList.toggle('is-complete', done);
        const check = item.querySelector('.ns-onboarding__check');
        if (check) {
          check.innerHTML = done
            ? '<span class="dashicons dashicons-yes"></span>'
            : '<span class="ns-onboarding__check-empty"></span>';
        }
        let mark = item.querySelector('[data-ns-onboarding-mark]');
        if (done && mark) {
          mark.remove();
        } else if (!done && !mark) {
          mark = document.createElement('button');
          mark.type = 'button';
          mark.className = 'button-link ns-onboarding__mark';
          mark.setAttribute('data-ns-onboarding-mark', key);
          mark.textContent = i18n.markComplete || 'Mark complete';
          item.appendChild(mark);
        }
      });
    }

    root.querySelectorAll('[data-ns-onboarding-path]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const path = btn.getAttribute('data-ns-onboarding-path');
        try {
          const data = await post('ns_onboarding_set_path', { path });
          applyState(data);
          if (data.redirect) {
            window.location.href = data.redirect;
          }
        } catch (e) {
          // ignore
        }
      });
    });

    document.getElementById('ns-onboarding-minimize')?.addEventListener('click', async () => {
      try {
        applyState(await post('ns_onboarding_minimize'));
      } catch (e) {
        // ignore
      }
    });

    document.getElementById('ns-onboarding-restore')?.addEventListener('click', async () => {
      try {
        applyState(await post('ns_onboarding_restore'));
      } catch (e) {
        // ignore
      }
    });

    document.getElementById('ns-onboarding-dismiss')?.addEventListener('click', async () => {
      const ok = typeof window.nsConfirm === 'function'
        ? await window.nsConfirm(i18n.dismissBody || 'Dismiss setup guide?', {
          title: i18n.dismissTitle || 'Dismiss setup guide?',
          confirmLabel: i18n.dismissConfirm || 'Dismiss',
          cancelLabel: cfg.i18n?.cancel || 'Cancel',
        })
        : window.confirm(i18n.dismissBody || 'Dismiss setup guide?');
      if (!ok) {
        return;
      }
      try {
        const data = await post('ns_onboarding_dismiss');
        applyState(data);
      } catch (e) {
        // ignore
      }
    });

    root.addEventListener('click', async (event) => {
      const mark = event.target?.closest?.('[data-ns-onboarding-mark]');
      if (!mark) {
        return;
      }
      const step = mark.getAttribute('data-ns-onboarding-mark');
      try {
        applyState(await post('ns_onboarding_mark_step', { step }));
      } catch (e) {
        // ignore
      }
    });

    document.addEventListener('ns-onboarding-sync', (event) => {
      applyState(event.detail || {});
    });
  }

  initLogs();
})();
