const { defineConfig } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const uploadTemp = path.resolve('writable/upload-tmp').replaceAll('\\', '/');
fs.mkdirSync(uploadTemp, { recursive: true });
module.exports = defineConfig({
  testDir: './tests/browser',
  workers: 1,
  timeout: 60000,
  reporter: 'list',
  use: { baseURL: 'http://localhost:8081', headless: true, channel: 'chrome', screenshot: 'only-on-failure', trace: 'retain-on-failure' },
  webServer: {
    command: `php -d upload_tmp_dir="${uploadTemp}" -S localhost:8081 -t public vendor/codeigniter4/framework/system/rewrite.php`,
    url: 'http://localhost:8081/index.php/login',
    reuseExistingServer: false,
    env: { 'workspace.databaseDriver': process.env.ORBIT_TEST_MYSQL === '1' ? 'mysql' : 'sqlite', 'database.default.database': process.env.ORBIT_TEST_MYSQL_DATABASE || 'orbit_browser_tests', 'workspace.databasePath': path.resolve(`writable/browser-tests-${Date.now()}.sqlite`) },
  },
});
