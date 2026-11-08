'use strict';

const SESSION_PREFIX = 'eum-click-login-';
const activeFillTabs = new Set();

function sessionKey(id) {
  return SESSION_PREFIX + id;
}

function sendResult(item, success, message) {
  if (!item || !Number.isInteger(item.portal_tab_id) || !item.launch || !item.launch.launch_id) return;
  chrome.tabs.sendMessage(item.portal_tab_id, {
    type: 'EUM_CLICK_LOGIN_RESULT',
    launch_id: item.launch.launch_id,
    success: Boolean(success),
    message: String(message || '')
  }).catch(function () {});
}

chrome.runtime.onMessage.addListener(function (message, sender, sendResponse) {
  if (!message || typeof message !== 'object') return false;

  if (message.type === 'EUM_CLICK_LOGIN_LAUNCH') {
    const launch = message.launch;
    if (!launch || !launch.app_id || !launch.login_url || !launch.password || !launch.username) {
      sendResponse({ ok: false });
      return false;
    }
    try {
      const portalUrl = new URL(sender.url || '');
      if (portalUrl.protocol !== 'https:' || portalUrl.hostname !== 'internal.jas.enterprises') throw new Error('Portal sender required');
    } catch (error) {
      sendResponse({ ok: false });
      return false;
    }
    let loginUrl;
    try {
      loginUrl = new URL(launch.login_url);
      if (loginUrl.protocol !== 'https:') throw new Error('HTTPS required');
    } catch (error) {
      sendResponse({ ok: false });
      return false;
    }

    if (!sender.tab || !Number.isInteger(sender.tab.id)) {
      sendResponse({ ok: false });
      return false;
    }
    const launchId = crypto.randomUUID();
    const key = sessionKey(launchId);
    const item = {
      launch: Object.assign({}, launch, { launch_id: launchId }),
      portal_tab_id: sender.tab.id,
      created_at: Date.now(),
      starting: true
    };
    chrome.storage.session.set({ [key]: item })
      .then(function () { return chrome.tabs.create({ url: loginUrl.href, active: true }); })
      .then(async function (tab) {
        item.login_tab_id = tab.id;
        item.starting = false;
        await chrome.storage.session.set({ [key]: item });
        sendResponse({ ok: true, launch_id: launchId });
        const current = await chrome.tabs.get(tab.id).catch(function () { return null; });
        if (current && current.status === 'complete') fillLoginTab(tab.id, current);
      })
      .catch(async function () {
        await chrome.storage.session.remove(key).catch(function () {});
        sendResponse({ ok: false });
      });
    return true;
  }

  return false;
});

chrome.tabs.onUpdated.addListener(function (tabId, changeInfo, tab) {
  if (changeInfo.status === 'complete') fillLoginTab(tabId, tab);
});

chrome.tabs.onRemoved.addListener(function (tabId) {
  chrome.storage.session.get(null).then(function (items) {
    const removals = [];
    Object.keys(items).forEach(function (key) {
      const item = items[key];
      if (key.indexOf(SESSION_PREFIX) === 0 && item && item.login_tab_id === tabId) removals.push(key);
    });
    if (removals.length) chrome.storage.session.remove(removals);
  });
});

async function fillLoginTab(tabId, tab) {
  if (activeFillTabs.has(tabId)) return;
  activeFillTabs.add(tabId);
  try {
    await fillLoginTabOnce(tabId, tab);
  } finally {
    activeFillTabs.delete(tabId);
  }
}

async function fillLoginTabOnce(tabId, tab) {
  const sessionItems = await chrome.storage.session.get(null);
  const key = Object.keys(sessionItems).find(function (candidate) {
    const item = sessionItems[candidate];
    return candidate.indexOf(SESSION_PREFIX) === 0 && item && item.login_tab_id === tabId;
  });
  if (!key) return;
  const record = await chrome.storage.session.get(key);
  const item = record[key];
  if (!item || !item.launch) return;
  if (item.filling || Date.now() - Number(item.created_at || 0) > 300000) {
    if (item.filling) return;
    await failLogin(key, item, 'This login request expired. Return to the portal and try again.');
    return;
  }
  item.filling = true;
  await chrome.storage.session.set({ [key]: item });

  const launch = item.launch;
  let expectedOrigin;
  try { expectedOrigin = new URL(launch.login_url).origin; }
  catch (error) { await failLogin(key, item, 'The configured login URL is invalid.'); return; }
  let currentOrigin = '';
  try { currentOrigin = new URL(tab.url || '').origin; } catch (error) {}
  if (currentOrigin !== expectedOrigin) {
    await failLogin(key, item, 'The login page redirected to another domain. Update the app login URL with IT.');
    return;
  }

  try {
    const injected = await chrome.scripting.executeScript({
      target: { tabId: tabId },
      func: autofillLoginForm,
      args: [
        String(launch.username || ''),
        String(launch.password || ''),
        String(launch.username_selector || ''),
        String(launch.password_selector || ''),
        String(launch.submit_selector || ''),
        Boolean(launch.auto_submit),
        Boolean(item.username_step_submitted)
      ]
    });
    const result = injected && injected[0] ? injected[0].result : null;
    if (result && result.awaiting_password) {
      item.filling = false;
      item.username_step_submitted = true;
      item.password_step_attempts = Number(item.password_step_attempts || 0) + 1;
      if (item.password_step_attempts >= 30) {
        await failLogin(key, item, 'The password step did not appear. Check the Backstage login page and app selectors.');
        return;
      }
      await chrome.storage.session.set({ [key]: item });
      scheduleLoginRetry(tabId, 350);
      return;
    }
    if (!result || !result.ok) {
      await failLogin(key, item, result && result.message ? result.message : 'Could not find the configured login fields.');
      return;
    }
    await chrome.storage.session.remove(key);
    sendResult(item, true, result.submitted ? 'Credentials filled and submitted.' : 'Credentials filled. Sign in on the app tab.');
  } catch (error) {
    item.filling = false;
    item.injection_retries = Number(item.injection_retries || 0) + 1;
    if (item.injection_retries <= 3 && Date.now() - Number(item.created_at || 0) < 30000) {
      await chrome.storage.session.set({ [key]: item }).catch(function () {});
      scheduleLoginRetry(tabId, 500);
      return;
    }
    await failLogin(key, item, 'The browser could not fill the login form. Reload the extension and check its site access.');
  }
}

function scheduleLoginRetry(tabId, delayMs) {
  setTimeout(async function () {
    const current = await chrome.tabs.get(tabId).catch(function () { return null; });
    if (current) fillLoginTab(tabId, current);
  }, delayMs);
}

async function failLogin(key, item, message) {
  await chrome.storage.session.remove(key);
  sendResult(item, false, message);
}

async function autofillLoginForm(username, password, usernameSelector, passwordSelector, submitSelector, autoSubmit, usernameStepSubmitted) {
  const pause = function (ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); };
  const isVisibleElement = function (element) {
    if (!element || element.disabled || element.type === 'hidden') return false;
    const style = window.getComputedStyle(element);
    return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
  };
  const isInput = function (element) {
    return element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement;
  };
  const queryElement = function (selector) {
    if (!selector) return null;
    try {
      const element = document.querySelector(selector);
      return isVisibleElement(element) ? element : null;
    } catch (error) {
      return null;
    }
  };
  const queryInput = function (selector) {
    const element = queryElement(selector);
    return element && isInput(element) ? element : null;
  };
  const firstVisibleInput = function (selectors) {
    for (const selector of selectors) {
      const element = queryInput(selector);
      if (element) return element;
    }
    return null;
  };
  const usernameField = queryInput(usernameSelector) || firstVisibleInput([
    '#validate-email',
    'input[autocomplete="username"]',
    'input[type="email"]',
    'input[name*="email" i]',
    'input[id*="email" i]',
    'input[name*="username" i]',
    'input[id*="username" i]',
    'input[type="text"]'
  ]);
  const passwordField = queryInput(passwordSelector) || firstVisibleInput([
    'input[type="password"]',
    'input[autocomplete="current-password"]'
  ]);

  const findSubmit = function (field) {
    const configured = queryElement(submitSelector);
    if (configured && (!field || !field.form || configured.form === field.form)) return configured;
    const form = field && (field.form || field.closest('form'));
    const scope = form || document;
    const submit = scope.querySelector('button[type="submit"], input[type="submit"]');
    if (submit && !submit.disabled && submit.getClientRects().length > 0) return submit;
    const buttons = Array.from(scope.querySelectorAll('button, input[type="button"]'));
    return buttons.find(function (button) {
      const label = String(button.innerText || button.value || button.getAttribute('aria-label') || '').trim();
      return !button.disabled && button.getClientRects().length > 0 && /^(next|continue|sign in|log in|login)$/i.test(label);
    }) || null;
  };
  const setInputValue = function (element, value) {
    const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');
    if (descriptor && descriptor.set) descriptor.set.call(element, value);
    else element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  };

  if (!passwordField) {
    if (!usernameField) return { ok: false, message: 'The login email field was not found on this page.' };
    if (!usernameStepSubmitted) {
      setInputValue(usernameField, username);
      let nextButton = findSubmit(usernameField);
      for (let attempt = 0; !nextButton && attempt < 10; attempt += 1) {
        await pause(150);
        nextButton = findSubmit(usernameField);
      }
      if (!nextButton) return { ok: false, message: 'The email step needs a Next or Continue button. Check the app submit selector.' };
      nextButton.click();
    }
    return { ok: true, awaiting_password: true };
  }

  if (usernameField && !usernameStepSubmitted) setInputValue(usernameField, username);
  setInputValue(passwordField, password);

  let submitted = false;
  if (autoSubmit) {
    const submit = findSubmit(passwordField);
    if (submit) {
      submit.click();
      submitted = true;
    }
  }
  return { ok: true, submitted: submitted };
}
