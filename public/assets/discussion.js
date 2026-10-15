(() => {
  'use strict';
  const c = window.ISSUE_PAGE, h = escapeHTML, base = `issues/${c.id}/discussion`;
  let feed = c.discussion;
  const drafts = new Map();
  const section = document.querySelector('#activity-section');
  const error = document.querySelector('#comment-error');
  const list = document.querySelector('#comments-list');
  const draft = key => { if (!drafts.has(key)) drafts.set(key, {body:'', files:[]}); return drafts.get(key); };
  window.hasDiscussionDrafts = () => [...drafts.values()].some(d => d.body.trim() || d.files.length);
  const button = (action, text, extra='') => `<button type="button" data-action="${action}" ${extra}>${text}</button>`;
  const url = id => `${c.issueUrl}${id}`;
  const attachment = f => `${c.api}comment-attachments/${f.id}`;
  function body(text) {
    return h(text).replace(/@\[([^\]\r\n]+)\]\(issue:(\d+)\)/g, (_, name, id) => `<a class="mention" href="${url(id)}">${name}</a>`)
      .replace(/@\[([^\]\r\n]+)\]\(user:(\d+)\)/g, (_, name, id) => `<span class="mention">@${h(c.users.find(u => +u.id === +id)?.name || name)}</span>`)
      .replace(/\*\*([^*\n]+)\*\*/g,'<strong>$1</strong>').replace(/`([^`\n]+)`/g,'<code>$1</code>');
  }
  function composer(key, label) {
    const d = draft(key), main = key === 'new';
    return `<form class="discussion-composer" data-key="${key}" ${main ? 'id="comment-form"' : ''}>
      <label>${label}<textarea ${main ? 'id="comment-body"' : ''} aria-label="${label}" maxlength="5000" placeholder="Write an update… Paste a screenshot or mention a teammate.">${h(d.body)}</textarea></label>
      <div class="queued-files">${d.files.map((f,i) => `<span>${h(f.name)} ${button('unqueue','×',`data-index="${i}" aria-label="Remove ${h(f.name)}"`)}</span>`).join('')}</div>
      <div class="composer-tools"><label class="attach-control">Attach<input type="file" multiple accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,text/plain" aria-label="Attach files"></label>
      <button type="button" class="mention-trigger" aria-label="Mention a teammate or issue">@ Mention</button>
      ${main ? '' : button('cancel','Cancel')}<button class="button" type="submit">${key.startsWith('edit-') ? 'Save comment' : main ? 'Post comment →' : 'Reply'}</button></div>
      <small>PNG, JPG, GIF, WebP, PDF or text · 2 MB each · up to 5 files</small></form>`;
  }
  function card(comment) {
    const id = comment.id, root = feed.comments.find(r => +r.id === +(comment.parent_id || id));
    return `<article class="discussion-comment" id="comment-${id}" data-id="${id}"><header><span class="avatar" style="--avatar:${h(comment.color)}">${h(comment.name.slice(0,1))}</span><strong>${h(comment.name)}</strong><a class="comment-time" href="#comment-${id}">${h(new Date(comment.created_at).toLocaleString())}</a>${comment.edited_at ? '<small>edited</small>' : ''}
      <div class="comment-controls">${comment.deleted_at ? '' : `<details class="reaction-picker"><summary aria-label="Add reaction">☺+</summary><div>${c.reactions.map(emoji => button('react',emoji,`data-emoji="${emoji}" aria-label="React ${emoji}"`)).join('')}</div></details>`}
      <details class="comment-menu"><summary aria-label="Comment actions">•••</summary><div>
      ${comment.can_edit ? button('edit','Edit comment') + button('delete','Delete comment') : ''}
      ${button(root.following ? 'unfollow':'follow',root.following ? 'Unfollow thread':'Follow thread')}
      ${button(root.resolved_at ? 'reopen':'resolve', root.resolved_at ? 'Reopen thread':'Resolve thread')}
      ${button('copy','Copy link to comment')}${button('markdown','Copy thread as Markdown')}
      ${comment.deleted_at ? '' : button('issue','New issue from comment') + button('subissue','New sub-issue from comment')}</div></details></div></header>
      ${drafts.has(`edit-${id}`) ? composer(`edit-${id}`,'Edit comment') : `<div class="comment-text">${comment.deleted_at ? '<em>Comment deleted</em>' : body(comment.body)}</div>`}
      <div class="comment-attachments">${comment.attachments.map(f => `<div><a href="${attachment(f)}" target="_blank" rel="noopener">${f.mime.startsWith('image/') ? `<img src="${attachment(f)}" alt="${h(f.filename)}" loading="lazy">` : ''}${h(f.filename)}</a>${comment.can_edit ? button('remove-file','Remove attachment',`data-file="${f.id}"`) : ''}</div>`).join('')}</div>
      <div class="reaction-pills">${comment.reactions.map(r => button('react',`${r.emoji} ${r.count}`,`data-emoji="${r.emoji}" aria-pressed="${!!r.mine}" title="${h(r.names.join(', '))}"`)).join('')}${!comment.deleted_at && !comment.can_edit && !root.resolved_at ? button('reply','Reply') : ''}</div>
      ${comment.linked_issues.map(i => `<a class="linked-issue" href="${url(i.id)}">${i.parent_issue_id ? 'Sub-issue':'Issue'} ORB-${i.id} · ${h(i.title)} ↗</a>`).join('')}</article>`;
  }
  function render() {
    window.closeMentionPicker?.();
    document.querySelector('#activity-count').textContent = feed.comments.filter(x => !x.deleted_at).length;
    list.innerHTML = `<div class="issue-events">${feed.events.map(e => `<p>${h(e.name || 'Team')} · ${h(e.body)} <time>${h(new Date(e.created_at).toLocaleString())}</time></p>`).join('')}</div>` + feed.comments.filter(x => !x.parent_id).map(root => `<div class="discussion-thread ${root.resolved_at ? 'resolved' : ''}">${root.resolved_at ? '<div class="resolved-label">✓ Resolved thread</div>' : ''}${card(root)}<div class="thread-replies">${feed.comments.filter(r => +r.parent_id === +root.id).map(card).join('')}${drafts.has(`reply-${root.id}`) ? composer(`reply-${root.id}`,'Leave a reply') : ''}</div></div>`).join('');
    document.querySelector('#comment-form').outerHTML = composer('new','Add a comment');
  }
  async function refresh() { feed = await api(base); render(); }
  function fail(e) { error.textContent = e.message; error.hidden = false; }
  function files(form, items) {
    const d = draft(form.dataset.key);
    if (d.files.length + items.length > 5 || items.some(f => f.size > 2*1024*1024) || [...d.files,...items].reduce((n,f)=>n+f.size,0)>6*1024*1024) throw new Error('Choose up to 5 files, at most 2 MB each and 6 MB total.');
    d.files.push(...items); render();
  }
  section.addEventListener('input', e => { const f=e.target.closest('form[data-key]'); if(f && e.target.tagName==='TEXTAREA') draft(f.dataset.key).body=e.target.value; });
  section.addEventListener('change', e => {
    const f=e.target.closest('form[data-key]'); if(!f)return;
    try {
      if(e.target.type==='file') files(f,[...e.target.files]);
      if(e.target.tagName==='SELECT' && e.target.value) { const u=c.users.find(u=>+u.id===+e.target.value), t=f.querySelector('textarea'); t.setRangeText(`@[${u.name}](user:${u.id}) `,t.selectionStart,t.selectionEnd,'end'); draft(f.dataset.key).body=t.value; e.target.value=''; t.focus(); }
    } catch(e){fail(e);}
  });
  section.addEventListener('paste', e => { const f=e.target.closest('form[data-key]'); const images=[...(e.clipboardData?.files || [])]; if(f && images.length){e.preventDefault();try{files(f,images);}catch(e){fail(e);}} });
  section.addEventListener('submit', async e => {
    const f=e.target.closest('form[data-key]'); if(!f)return; e.preventDefault();
    const key=f.dataset.key, d=draft(key), submit=f.querySelector('[type=submit]'); submit.disabled=true; error.hidden=true;
    try {
      const data=new FormData(); data.set('body',d.body); d.files.forEach(file=>data.append('attachments[]',file));
      if(key.startsWith('reply-'))data.set('parent_id',key.slice(6));
      if(key.startsWith('edit-'))data.set('version',d.version);
      const response=await fetch(c.api+base+(key.startsWith('edit-') ? '/'+key.slice(5) : ''),{method:'POST',headers:{'X-CSRF-Token':c.csrf,Accept:'application/json'},body:data});
      const result=await response.json(); if(!response.ok)throw new Error(result.error || 'Unable to save comment.');
      drafts.delete(key); await refresh(); document.querySelector(`#comment-${result.id}`)?.scrollIntoView({block:'nearest'});
    }catch(e){fail(e);}finally{submit.disabled=false;}
  });
  async function copy(text) {
    if(navigator.clipboard) await navigator.clipboard.writeText(text);
    else {const t=document.createElement('textarea');t.value=text;document.body.append(t);t.select();const ok=document.execCommand('copy');t.remove();if(!ok)throw new Error('Copy was unavailable in this browser.');}
  }
  section.addEventListener('click', async e => {
    const b=e.target.closest('[data-action]');if(!b)return;
    const action=b.dataset.action, article=b.closest('[data-id]'), id=article?.dataset.id, comment=feed.comments.find(x=>+x.id===+id), f=b.closest('form[data-key]');
    error.hidden=true; b.disabled=true;
    try {
      if(action==='unqueue'){draft(f.dataset.key).files.splice(+b.dataset.index,1);render();}
      else if(action==='cancel'){drafts.delete(f.dataset.key);render();}
      else if(action==='edit'){drafts.set(`edit-${id}`,{body:comment.body,version:comment.version,files:[]});render();document.querySelector(`#comment-${id} textarea`).focus();}
      else if(action==='reply'){if(comment.can_edit)return;const key=`reply-${comment.parent_id || id}`;draft(key);render();document.querySelector(`[data-key="${key}"] textarea`).focus();}
      else if(action==='copy'){await copy(`${url(c.id)}#comment-${id}`);b.textContent='Link copied';}
      else if(action==='markdown'){const root=comment.parent_id || comment.id; await copy(feed.comments.filter(x=>+x.id===+root || +x.parent_id===+root).map(x=>`**${x.name}** (${x.created_at})\n\n${x.deleted_at ? '[Deleted]' : x.body}\n${x.attachments.map(f=>`[${f.filename}](${attachment(f)})`).join('\n')}`).join('\n\n---\n\n'));b.textContent='Thread copied';}
      else if(action==='issue' || action==='subissue'){
        if(article.querySelector('.convert-form'))return;
        const box=document.createElement('div');box.className='convert-form';box.innerHTML=`<label>${action==='subissue'?'Sub-issue':'Issue'} title<input maxlength="200" aria-label="New issue title" value="${h(comment.body.slice(0,100))}"></label>${button('convert','Create issue',`data-kind="${action}"`)}${button('dismiss','Cancel')}`;article.append(box);box.querySelector('input').focus();
      } else if(action==='dismiss')b.closest('.convert-form').remove();
      else if(action==='convert'){await api(`${base}/${id}/issue`,{title:b.closest('.convert-form').querySelector('input').value,kind:b.dataset.kind});await refresh();}
      else if(action==='delete'){if(!confirm('Delete your comment? Replies will remain.'))return;await api(`${base}/${id}/delete`,{});drafts.delete(`edit-${id}`);await refresh();}
      else if(action==='remove-file'){if(!confirm('Remove this attachment?'))return;await api(`comment-attachments/${b.dataset.file}/remove`,{});drafts.delete(`edit-${id}`);await refresh();}
      else if(action==='react'){await api(`${base}/${id}/react`,{emoji:b.dataset.emoji});await refresh();}
      else {await api(`${base}/${id}/thread`,{action});await refresh();}
    }catch(e){fail(e);}finally{b.disabled=false;}
  });
  const notifications=document.createElement('details');notifications.className='discussion-notifications';notifications.innerHTML='<summary>Updates</summary><div>Loading…</div>';document.querySelector('.issue-page-header').append(notifications);
  notifications.addEventListener('toggle',async()=>{if(!notifications.open)return;try{const data=await api('comment-notifications');notifications.querySelector('div').innerHTML=data.notifications.map(n=>`<a href="${url(n.issue_id)}#comment-${n.comment_id}" data-notification="${n.id}">${n.read_at?'':'● '}${h(n.name)} ${n.kind==='mention'?'mentioned you':'replied'}<small>${h(n.title)}</small></a>`).join('') || 'No comment updates yet.';}catch(e){notifications.querySelector('div').textContent=e.message;}});
  notifications.addEventListener('click',async e=>{const a=e.target.closest('[data-notification]');if(a){e.preventDefault();try{await api(`comment-notifications/${a.dataset.notification}/read`,{});location.href=a.href;}catch(e){fail(e);}}});
  if(c.related.length){const box=document.createElement('div');box.className='related-discussions';box.innerHTML='<h2>Related issues</h2>'+c.related.map(i=>`<a href="${url(i.id)}">${+i.is_parent?'Parent':'Sub-issue'} ORB-${i.id} · ${h(i.title)}</a>`).join('');document.querySelector('.issue-properties-panel').append(box);}
  window.addEventListener('issue-saved',()=>refresh().catch(fail));
  render();
  if(/^#comment-\d+$/.test(location.hash))requestAnimationFrame(()=>document.querySelector(location.hash)?.scrollIntoView({block:'center'}));
})();
