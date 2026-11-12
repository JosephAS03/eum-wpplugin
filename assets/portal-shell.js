(function () {
  'use strict';

  function init() {
  var wraps = document.querySelectorAll('.eum-portal-shell__dropdown-wrap');

  function showAppsView(menu, viewName) {
    if (!menu) return;
    menu.querySelectorAll('[data-apps-view]').forEach(function (view) {
      var isActive = view.getAttribute('data-apps-view') === viewName;
      view.hidden = !isActive;
      view.setAttribute('aria-hidden', isActive ? 'false' : 'true');
    });
    menu.querySelectorAll('[data-open-mailboxes], [data-open-app-view]').forEach(function (opener) {
      var target = opener.getAttribute('data-open-app-view');
      var isOpen = opener.hasAttribute('data-open-mailboxes') ? viewName === 'mailboxes' : target === viewName;
      opener.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }

  function setOpen(wrap, open) {
    var button = wrap.querySelector('button[aria-expanded]');
    var menu = wrap.querySelector('.eum-portal-shell__popup');
    if (!button || !menu) return;
    if (!open && wrap.classList.contains('eum-portal-shell__apps')) showAppsView(menu, 'main');
    wrap.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    menu.setAttribute('aria-hidden', open ? 'false' : 'true');
  }

  wraps.forEach(function (wrap) {
    var button = wrap.querySelector('button[aria-expanded]');
    if (!button) return;

    button.addEventListener('click', function () {
      setOpen(wrap, button.getAttribute('aria-expanded') !== 'true');
    });

    if (!wrap.classList.contains('eum-portal-shell__click-popup')) {
      wrap.addEventListener('mouseenter', function () {
        setOpen(wrap, true);
      });

      wrap.addEventListener('mouseleave', function () {
        if (!wrap.contains(document.activeElement)) setOpen(wrap, false);
      });

      wrap.addEventListener('focusout', function (event) {
        if (!wrap.contains(event.relatedTarget)) setOpen(wrap, false);
      });
    }
  });

  document.querySelectorAll('.eum-portal-shell__apps-menu').forEach(function (menu) {
    menu.addEventListener('click', function (event) {
      var openButton = event.target.closest && event.target.closest('[data-open-mailboxes], [data-open-app-view]');
      if (openButton && menu.contains(openButton)) {
        event.preventDefault();
        var viewName = openButton.hasAttribute('data-open-mailboxes') ? 'mailboxes' : openButton.getAttribute('data-open-app-view');
        var wrap = menu.closest('.eum-portal-shell__dropdown-wrap');
        if (wrap) setOpen(wrap, true);
        showAppsView(menu, viewName);
        var activeBackButton = null;
        menu.querySelectorAll('[data-apps-view]').forEach(function (view) {
          if (view.getAttribute('data-apps-view') === viewName) activeBackButton = view.querySelector('[data-back-to-apps]');
        });
        if (activeBackButton) activeBackButton.focus();
        return;
      }

      var backButton = event.target.closest && event.target.closest('[data-back-to-apps]');
      if (backButton && menu.contains(backButton)) {
        event.preventDefault();
        var opener = menu.querySelector('[data-open-mailboxes][aria-expanded="true"], [data-open-app-view][aria-expanded="true"]');
        showAppsView(menu, 'main');
        if (opener) opener.focus();
      }
    });
  });

  document.addEventListener('click', function (event) {
    var eventPath = typeof event.composedPath === 'function' ? event.composedPath() : [];
    wraps.forEach(function (wrap) {
      if (!wrap.contains(event.target) && eventPath.indexOf(wrap) === -1) setOpen(wrap, false);
    });
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    wraps.forEach(function (wrap) {
      if (wrap.classList.contains('is-open')) {
        setOpen(wrap, false);
        var button = wrap.querySelector('button[aria-expanded]');
        if (button) button.focus();
      }
    });
  });

  function postPortalAction(action, nonceName, nonceValue, fields) {
    if (!window.EUMPortalShell || !EUMPortalShell.ajaxUrl) return Promise.reject(new Error('Portal connection is unavailable.'));
    var body = new URLSearchParams();
    body.set('action', action);
    body.set(nonceName || 'nonce', nonceValue || '');
    Object.keys(fields || {}).forEach(function (key) {
      var value = fields[key];
      if (Array.isArray(value)) value.forEach(function (item) { body.append(key + '[]', item); });
      else body.set(key, value == null ? '' : String(value));
    });
    return window.fetch(EUMPortalShell.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok || !payload || !payload.success) {
          var message = payload && payload.data && payload.data.message ? payload.data.message : 'The request could not be completed.';
          throw new Error(message);
        }
        return payload.data || {};
      });
    });
  }

  function updatePortalBadge(selector, count) {
    var badge = document.querySelector(selector);
    if (!badge) return;
    var value = Math.max(0, parseInt(count, 10) || 0);
    badge.textContent = value > 99 ? '99+' : String(value);
    badge.hidden = value === 0;
    var button = badge.closest('button');
    if (button) button.setAttribute('aria-label', (selector.indexOf('message') !== -1 ? 'Messages' : 'Notifications') + (value ? ', ' + value + ' unread' : ''));
  }

  var notificationWrap = document.querySelector('.eum-portal-shell__notification-wrap');
  if (notificationWrap) {
    var notificationList = notificationWrap.querySelector('[data-eum-notification-list]');
    var notificationReadAll = notificationWrap.querySelector('[data-eum-notification-read-all]');
    var notificationMore = notificationWrap.querySelector('[data-eum-notification-more]');
    var notificationLoading = false;
    var notificationOffset = 0;
    var notificationHasMore = false;
    var notificationStatus = 'unread';
    var notificationRequest = 0;
    var notificationViews = notificationWrap.querySelectorAll('[data-eum-notification-view]');

    function notificationEmptyMessage() {
      return notificationStatus === 'read' ? 'No read notifications yet.' : 'You’re all caught up.';
    }

    function setNotificationStatus(status) {
      notificationStatus = status === 'read' ? 'read' : 'unread';
      notificationViews.forEach(function (view) {
        view.setAttribute('aria-pressed', view.getAttribute('data-eum-notification-view') === notificationStatus ? 'true' : 'false');
      });
      if (notificationReadAll) notificationReadAll.hidden = notificationStatus !== 'unread';
      var summary = notificationWrap.querySelector('[data-eum-notification-summary]');
      if (summary) summary.textContent = notificationStatus === 'read' ? 'Previously read updates' : 'Unread updates';
      notificationOffset = 0;
      notificationHasMore = false;
      notificationMore.hidden = true;
      loadNotifications(false);
    }

    function notificationEmpty(message) {
      if (!notificationList) return;
      notificationList.replaceChildren();
      var empty = document.createElement('p');
      empty.className = 'eum-portal-shell__empty';
      empty.textContent = message;
      notificationList.appendChild(empty);
    }

    function renderNotifications(items, append) {
      if (!notificationList) return;
      if (!append) notificationList.replaceChildren();
      if (!items.length && !append) {
        notificationEmpty(notificationEmptyMessage());
        return;
      }
      items.forEach(function (item) {
        var card = document.createElement('article');
        card.className = 'eum-portal-shell__notification-item' + (item.importance === 'high' ? ' is-high' : '');
        card.setAttribute('data-notification-id', String(item.id));
        var copy = document.createElement('div');
        copy.className = 'eum-portal-shell__notification-copy';
        var title = document.createElement('strong');
        title.textContent = item.title || 'Notification';
        copy.appendChild(title);
        if (item.body) {
          var body = document.createElement('p');
          body.textContent = item.body;
          copy.appendChild(body);
        }
        var time = document.createElement('time');
        time.textContent = item.time || '';
        copy.appendChild(time);
        var actions = document.createElement('div');
        actions.className = 'eum-portal-shell__notification-actions';
        if (item.action_url) {
          var open = document.createElement('a');
          open.href = item.action_url;
          open.textContent = 'Open';
          actions.appendChild(open);
        }
        var mark = document.createElement('button');
        mark.type = 'button';
        var action = notificationStatus === 'read' ? 'unread' : 'read';
        mark.textContent = action === 'read' ? 'Mark read' : 'Mark unread';
        mark.addEventListener('click', function () {
          mark.disabled = true;
          postPortalAction('eum_notification_popup_' + action, 'nonce', EUMPortalShell.notificationNonce, { notification_id: item.id }).then(function (data) {
            card.remove();
            if (notificationHasMore) notificationOffset = Math.max(0, notificationOffset - 1);
            updatePortalBadge('[data-eum-notification-count]', data.unread_count);
            if (!notificationList.querySelector('[data-notification-id]')) {
              if (notificationHasMore) loadNotifications(false);
              else notificationEmpty(notificationEmptyMessage());
            }
          }).catch(function (error) {
            mark.disabled = false;
            mark.textContent = error.message || (action === 'read' ? 'Could not mark read' : 'Could not mark unread');
          });
        });
        actions.appendChild(mark);
        card.appendChild(copy);
        card.appendChild(actions);
        notificationList.appendChild(card);
      });
    }

    function loadNotifications(append) {
      if (notificationLoading && append) return;
      if (!append) notificationOffset = 0;
      notificationLoading = true;
      var request = append ? notificationRequest : ++notificationRequest;
      postPortalAction('eum_notification_popup_list', 'nonce', EUMPortalShell.notificationNonce, { offset: notificationOffset, status: notificationStatus }).then(function (data) {
        if (request !== notificationRequest) return;
        var items = data.items || [];
        renderNotifications(items, !!append);
        notificationOffset = append ? notificationOffset + items.length : items.length;
        notificationHasMore = !!data.has_more;
        notificationMore.hidden = !notificationHasMore;
        updatePortalBadge('[data-eum-notification-count]', data.unread_count);
      }).catch(function (error) {
        if (request === notificationRequest) notificationEmpty(error.message || 'Notifications could not be loaded.');
      }).finally(function () { if (request === notificationRequest) notificationLoading = false; });
    }

    if (notificationMore) notificationMore.addEventListener('click', function () { loadNotifications(true); });
    notificationViews.forEach(function (view) {
      view.addEventListener('click', function () { setNotificationStatus(view.getAttribute('data-eum-notification-view')); });
    });

    var notificationButton = notificationWrap.querySelector('button[aria-expanded]');
    if (notificationButton) notificationButton.addEventListener('click', function () {
      if (notificationButton.getAttribute('aria-expanded') === 'true') loadNotifications();
    });
    if (notificationReadAll) notificationReadAll.addEventListener('click', function () {
      notificationReadAll.disabled = true;
      postPortalAction('eum_notification_popup_read_all', 'nonce', EUMPortalShell.notificationNonce, {}).then(function (data) {
        notificationEmpty(notificationEmptyMessage());
        notificationOffset = 0;
        notificationHasMore = false;
        notificationMore.hidden = true;
        updatePortalBadge('[data-eum-notification-count]', data.unread_count);
      }).catch(function (error) {
        notificationEmpty(error.message || 'Notifications could not be updated.');
      }).finally(function () { notificationReadAll.disabled = false; });
    });
  }

  var messengerWrap = document.querySelector('[data-eum-messenger]');
  if (messengerWrap) {
    var messenger = messengerWrap.querySelector('[data-eum-messenger-popup]');
    var threadList = messenger.querySelector('[data-eum-thread-list]');
    var emptyPane = messenger.querySelector('[data-eum-message-empty]');
    var conversation = messenger.querySelector('[data-eum-conversation]');
    var composer = messenger.querySelector('[data-eum-message-composer]');
    var teamBar = messenger.querySelector('[data-eum-team-inbox]');
    var teamSelect = messenger.querySelector('[data-eum-team-inbox-select]');
    var history = messenger.querySelector('[data-eum-message-history]');
    var replyForm = messenger.querySelector('[data-eum-message-reply]');
    var replyError = messenger.querySelector('[data-eum-reply-error]');
    var newMessageForm = messenger.querySelector('[data-eum-new-message-form]');
    var contactSearch = messenger.querySelector('[data-eum-contact-search]');
    var contactResults = messenger.querySelector('[data-eum-contact-results]');
    var contactChips = messenger.querySelector('[data-eum-contact-chips]');
    var createExcludeSearch = messenger.querySelector('[data-eum-create-exclude-search]');
    var createExcludeResults = messenger.querySelector('[data-eum-create-exclude-results]');
    var createExcludeChips = messenger.querySelector('[data-eum-create-exclude-chips]');
    var showHiddenButton = messenger.querySelector('[data-eum-show-hidden]');
    var membersPanel = messenger.querySelector('[data-eum-members-panel]');
    var memberList = messenger.querySelector('[data-eum-member-list]');
    var memberManagement = messenger.querySelector('[data-eum-member-management]');
    var memberStatusNode = messenger.querySelector('[data-eum-members-status]');
    var memberAddSearch = messenger.querySelector('[data-eum-member-add-search]');
    var memberAddResults = messenger.querySelector('[data-eum-member-add-results]');
    var memberAddChips = messenger.querySelector('[data-eum-member-add-chips]');
    var adminOptions = messenger.querySelector('[data-eum-admin-options]');
    var excludeSearch = messenger.querySelector('[data-eum-exclude-search]');
    var excludeResults = messenger.querySelector('[data-eum-exclude-results]');
    var excludeChips = messenger.querySelector('[data-eum-exclude-chips]');
    var chatImageTools = messenger.querySelector('[data-eum-chat-image-tools]');
    var chatImageForm = messenger.querySelector('[data-eum-chat-image-form]');
    var chatImageInput = messenger.querySelector('[data-eum-chat-image]');
    var threadOptions = messenger.querySelector('[data-eum-thread-options]');
    var createExcludedUsers = new Map();
    var createExcludedStatuses = [];
    var memberAddSelections = new Map();
    var threadExcludedUsers = new Map();
    var threadExcludedStatuses = [];
    var currentThread = null;
    var includeHiddenChats = false;
    var recipientMode = messenger.querySelector('[data-eum-recipient-mode]');
    var audienceField = messenger.querySelector('[data-eum-audience-field]');
    var audienceOptions = messenger.querySelector('[data-eum-audience-options]');
    var announcementOption = messenger.querySelector('[data-eum-announcement-option]');
    var announcementCheckbox = messenger.querySelector('[data-eum-announcement]');
    var recipientField = messenger.querySelector('[data-eum-recipient-field]');
    var selectedContacts = new Map();
    var audienceTree = { groups: [], job_paths: [] };
    var selectedAudience = new Set();
    var audienceCategory = '';
    var audiencePathStack = [];
    var canAnnounce = false;
    var inboxUserId = 0;
    var activeThreadId = 0;
    var contactsTimer = null;
    var currentThreads = [];

    function messengerMessage(message) {
      threadList.replaceChildren();
      var error = document.createElement('p');
      error.className = 'eum-messenger__loading';
      error.textContent = message;
      threadList.appendChild(error);
    }

    function initials(label) {
      return String(label || '?').trim().split(/\s+/).slice(0, 2).map(function (part) { return part.charAt(0); }).join('').toUpperCase();
    }

    function renderThreads(items) {
      currentThreads = items.slice();
      threadList.replaceChildren();
      if (!items.length) {
        messengerMessage('No conversations yet. Start a new message.');
        return;
      }
      items.forEach(function (thread) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'eum-messenger__thread' + (thread.id === activeThreadId ? ' is-active' : '');
        button.setAttribute('data-thread-id', String(thread.id));
        var avatar = document.createElement('span');
        avatar.className = 'eum-messenger__thread-avatar';
        if (thread.avatar) {
          var avatarImage = document.createElement('img');
          avatarImage.src = thread.avatar;
          avatarImage.alt = '';
          avatarImage.loading = 'lazy';
          avatar.appendChild(avatarImage);
        } else {
          if (thread.is_group) {
            avatar.classList.add('is-generated');
            avatar.style.setProperty('--eum-avatar-hue', String((thread.id * 137.508) % 360));
          }
          avatar.textContent = initials(thread.title);
        }
        var copy = document.createElement('span');
        copy.className = 'eum-messenger__thread-copy';
        var title = document.createElement('strong');
        title.textContent = thread.title || 'Conversation';
        var preview = document.createElement('span');
        var personalLabels = [];
        if (thread.archived) personalLabels.push('Archived');
        if (thread.deleted) personalLabels.push('Deleted for you');
        if (thread.muted) personalLabels.push('Muted');
        preview.textContent = personalLabels.length ? personalLabels.join(' · ') : (thread.preview || (thread.type === 'announcement' ? 'Announcement' : 'No messages yet'));
        copy.appendChild(title);
        copy.appendChild(preview);
        var meta = document.createElement('span');
        meta.className = 'eum-messenger__thread-meta';
        var time = document.createElement('span');
        time.textContent = thread.time || '';
        meta.appendChild(time);
        if (thread.unread) {
          var dot = document.createElement('span');
          dot.className = 'eum-messenger__unread-dot';
          dot.setAttribute('aria-label', 'Unread');
          meta.appendChild(dot);
        }
        button.appendChild(avatar);
        button.appendChild(copy);
        button.appendChild(meta);
        button.addEventListener('click', function () { openThread(thread.id); });
        threadList.appendChild(button);
      });
    }

    function showInboxPane() {
      activeThreadId = 0;
      conversation.hidden = true;
      composer.hidden = true;
      emptyPane.hidden = false;
      messenger.classList.remove('is-conversation-open');
    }

    function loadMessenger(threadToOpen) {
      messengerMessage('Loading conversations…');
      postPortalAction('eum_dm_popup_load', 'nonce', EUMPortalShell.messageNonce, { subject_user_id: inboxUserId, include_hidden: includeHiddenChats ? '1' : '0' }).then(function (data) {
        inboxUserId = parseInt(data.subject_user_id, 10) || 0;
        renderThreads(data.threads || []);
        updatePortalBadge('[data-eum-message-count]', data.unread_count);
        canAnnounce = !!data.can_announce;
        var groupModeOption = recipientMode && recipientMode.querySelector('option[value="group"]');
        if (groupModeOption) groupModeOption.hidden = !data.can_select_audience;
        if (!data.can_select_audience && recipientMode && recipientMode.value === 'group') recipientMode.value = 'user';
        audienceTree = data.audience_tree || { groups: [], job_paths: [] };
        renderAudienceOptions();
        updateComposeMode();
        populateTeamMembers(data.team_members || []);
        if (showHiddenButton) {
          showHiddenButton.setAttribute('aria-pressed', includeHiddenChats ? 'true' : 'false');
          showHiddenButton.textContent = includeHiddenChats ? 'All chats' : 'Hidden';
          showHiddenButton.title = includeHiddenChats ? 'Show active chats only' : 'Show archived and deleted chats';
        }
        if (threadToOpen) openThread(threadToOpen);
      }).catch(function (error) {
        messengerMessage(error.message || 'Messages could not be loaded.');
      });
    }

    function populateTeamMembers(members) {
      teamSelect.replaceChildren();
      var own = document.createElement('option');
      own.value = '0';
      own.textContent = 'My inbox';
      teamSelect.appendChild(own);
      members.forEach(function (member) {
        var option = document.createElement('option');
        option.value = String(member.id);
        option.textContent = member.name;
        teamSelect.appendChild(option);
      });
      teamBar.hidden = members.length === 0;
      teamSelect.value = String(inboxUserId);
    }

    function openThread(threadId) {
      return postPortalAction('eum_dm_popup_thread', 'nonce', EUMPortalShell.messageNonce, {
        thread_id: threadId,
        subject_user_id: inboxUserId,
        include_hidden: includeHiddenChats ? '1' : '0'
      }).then(function (data) {
        var thread = data.thread;
        currentThread = thread;
        activeThreadId = thread.id;
        emptyPane.hidden = true;
        composer.hidden = true;
        membersPanel.hidden = true;
        threadOptions.hidden = true;
        messenger.querySelector('[data-eum-members-open]').setAttribute('aria-expanded', 'false');
        messenger.querySelector('[data-eum-thread-options-toggle]').setAttribute('aria-expanded', 'false');
        conversation.hidden = false;
        messenger.classList.add('is-conversation-open');
        messenger.querySelector('[data-eum-conversation-title]').textContent = thread.title || 'Conversation';
        messenger.querySelector('[data-eum-conversation-type]').textContent = thread.type + (thread.type === 'announcement' ? ' · Read-only' : '');
        var chatAvatar = messenger.querySelector('[data-eum-chat-avatar]');
        chatAvatar.replaceChildren();
        chatAvatar.className = 'eum-messenger__thread-avatar';
        if (thread.avatar) {
          var chatAvatarImage = document.createElement('img');
          chatAvatarImage.src = thread.avatar;
          chatAvatarImage.alt = '';
          chatAvatarImage.loading = 'lazy';
          chatAvatar.appendChild(chatAvatarImage);
        } else {
          if (thread.is_group) {
            chatAvatar.classList.add('is-generated');
            chatAvatar.style.setProperty('--eum-avatar-hue', String((thread.id * 137.508) % 360));
          }
          chatAvatar.textContent = initials(thread.title);
        }
        renderMemberPanel(thread);
        updateChatOptions(thread);
        history.replaceChildren();
        (thread.messages || []).forEach(function (message) {
          var bubble = document.createElement('article');
          bubble.className = 'eum-messenger__bubble' + (message.mine ? ' is-mine' : '');
          var bubbleHead = document.createElement('div');
          bubbleHead.className = 'eum-messenger__bubble-head';
          if (message.avatar) {
            var bubbleAvatar = document.createElement('img');
            bubbleAvatar.src = message.avatar;
            bubbleAvatar.alt = '';
            bubbleAvatar.loading = 'lazy';
            bubbleHead.appendChild(bubbleAvatar);
          }
          var author = document.createElement('span');
          author.className = 'eum-messenger__bubble-author';
          author.textContent = message.mine ? 'You' : message.sender;
          bubbleHead.appendChild(author);
          var body = document.createElement('div');
          body.className = 'eum-messenger__bubble-body';
          body.textContent = message.body;
          var time = document.createElement('time');
          time.textContent = message.time || '';
          bubble.appendChild(bubbleHead);
          bubble.appendChild(body);
          bubble.appendChild(time);
          history.appendChild(bubble);
        });
        replyForm.hidden = !thread.can_reply;
        replyError.hidden = true;
        replyError.textContent = '';
        messenger.querySelector('[data-eum-message-readonly]').hidden = !!thread.can_reply;
        if (replyForm.hidden) replyForm.reset();
        renderThreads(currentThreads.map(function (item) {
          if (item.id === activeThreadId) item.unread = false;
          return item;
        }));
        updatePortalBadge('[data-eum-message-count]', data.unread_count);
        history.scrollTop = history.scrollHeight;
      }).catch(function (error) {
        messengerMessage(error.message || 'This conversation could not be opened.');
      });
    }

    function renderUserChips(container, selection, labelPrefix, onChange) {
      if (!container) return;
      container.replaceChildren();
      selection.forEach(function (person, id) {
        var chip = document.createElement('span');
        chip.className = 'eum-messenger__chip';
        chip.appendChild(document.createTextNode(labelPrefix ? labelPrefix + person.name : person.name));
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label', 'Remove ' + person.name);
        remove.addEventListener('click', function () {
          selection.delete(id);
          if (onChange) onChange();
          renderUserChips(container, selection, labelPrefix, onChange);
        });
        chip.appendChild(remove);
        container.appendChild(chip);
      });
    }

    function addUserSearchResults(query, results, selection, action, fields, onSelect, skipIds) {
      postPortalAction(action, 'nonce', EUMPortalShell.messageNonce, Object.assign({ q: query }, fields || {})).then(function (data) {
        results.replaceChildren();
        (data.contacts || []).forEach(function (person) {
          if (selection.has(person.id) || (skipIds && skipIds.indexOf(person.id) !== -1)) return;
          var option = document.createElement('button');
          option.type = 'button';
          option.className = 'eum-messenger__contact-option';
          var name = document.createElement('strong');
          name.textContent = person.name;
          option.appendChild(name);
          option.addEventListener('click', function () {
            selection.set(person.id, person);
            onSelect();
            results.hidden = true;
          });
          results.appendChild(option);
        });
        results.hidden = results.childElementCount === 0;
      }).catch(function () { results.hidden = true; });
    }

    function statusValues(selector) {
      return Array.prototype.map.call(messenger.querySelectorAll(selector + ':checked'), function (input) { return input.value; });
    }

    function renderMemberPanel(thread) {
      memberList.replaceChildren();
      var members = thread.members || [];
      messenger.querySelector('[data-eum-member-count]').textContent = members.length + (members.length === 1 ? ' member' : ' members');
      messenger.querySelector('[data-eum-members-open]').textContent = 'Members (' + members.length + ')';
      memberManagement.hidden = !thread.can_manage;
      chatImageTools.hidden = !thread.can_manage || !thread.is_group;
      memberStatusNode.textContent = '';
      memberStatusNode.classList.remove('is-error');
      memberAddSelections.clear();
      renderUserChips(memberAddChips, memberAddSelections, '', null);
      members.forEach(function (person) {
        var row = document.createElement('div');
        row.className = 'eum-messenger__member-row';
        var avatar = document.createElement('span');
        avatar.className = 'eum-messenger__member-avatar';
        if (person.avatar) {
          var image = document.createElement('img');
          image.src = person.avatar;
          image.alt = '';
          image.loading = 'lazy';
          avatar.appendChild(image);
        } else avatar.textContent = initials(person.name);
        var name = document.createElement('span');
        name.className = 'eum-messenger__member-name';
        name.textContent = person.name;
        row.appendChild(avatar);
        row.appendChild(name);
        if (person.is_admin) {
          var adminBadge = document.createElement('span');
          adminBadge.className = 'eum-messenger__member-admin';
          adminBadge.textContent = 'Admin';
          row.appendChild(adminBadge);
        }
        if (thread.can_manage) {
          var remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'eum-messenger__member-remove';
          remove.textContent = 'Remove';
          remove.addEventListener('click', function () { manageThread('remove_member', { user_id: person.id }); });
          row.appendChild(remove);
        }
        memberList.appendChild(row);
      });

      adminOptions.replaceChildren();
      members.forEach(function (person) {
        var label = document.createElement('label');
        label.className = 'eum-messenger__admin-option';
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = String(person.id);
        checkbox.checked = !!person.is_admin;
        checkbox.setAttribute('data-eum-admin-id', String(person.id));
        var textNode = document.createElement('span');
        textNode.textContent = person.name;
        label.appendChild(checkbox);
        label.appendChild(textNode);
        adminOptions.appendChild(label);
      });

      threadExcludedUsers.clear();
      (thread.excluded_users || []).forEach(function (person) { threadExcludedUsers.set(person.id, person); });
      threadExcludedStatuses = thread.excluded_statuses || [];
      messenger.querySelectorAll('[data-eum-rule-status]').forEach(function (input) {
        input.checked = threadExcludedStatuses.indexOf(input.value) !== -1;
      });
      renderUserChips(excludeChips, threadExcludedUsers, '', null);
      if (chatImageInput) chatImageInput.value = '';
      updateChatOptions(thread);
    }

    function updateChatOptions(thread) {
      var state = thread.personal_state || {};
      var targetName = inboxUserId > 0 && teamSelect.selectedOptions.length ? teamSelect.selectedOptions[0].textContent : 'me';
      var canControl = inboxUserId === 0 || !!thread.can_manage;
      var toggle = messenger.querySelector('[data-eum-thread-options-toggle]');
      toggle.hidden = !canControl;
      messenger.querySelector('[data-eum-chat-archive]').textContent = 'Archive for ' + targetName;
      messenger.querySelector('[data-eum-chat-delete]').textContent = 'Delete for ' + targetName;
      messenger.querySelector('[data-eum-chat-mute]').textContent = state.muted ? 'Unmute for ' + targetName : 'Mute for ' + targetName;
      messenger.querySelector('[data-eum-chat-restore]').hidden = !(state.archived || state.deleted);
      messenger.querySelector('[data-eum-chat-restore]').textContent = 'Restore for ' + targetName;
      var stateStatus = messenger.querySelector('[data-eum-chat-state-status]');
      stateStatus.textContent = [state.archived ? 'Archived' : '', state.deleted ? 'Deleted' : '', state.muted ? 'Muted' : ''].filter(Boolean).join(' · ');
      threadOptions.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }

    function memberStatus(message, isError) {
      memberStatusNode.textContent = message || '';
      memberStatusNode.classList.toggle('is-error', !!isError);
    }

    function manageThread(operation, fields) {
      if (!currentThread) return Promise.resolve();
      memberStatus('', false);
      return postPortalAction('eum_dm_popup_manage', 'nonce', EUMPortalShell.messageNonce, Object.assign({
        thread_id: activeThreadId,
        operation: operation
      }, fields || {})).then(function (data) {
        memberStatus(data.message || 'Chat updated.', false);
        if (data.actor_removed) {
          showInboxPane();
          loadMessenger();
          return;
        }
        return openThread(activeThreadId);
      }).catch(function (error) {
        memberStatus(error.message || 'The chat could not be updated.', true);
        return null;
      });
    }

    function updatePersonalState(operation) {
      if (!currentThread) return;
      var status = messenger.querySelector('[data-eum-chat-state-status]');
      status.textContent = 'Saving…';
      postPortalAction('eum_dm_popup_personal_state', 'nonce', EUMPortalShell.messageNonce, {
        thread_id: activeThreadId,
        target_user_id: inboxUserId,
        operation: operation
      }).then(function (data) {
        currentThread.personal_state = data.state || {};
        updateChatOptions(currentThread);
        if (operation === 'archive' || operation === 'delete') {
          showInboxPane();
          loadMessenger();
        } else if (operation === 'restore') loadMessenger(activeThreadId);
      }).catch(function (error) {
        status.textContent = error.message || 'Chat settings could not be updated.';
      });
    }

    function audiencePath(id) {
      return (audienceTree.job_paths || []).find(function (path) { return path.id === id; }) || null;
    }

    function audiencePathDescendants(id) {
      var result = [];
      var pending = [id];
      while (pending.length) {
        var current = pending.pop();
        result.push(current);
        (audienceTree.job_paths || []).forEach(function (path) {
          if (path.parent_id === current) pending.push(path.id);
        });
      }
      return result;
    }

    function renderAudienceSelection(summary) {
      summary.replaceChildren();
      selectedAudience.forEach(function (value) {
        var isGroup = value.indexOf('group:') === 0;
        var target = isGroup
          ? (audienceTree.groups || []).find(function (group) { return group.value === value; })
          : (audienceTree.job_paths || []).find(function (path) { return path.value === value; });
        if (!target) return;
        var selectedLabel = (isGroup ? 'Group: ' : 'Job Path: ') + target.label;
        var chip = document.createElement('span');
        chip.className = 'eum-messenger__chip';
        chip.appendChild(document.createTextNode(selectedLabel));
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label', 'Remove ' + selectedLabel);
        remove.addEventListener('click', function () {
          selectedAudience.delete(value);
          renderAudienceOptions();
        });
        chip.appendChild(remove);
        summary.appendChild(chip);
      });
      if (!summary.childElementCount) {
        var hint = document.createElement('span');
        hint.textContent = 'No groups or job paths selected.';
        summary.appendChild(hint);
      }
    }

    function renderAudienceOptions() {
      audienceOptions.replaceChildren();
      var selected = document.createElement('div');
      selected.className = 'eum-messenger__audience-selected';
      renderAudienceSelection(selected);
      audienceOptions.appendChild(selected);

      var toolbar = document.createElement('div');
      toolbar.className = 'eum-messenger__tree-toolbar';
      var back = document.createElement('button');
      back.type = 'button';
      back.textContent = '← Back';
      back.hidden = !audienceCategory || (audienceCategory === 'job_paths' && audiencePathStack.length === 0);
      back.addEventListener('click', function () {
        if (audienceCategory === 'job_paths' && audiencePathStack.length) audiencePathStack.pop();
        else { audienceCategory = ''; audiencePathStack = []; }
        renderAudienceOptions();
      });
      var crumb = document.createElement('strong');
      var currentPath = audiencePathStack.length ? audiencePath(audiencePathStack[audiencePathStack.length - 1]) : null;
      crumb.textContent = !audienceCategory ? 'Choose an audience type' : (audienceCategory === 'groups' ? 'Groups' : (currentPath ? currentPath.label : 'Job Paths'));
      toolbar.appendChild(back);
      toolbar.appendChild(crumb);
      audienceOptions.appendChild(toolbar);

      var list = document.createElement('div');
      list.className = 'eum-messenger__tree-list';
      if (!audienceCategory) {
        [
          { key: 'groups', label: 'Groups', count: (audienceTree.groups || []).length },
          { key: 'job_paths', label: 'Job Paths', count: (audienceTree.job_paths || []).length }
        ].forEach(function (category) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'eum-messenger__tree-category';
          button.textContent = category.label + ' (' + category.count + ')  ›';
          button.disabled = category.count === 0;
          button.addEventListener('click', function () {
            audienceCategory = category.key;
            audiencePathStack = [];
            renderAudienceOptions();
          });
          list.appendChild(button);
        });
      } else if (audienceCategory === 'groups') {
        (audienceTree.groups || []).forEach(function (group) {
          var row = document.createElement('label');
          row.className = 'eum-messenger__tree-row';
          var checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.value = group.value;
          checkbox.checked = selectedAudience.has(group.value);
          checkbox.addEventListener('change', function () {
            if (checkbox.checked) selectedAudience.add(group.value); else selectedAudience.delete(group.value);
            renderAudienceOptions();
          });
          var name = document.createElement('span');
          name.textContent = group.label;
          row.appendChild(checkbox);
          row.appendChild(name);
          list.appendChild(row);
        });
      } else {
        var parentId = audiencePathStack.length ? audiencePathStack[audiencePathStack.length - 1] : 0;
        var childPaths = (audienceTree.job_paths || []).filter(function (path) { return path.parent_id === parentId; });
        childPaths.forEach(function (path) {
          var row = document.createElement('div');
          row.className = 'eum-messenger__tree-row';
          var label = document.createElement('label');
          var checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.value = path.value;
          checkbox.checked = selectedAudience.has(path.value);
          checkbox.addEventListener('change', function () {
            if (checkbox.checked) {
              var descendants = audiencePathDescendants(path.id);
              descendants.forEach(function (id) { selectedAudience.delete('jobpath:' + id); });
              var ancestorId = path.parent_id;
              while (ancestorId) {
                selectedAudience.delete('jobpath:' + ancestorId);
                var ancestor = audiencePath(ancestorId);
                ancestorId = ancestor ? ancestor.parent_id : 0;
              }
              selectedAudience.add(path.value);
            } else selectedAudience.delete(path.value);
            renderAudienceOptions();
          });
          var name = document.createElement('span');
          name.textContent = path.label;
          label.appendChild(checkbox);
          label.appendChild(name);
          row.appendChild(label);
          if ((audienceTree.job_paths || []).some(function (child) { return child.parent_id === path.id; })) {
            var openPath = document.createElement('button');
            openPath.type = 'button';
            openPath.className = 'eum-messenger__tree-open';
            openPath.textContent = '›';
            openPath.setAttribute('aria-label', 'Open ' + path.label);
            openPath.addEventListener('click', function () { audiencePathStack.push(path.id); renderAudienceOptions(); });
            row.appendChild(openPath);
          }
          list.appendChild(row);
        });
      }
      if (!list.childElementCount) {
        var empty = document.createElement('p');
        empty.className = 'eum-messenger__loading';
        empty.textContent = 'No managed groups or job paths are available here.';
        list.appendChild(empty);
      }
      audienceOptions.appendChild(list);
    }

    function updateComposeMode() {
      var isGroup = recipientMode && recipientMode.value === 'group';
      recipientField.hidden = isGroup;
      audienceField.hidden = !isGroup;
      announcementOption.hidden = !isGroup || !canAnnounce;
      if (!isGroup && announcementCheckbox) announcementCheckbox.checked = false;
    }

    function renderContactChips() {
      contactChips.replaceChildren();
      selectedContacts.forEach(function (contact, id) {
        var chip = document.createElement('span');
        chip.className = 'eum-messenger__chip';
        chip.appendChild(document.createTextNode(contact.name));
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label', 'Remove ' + contact.name);
        remove.addEventListener('click', function () {
          selectedContacts.delete(id);
          renderContactChips();
        });
        chip.appendChild(remove);
        contactChips.appendChild(chip);
      });
    }

    function findContacts(query) {
      addUserSearchResults(query, contactResults, selectedContacts, 'eum_dm_popup_contacts', {
        excluded_user_ids: Array.from(createExcludedUsers.keys()),
        excluded_statuses: statusValues('[data-eum-create-rule-status]')
      }, function () {
        renderContactChips();
        contactSearch.value = '';
      });
    }

    function findCreateExclusions(query) {
      addUserSearchResults(query, createExcludeResults, createExcludedUsers, 'eum_dm_popup_contacts', {}, function () {
        renderUserChips(createExcludeChips, createExcludedUsers, '', null);
        if (contactSearch.value.trim()) findContacts(contactSearch.value.trim());
      });
    }

    function findMembersToAdd(query) {
      if (!currentThread) return;
      addUserSearchResults(query, memberAddResults, memberAddSelections, 'eum_dm_popup_member_search', {
        thread_id: activeThreadId,
        purpose: 'add'
      }, function () { renderUserChips(memberAddChips, memberAddSelections, '', null); }, (currentThread.members || []).map(function (person) { return person.id; }));
    }

    function findThreadExclusions(query) {
      if (!currentThread) return;
      addUserSearchResults(query, excludeResults, threadExcludedUsers, 'eum_dm_popup_member_search', {
        thread_id: activeThreadId,
        purpose: 'exclude'
      }, function () { renderUserChips(excludeChips, threadExcludedUsers, '', null); });
    }

    messenger.querySelector('[data-eum-compose-open]').addEventListener('click', function () {
      activeThreadId = 0;
      emptyPane.hidden = true;
      conversation.hidden = true;
      composer.hidden = false;
      messenger.classList.add('is-conversation-open');
      updateComposeMode();
      if (recipientMode) recipientMode.focus();
    });
    messenger.querySelector('[data-eum-compose-cancel]').addEventListener('click', showInboxPane);
    messenger.querySelector('[data-eum-conversation-back]').addEventListener('click', showInboxPane);
    if (showHiddenButton) showHiddenButton.addEventListener('click', function () {
      includeHiddenChats = !includeHiddenChats;
      showInboxPane();
      loadMessenger();
    });
    messenger.querySelector('[data-eum-members-open]').addEventListener('click', function () {
      var open = membersPanel.hidden;
      membersPanel.hidden = !open;
      messenger.querySelector('[data-eum-members-open]').setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) messenger.querySelector('[data-eum-members-close]').focus();
    });
    messenger.querySelector('[data-eum-members-close]').addEventListener('click', function () {
      membersPanel.hidden = true;
      messenger.querySelector('[data-eum-members-open]').setAttribute('aria-expanded', 'false');
      messenger.querySelector('[data-eum-members-open]').focus();
    });
    messenger.querySelector('[data-eum-thread-options-toggle]').addEventListener('click', function () {
      var open = threadOptions.hidden;
      threadOptions.hidden = !open;
      messenger.querySelector('[data-eum-thread-options-toggle]').setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    messenger.querySelector('[data-eum-chat-archive]').addEventListener('click', function () { updatePersonalState('archive'); });
    messenger.querySelector('[data-eum-chat-delete]').addEventListener('click', function () { updatePersonalState('delete'); });
    messenger.querySelector('[data-eum-chat-mute]').addEventListener('click', function () { updatePersonalState('mute'); });
    messenger.querySelector('[data-eum-chat-restore]').addEventListener('click', function () { updatePersonalState('restore'); });
    messenger.querySelector('[data-eum-member-add]').addEventListener('click', function () {
      manageThread('add_members', { user_ids: Array.from(memberAddSelections.keys()) }).then(function (data) {
        if (data) {
          memberAddSelections.clear();
          renderUserChips(memberAddChips, memberAddSelections, '', null);
        }
      });
    });
    messenger.querySelector('[data-eum-admin-save]').addEventListener('click', function () {
      var adminIds = Array.prototype.map.call(adminOptions.querySelectorAll('[data-eum-admin-id]:checked'), function (input) { return input.value; });
      manageThread('save_admins', { admin_ids: adminIds });
    });
    messenger.querySelector('[data-eum-rules-save]').addEventListener('click', function () {
      manageThread('save_rules', {
        excluded_user_ids: Array.from(threadExcludedUsers.keys()),
        excluded_statuses: statusValues('[data-eum-rule-status]')
      });
    });
    if (chatImageForm) chatImageForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!currentThread || !chatImageInput.files || !chatImageInput.files[0]) {
        memberStatus('Choose an image to upload.', true);
        return;
      }
      var data = new FormData();
      data.append('action', 'eum_dm_popup_avatar');
      data.append('nonce', EUMPortalShell.messageNonce);
      data.append('thread_id', String(activeThreadId));
      data.append('chat_image', chatImageInput.files[0]);
      memberStatus('Uploading group image…', false);
      window.fetch(EUMPortalShell.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || !payload.success) throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'The image could not be uploaded.');
          return payload.data || {};
        });
      }).then(function () {
        chatImageInput.value = '';
        return openThread(activeThreadId);
      }).catch(function (error) { memberStatus(error.message || 'The image could not be uploaded.', true); });
    });
    messenger.querySelector('[data-eum-chat-image-reset]').addEventListener('click', function () { manageThread('remove_avatar'); });
    messenger.querySelector('[data-eum-popup-close]').addEventListener('click', function () {
      setOpen(messengerWrap, false);
      messengerWrap.querySelector('button[aria-expanded]').focus();
    });
    if (teamSelect) teamSelect.addEventListener('change', function () {
      inboxUserId = parseInt(teamSelect.value, 10) || 0;
      showInboxPane();
      loadMessenger();
    });
    if (contactSearch) {
      contactSearch.addEventListener('focus', function () { findContacts(contactSearch.value.trim()); });
      contactSearch.addEventListener('input', function () {
        window.clearTimeout(contactsTimer);
        contactsTimer = window.setTimeout(function () { findContacts(contactSearch.value.trim()); }, 180);
      });
      document.addEventListener('click', function (event) {
        if (!event.target.closest('.eum-messenger__field')) {
          contactResults.hidden = true;
          createExcludeResults.hidden = true;
          memberAddResults.hidden = true;
          excludeResults.hidden = true;
        }
      });
    }
    if (createExcludeSearch) {
      createExcludeSearch.addEventListener('focus', function () { findCreateExclusions(createExcludeSearch.value.trim()); });
      createExcludeSearch.addEventListener('input', function () {
        window.clearTimeout(contactsTimer);
        contactsTimer = window.setTimeout(function () { findCreateExclusions(createExcludeSearch.value.trim()); }, 180);
      });
    }
    if (memberAddSearch) {
      memberAddSearch.addEventListener('focus', function () { findMembersToAdd(memberAddSearch.value.trim()); });
      memberAddSearch.addEventListener('input', function () {
        window.clearTimeout(contactsTimer);
        contactsTimer = window.setTimeout(function () { findMembersToAdd(memberAddSearch.value.trim()); }, 180);
      });
    }
    if (excludeSearch) {
      excludeSearch.addEventListener('focus', function () { findThreadExclusions(excludeSearch.value.trim()); });
      excludeSearch.addEventListener('input', function () {
        window.clearTimeout(contactsTimer);
        contactsTimer = window.setTimeout(function () { findThreadExclusions(excludeSearch.value.trim()); }, 180);
      });
    }
    messenger.querySelectorAll('[data-eum-create-rule-status]').forEach(function (input) {
      input.addEventListener('change', function () {
        createExcludedStatuses = statusValues('[data-eum-create-rule-status]');
        if (contactSearch.value.trim()) findContacts(contactSearch.value.trim());
      });
    });
    if (recipientMode) recipientMode.addEventListener('change', updateComposeMode);
    if (replyForm) replyForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var textarea = replyForm.querySelector('textarea[name="message"]');
      var send = replyForm.querySelector('button[type="submit"]');
      send.disabled = true;
      postPortalAction('eum_dm_popup_reply', 'nonce', EUMPortalShell.messageNonce, {
        thread_id: activeThreadId,
        message: textarea.value
      }).then(function () {
        textarea.value = '';
        replyError.hidden = true;
        replyError.textContent = '';
        loadMessenger(activeThreadId);
      }).catch(function (error) {
        replyError.textContent = error.message || 'Message could not be sent.';
        replyError.hidden = false;
      }).finally(function () { send.disabled = false; });
    });
    if (newMessageForm) newMessageForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var errorNode = messenger.querySelector('[data-eum-compose-error]');
      errorNode.textContent = '';
      var submit = newMessageForm.querySelector('button[type="submit"]');
      submit.disabled = true;
      createExcludedStatuses = statusValues('[data-eum-create-rule-status]');
      postPortalAction('eum_dm_popup_send', 'nonce', EUMPortalShell.messageNonce, {
        recipient_mode: recipientMode.value,
        announcement: announcementCheckbox && announcementCheckbox.checked ? '1' : '',
        subject: newMessageForm.querySelector('input[name="subject"]').value,
        message: newMessageForm.querySelector('textarea[name="message"]').value,
        recipient_ids: Array.from(selectedContacts.keys()),
        audience_targets: Array.from(selectedAudience),
        excluded_user_ids: Array.from(createExcludedUsers.keys()),
        excluded_statuses: createExcludedStatuses
      }).then(function (data) {
        newMessageForm.reset();
        selectedContacts.clear();
        createExcludedUsers.clear();
        createExcludedStatuses = [];
        selectedAudience.clear();
        audienceCategory = '';
        audiencePathStack = [];
        renderContactChips();
        renderUserChips(createExcludeChips, createExcludedUsers, '', null);
        renderAudienceOptions();
        updateComposeMode();
        inboxUserId = 0;
        loadMessenger(data.thread_id);
      }).catch(function (error) {
        errorNode.textContent = error.message || 'Message could not be sent.';
      }).finally(function () { submit.disabled = false; });
    });

    var messengerButton = messengerWrap.querySelector('button[aria-expanded]');
    if (messengerButton) messengerButton.addEventListener('click', function () {
      if (messengerButton.getAttribute('aria-expanded') === 'true') {
        var initialInbox = parseInt(messengerWrap.getAttribute('data-eum-initial-inbox') || '0', 10) || 0;
        var initialThread = parseInt(messengerWrap.getAttribute('data-eum-initial-thread') || '0', 10) || 0;
        if (initialInbox > 0) inboxUserId = initialInbox;
        messengerWrap.removeAttribute('data-eum-initial-inbox');
        messengerWrap.removeAttribute('data-eum-initial-thread');
        showInboxPane();
        loadMessenger(initialThread || undefined);
      }
    });
  }

  var popupUrl = new URL(window.location.href);
  if (popupUrl.searchParams.get('eum_open_notifications') === '1' && notificationWrap) {
    var notificationButtonForDeepLink = notificationWrap.querySelector('button[aria-expanded]');
    if (notificationButtonForDeepLink) notificationButtonForDeepLink.click();
    popupUrl.searchParams.delete('eum_open_notifications');
  }
  if (popupUrl.searchParams.get('eum_open_messages') === '1' && messengerWrap) {
    messengerWrap.setAttribute('data-eum-initial-inbox', popupUrl.searchParams.get('eum_dm_inbox_user') || '0');
    messengerWrap.setAttribute('data-eum-initial-thread', popupUrl.searchParams.get('eum_dm_thread') || '0');
    var messageButtonForDeepLink = messengerWrap.querySelector('button[aria-expanded]');
    if (messageButtonForDeepLink) messageButtonForDeepLink.click();
    popupUrl.searchParams.delete('eum_open_messages');
    popupUrl.searchParams.delete('eum_dm_inbox_user');
    popupUrl.searchParams.delete('eum_dm_thread');
  }
  if (window.location.search !== popupUrl.search) {
    window.history.replaceState({}, '', popupUrl.pathname + popupUrl.search + popupUrl.hash);
  }

  var search = document.querySelector('.eum-portal-shell__search');
  if (!search) return;

  var input = search.querySelector('input[type="search"]');
  var results = search.querySelector('.eum-portal-shell__link-results');
  if (!input || !results) return;

  var timer = null;
  var controller = null;
  var requestNumber = 0;
  var currentView = 'bookmarks';

  function setSearchOpen(open) {
    input.setAttribute('aria-expanded', open ? 'true' : 'false');
    results.setAttribute('aria-hidden', open ? 'false' : 'true');
    search.classList.toggle('is-open', open);
  }

  function showMessage(message, view) {
    results.replaceChildren();
    if (view === 'bookmarks') {
      var heading = document.createElement('p');
      heading.className = 'eum-portal-shell__link-results-heading';
      heading.textContent = 'Bookmarks';
      results.appendChild(heading);
    }
    var messageNode = document.createElement('p');
    messageNode.className = 'eum-portal-shell__empty';
    messageNode.textContent = message;
    results.appendChild(messageNode);
  }

  function renderItems(items, view) {
    results.replaceChildren();
    currentView = view === 'bookmarks' ? 'bookmarks' : 'search';
    if (!items.length) {
      showMessage(currentView === 'bookmarks' ? 'No bookmarks yet. Star a search result to save it.' : 'No matching links were found.', currentView);
      return;
    }
    if (currentView === 'bookmarks') {
      var heading = document.createElement('p');
      heading.className = 'eum-portal-shell__link-results-heading';
      heading.textContent = 'Bookmarks';
      results.appendChild(heading);
    }

    var list = document.createElement('div');
    list.className = 'eum-portal-shell__link-list';
    items.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'eum-portal-shell__link-row';
      var link = document.createElement('a');
      link.className = 'eum-portal-shell__link-result';
      link.href = item.url;
      if (item.target === '_blank') {
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
      }

      var title = document.createElement('span');
      title.className = 'eum-portal-shell__link-title';
      title.textContent = item.title || 'Untitled link';
      link.appendChild(title);

      var detailText = item.header || item.description || '';
      if (detailText) {
        var detail = document.createElement('span');
        detail.className = 'eum-portal-shell__link-detail';
        detail.textContent = detailText;
        link.appendChild(detail);
      }

      link.addEventListener('click', function () {
        setSearchOpen(false);
      });

      var bookmark = document.createElement('button');
      bookmark.type = 'button';
      bookmark.className = 'eum-portal-shell__link-bookmark';
      bookmark.setAttribute('aria-pressed', item.bookmarked ? 'true' : 'false');
      bookmark.setAttribute('aria-label', item.bookmarked ? 'Remove bookmark for ' + (item.title || 'link') : 'Bookmark ' + (item.title || 'link'));
      bookmark.title = item.bookmarked ? 'Remove bookmark' : 'Bookmark this link';
      bookmark.textContent = item.bookmarked ? '★' : '☆';
      bookmark.addEventListener('click', function () {
        if (!window.EUMPortalShell || !EUMPortalShell.ajaxUrl || !EUMPortalShell.searchNonce) return;
        bookmark.disabled = true;
        var desired = !item.bookmarked;
        var body = new URLSearchParams();
        body.set('action', 'eum_portal_link_bookmark');
        body.set('nonce', EUMPortalShell.searchNonce);
        body.set('link_id', String(item.id || 0));
        body.set('bookmarked', desired ? '1' : '0');
        window.fetch(EUMPortalShell.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString()
        }).then(function (response) {
          if (!response.ok) throw new Error('Bookmark update failed');
          return response.json();
        }).then(function (payload) {
          if (!payload || !payload.success) throw new Error('Bookmark update failed');
          item.bookmarked = desired;
          if (currentView === 'bookmarks' && !desired) {
            row.remove();
            if (!list.querySelector('.eum-portal-shell__link-row')) showMessage('No bookmarks yet. Star a search result to save it.', 'bookmarks');
            return;
          }
          bookmark.setAttribute('aria-pressed', desired ? 'true' : 'false');
          bookmark.setAttribute('aria-label', desired ? 'Remove bookmark for ' + (item.title || 'link') : 'Bookmark ' + (item.title || 'link'));
          bookmark.title = desired ? 'Remove bookmark' : 'Bookmark this link';
          bookmark.textContent = desired ? '★' : '☆';
        }).catch(function () {
          bookmark.title = 'Could not update bookmark. Try again.';
          bookmark.setAttribute('aria-label', 'Could not update bookmark for ' + (item.title || 'link') + '. Try again.');
        }).finally(function () {
          bookmark.disabled = false;
        });
      });

      row.appendChild(link);
      row.appendChild(bookmark);
      list.appendChild(row);
    });
    results.appendChild(list);
  }

  function loadResults(query) {
    requestNumber += 1;
    var currentRequest = requestNumber;
    window.clearTimeout(timer);
    if (controller) controller.abort();
    controller = null;

    setSearchOpen(true);
    showMessage(query ? 'Searching links…' : 'Loading bookmarks…', query ? 'search' : 'bookmarks');
    timer = window.setTimeout(function () {
      if (!window.EUMPortalShell || !EUMPortalShell.ajaxUrl || !EUMPortalShell.searchNonce) {
        showMessage('Link search is unavailable right now.');
        return;
      }

      controller = window.AbortController ? new AbortController() : null;
      var body = new URLSearchParams();
      body.set('action', 'eum_portal_link_search');
      body.set('nonce', EUMPortalShell.searchNonce);
      body.set('q', query);

      window.fetch(EUMPortalShell.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        signal: controller ? controller.signal : undefined
      }).then(function (response) {
        if (!response.ok) throw new Error('Search request failed');
        return response.json();
      }).then(function (payload) {
        if (currentRequest !== requestNumber) return;
        if (!payload || !payload.success || !payload.data || !Array.isArray(payload.data.items)) {
          throw new Error('Search response was invalid');
        }
        renderItems(payload.data.items, payload.data.view);
      }).catch(function (error) {
        if (error && error.name === 'AbortError') return;
        if (currentRequest === requestNumber) showMessage('Link search is unavailable right now.');
      });
    }, query ? 180 : 0);
  }

  input.addEventListener('input', function () {
    loadResults(input.value.trim());
  });

  input.addEventListener('focus', function () {
    loadResults(input.value.trim());
  });

  input.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      setSearchOpen(false);
      return;
    }
    if (event.key === 'ArrowDown' || event.key === 'Enter') {
      var firstLink = results.querySelector('.eum-portal-shell__link-result');
      if (firstLink && results.getAttribute('aria-hidden') === 'false') {
        event.preventDefault();
        if (event.key === 'Enter') firstLink.click();
        else firstLink.focus();
      }
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && results.getAttribute('aria-hidden') === 'false') {
      setSearchOpen(false);
      input.focus();
    }
  });

  search.addEventListener('focusout', function (event) {
    if (!search.contains(event.relatedTarget)) setSearchOpen(false);
  });

  document.addEventListener('click', function (event) {
    if (!search.contains(event.target)) setSearchOpen(false);
  });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}());
