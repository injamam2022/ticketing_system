const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1cAAAAASUVORK5CYII=', 'base64');
const image = name => ({ name, mimeType: 'image/png', buffer: png });

async function createClient(page, email) {
  await page.goto('/index.php/clients');
  const form = page.locator('#create-client form');
  await form.locator('[name=name]').fill('Taylor James');
  await form.locator('[name=company]').fill('Acme Studio');
  await form.locator('[name=email]').fill(email);
  await form.locator('[name=password]').fill('Client-password-123');
  await form.locator('[name="projects[]"][value="1"]').check();
  await form.getByRole('button', { name: 'Create client account' }).click();
  await expect(page.getByRole('status')).toContainText('Client created');
}

async function loginClient(page, email) {
  await page.goto('/index.php/portal/login');
  await page.locator('[name=email]').fill(email);
  await page.locator('[name=password]').fill('Client-password-123');
  await page.getByRole('button', { name: 'Sign in to client portal' }).click();
  await expect(page.locator('#new-ticket')).toBeVisible();
}

test('multiple client images preview, persist, and stay private on desktop and mobile', async ({ page, browser }) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const email = `images-${Date.now()}@example.com`;
  await page.goto('/index.php/login');
  await page.getByRole('button', { name: 'Enter workspace' }).click();
  await expect(page.locator('.issue-row').first()).toBeVisible();
  await createClient(page, email);
  const context = await browser.newContext({ baseURL: 'http://localhost:8081', viewport: { width: 1440, height: 1100 } });
  const portal = await context.newPage();
  portal.on('pageerror', error => errors.push(error.message));
  await loginClient(portal, email);
  const formWidth = await portal.locator('#new-ticket').evaluate(el => el.getBoundingClientRect().width);
  expect(formWidth).toBeGreaterThan(500);
  const input = portal.locator('#ticket-images');
  await input.setInputFiles([image('login-problem.png'), image('error-message.png')]);
  await expect(portal.locator('.ticket-upload-preview')).toHaveCount(2);
  await portal.getByRole('button', { name: 'Remove error-message.png', exact: true }).click();
  await expect(portal.locator('.ticket-upload-preview')).toHaveCount(1);
  await portal.locator('#ticket-dropzone').evaluate((zone, base64) => {
    const bytes = Uint8Array.from(atob(base64), char => char.charCodeAt(0));
    const transfer = new DataTransfer();
    transfer.items.add(new File([bytes], 'error-message.png', { type: 'image/png' }));
    zone.dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }));
  }, png.toString('base64'));
  await expect(portal.locator('.ticket-upload-preview')).toHaveCount(2);
  await portal.locator('[name=project_id]').selectOption('1');
  await portal.locator('[name=title]').fill('Login fails with an error');
  await portal.locator('[name=description]').fill('The login page shows an error after I submit the form. Screenshots are attached.');
  await portal.screenshot({ path: 'build/screenshots/client-upload-desktop.png', fullPage: true });
  await portal.getByRole('button', { name: 'Submit ticket', exact: false }).click();
  await expect(portal.locator('.ticket-image-tile')).toHaveCount(2);
  const ticketUrl = new URL(portal.url()).pathname;
  const imageUrl = await portal.locator('.ticket-image-tile').first().getAttribute('href');
  const response = await context.request.get(imageUrl);
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('image/png');
  expect(response.headers()['cache-control']).toContain('no-store');
  expect(await response.body()).toEqual(png);
  await portal.reload();
  await expect(portal.locator('.ticket-image-tile img').first()).toHaveJSProperty('naturalWidth', 1);
  const id = ticketUrl.split('/').pop();
  await page.goto(`/index.php/issues/${id}`);
  await expect(page.locator('.ticket-image-tile')).toHaveCount(2);
  const staffImage = await page.locator('.ticket-image-tile').first().getAttribute('href');
  expect((await page.request.get(staffImage)).status()).toBe(200);
  await page.goto(`/index.php/clients/tickets/${id}`);
  await expect(page.locator('.ticket-image-tile')).toHaveCount(2);

  // The client cannot use staff URLs or access images after project revocation.
  expect((await context.request.get(staffImage, { maxRedirects: 0 })).status()).toBe(302);
  await page.goto('/index.php/clients');
  const card = page.locator('.client-card').filter({ hasText: email });
  await card.locator('summary').click();
  await card.locator('[name="projects[]"][value="1"]').uncheck();
  await card.locator('[name="projects[]"][value="2"]').check();
  await card.getByRole('button', { name: 'Save client access' }).click();
  expect((await context.request.get(imageUrl)).status()).toBe(404);
  expect((await page.request.get(staffImage)).status()).toBe(200);
  const anonymous = await browser.newContext();
  expect((await anonymous.request.get(imageUrl, { maxRedirects: 0 })).status()).toBe(302);
  await anonymous.close();

  await portal.setViewportSize({ width: 390, height: 844 });
  await portal.goto('/index.php/portal');
  await input.setInputFiles(image('mobile-screenshot.png'));
  await expect(portal.locator('.ticket-upload-preview')).toHaveCount(1);
  expect(await portal.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
  await portal.screenshot({ path: 'build/screenshots/client-upload-mobile.png', fullPage: true });
  expect(errors).toEqual([]);
  await context.close();
});

test('invalid images and upload limits are rejected without creating a ticket', async ({ page, browser }) => {
  const email = `invalid-images-${Date.now()}@example.com`;
  await page.goto('/index.php/login');
  await page.getByRole('button', { name: 'Enter workspace' }).click();
  await createClient(page, email);
  const context = await browser.newContext({ baseURL: 'http://localhost:8081' });
  const portal = await context.newPage();
  await loginClient(portal, email);
  const input = portal.locator('#ticket-images');
  await input.setInputFiles(Array.from({ length: 6 }, (_, i) => image(`image-${i}.png`)));
  await expect(portal.locator('#ticket-image-error')).toContainText('up to 5 images');
  await expect(portal.locator('.ticket-upload-preview')).toHaveCount(0);
  await input.setInputFiles({ name: 'large.png', mimeType: 'image/png', buffer: Buffer.alloc(2 * 1024 * 1024 + 1) });
  await expect(portal.locator('#ticket-image-error')).toContainText('up to 2 MB');
  await input.setInputFiles(Array.from({ length: 4 }, (_, i) => ({ name: `large-${i}.png`, mimeType: 'image/png', buffer: Buffer.alloc(2 * 1024 * 1024) })));
  await expect(portal.locator('#ticket-image-error')).toContainText('total image size up to 6 MB');
  await input.setInputFiles({ name: 'image.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') });
  await expect(portal.locator('#ticket-image-error')).toContainText('PNG, JPG');
  await input.setInputFiles({ name: 'fake.png', mimeType: 'image/png', buffer: Buffer.from('This is not an image') });
  await portal.locator('[name=project_id]').selectOption('1');
  await portal.locator('[name=title]').fill('Fake image rejected');
  await portal.locator('[name=description]').fill('Testing server validation');
  await portal.getByRole('button', { name: 'Submit ticket', exact: false }).click();
  await expect(portal.getByRole('alert')).toContainText('Use PNG, JPG, GIF, or WebP images.');
  const data = await (await page.request.get('/index.php/api/workspace')).json();
  expect(data.issues.some(issue => issue.title === 'Fake image rejected')).toBeFalsy();
  await expect(portal.locator('[name=title]')).toHaveValue('Fake image rejected');
  await expect(portal.locator('[name=description]')).toHaveValue('Testing server validation');
  const csrf = await portal.locator('#ticket-submit-form [name=csrf]').inputValue();
  const folder = path.resolve('writable/uploads/ticket-images');
  const before = fs.existsSync(folder) ? fs.readdirSync(folder).sort() : [];
  const failedTicket = await context.request.post('/index.php/portal/tickets', { multipart: { csrf, project_id: '1', title: '   ', description: 'Invalid title with a valid image', 'images[]': image('discarded-image.png') } });
  expect(await failedTicket.text()).toContain('Add a title between 1 and 200 characters.');
  expect(fs.readdirSync(folder).sort()).toEqual(before);
  const denied = await context.request.post('/index.php/portal/tickets', { multipart: { csrf: 'invalid', project_id: '1', title: 'CSRF rejected', description: 'Must not save images', 'images[]': image('denied.png') } });
  expect(denied.status()).toBe(403);
  expect(fs.readdirSync(folder).sort()).toEqual(before);
  await context.close();
});
