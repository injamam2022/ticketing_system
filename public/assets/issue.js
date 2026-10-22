'use strict';
const config = window.ISSUE_PAGE;
const form = document.querySelector('#issue-form');
const description = document.querySelector('#issue-description');
let savedForm = JSON.stringify(Object.fromEntries(new FormData(form)));
let leaving = false;
const dirty = () => JSON.stringify(Object.fromEntries(new FormData(form))) !== savedForm;
const escapeHTML = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
function resizeDescription() {
  for (const field of [description, document.querySelector('#issue-title')]) {
    field.style.height = 'auto'; field.style.height = field.scrollHeight + 'px';
  }
}
description.addEventListener('input', resizeDescription);
document.querySelector('#issue-title').addEventListener('input', resizeDescription);
window.addEventListener('resize', resizeDescription);
resizeDescription();
window.addEventListener('beforeunload', event => { if (!leaving && (dirty() || window.hasDiscussionDrafts?.() || window.hasClientReplyDraft?.())) { event.preventDefault(); event.returnValue = ''; } });
function errorAt(id, error) { const el = document.querySelector(id); el.textContent = error.message; el.hidden = false; }
async function api(path, payload) {
  const response = await fetch(config.api + path, { method: payload === undefined ? 'GET' : 'POST', headers: { 'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-Token': config.csrf }, ...(payload === undefined ? {} : { body: JSON.stringify(payload) }) });
  const body = await response.json().catch(() => ({ error:'The request could not be completed. Please try again.' }));
  if (!response.ok) throw new Error(body.error || 'Unable to save changes.');
  return body;
}
form.addEventListener('submit', async event => {
  event.preventDefault(); const button = document.querySelector('#save-issue'); button.disabled = true;
  document.querySelector('#issue-error').hidden = true; document.querySelector('#issue-notice').hidden = true;
  const payload = Object.fromEntries(new FormData(form));
  try {
    await api(`issues/${config.id}`, payload); savedForm = JSON.stringify(payload);
    document.title = `${config.code} · ${payload.title} · JIS`;
    document.querySelector('#issue-status-badge').textContent = payload.status;
    document.querySelector('#saved-at').textContent = 'Just saved';
    const notice = document.querySelector('#issue-notice'); notice.textContent = 'Changes saved.'; notice.hidden = false;
    window.dispatchEvent(new Event('issue-saved'));
  } catch (error) { errorAt('#issue-error', error); } finally { button.disabled = false; }
});
let confirmDelete = false;
document.querySelector('#delete-issue').addEventListener('click', async event => {
  const button = event.currentTarget;
  if (!confirmDelete) { confirmDelete = true; document.querySelector('#delete-confirmation').hidden = false; button.textContent = 'Confirm delete'; return; }
  button.disabled = true;
  try { await api(`issues/${config.id}/delete`, {}); leaving = true; location.href = config.home; }
  catch (error) { errorAt('#issue-error', error); button.disabled = false; }
});
document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); if (!document.querySelector('#save-issue').disabled) form.requestSubmit(); } });
