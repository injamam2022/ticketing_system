const { test, expect } = require('@playwright/test');

async function staffLogin(page) {
  await page.goto('/index.php/login');
  await page.getByRole('button', { name: 'Enter workspace' }).click();
  await expect(page.locator('#page-title')).toContainText('All issues');
}

async function createClient(page, email, project = '1') {
  await page.goto('/index.php/clients');
  const form = page.locator('#create-client form');
  await form.locator('[name=name]').fill('Taylor James');
  await form.locator('[name=company]').fill('Acme Studio');
  await form.locator('[name=email]').fill(email);
  await form.locator('[name=password]').fill('Client-password-123');
  await form.locator(`[name="projects[]"][value="${project}"]`).check();
  await form.getByRole('button', { name: 'Create client account' }).click();
  await expect(page.getByRole('status')).toContainText('Client created');
}

async function clientLogin(page, email) {
  await page.goto('/index.php/portal/login');
  await page.locator('[name=email]').fill(email);
  await page.locator('[name=password]').fill('Client-password-123');
  await page.getByRole('button', { name: 'Sign in to client portal' }).click();
  await expect(page.getByRole('heading', { name: 'How can we help, Taylor?' })).toBeVisible();
}

test('client submission, staff management, and shared replies work end to end', async ({ page, browser }) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const email = `taylor-${Date.now()}@example.com`;
  await staffLogin(page);
  await page.goto('/index.php/statuses');
  const statusForm = page.locator('#add-status form');
  await statusForm.locator('[name=name]').fill('Testing Complete');
  await statusForm.locator('[name=category]').selectOption('done');
  await statusForm.getByRole('button', { name: 'Add status', exact: true }).click();
  await createClient(page, email);
  await page.screenshot({ path: 'build/screenshots/client-management.png', fullPage: true });
  const clientContext = await browser.newContext({ baseURL: 'http://localhost:8081', viewport: { width: 1440, height: 1000 } });
  const portal = await clientContext.newPage();
  await clientLogin(portal, email);
  await expect(portal.locator('[name=project_id] option')).toHaveCount(2);
  await expect(portal.locator('[name=project_id]')).not.toContainText('Mobile app');
  await portal.screenshot({ path: 'build/screenshots/client-portal.png', fullPage: true });
  const title = `Client request ${Date.now()}`;
  await portal.locator('[name=project_id]').selectOption('1');
  await portal.locator('[name=due_date]').fill('2026-11-15');
  await portal.locator('[name=title]').fill(title);
  await portal.locator('[name=description]').fill('Please help with the customer dashboard. <b>Plain text</b>');
  await portal.getByRole('button', { name: 'Submit ticket' }).click();
  await expect(portal.getByRole('heading', { name: title })).toBeVisible();
  const ticketUrl = new URL(portal.url()).pathname;
  const id = ticketUrl.split('/').pop();
  await portal.locator('[name=body]').fill('More details from the client.');
  await portal.getByRole('button', { name: 'Send reply' }).click();
  await expect(portal.locator('.conversation')).toContainText('More details from the client.');

  await page.goto('/');
  await page.locator('[data-view=requests]').click();
  await page.locator('.issue-row').filter({ hasText: title }).click();
  await expect(page.locator('#client-request-info')).toContainText('Acme Studio');
  await page.locator('#comment-body').fill('Staff-only confidential note.');
  await page.locator('#comment-form button[type=submit]').click();
  await expect(page.locator('#comments-list')).toContainText('Staff-only confidential note.');
  await page.locator('#client-reply-body').fill('A public reply from the workspace <b>shown as text</b>.');
  await page.locator('#client-reply-form button[type=submit]').click();
  await expect(page.locator('[data-conversation-notice]')).toHaveText('Reply sent to the client.');
  // The client receives workspace replies without reloading or losing their draft.
  await portal.locator('[name=body]').fill('Draft that should survive updates');
  await expect(portal.locator('.conversation')).toContainText('A public reply from the workspace <b>shown as text</b>.', { timeout: 12000 });
  await expect(portal.locator('[name=body]')).toHaveValue('Draft that should survive updates');
  await expect(portal.locator('.conversation')).not.toContainText('Staff-only confidential note.');
  await page.screenshot({ path: 'build/screenshots/workspace-client-conversation.png', fullPage: true });
  await page.getByRole('link', { name: 'Open client conversation' }).click();
  await expect(page.locator('.conversation')).toContainText('More details from the client.');
  await page.locator('[name=status]').selectOption('Testing Complete');
  await page.getByRole('button', { name: 'Update ticket' }).click();
  await expect(portal.locator('.ticket-hero .portal-status')).toHaveText('Testing Complete', { timeout: 12000 });
  await page.locator('[name=body]').fill('We are working on your request.');
  await page.getByRole('button', { name: 'Send reply' }).click();
  await portal.reload();
  await expect(portal.locator('.ticket-hero .portal-status')).toHaveText('Testing Complete');
  await portal.goto('/index.php/portal');
  await expect(portal.locator('.portal-ticket-row').filter({ hasText: title })).toHaveAttribute('data-ticket-status', 'done');
  await portal.goto(ticketUrl);
  await expect(portal.locator('.conversation')).toContainText('We are working on your request.');
  await expect(portal.locator('body')).not.toContainText('Staff-only confidential note.');
  await portal.screenshot({ path: 'build/screenshots/client-ticket.png', fullPage: true });

  // Cross-client access remains denied even when both clients share the project.
  const otherEmail = `other-${Date.now()}@example.com`;
  await createClient(page, otherEmail);
  await portal.getByRole('button', { name: 'Sign out', exact: true }).click();
  await clientLogin(portal, otherEmail);
  const forbidden = await portal.goto(ticketUrl);
  expect(forbidden.status()).toBe(404);
  const forbiddenUpdates = await clientContext.request.get(ticketUrl + '/updates');
  expect(forbiddenUpdates.status()).toBe(404);
  expect(await forbiddenUpdates.text()).not.toContain('A public reply from the workspace');
  const staffConversation = await clientContext.request.get(`/index.php/api/issues/${id}/client-conversation`);
  expect(staffConversation.status()).toBe(401);
  await portal.goto('/index.php/portal');
  const csrf = await portal.locator('[name=csrf]').first().inputValue();
  const reply = await clientContext.request.post(ticketUrl + '/reply', { form: { csrf, body: 'Unauthorized reply' } });
  expect(reply.status()).toBe(404);
  const api = await clientContext.request.get('/index.php/api/workspace');
  expect(api.status()).toBe(401);
  const statuses = await clientContext.request.post('/index.php/statuses', { form: { csrf, name: 'Unauthorized', color: '#112233', category: 'todo' }, maxRedirects: 0 });
  expect(statuses.status()).toBe(303);
  const staffArea = await portal.goto('/index.php/clients');
  await expect(portal).toHaveURL(/\/login$/);
  expect(staffArea.status()).toBe(200);

  await portal.goto('/index.php/portal');
  const token = await portal.locator('[name=csrf]').first().inputValue();
  const invalid = await clientContext.request.post('/index.php/portal/tickets', { form: { csrf: token, title: 'Wrong project', description: 'Rejected', project_id: '2' } });
  expect(await invalid.text()).toContain('Choose one of your assigned projects.');
  const noCsrf = await clientContext.request.post('/index.php/portal/tickets', { form: { title: 'Rejected', project_id: '1' } });
  expect(noCsrf.status()).toBe(403);
  expect(errors).toEqual([]);
  await clientContext.close();
});

test('client access can be changed and revoked; portal works on mobile', async ({ page, browser }) => {
  const email = `mobile-${Date.now()}@example.com`;
  await staffLogin(page); await createClient(page, email);
  const context = await browser.newContext({ baseURL: 'http://localhost:8081', viewport: { width: 390, height: 844 } });
  const portal = await context.newPage();
  await clientLogin(portal, email);
  expect(await portal.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await portal.screenshot({ path: 'build/screenshots/client-portal-mobile.png', fullPage: true });
  await page.goto('/index.php/clients');
  let card = page.locator('.client-card').filter({ hasText: email });
  await card.locator('summary').click();
  await card.locator('[name="projects[]"][value="1"]').uncheck();
  await card.locator('[name="projects[]"][value="2"]').check();
  await card.getByRole('button', { name: 'Save client access' }).click();
  await expect(page.getByRole('status')).toContainText('Client access updated');
  await portal.reload();
  await expect(portal.locator('[name=project_id]')).toContainText('Website redesign');
  await expect(portal.locator('[name=project_id]')).not.toContainText('Platform');
  card = page.locator('.client-card').filter({ hasText: email });
  await card.locator('summary').click();
  await card.locator('[name=active]').uncheck();
  await card.getByRole('button', { name: 'Save client access' }).click();
  await portal.reload();
  await expect(portal).toHaveURL(/\/portal\/login$/);
  await context.close();
});
