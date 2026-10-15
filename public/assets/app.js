'use strict';
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const paths = {
  plus:'M12 5v14M5 12h14', close:'m6 6 12 12M6 18 18 6', search:'m21 21-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
  inbox:'M4 4h16l2 11v5H2v-5L4 4ZM2 15h6l2 3h4l2-3h6', layers:'m12 3 10 5-10 5L2 8l10-5ZM2 12l10 5 10-5M2 16l10 5 10-5',
  cycle:'M20 7a9 9 0 1 0 1 9M20 2v6h-6M12 7v5l3 2', box:'m12 3 9 5v10l-9 4-9-4V8l9-5ZM3 8l9 4 9-4M12 12v10M7 5l9 5',
  help:'M9 9a3 3 0 1 1 5 2c-2 1-2 2-2 3M12 17h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  logout:'M9 4H4v16h5M9 12h12m-4-4 4 4-4 4', orbit:'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0M5 17 19 7M8 20 21 11',
  list:'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',board:'M3 4h5v16H3zM10 4h5v11h-5zM17 4h5v14h-5z',
  filter:'M4 5h16M7 12h10M10 19h4',sort:'M8 4v16m-4-4 4 4 4-4M15 5h6M15 10h4M15 15h2',
  menu:'M4 6h16M4 12h16M4 18h16',status:'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',priority:'M5 18v-4M12 18V9M19 18V4',
  user:'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0M4 21v-2a8 8 0 0 1 16 0v2',tag:'M3 3h8l10 10-8 8L3 11V3ZM7 7h.01',
  calendar:'M8 2v4M16 2v4M3 10h18M3 4h18v17H3z',trash:'M3 6h18M5 6l1 15h12l1-15M9 6V3h6v3M10 10v7M14 10v7',
  send:'m22 2-7 20-4-9-9-4 20-7ZM11 13 22 2',chevron:'m9 5 7 7-7 7',down:'m6 9 6 6 6-6',check:'m5 12 4 4L19 6',sparkles:'m12 3 3 6 6 3-6 3-3 6-3-6-6-3 6-3 3-6'
};
const icon = name => `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${paths[name] || paths.box}"/></svg>`;
function hydrateIcons(root = document) { $$('[data-icon]', root).forEach(el => { el.innerHTML = icon(el.dataset.icon); }); }
let statuses = [];
let statusDefinitions = [];
let statusColors = {};
const statusCategory = name => statusDefinitions.find(status => status.name === name)?.category;
const isClosed = name => ['done','canceled'].includes(statusCategory(name));
const priorities = ['No priority','Urgent','High','Medium','Low'];
const labelColors = {'Feature':'#ab92d5','Bug':'#d4838d','Design':'#78a7ce','Improvement':'#d0ac71'};
const state = {issues:[],projects:[],users:[],currentUser:1,view:'all',project:null,layout:localStorage.getItem('orbit-layout') === 'board' ? 'board' : 'list',search:'',category:'',status:'',priority:'',assignee:'',sort:'newest',editing:null,collapsed:new Set(),ready:false};
const initials = name => name.split(' ').map(word => word[0]).slice(0,2).join('');
const avatar = user => user ? `<span class="avatar" style="--avatar:${escapeHTML(user.color)}" title="${escapeHTML(user.name)}">${escapeHTML(initials(user.name))}</span>` : `<span class="avatar unassigned" title="Unassigned">${icon('user')}</span>`;
const statusIcon = status => `<span class="status-icon ${escapeHTML(statusCategory(status) === 'done' ? 'done' : statusCategory(status) === 'active' ? 'in-progress' : statusCategory(status) === 'review' ? 'in-review' : statusCategory(status) === 'backlog' ? 'backlog' : 'todo')}" style="--status:${escapeHTML(statusColors[status] || '#777283')}" title="${escapeHTML(status)}"></span>`;
const priorityIcon = priority => `<span class="priority-icon ${priority.toLowerCase().replaceAll(' ','-')}" title="${priority}">${priority === 'Urgent' ? '!' : priority === 'No priority' ? '–' : '<i></i><i></i><i></i>'}</span>`;
const label = value => value ? `<span class="issue-label" style="--label-color:${labelColors[value] || '#8f819e'}">${escapeHTML(value)}</span>` : '';
const userFor = id => state.users.find(user => Number(user.id) === Number(id));
const projectFor = id => state.projects.find(project => Number(project.id) === Number(id));
const code = id => `ORB-${String(id).padStart(3,'0')}`;
let toastTimer;
function toast(message) { const el = $('#toast'); el.textContent = message; el.classList.add('visible'); clearTimeout(toastTimer); toastTimer = setTimeout(() => el.classList.remove('visible'), 3800); }
async function api(path, data) {
  const response = await fetch(window.ORBIT.api + path, {method:data === undefined ? 'GET' : 'POST',credentials:'same-origin',headers:{'Accept':'application/json',...(data === undefined ? {} : {'Content-Type':'application/json','X-CSRF-Token':window.ORBIT.csrf})},...(data === undefined ? {} : {body:JSON.stringify(data)})});
  if (response.status === 401) { location.href = window.ORBIT.login; throw new Error('Please sign in again.'); }
  const body = await response.json().catch(() => ({error:'The server could not complete the request. Please try again.'}));
  if (!response.ok) throw new Error(body.error || 'Something went wrong. Please try again.');
  return body;
}
async function refresh() {
  const data = await api('workspace'); Object.assign(state, data);
  statusDefinitions = data.statuses; statuses = statusDefinitions.map(status => status.name);
  statusColors = Object.fromEntries(statusDefinitions.map(status => [status.name,status.color]));
  if (state.status && !statuses.includes(state.status)) { state.status = ''; state.category = ''; }
  $('#status-filter').innerHTML = '<option value="">All statuses</option>' + options(statuses,state.status);
  state.ready = true; renderNavigation(); render();
}
function renderNavigation() {
  $('#all-count').textContent = state.issues.length;
  $('#my-count').textContent = state.issues.filter(issue => Number(issue.assignee_id) === Number(state.currentUser) && !isClosed(issue.status)).length;
  $('#project-nav').innerHTML = state.projects.map(project => `<button class="nav-item ${state.view === 'project' && Number(state.project) === Number(project.id) ? 'active' : ''}" data-project="${project.id}"><span class="project-dot" style="background:${escapeHTML(project.color)}"></span><span class="project-nav-name">${escapeHTML(project.name)}</span></button>`).join('');
  $$('[data-view]').forEach(button => button.classList.toggle('active', button.dataset.view === state.view));
  const current = userFor(state.currentUser); $('#current-avatar').innerHTML = avatar(current); $('#current-name').textContent = current?.name || 'Your workspace';
  const selected = state.assignee;
  $('#assignee-filter').innerHTML = '<option value="">Everyone</option><option value="unassigned">Unassigned</option>' + state.users.map(user => `<option value="${user.id}">${escapeHTML(user.name)}</option>`).join(''); $('#assignee-filter').value = selected;
}
function scopedIssues() {
  return state.issues.filter(issue => state.view === 'mine' ? Number(issue.assignee_id) === Number(state.currentUser) : state.view === 'requests' ? !!issue.client_id : state.view === 'active' ? ['active','review'].includes(statusCategory(issue.status)) : state.view === 'project' ? Number(issue.project_id) === Number(state.project) : true);
}
function filteredIssues() {
  return scopedIssues().filter(issue => {
    const text = [issue.title,issue.description,code(issue.id),issue.label,issue.client_name,issue.client_company,projectFor(issue.project_id)?.name,userFor(issue.assignee_id)?.name].join(' ').toLowerCase();
    return (!state.category || statusCategory(issue.status) === state.category) && (!state.search || text.includes(state.search.toLowerCase())) && (!state.status || issue.status === state.status) && (!state.priority || issue.priority === state.priority) && (!state.assignee || (state.assignee === 'unassigned' ? !issue.assignee_id : Number(issue.assignee_id) === Number(state.assignee)));
  }).sort((a,b) => state.sort === 'priority' ? ((priorities.indexOf(a.priority) || 5) - (priorities.indexOf(b.priority) || 5) || b.id-a.id) : state.sort === 'title' ? a.title.localeCompare(b.title) : state.sort === 'due' ? (a.due_date || '9999').localeCompare(b.due_date || '9999') : b.id-a.id);
}
function navigate(view, project = null) {
  state.view = view; state.project = project; state.search = ''; state.status = ''; state.category = ''; state.priority = ''; state.assignee = ''; state.collapsed.clear();
  $('#issue-search').value = ''; $('#status-filter').value = ''; $('#priority-filter').value = ''; $('#assignee-filter').value = ''; $('#filter-panel').hidden = true;
  $('#sidebar').classList.remove('open'); renderNavigation(); render();
}
function renderSummary(issues) {
  const done = issues.filter(issue => statusCategory(issue.status) === 'done').length;
  const cards = [{name:'Total issues',value:issues.length,color:'#9b88bd',icon:'layers',note:'Ideas into action',filter:''},{name:'In progress',value:issues.filter(issue=>statusCategory(issue.status)==='active').length,color:'#dfbc6d',icon:'cycle',note:'Moving things forward',filter:'In progress'},{name:'In review',value:issues.filter(issue=>statusCategory(issue.status)==='review').length,color:'#a893db',icon:'search',note:'A fresh pair of eyes',filter:'In review'},{name:'Completed',value:done,color:'#80bca0',icon:'check',note:`${issues.length ? Math.round(done/issues.length*100) : 0}% of all issues`,filter:'Done'}];
  $('#summary-strip').innerHTML = cards.map(card => `<button class="summary-card" data-summary-category="${({ 'In progress':'active', 'In review':'review', 'Done':'done' })[card.filter] || ''}" style="--stat-color:${card.color}"><div class="summary-top"><span>${card.name}</span>${icon(card.icon)}</div><div class="summary-value"><strong>${card.value}</strong><small>${card.note}</small></div></button>`).join('');
}
function render() {
  const project = projectFor(state.project);
  const titles = {all:'All issues',requests:'Client requests',mine:'My issues',active:'Active work',projects:'Projects',project:project?.name};
  const descriptions = {all:'A little structure for your next big thing.',requests:'Requests from your clients, connected to their projects.',mine:'Your focus, your flow. Everything assigned to you.',active:'Keep the momentum going. Here’s what’s moving.',projects:'Big ideas, broken down into meaningful steps.',project:project?.description || 'A shared space to move this project forward.'};
  const scope = scopedIssues(); const issues = filteredIssues(); const isProjects = state.view === 'projects';
  document.title = `${titles[state.view]} · Orbit`; $('#breadcrumb-title').textContent = titles[state.view];
  $('#page-title').innerHTML = `${escapeHTML(titles[state.view])} <span>${isProjects ? state.projects.length : scope.length}</span>`;
  $('#page-description').textContent = descriptions[state.view]; $('#main-create-label').textContent = isProjects ? 'New project' : 'New issue';
  $('#view-toolbar').hidden = isProjects; $('#summary-strip').hidden = isProjects; if (isProjects) $('#filter-panel').hidden = true;
  renderSummary(scope); $$('[data-layout]').forEach(button => button.classList.toggle('active',button.dataset.layout === state.layout));
  $('#status-filter [data-category-filter]')?.remove();
  if (state.category) {
    const names = {backlog:'Backlog',todo:'To do',active:'In progress',review:'In review',done:'Completed',canceled:'Canceled'};
    $('#status-filter').insertAdjacentHTML('beforeend', `<option data-category-filter disabled selected>Category: ${names[state.category] || ''}</option>`);
  } else { $('#status-filter').value = state.status; }
  const count = [state.status,state.category,state.priority,state.assignee].filter(Boolean).length; $('#filter-count').textContent = count || ''; $('#filter-toggle').classList.toggle('active',count > 0);
  $('#result-count').textContent = isProjects ? `${state.projects.length} projects in your workspace` : `Showing ${issues.length} of ${scope.length} issues`;
  if (isProjects) { renderProjects(); return; }
  if (!issues.length) {
    $('#issue-content').innerHTML = `<div class="empty-state">${icon('sparkles')}<h2>${state.search || count ? 'No matching issues' : 'A little room for what’s next'}</h2><p>${state.search || count ? 'Try a different search or clear your filters to see more of your work.' : 'Every great project starts with one small step. Create your first issue.'}</p><button class="button primary" ${state.search || count ? 'data-reset' : 'data-new-issue'}>${state.search || count ? 'Clear search & filters' : 'Create an issue'}</button></div>`; return;
  }
  if (state.layout === 'board') renderBoard(issues); else renderList(issues);
}
function groupHeading(status, count, board = false) { return `<div class="group-heading">${board ? '' : `<button aria-label="${state.collapsed.has(status) ? 'Expand' : 'Collapse'} ${escapeHTML(status)}" data-collapse="${escapeHTML(status)}">${icon(state.collapsed.has(status) ? 'chevron' : 'down')}</button>`}${statusIcon(status)}<span>${escapeHTML(status)}</span><span class="group-count">${count}</span><button class="icon-button" data-new-status="${escapeHTML(status)}" aria-label="New ${escapeHTML(status)} issue">${icon('plus')}</button></div>`; }
function renderList(issues) {
  const order = statuses;
  $('#issue-content').innerHTML = order.filter(status => issues.some(issue => issue.status === status)).map(status => {
    const group = issues.filter(issue => issue.status === status);
    return `<section class="issue-group">${groupHeading(status,group.length)}${state.collapsed.has(status) ? '' : group.map(issue => {
      const project = projectFor(issue.project_id);
      return `<button class="issue-row" data-issue="${issue.id}" aria-label="${escapeHTML(code(issue.id) + ': ' + issue.title)}">${priorityIcon(issue.priority)}<span class="issue-id">${code(issue.id)}</span>${statusIcon(issue.status)}<span class="issue-name">${escapeHTML(issue.title)}</span>${issue.client_id ? '<span class="client-origin" title="Submitted by a client">Client</span>' : ''}${label(issue.label)}<span class="row-project">${project ? `<span class="project-dot" style="background:${escapeHTML(project.color)}"></span>${escapeHTML(project.name)}` : ''}</span>${issue.due_date ? `<span class="row-date">${new Date(issue.due_date + 'T12:00:00').toLocaleDateString(undefined,{month:'short',day:'numeric'})}</span>` : ''}${avatar(userFor(issue.assignee_id))}</button>`;
    }).join('')}</section>`;
  }).join('');
}
function renderBoard(issues) {
  $('#issue-content').innerHTML = `<div class="board-wrap"><div class="board">${statuses.map(status => `<section class="board-column" data-drop-status="${escapeHTML(status)}">${groupHeading(status,issues.filter(issue => issue.status === status).length,true)}${issues.filter(issue => issue.status === status).map(issue => `<article class="board-card" draggable="true" tabindex="0" role="button" data-issue="${issue.id}" aria-label="${escapeHTML(code(issue.id) + ': ' + issue.title)}"><div class="board-card-header"><span class="issue-id">${code(issue.id)}</span>${priorityIcon(issue.priority)}</div><h3>${escapeHTML(issue.title)}</h3><div class="board-card-footer">${label(issue.label) || '<span></span>'}${avatar(userFor(issue.assignee_id))}</div></article>`).join('')}<button class="board-add" data-new-status="${escapeHTML(status)}">${icon('plus')} Add issue</button></section>`).join('')}</div></div>`;
}
function renderProjects() {
  $('#issue-content').innerHTML = `<div class="projects-grid">${state.projects.map(project => { const issues = state.issues.filter(issue => Number(issue.project_id) === Number(project.id)); const done = issues.filter(issue => statusCategory(issue.status) === 'done').length; const percent = issues.length ? Math.round(done/issues.length*100) : 0; return `<button class="project-card" data-project="${project.id}" style="--project-color:${escapeHTML(project.color)}"><div class="project-card-icon">${icon('box')}</div><h2>${escapeHTML(project.name)}</h2><p>${escapeHTML(project.description || 'Your next big thing starts here.')}</p><div class="project-card-progress"><i style="width:${percent}%"></i></div><div class="project-card-stats"><span>${issues.length} issues · ${done} completed</span><span>${percent}%</span></div></button>`; }).join('')}</div>`;
}
function options(values, selected) { return values.map(value => `<option value="${escapeHTML(value)}" ${value === selected ? 'selected' : ''}>${escapeHTML(value)}</option>`).join(''); }
function openIssue(id = null, status = null) {
  if (!state.ready) return;
  status ??= statusDefinitions.find(item => item.system_key === 'Todo')?.name || statuses[0];
  if (id) {
    sessionStorage.setItem('orbit-return-view', JSON.stringify(Object.fromEntries(['view','project','layout','search','status','category','priority','assignee','sort'].map(key => [key,state[key]]))));
    location.href = `${window.ORBIT.issues}/${Number(id)}`;
    return;
  }
  const issue = id ? state.issues.find(issue => Number(issue.id) === Number(id)) : null;
  if (id && !issue) return;
  state.editing = issue?.id || null;
  $('#client-request-info')?.remove();
  if (issue?.client_id) {
    const info = document.createElement('div');
    info.id = 'client-request-info';
    info.className = 'client-request-info';
    info.innerHTML = `<span>Client request · ${escapeHTML(issue.client_name)} / ${escapeHTML(issue.client_company)}</span><a href="${escapeHTML(window.ORBIT.clients)}/tickets/${issue.id}">Open client conversation →</a>`;
    $('.issue-editor').prepend(info);
  }
  $('#issue-form').reset(); $('#issue-error').hidden = true; $('#issue-dialog-id').textContent = issue ? code(issue.id) : 'Workspace / New issue';
  $('#issue-title').value = issue?.title || ''; $('#issue-description').value = issue?.description || '';
  $('#edit-status').innerHTML = options(statuses, issue?.status || status); $('#edit-priority').innerHTML = options(priorities, issue?.priority || 'No priority');
  $('#edit-assignee').innerHTML = '<option value="">Unassigned</option>' + state.users.map(user => `<option value="${user.id}">${escapeHTML(user.name)}</option>`).join('');
  $('#edit-assignee').value = issue?.assignee_id || (state.view === 'mine' ? state.currentUser : '');
  $('#edit-project').innerHTML = '<option value="">No project</option>' + state.projects.map(project => `<option value="${project.id}">${escapeHTML(project.name)}</option>`).join('');
  $('#edit-project').value = issue?.project_id || (state.view === 'project' ? state.project : '');
  $('#edit-label').value = issue?.label || ''; $('#edit-due').value = issue?.due_date || '';
  $('#save-issue').textContent = issue ? 'Save changes' : 'Create issue'; $('#delete-issue').hidden = !issue; $('#delete-issue').dataset.confirm = ''; $('#delete-issue').innerHTML = icon('trash') + ' Delete issue';
  $('#activity-section').hidden = !issue; $('#comments-list').innerHTML = ''; $('#activity-count').textContent = ''; $('#comment-body').value = '';
  $('#issue-dialog').showModal(); if (!issue) $('#issue-title').focus();
  if (issue) loadComments(issue.id);
}
async function loadComments(id) {
  try { const data = await api(`issues/${id}/comments`); if (Number(state.editing) !== Number(id)) return;
    $('#activity-count').textContent = data.comments.length;
    $('#comments-list').innerHTML = data.comments.length ? data.comments.map(comment => `<div class="comment">${avatar(comment)}<div><strong>${escapeHTML(comment.name)}</strong><time>${new Date(comment.created_at).toLocaleDateString(undefined,{month:'short',day:'numeric'})}</time><p>${escapeHTML(comment.body)}</p></div></div>`).join('') : '<p class="no-comments">Start the conversation. Share an update or ask a question.</p>';
  } catch (error) { toast(error.message); }
}
function openProject() { $('#project-form').reset(); $('#project-error').hidden = true; $('#project-dialog').showModal(); }
function showError(selector, error) { const el = $(selector); el.textContent = error.message; el.hidden = false; }
async function saveIssue(event) {
  event.preventDefault(); const button = $('#save-issue'); button.disabled = true; $('#issue-error').hidden = true;
  const id = state.editing; const payload = Object.fromEntries(new FormData(event.target));
  let saved = false;
  try { await api(`issues${id ? '/' + id : ''}`,payload); saved = true; await refresh(); $('#issue-dialog').close(); toast(id ? 'Issue updated' : 'Issue created'); }
  catch (error) { if (saved) { $('#issue-dialog').close(); toast('Saved, but the list could not refresh. Reload this page.'); } else showError('#issue-error', error); }
  finally { button.disabled = false; }
}
async function deleteIssue() {
  const button = $('#delete-issue');
  if (button.dataset.confirm !== 'yes') { button.dataset.confirm = 'yes'; button.innerHTML = icon('trash') + ' Confirm delete'; return; }
  button.disabled = true;
  try { await api(`issues/${state.editing}/delete`,{}); $('#issue-dialog').close(); toast('Issue deleted'); await refresh(); }
  catch (error) { showError('#issue-error',error); } finally { button.disabled = false; }
}
document.addEventListener('click', event => {
  const target = event.target.closest('button,[data-issue]'); if (!target) return;
  if (target.hasAttribute('data-close')) target.closest('dialog').close();
  if (target.hasAttribute('data-new-issue')) openIssue();
  if (target.hasAttribute('data-new-status')) openIssue(null,target.dataset.newStatus);
  if (target.hasAttribute('data-issue')) openIssue(target.dataset.issue);
  if (target.hasAttribute('data-view')) navigate(target.dataset.view);
  if (target.hasAttribute('data-project')) navigate('project',Number(target.dataset.project));
  if (target.hasAttribute('data-layout')) { state.layout = target.dataset.layout; localStorage.setItem('orbit-layout',state.layout); render(); }
  if (target.hasAttribute('data-collapse')) { const status = target.dataset.collapse; state.collapsed.has(status) ? state.collapsed.delete(status) : state.collapsed.add(status); render(); }
  if (target.hasAttribute('data-summary-category')) { state.status = ''; state.category = target.dataset.summaryCategory; $('#status-filter').value = ''; $('#filter-panel').hidden = false; render(); }
  if (target.hasAttribute('data-reset')) { state.search = ''; $('#issue-search').value = ''; clearFilters(); }
});
function clearFilters() { state.status = ''; state.category = ''; state.priority = ''; state.assignee = ''; ['status','priority','assignee'].forEach(key => $(`#${key}-filter`).value = ''); render(); }
function focusSearch() { if (state.view === 'projects') navigate('all'); $('#sidebar').classList.remove('open'); $('#issue-search').focus(); }
$('#main-create').addEventListener('click',() => state.view === 'projects' ? openProject() : openIssue());
$('#sidebar-new-project').addEventListener('click',openProject);
$('#issue-form').addEventListener('submit',saveIssue); $('#delete-issue').addEventListener('click',deleteIssue);
$('#project-form').addEventListener('submit',async event => { event.preventDefault(); const button = $('button[type=submit]',event.target); button.disabled = true; try { const result = await api('projects',Object.fromEntries(new FormData(event.target))); $('#project-dialog').close(); await refresh(); navigate('project',result.id); toast('Project created'); } catch(error) { showError('#project-error',error); } finally { button.disabled = false; } });
$('#comment-form').addEventListener('submit',async event => { event.preventDefault(); const button = $('button',event.target); const id = state.editing; button.disabled = true; try { await api(`issues/${id}/comments`,{body:$('#comment-body').value}); if (state.editing === id) { $('#comment-body').value = ''; await loadComments(id); } toast('Comment added'); } catch(error) { toast(error.message); } finally { button.disabled = false; } });
$('#issue-search').addEventListener('input',event => { state.search = event.target.value; render(); });
$('#filter-toggle').addEventListener('click',() => { $('#filter-panel').hidden = !$('#filter-panel').hidden; });
$('#clear-filters').addEventListener('click',clearFilters);
['status','priority','assignee'].forEach(key => $(`#${key}-filter`).addEventListener('change',event => { state[key] = event.target.value; if (key === 'status') state.category = ''; render(); }));
$('#sort-order').addEventListener('change',event => { state.sort = event.target.value; render(); });
$('#search-trigger').addEventListener('click',focusSearch);
['#help-button','#top-help'].forEach(selector => $(selector).addEventListener('click',() => $('#help-dialog').showModal()));
$('#open-sidebar').addEventListener('click',() => $('#sidebar').classList.add('open'));
['#close-sidebar','#sidebar-scrim'].forEach(selector => $(selector).addEventListener('click',() => $('#sidebar').classList.remove('open')));
document.addEventListener('keydown',event => {
  if (event.target.matches('input,textarea,select,[contenteditable]') || event.ctrlKey || event.metaKey || event.altKey) return;
  if ($('dialog[open]')) return;
  if (event.target.matches('.board-card') && ['Enter',' '].includes(event.key)) { event.preventDefault(); openIssue(event.target.dataset.issue); return; }
  if (event.key.toLowerCase() === 'c') { event.preventDefault(); openIssue(); }
  if (event.key === '/') { event.preventDefault(); focusSearch(); }
  if (event.key.toLowerCase() === 'v') { state.layout = state.layout === 'list' ? 'board' : 'list'; localStorage.setItem('orbit-layout',state.layout); render(); }
  if (event.key === '?') $('#help-dialog').showModal();
});
let dragging = null;
document.addEventListener('dragstart',event => { const card = event.target.closest('.board-card'); if (!card) return; dragging = Number(card.dataset.issue); event.dataTransfer.setData('text/plain',String(dragging)); event.dataTransfer.effectAllowed = 'move'; card.classList.add('dragging'); });
document.addEventListener('dragend',() => { dragging = null; $$('.drag-over,.dragging').forEach(el => el.classList.remove('drag-over','dragging')); });
document.addEventListener('dragover',event => { const column = event.target.closest('[data-drop-status]'); if (column && dragging) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; $$('.drag-over').forEach(el => el.classList.remove('drag-over')); column.classList.add('drag-over'); } });
document.addEventListener('drop',async event => { const column = event.target.closest('[data-drop-status]'); if (!column || !dragging) return; event.preventDefault(); const id = dragging; const status = column.dataset.dropStatus; dragging = null; $$('.drag-over,.dragging').forEach(el => el.classList.remove('drag-over','dragging')); const issue = state.issues.find(issue => Number(issue.id) === id); if (!issue || issue.status === status) return; try { await api(`issues/${id}`,{status}); await refresh(); toast(`${code(id)} moved to ${status.toLowerCase()}`); } catch(error) { toast(error.message); } });
hydrateIcons();
$('#status-filter').innerHTML += options(statuses,''); $('#priority-filter').innerHTML += options(priorities,'');
try {
  const saved = JSON.parse(sessionStorage.getItem('orbit-return-view') || 'null');
  if (saved && ['all','mine','active','projects','project','requests'].includes(saved.view)) {
    for (const key of ['view','project','layout','search','status','category','priority','assignee','sort']) if (key in saved) state[key] = saved[key];
    $('#issue-search').value = state.search; $('#status-filter').value = state.status; $('#priority-filter').value = state.priority; $('#sort-order').value = state.sort;
    $('#filter-panel').hidden = ![state.status,state.category,state.priority,state.assignee].some(Boolean);
  }
  sessionStorage.removeItem('orbit-return-view');
} catch { sessionStorage.removeItem('orbit-return-view'); }
window.addEventListener('pageshow', event => { if (event.persisted) refresh().catch(error => toast(error.message)); });
refresh().then(() => { const id = Number(new URLSearchParams(location.search).get('issue')); if (id) openIssue(id); }).catch(error => { $('#issue-content').innerHTML = `<div class="empty-state">${icon('cycle')}<h2>Couldn’t load your workspace</h2><p>${escapeHTML(error.message)}</p><button class="button primary" id="retry-load">Try again</button></div>`; $('#retry-load').addEventListener('click',() => location.reload()); });
