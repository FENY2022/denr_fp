'use strict';
(function () {
  const root = document.querySelector('[data-fp-messenger]');
  const configNode = document.getElementById('fp-chat-config');
  if (!root || !configNode) return;

  let cfg;
  try { cfg = JSON.parse(configNode.textContent); } catch (_) { return; }

  const el = function (id) { return document.getElementById(id); };
  const fab = el('fpChatFab');
  const panel = el('fpChatPanel');
  const badge = el('fpChatBadge');
  const status = el('fpChatStatus');
  const home = el('fpChatHome');
  const newView = el('fpChatNewView');
  const groupView = el('fpChatGroupView');
  const threadView = el('fpChatThreadView');
  const conversationList = el('fpChatConversationList');
  const conversationSearch = el('fpChatConversationSearch');
  const userSearch = el('fpChatUserSearch');
  const userList = el('fpChatUserList');
  const groupSearch = el('fpChatGroupSearch');
  const groupUsers = el('fpChatGroupUsers');
  const groupName = el('fpChatGroupName');
  const selectedMembers = el('fpChatSelectedMembers');
  const createGroupBtn = el('fpChatCreateGroup');
  const messages = el('fpChatMessages');
  const messageBox = el('fpChatMessage');
  const form = el('fpChatForm');
  const sendBtn = el('fpChatSend');
  const fileInput = el('fpChatFile');
  const filePreview = el('fpChatFilePreview');
  const fileName = el('fpChatFileName');
  const typing = el('fpChatTyping');
  const threadName = el('fpChatThreadName');
  const threadAvatar = el('fpChatThreadAvatar');
  const threadMeta = el('fpChatThreadMeta');

  let conversations = [];
  let currentThread = null;
  let searchTimer = null;
  let typingTimer = null;
  let typingSent = false;
  let selected = new Map();
  let panelOpen = false;
  let loadingMessages = false;

  function setStatus(text, tone) {
    if (!status) return;
    status.textContent = text || '';
    status.className = 'fp-chat-status' + (text ? '' : ' d-none') + (tone ? ' is-' + tone : '');
  }

  function api(url, options) {
    const opts = options || {};
    opts.credentials = 'same-origin';
    opts.cache = 'no-store';
    opts.headers = Object.assign({'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, opts.headers || {});
    return fetch(url, opts).then(function (response) {
      return response.json().catch(function () { return {error:'Invalid server response.'}; }).then(function (payload) {
        if (!response.ok || (payload && payload.error)) throw new Error(payload.error || 'Request failed.');
        return payload;
      });
    });
  }

  function postForm(url, data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData) && data) {
      Object.keys(data).forEach(function (key) {
        const value = data[key];
        if (Array.isArray(value)) value.forEach(function (item) { fd.append(key + '[]', item); });
        else fd.append(key, value);
      });
    }
    fd.append('csrf_token', cfg.csrf);
    return api(url, {method:'POST', body:fd});
  }

  function initials(name) {
    return String(name || 'U').trim().split(/\s+/).slice(0,2).map(function (part) {
      return part.charAt(0).toUpperCase();
    }).join('') || 'U';
  }

  function formatWhen(value) {
    if (!value) return '';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return '';
    const now = new Date();
    if (parsed.toDateString() === now.toDateString()) {
      return parsed.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    }
    return parsed.toLocaleDateString([], {month:'short',day:'numeric'});
  }

  function formatBytes(bytes) {
    const n = Number(bytes || 0);
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function emptyState(container, icon, title, copy) {
    container.textContent = '';
    const box = document.createElement('div');
    box.className = 'fp-chat-empty';
    const i = document.createElement('i');
    i.className = 'bi ' + icon;
    const strong = document.createElement('strong');
    strong.textContent = title;
    const small = document.createElement('small');
    small.textContent = copy;
    box.append(i, strong, small);
    container.appendChild(box);
  }

  function show(view) {
    [home,newView,groupView,threadView].forEach(function (node) {
      if (node) node.classList.add('d-none');
    });
    if (view) view.classList.remove('d-none');
  }

  function setBadge(count) {
    const n = Math.max(0, Number(count || 0));
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.classList.toggle('d-none', n === 0);
  }

  function openPanel() {
    panelOpen = true;
    panel.classList.remove('d-none');
    fab.setAttribute('aria-expanded','true');
    fab.classList.add('is-open');
    show(home);
    loadConversations();
  }

  function closePanel() {
    panelOpen = false;
    panel.classList.add('d-none');
    fab.setAttribute('aria-expanded','false');
    fab.classList.remove('is-open');
    sendTyping(false);
  }

  function conversationButton(item) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fp-chat-conversation';
    button.dataset.threadId = String(item.id);

    const avatar = document.createElement('span');
    avatar.className = 'fp-chat-avatar';
    avatar.textContent = item.initials || initials(item.label);

    const body = document.createElement('span');
    body.className = 'fp-chat-conversation-body';
    const top = document.createElement('span');
    top.className = 'fp-chat-conversation-top';
    const name = document.createElement('strong');
    name.textContent = item.label;
    const time = document.createElement('small');
    time.textContent = formatWhen(item.last_time);
    top.append(name,time);
    const bottom = document.createElement('span');
    bottom.className = 'fp-chat-conversation-bottom';
    const preview = document.createElement('small');
    preview.textContent = item.preview || (item.type === 'group' ? 'Group conversation' : 'No messages yet');
    bottom.appendChild(preview);
    if (Number(item.unread || 0) > 0) {
      const unread = document.createElement('b');
      unread.textContent = Number(item.unread) > 99 ? '99+' : String(item.unread);
      bottom.appendChild(unread);
    }
    body.append(top,bottom);
    button.append(avatar,body);
    button.addEventListener('click', function () { openThread(Number(item.id), item.label, item.type); });
    return button;
  }

  function renderConversations() {
    const q = String(conversationSearch.value || '').trim().toLowerCase();
    const filtered = conversations.filter(function (item) {
      return !q || String(item.label || '').toLowerCase().includes(q) ||
        String(item.preview || '').toLowerCase().includes(q);
    });
    conversationList.textContent = '';
    if (!filtered.length) {
      emptyState(conversationList,'bi-chat-left-text','No conversations',q ? 'No matching conversation.' : 'Start a new message.');
      return;
    }
    filtered.forEach(function (item) { conversationList.appendChild(conversationButton(item)); });
  }

  function loadConversations(silent) {
    return api(cfg.conversations).then(function (data) {
      conversations = Array.isArray(data.conversations) ? data.conversations : [];
      setBadge(data.unread_total);
      renderConversations();
      if (!silent) setStatus('');
    }).catch(function (error) {
      if (!silent) setStatus(error.message,'danger');
    });
  }

  function personButton(person, onClick, checked) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fp-chat-person';
    const avatar = document.createElement('span');
    avatar.className = 'fp-chat-avatar';
    avatar.textContent = person.initials || initials(person.name);
    const body = document.createElement('span');
    body.className = 'fp-chat-person-body';
    const name = document.createElement('strong');
    name.textContent = person.name;
    const detail = document.createElement('small');
    detail.textContent = person.detail || (person.kind === 'applicant' ? 'Applicant' : 'DENR Personnel');
    body.append(name,detail);
    if (typeof checked === 'boolean') {
      const mark = document.createElement('span');
      mark.className = 'fp-chat-check';
      mark.innerHTML = checked ? '<i class="bi bi-check-circle-fill"></i>' : '<i class="bi bi-circle"></i>';
      button.append(avatar,body,mark);
    } else button.append(avatar,body);
    button.addEventListener('click', onClick);
    return button;
  }

  function searchPeople(input, container, groupMode) {
    const q = encodeURIComponent(String(input.value || '').trim());
    api(cfg.users + '?q=' + q).then(function (data) {
      const users = Array.isArray(data.users) ? data.users : [];
      container.textContent = '';
      if (!users.length) {
        emptyState(container,'bi-person-x','No users found','Try another name.');
        return;
      }
      users.forEach(function (person) {
        const token = person.kind + ':' + person.id;
        if (groupMode) {
          container.appendChild(personButton(person,function () {
            if (selected.has(token)) selected.delete(token); else selected.set(token,person);
            renderSelected();
            searchPeople(groupSearch,groupUsers,true);
          },selected.has(token)));
        } else {
          container.appendChild(personButton(person,function () {
            setStatus('Opening conversation...');
            postForm(cfg.direct,{target_kind:person.kind,target_id:person.id}).then(function (data) {
              setStatus('');
              if (data.thread) openThread(Number(data.thread.id),data.thread.label,data.thread.type);
            }).catch(function (error) { setStatus(error.message,'danger'); });
          }));
        }
      });
    }).catch(function (error) { setStatus(error.message,'danger'); });
  }

  function debouncePeople(input, container, groupMode) {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(function () { searchPeople(input,container,groupMode); },250);
  }

  function renderSelected() {
    selectedMembers.textContent = '';
    selected.forEach(function (person, token) {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'fp-chat-chip';
      chip.textContent = person.name + ' ×';
      chip.addEventListener('click',function () {
        selected.delete(token);
        renderSelected();
        searchPeople(groupSearch,groupUsers,true);
      });
      selectedMembers.appendChild(chip);
    });
  }

  function renderMessage(item) {
    const mine = item.sender_kind === cfg.kind && Number(item.sender_id) === Number(cfg.id);
    const row = document.createElement('div');
    row.className = 'fp-chat-message-row ' + (mine ? 'is-mine' : 'is-theirs');
    const bubble = document.createElement('div');
    bubble.className = 'fp-chat-bubble';
    if (!mine && currentThread && currentThread.type === 'group') {
      const sender = document.createElement('strong');
      sender.className = 'fp-chat-sender';
      sender.textContent = item.sender_name || 'User';
      bubble.appendChild(sender);
    }
    if (item.message) {
      const text = document.createElement('div');
      text.className = 'fp-chat-message-text';
      text.textContent = item.message;
      bubble.appendChild(text);
    }
    if (item.file) {
      const link = document.createElement('a');
      link.className = 'fp-chat-attachment';
      link.href = item.file.url;
      link.target = '_blank';
      link.rel = 'noopener';
      const icon = document.createElement('i');
      icon.className = String(item.file.mime || '').startsWith('image/') ? 'bi bi-file-image' : 'bi bi-file-earmark-pdf';
      const copy = document.createElement('span');
      const name = document.createElement('strong');
      name.textContent = item.file.name;
      const size = document.createElement('small');
      size.textContent = formatBytes(item.file.size);
      copy.append(name,size);
      link.append(icon,copy);
      bubble.appendChild(link);
    }
    const time = document.createElement('small');
    time.className = 'fp-chat-message-time';
    time.textContent = formatWhen(item.created_at);
    bubble.appendChild(time);
    row.appendChild(bubble);
    return row;
  }

  function loadMessages(silent) {
    if (!currentThread || loadingMessages) return Promise.resolve();
    loadingMessages = true;
    return api(cfg.messages + '?thread_id=' + encodeURIComponent(currentThread.id)).then(function (data) {
      if (!data.thread || Number(data.thread.id) !== Number(currentThread.id)) return;
      currentThread = data.thread;
      threadName.textContent = data.thread.label || 'Conversation';
      threadAvatar.textContent = initials(data.thread.label);
      threadMeta.textContent = data.thread.type === 'group' ? 'Group conversation' : 'Secure FP-OMS chat';
      messages.textContent = '';
      const list = Array.isArray(data.messages) ? data.messages : [];
      if (!list.length) emptyState(messages,'bi-chat-dots','No messages yet','Send a message to begin.');
      else list.forEach(function (item) { messages.appendChild(renderMessage(item)); });
      messages.scrollTop = messages.scrollHeight;
      loadConversations(true);
      if (!silent) setStatus('');
    }).catch(function (error) {
      if (!silent) setStatus(error.message,'danger');
    }).finally(function () { loadingMessages = false; });
  }

  function openThread(id, label, type) {
    currentThread = {id:id,label:label || 'Conversation',type:type || 'direct'};
    threadName.textContent = currentThread.label;
    threadAvatar.textContent = initials(currentThread.label);
    threadMeta.textContent = currentThread.type === 'group' ? 'Group conversation' : 'Secure FP-OMS chat';
    messages.textContent = '';
    show(threadView);
    loadMessages();
    window.setTimeout(function () { messageBox.focus(); },50);
  }

  function sendTyping(value) {
    if (!currentThread) return;
    const next = Boolean(value);
    if (next === typingSent) return;
    typingSent = next;
    postForm(cfg.typing,{thread_id:currentThread.id,is_typing:next ? '1' : '0'}).catch(function () {});
  }

  fab.addEventListener('click',function () { panelOpen ? closePanel() : openPanel(); });
  el('fpChatClose').addEventListener('click',closePanel);
  el('fpChatNew').addEventListener('click',function () {
    setStatus('');
    show(newView);
    userSearch.value = '';
    searchPeople(userSearch,userList,false);
    window.setTimeout(function () { userSearch.focus(); },50);
  });
  if (el('fpChatNewGroup')) {
    el('fpChatNewGroup').addEventListener('click',function () {
      setStatus('');
      selected.clear();
      renderSelected();
      groupName.value = '';
      groupSearch.value = '';
      show(groupView);
      searchPeople(groupSearch,groupUsers,true);
    });
  }

  document.querySelectorAll('[data-chat-back]').forEach(function (button) {
    button.addEventListener('click',function () {
      sendTyping(false);
      currentThread = null;
      show(home);
      loadConversations();
    });
  });

  conversationSearch.addEventListener('input',renderConversations);
  userSearch.addEventListener('input',function () { debouncePeople(userSearch,userList,false); });
  if (groupSearch) groupSearch.addEventListener('input',function () { debouncePeople(groupSearch,groupUsers,true); });

  if (createGroupBtn) {
    createGroupBtn.addEventListener('click',function () {
      const name = String(groupName.value || '').trim();
      if (!name) { setStatus('Enter a group name.','warning'); groupName.focus(); return; }
      if (!selected.size) { setStatus('Select at least one group member.','warning'); return; }
      createGroupBtn.disabled = true;
      createGroupBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';
      postForm(cfg.groupCreate,{name:name,members:Array.from(selected.keys())}).then(function (data) {
        selected.clear();
        if (data.thread) openThread(Number(data.thread.id),data.thread.label,data.thread.type);
        loadConversations(true);
      }).catch(function (error) {
        setStatus(error.message,'danger');
      }).finally(function () {
        createGroupBtn.disabled = false;
        createGroupBtn.innerHTML = '<i class="bi bi-people-fill me-1"></i>Create Group';
      });
    });
  }

  el('fpChatAttach').addEventListener('click',function () { fileInput.click(); });
  el('fpChatFileClear').addEventListener('click',function () {
    fileInput.value = '';
    filePreview.classList.add('d-none');
    fileName.textContent = '';
  });
  fileInput.addEventListener('change',function () {
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      filePreview.classList.add('d-none');
      return;
    }
    if (file.size > 25 * 1024 * 1024) {
      setStatus('Attachment must be 25 MB or smaller.','warning');
      fileInput.value = '';
      filePreview.classList.add('d-none');
      return;
    }
    fileName.textContent = file.name;
    filePreview.classList.remove('d-none');
  });

  messageBox.addEventListener('input',function () {
    messageBox.style.height = 'auto';
    messageBox.style.height = Math.min(messageBox.scrollHeight,110) + 'px';
    sendTyping(true);
    window.clearTimeout(typingTimer);
    typingTimer = window.setTimeout(function () { sendTyping(false); },1400);
  });

  form.addEventListener('submit',function (event) {
    event.preventDefault();
    if (!currentThread) return;
    const text = String(messageBox.value || '').trim();
    const file = fileInput.files && fileInput.files[0];
    if (!text && !file) return;
    sendTyping(false);
    const fd = new FormData();
    fd.append('thread_id',String(currentThread.id));
    fd.append('message',text);
    if (file) fd.append('file',file);
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    postForm(cfg.send,fd).then(function () {
      messageBox.value = '';
      messageBox.style.height = 'auto';
      fileInput.value = '';
      filePreview.classList.add('d-none');
      fileName.textContent = '';
      return loadMessages(true);
    }).catch(function (error) {
      setStatus(error.message,'danger');
    }).finally(function () {
      sendBtn.disabled = false;
      sendBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
    });
  });

  document.querySelectorAll('[data-chat-action]').forEach(function (button) {
    button.addEventListener('click',function () {
      if (!currentThread) return;
      const action = button.dataset.chatAction;
      const prompt = action === 'delete'
        ? 'Delete this conversation from your view? New messages can make it appear again.'
        : 'Archive this conversation?';
      if (!window.confirm(prompt)) return;
      postForm(cfg.threadAction,{thread_id:currentThread.id,action:action}).then(function () {
        currentThread = null;
        show(home);
        loadConversations();
      }).catch(function (error) { setStatus(error.message,'danger'); });
    });
  });

  document.addEventListener('fpoms:chat-message',function (event) {
    const detail = event.detail || {};
    loadConversations(true);
    if (panelOpen && currentThread && Number(detail.thread_id) === Number(currentThread.id)) {
      loadMessages(true);
    }
  });

  document.addEventListener('fpoms:chat-typing',function (event) {
    const detail = event.detail || {};
    if (!currentThread || Number(detail.thread_id) !== Number(currentThread.id)) return;
    typing.classList.toggle('d-none',!detail.is_typing);
    if (detail.is_typing) {
      window.clearTimeout(typingTimer);
      typingTimer = window.setTimeout(function () { typing.classList.add('d-none'); },2500);
    }
  });

  window.setInterval(function () {
    loadConversations(true);
    if (panelOpen && currentThread) loadMessages(true);
  },8000);

  loadConversations(true);
}());
