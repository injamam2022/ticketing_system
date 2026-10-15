const { test, expect } = require('@playwright/test');

async function signIn(page) {
  await page.goto('/');
  await page.getByRole('button', {name:'Enter workspace'}).click();
  await expect(page.locator('#page-title')).toContainText('All issues');
  await expect(page.locator('.issue-row').first()).toBeVisible();
}

test('authentication and CSRF are enforced', async ({page, request}) => {
  const anonymous = await request.get('/index.php/api/workspace');
  expect(anonymous.status()).toBe(401);
  await signIn(page);
  const status = await page.evaluate(async () => (await fetch(window.ORBIT.api + 'issues',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({title:'Rejected request'})})).status);
  expect(status).toBe(403);
  await page.getByRole('button',{name:'Sign out',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Welcome to your workspace'})).toBeVisible();
});

test('create, edit, comment, drag, reload, and delete an issue', async ({page}) => {
  const errors = []; page.on('pageerror',error => errors.push(error.message));
  await page.setViewportSize({width:1440,height:1000});
  await signIn(page);
  await page.screenshot({path:'build/screenshots/workspace-desktop.png',fullPage:true});
  const title = `Browser ticket ${Date.now()}`;
  await page.locator('#main-create').click();
  await page.locator('#issue-title').fill(title);
  await page.locator('#issue-description').fill('Persist this detail <script> safely.');
  await page.locator('#edit-priority').selectOption('High');
  await page.locator('#edit-assignee').selectOption('1');
  await page.locator('#edit-project').selectOption('1');
  await page.locator('#edit-label').fill('Feature');
  await page.locator('#save-issue').click();
  const row = page.locator('.issue-row').filter({hasText:title});
  await expect(row).toBeVisible();
  await row.click();
  await expect(page).toHaveURL(/\/issues\/\d+$/);
  await expect(page.locator('dialog')).toHaveCount(0);
  await page.locator('#comment-body').fill('Verified from the browser <b>as text</b>.');
  await page.locator('#comment-form button[type=submit]').click();
  await expect(page.locator('#comments-list')).toContainText('Verified from the browser <b>as text</b>.');
  await page.locator('#edit-status').selectOption('In progress');
  await page.locator('#save-issue').click();
  await expect(page.locator('#issue-notice')).toHaveText('Changes saved.');
  await page.reload();
  await expect(page.locator('#edit-status')).toHaveValue('In progress');
  await page.screenshot({path:'build/screenshots/issue-page-desktop.png',fullPage:true});
  await page.locator('#back-to-issues').click();
  await page.locator('[data-layout=board]').click();
  const card = page.locator('.board-card').filter({hasText:title});
  await expect(card).toBeVisible();
  await expect(page.locator('[data-drop-status="In progress"]')).toContainText(title);
  await card.dragTo(page.locator('[data-drop-status="In review"] .group-heading'));
  await expect(page.locator('[data-drop-status="In review"]')).toContainText(title);
  await page.reload();
  await expect(page.locator('[data-drop-status="In review"]')).toContainText(title);
  await page.screenshot({path:'build/screenshots/workspace-board.png',fullPage:true});
  await page.locator('.board-card').filter({hasText:title}).click();
  await expect(page.locator('#issue-description')).toHaveValue('Persist this detail <script> safely.');
  await expect(page.locator('#comments-list')).toContainText('Verified from the browser');
  await page.locator('#delete-issue').click(); await page.locator('#delete-issue').click();
  await expect(page.locator('#page-title')).toContainText('All issues');
  await expect(page.locator('.board-card').filter({hasText:title})).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('search, filters, projects, and keyboard shortcuts', async ({page}) => {
  await signIn(page);
  await page.keyboard.press('/');
  await expect(page.locator('#issue-search')).toBeFocused();
  await page.locator('#issue-search').fill('session timeout');
  await expect(page.locator('.issue-row')).toHaveCount(1);
  await page.locator('#issue-search').fill('no matching issue 198348');
  await expect(page.getByRole('heading',{name:'No matching issues'})).toBeVisible();
  await page.getByRole('button',{name:'Clear search & filters'}).click();
  await page.locator('#filter-toggle').click();
  await page.locator('#priority-filter').selectOption('Urgent');
  await expect(page.locator('.issue-row')).toHaveCount(1);
  await page.locator('#clear-filters').click();
  await page.locator('[data-view=projects]').click();
  await expect(page.locator('.project-card').first()).toBeVisible();
  await page.locator('#main-create').click();
  const project = `Browser project ${Date.now()}`;
  await page.locator('#project-form [name=name]').fill(project);
  await page.locator('#project-form [name=description]').fill('Created through the project form.');
  await page.locator('#project-form button[type=submit]').click();
  await expect(page.locator('#page-title')).toContainText(project);
  await expect(page.getByRole('heading',{name:'A little room for what’s next'})).toBeVisible();
  await page.keyboard.press('c');
  await expect(page.locator('#issue-dialog')).toBeVisible();
  await expect(page.locator('#edit-project option:checked')).toHaveText(project);
  await page.keyboard.press('Escape');
});

test('issue page works on mobile and preserves the filtered list', async ({page, request}) => {
  await page.setViewportSize({width:390,height:844});
  const anonymous = await request.get('/index.php/issues/17', {maxRedirects:0});
  expect(anonymous.status()).toBe(302);
  await signIn(page);
  await page.locator('#issue-search').fill('loading performance');
  await page.locator('.issue-row').click();
  await expect(page).toHaveURL(/\/issues\/17$/);
  await expect(page.locator('#issue-description')).toContainText('Overview');
  const title = await page.locator('#issue-title').inputValue();
  await page.locator('#issue-title').fill(title + ' (unsaved)');
  page.once('dialog', dialog => dialog.dismiss());
  await page.locator('#back-to-issues').click();
  await expect(page).toHaveURL(/\/issues\/17$/);
  await page.locator('#issue-title').fill(title);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({path:'build/screenshots/issue-page-mobile.png',fullPage:true});
  await page.locator('#back-to-issues').click();
  await expect(page.locator('#issue-search')).toHaveValue('loading performance');
  await expect(page.locator('.issue-row')).toHaveCount(1);
  const missing = await page.goto('/index.php/issues/9999999');
  expect(missing.status()).toBe(404);
});

test('mobile navigation and layout', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await signIn(page);
  await page.screenshot({path:'build/screenshots/workspace-mobile.png',fullPage:true});
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.locator('#open-sidebar').click();
  await page.locator('[data-view=mine]').click();
  await expect(page.locator('#page-title')).toContainText('My issues');
  await page.locator('#main-create').click();
  await expect(page.locator('#edit-assignee')).toHaveValue('1');
  await expect(page.locator('#save-issue')).toBeInViewport();
  await page.keyboard.press('Escape');
});
