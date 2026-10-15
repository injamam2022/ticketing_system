'use strict';
(() => {
  const section = document.querySelector('.client-conversation[data-feed]');
  if (!section) return;
  const list = section.querySelector('[data-message-list]');
  const error = section.querySelector('[data-conversation-error]');
  const form = section.querySelector('[data-client-reply]');
  let busy = false;
  let submitting = false;
  let stopped = false;
  let rendered = null;
  let version = 0;
  if (form) window.hasClientReplyDraft = () => !!form.querySelector('textarea').value.trim();

  function render(data) {
    if (rendered !== data.html) { list.innerHTML = data.html; rendered = data.html; }
    section.querySelector('[data-message-count]').textContent = data.count;
    document.querySelectorAll('[data-ticket-status-text]').forEach(el => { el.textContent = data.ticket.status; if (el.classList.contains('portal-status')) el.classList.toggle('resolved', data.ticket.closed); });
    document.querySelectorAll('[data-ticket-priority-text]').forEach(el => { el.textContent = data.ticket.priority; });
    document.querySelectorAll('[data-ticket-updated]').forEach(el => { el.textContent = data.ticket.updatedLabel; });
  }

  async function load() {
    if (busy || submitting || stopped || document.hidden) return;
    busy = true;
    const startedVersion = version;
    try {
      const response = await fetch(section.dataset.feed, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (startedVersion !== version) return;
      if (response.redirected || response.status === 401 || response.status === 404) {
        stopped = true;
        list.replaceChildren();
        error.textContent = 'Your session or ticket access has changed. Reload the page to continue.'; error.hidden = false;
        if (form) form.querySelector('[type=submit]').disabled = true;
        return;
      }
      if (!response.ok) throw new Error('Could not check for new replies. We will try again shortly.');
      const data = await response.json();
      if (startedVersion === version && !submitting) { render(data); error.hidden = true; }
    } catch (e) { error.textContent = e.message; error.hidden = false; }
    finally { busy = false; }
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault(); if (submitting || stopped) return;
    const textarea = form.querySelector('textarea');
    const button = form.querySelector('[type=submit]');
    const sentBody = textarea.value;
    const notice = section.querySelector('[data-conversation-notice]');
    version++; submitting = true; button.disabled = true; error.hidden = true; notice.hidden = true;
    try {
      const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': window.ISSUE_PAGE.csrf }, body: JSON.stringify({ body: sentBody }) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not send your reply.');
      render(data);
      if (textarea.value === sentBody) textarea.value = '';
      notice.textContent = 'Reply sent to the client.'; notice.hidden = false;
      document.querySelector('#saved-at').textContent = 'Last updated just now';
    } catch (e) { error.textContent = e.message; error.hidden = false; }
    finally { submitting = false; button.disabled = stopped; }
  });
  let timer = setInterval(load, 5000);
  window.addEventListener('focus', load);
  document.addEventListener('visibilitychange', load);
  window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
  window.addEventListener('pageshow', event => { if (event.persisted) { clearInterval(timer); timer = setInterval(load, 5000); load(); } });
  load();
})();
