(function () {
  'use strict';

  let statusTimer = 0;

  function showStatus(text, isError) {
    let status = document.getElementById('eum-click-login-status');
    if (!status) {
      status = document.createElement('div');
      status.id = 'eum-click-login-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.style.cssText = 'position:fixed;right:20px;top:20px;z-index:2147483647;max-width:min(420px,calc(100vw - 40px));padding:12px 16px;border-radius:10px;background:#101828;color:#fff;font:14px/1.4 system-ui,sans-serif;box-shadow:0 8px 28px rgba(16,24,40,.24)';
      document.documentElement.appendChild(status);
    }
    status.textContent = text;
    status.style.background = isError ? '#b42318' : '#101828';
    window.clearTimeout(statusTimer);
    statusTimer = window.setTimeout(function () {
      if (status && status.isConnected) status.remove();
    }, 7000);
  }

  chrome.runtime.onMessage.addListener(function (message) {
    if (!message || message.type !== 'EUM_CLICK_LOGIN_RESULT') return;
    showStatus(String(message.message || (message.success ? 'App login ready.' : 'The app login could not be filled.')), !message.success);
  });

  document.addEventListener('click', function (event) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const anchor = event.target && event.target.closest
      ? event.target.closest('a[data-eum-click-login-app]')
      : null;
    if (!anchor) return;

    const appId = anchor.getAttribute('data-eum-click-login-app') || '';
    const shell = anchor.closest('[data-eum-click-login-rest-root]');
    const apiRoot = shell && shell.getAttribute('data-eum-click-login-rest-root');
    const nonce = shell && shell.getAttribute('data-eum-click-login-rest-nonce');
    if (!appId || !apiRoot || !nonce || !window.chrome || !chrome.runtime) return;
    const assignmentId = anchor.getAttribute('data-eum-click-login-assignment') || '';

    event.preventDefault();
    event.stopPropagation();

    const endpoint = new URL('launch/' + encodeURIComponent(appId), apiRoot);
    if (assignmentId) endpoint.searchParams.set('login_id', assignmentId);
    fetch(endpoint.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('This app is not available to your account.');
        return response.json();
      })
      .then(function (launch) {
        chrome.runtime.sendMessage({ type: 'EUM_CLICK_LOGIN_LAUNCH', launch: launch }, function (result) {
          if (chrome.runtime.lastError || !result || !result.ok) {
            window.open(anchor.href, '_blank', 'noopener');
            showStatus('The app opened without automatic login. Check that the EUM extension is enabled.', true);
          } else {
            showStatus('Opening ' + (launch.label || 'app') + ' and filling its login form…', false);
          }
        });
      })
      .catch(function () {
        window.open(anchor.href, '_blank', 'noopener');
        showStatus('The app opened without automatic login. Check that the EUM extension is enabled.', true);
      });
  }, true);
}());
