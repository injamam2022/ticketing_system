const {test,expect}=require('@playwright/test');
test('comments support editing, reactions, threads, attachments and linked issues',async({page,request})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/');await page.getByRole('button',{name:'Enter workspace'}).click();
  await expect(page.locator('#page-title')).toBeVisible();
  const id=await page.evaluate(async()=>{const r=await fetch(window.ORBIT.api+'issues',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.ORBIT.csrf},body:JSON.stringify({title:'Discussion browser test'})});return (await r.json()).id;});
  await page.goto(`/index.php/issues/${id}`);
  await page.locator('#comment-body').fill('Original comment');await page.locator('#comment-form [type=submit]').click();
  const root=page.locator('.discussion-comment').first();await expect(root).toContainText('Original comment');
  await root.getByLabel('Comment actions').click();await root.getByRole('button',{name:'Edit comment',exact:true}).click();
  await root.getByLabel('Edit comment', {exact:true}).fill('Updated comment');await root.getByRole('button',{name:'Save comment'}).click();await expect(root).toContainText('edited');
  await root.getByLabel('Add reaction').click();await root.getByLabel('React 👍',{exact:true}).click();await expect(root.locator('[aria-pressed=true]')).toHaveText('👍 1');
  await page.reload();await expect(root).toContainText('Updated comment');await expect(root.locator('[aria-pressed=true]')).toHaveText('👍 1');
  await expect(root.getByRole('button',{name:'Reply',exact:true})).toHaveCount(0);
  // Keep an existing thread reply to verify deletion preserves its history.
  await page.evaluate(async()=>{const c=window.ISSUE_PAGE;await fetch(c.api+`issues/${c.id}/discussion`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':c.csrf},body:JSON.stringify({body:'Thread reply',parent_id:c.discussion.comments[0].id})});});
  await page.reload();await expect(page.locator('.thread-replies').first()).toContainText('Thread reply');
  await expect(page.locator('.thread-replies [data-action=reply]')).toHaveCount(0);
  await root.getByLabel('Comment actions').click();await root.getByRole('button',{name:'Resolve thread',exact:true}).click();await expect(page.locator('.resolved-label')).toBeVisible();
  await root.getByLabel('Comment actions').click();await root.getByRole('button',{name:'Reopen thread',exact:true}).click();await expect(page.locator('.resolved-label')).toHaveCount(0);
  await page.locator('#comment-body').fill('Screenshot attached');await page.locator('#comment-form input[type=file]').setInputFiles({name:'pixel.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64')});
  await page.locator('#comment-form [type=submit]').click();const img=page.locator('.comment-attachments img');await expect(img).toBeVisible();expect(await img.evaluate(i=>i.complete&&i.naturalWidth>0)).toBe(true);
  expect((await request.get(await img.getAttribute('src'))).status()).toBe(401);
  await root.getByLabel('Comment actions').click();await root.getByRole('button',{name:'New sub-issue from comment'}).click();await root.getByLabel('New issue title').fill('Child from discussion');await root.getByRole('button',{name:'Create issue',exact:true}).click();await expect(root.locator('.linked-issue')).toContainText('Child from discussion');
  await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.screenshot({path:'build/screenshots/discussion-mobile.png',fullPage:true});
  page.once('dialog',d=>d.accept());await root.getByLabel('Comment actions').click();await root.getByRole('button',{name:'Delete comment',exact:true}).click();await expect(root).toContainText('Comment deleted');await expect(page.locator('.thread-replies').first()).toContainText('Thread reply');
  expect(errors).toEqual([]);
});
