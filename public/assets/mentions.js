(() => {
  'use strict';
  const section = document.querySelector('#activity-section');
  const popup = document.createElement('div');
  popup.id = 'mention-suggestions'; popup.className = 'mention-suggestions';
  popup.setAttribute('role', 'listbox'); popup.setAttribute('aria-label', 'Mention suggestions');
  popup.hidden = true; document.body.append(popup);
  let active = null, options = [], selected = 0, issues = null, loading = null;
  const h = escapeHTML;
  function close() {
    if (active) { active.field.removeAttribute('aria-activedescendant'); active.field.setAttribute('aria-expanded', 'false'); }
    active = null; popup.hidden = true;
  }
  window.closeMentionPicker = close;
  function position() {
    if (!active) return;
    const r = active.field.getBoundingClientRect();
    popup.style.width = Math.min(390, innerWidth - 24) + 'px';
    popup.style.left = Math.max(12, Math.min(r.left, innerWidth - popup.offsetWidth - 12)) + 'px';
    const above = r.top > Math.min(320, innerHeight / 2);
    popup.style.maxHeight = Math.max(100, Math.min(340, above ? r.top - 16 : innerHeight - r.bottom - 16)) + 'px';
    popup.style.top = (above ? Math.max(8, r.top - popup.offsetHeight - 6) : r.bottom + 6) + 'px';
  }
  function paint() {
    if (!active) return;
    const query = active.query.toLocaleLowerCase();
    const users = window.ISSUE_PAGE.users.filter(u => u.name.toLocaleLowerCase().includes(query)).slice(0, 6).map(u => ({kind:'user', id:u.id, label:u.name, token:u.name.replace(/[\]\r\n]/g,''), color:u.color}));
    const tickets = (issues || []).map(i => ({kind:'issue',id:i.id,label:`ORB-${String(i.id).padStart(3,'0')} ${i.title}`,token:`ORB-${String(i.id).padStart(3,'0')}`})).filter(i => i.label.toLocaleLowerCase().includes(query)).slice(0,6);
    options = [...users,...tickets]; selected = Math.min(selected, Math.max(0,options.length-1));
    let group = '';
    popup.innerHTML = options.map((o,i) => {
      const heading = group !== o.kind ? `<div class="mention-group" role="presentation">${o.kind === 'user' ? 'Users' : 'Issues'}</div>` : ''; group = o.kind;
      return `${heading}<button type="button" role="option" tabindex="-1" id="mention-option-${i}" data-option="${i}" aria-selected="${i===selected}"><span class="mention-avatar" ${o.color ? `style="background:${h(o.color)}"` : ''}>${o.kind==='user' ? h(o.label.slice(0,1)) : '◉'}</span><span>${h(o.label)}</span></button>`;
    }).join('') + (!options.length ? '<div class="mention-empty">No matches</div>' : '') + (issues === null ? '<div class="mention-empty">Loading issues…</div>' : '');
    popup.hidden = false;
    active.field.setAttribute('aria-expanded','true');
    active.field.setAttribute('aria-controls',popup.id);
    active.field.setAttribute('aria-autocomplete','list');
    if(options.length) active.field.setAttribute('aria-activedescendant',`mention-option-${selected}`);
    else active.field.removeAttribute('aria-activedescendant');
    position();
  }
  function update(field) {
    if (!field.matches('form[data-key] textarea') || field.selectionStart !== field.selectionEnd) { close(); return; }
    const match = field.value.slice(0,field.selectionStart).match(/(?:^|[\s(])@([^@\n\[\]()]{0,80})$/u);
    if (!match) { close(); return; }
    active = {field,query:match[1],start:field.selectionStart-match[1].length-1,end:field.selectionStart}; selected=0; paint();
    if(issues===null && !loading) loading=api('workspace').then(data=>{issues=data.issues || [];paint();}).catch(()=>{issues=[];paint();}).finally(()=>{loading=null;});
  }
  function choose(index) {
    const o=options[index]; if(!active || !o)return;
    const {field,start,end}=active;
    const text=`@[${o.token}](${o.kind}:${o.id}) `;
    if(field.value.length-(end-start)+text.length>field.maxLength)return;
    field.setRangeText(text,start,end,'end'); close(); field.focus(); field.dispatchEvent(new Event('input',{bubbles:true}));
  }
  section.addEventListener('input',e=>{if(e.target.tagName==='TEXTAREA' && !e.isComposing)update(e.target);});
  section.addEventListener('compositionend',e=>{if(e.target.tagName==='TEXTAREA')update(e.target);});
  section.addEventListener('click',e=>{
    if(e.target.closest('.mention-trigger')) {
      const field=e.target.closest('form').querySelector('textarea'); field.focus();
      const prefix=field.selectionStart && !/\s/.test(field.value[field.selectionStart-1]) ? ' @' : '@';
      field.setRangeText(prefix,field.selectionStart,field.selectionEnd,'end');field.dispatchEvent(new Event('input',{bubbles:true}));
    } else if(e.target.tagName==='TEXTAREA')update(e.target);
  });
  section.addEventListener('keydown',e=>{
    if(!active || e.target!==active.field || e.isComposing)return;
    if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close();}
    else if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();selected=(selected+(e.key==='ArrowDown'?1:-1)+options.length)%Math.max(1,options.length);paint();popup.querySelector('[aria-selected=true]')?.scrollIntoView({block:'nearest'});}
    else if((e.key==='Enter' || e.key==='Tab') && options.length){e.preventDefault();choose(selected);}
  });
  section.addEventListener('keyup',e=>{if(['ArrowLeft','ArrowRight','Home','End'].includes(e.key) && e.target.tagName==='TEXTAREA')update(e.target);});
  popup.addEventListener('mousedown',e=>e.preventDefault());
  popup.addEventListener('click',e=>{const b=e.target.closest('[data-option]');if(b)choose(+b.dataset.option);});
  document.addEventListener('pointerdown',e=>{if(active && !popup.contains(e.target) && e.target!==active.field && !e.target.closest('.mention-trigger'))close();});
  document.addEventListener('focusin',e=>{if(active && e.target!==active.field && !popup.contains(e.target))close();});
  window.addEventListener('resize',position); window.addEventListener('scroll',position,true);
})();
