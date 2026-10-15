# Orbit — a focused ticketing workspace

A Linear-inspired application built with **PHP 8.2+, CodeIgniter 4, MySQL/MariaDB (PDO), and vanilla JavaScript**. SQLite remains supported for isolated tests. No frontend build step or external assets are needed.

## Run locally

```powershell
php spark serve --host localhost --port 8080
```

Open **http://localhost:8080**. The built-in server automatically uses its own localhost URL.

For XAMPP, start **Apache and MySQL** in the control panel and open **http://localhost/ticket-system/public/**. The included `.env` is configured for this location. The PHP development server also requires MySQL to be running.

### Demo account (development only)

- Email: `alex@orbit.local`
- Password: `demo12345`

In **development**, the form is prefilled and sample projects/issues are seeded on first database access. Only Alex has a usable demo password; Jamie and Sam are sample assignees.

In **production**, demo seeding is disabled. Set `workspace.ownerEmail` and a unique `workspace.ownerPassword` (12+ characters, not `demo12345`) before the first request. Create additional staff from **Team** in the sidebar. Production also requires HTTPS, Secure cookies, and a dedicated MySQL user with a non-empty password (not `root` with a blank password).

## Features

- Sign-in/out, hashed passwords, session rotation, CSRF checks, persistent login rate limit.
- Create, edit, and delete issues with descriptions, statuses, priorities, assignees, project, label, and due date.
- Existing issues open on dedicated `/index.php/issues/{id}` pages with properties, internal comments, client conversation links, and unsaved-change protection. Save with the button or Ctrl/Cmd+S; Back to issues restores the previous list/board filters.
- Grouped list and drag-and-drop board; the chosen view survives reloads.
- Search titles, descriptions, issue IDs, labels, projects, and assignee names.
- Status, priority, and assignee filters; priority, title, creation, and due-date sorting within status groups.
- My issues, active work, project overview, project-specific issues, and project creation.
- Persistent comments, mobile navigation, accessible native dialogs.
- Shortcuts: `C` create, `/` search, `V` switch view, `?` help, `Esc` close.

This is a **single shared staff workspace with a separate client portal**. Staff share workspace access and can add teammates or reset passwords from **Team**. Clients have access only to their own tickets in projects assigned to them. Email invitations, self-service password reset, fine-grained staff roles, notifications, real-time collaboration, and billing are not implemented.

## Custom statuses

Open **Manage statuses** in the staff sidebar, or visit `/index.php/statuses`. Add a name, color, and category. Expand an existing status to rename it or move it up/down in the workflow. Renaming updates existing tickets automatically. Statuses are shared across all projects and appear in ticket editing, filters, the list, the board, and client ticket updates. Categories control active work and completion totals; clients can see status changes but only staff can manage the workflow.

## Client portal

Open **http://localhost:8080/index.php/portal** (or **http://localhost/ticket-system/public/index.php/portal** under XAMPP).

1. Sign in to the staff workspace and click **Clients** in the sidebar.
2. Add a client contact, company, email, password, and assigned projects. Active accounts require at least one project.
3. Share the portal link and login credentials securely. The application does not send an email automatically.
4. The client signs in and submits a ticket for an assigned project. The ticket appears immediately in **Client requests**, **All issues**, and the relevant project in the staff workspace.
5. Open the issue and use **Reply to client** in **Client conversation** to send a public reply directly from the workspace. **Open client conversation** also opens the staff conversation and status controls. The client ticket checks for new public replies and status changes every five seconds, keeping any reply draft intact. **Internal activity** comments remain visible only to staff; they are not automatically published to clients.

Clients cannot browse internal project tickets, other clients' requests (even on a shared project), staff accounts, or the staff API. Removing a project assignment removes access to that project's tickets. Deactivating a client blocks their next request, including existing sessions. Moving a client ticket to a project the client cannot access also hides that ticket from their portal.

Clients can attach up to **5 images** when submitting a ticket: PNG, JPG, GIF, or WebP, up to **2 MB each** and **6 MB total**. The wider submission form supports drag and drop, previews, adding more images, and removing them before submission. Images appear on the client ticket, staff conversation, and workspace issue page. They are stored outside the public folder and served only after checking staff or client access; project revocation also blocks image access.

To change access or reset a password, expand the client's card on the Clients page. A blank password preserves the current password. No demo client account is created automatically.

The SQLite upgrade is additive: it adds client accounts, project memberships, a requester on issues, and a separate public conversation table. Existing staff issues and comments are retained. PHP tests cover upgrading an existing database and access isolation; browser tests cover client submission through staff replies and account revocation.

## Data and configuration

The active database is **`orbit_ticketing`** on your local XAMPP MySQL/MariaDB server at **127.0.0.1:3306**. Both the staff workspace and client portal use the same database. Connection settings are in `.env`, using `workspace.databaseDriver = mysql` and the `database.default.*` settings. Local XAMPP may use `CI_ENVIRONMENT = development` with the `root` account and an empty password. Production must use `CI_ENVIRONMENT = production`, a dedicated DB user with a real password, and an `https://` `app.baseURL`.

Open phpMyAdmin at **http://localhost/phpmyadmin/** and select `orbit_ticketing`. The database uses InnoDB tables, foreign keys, and utf8mb4. The repository connects through PDO MySQL using the configured credentials.

Existing SQLite records were copied and verified field by field. The original `writable/workspace.sqlite` and `writable/workspace-before-mysql-*.sqlite` backup are retained; new changes go to MySQL. Back up the active MySQL database through phpMyAdmin's Export function or mysqldump. SQLite files now contain only the pre-migration snapshot.

For a fresh installation, copy `.env.example` to `.env`, configure credentials, create an empty `orbit_ticketing` database, and run `composer install`. PHP requires `intl`, `mbstring`, and `pdo_mysql`; SQLite tests/migration also need `pdo_sqlite`.

To migrate another existing SQLite installation, configure the `database.default.*` connection values while keeping `workspace.databaseDriver = sqlite`, then run `php spark workspace:migrate-mysql`. It briefly blocks application requests, creates a SQLite backup, creates the MySQL schema, imports and verifies data, preserves ID sequences, and activates MySQL. It refuses to overwrite populated tables. Do not switch an active MySQL installation back to its old SQLite snapshot unless you intend to stop using newer MySQL changes.

To choose initial owner credentials, set these **before the first database access**:

```ini
workspace.ownerEmail = 'you@example.com'
workspace.ownerPassword = 'choose-a-long-unique-password'
```

These settings seed a new database; they do not change existing credentials. In production they are required and create only the owner account (no sample tickets). After sign-in, use **Team** to add staff or reset passwords. For deployment: set `CI_ENVIRONMENT = production`, use a dedicated MySQL user, set `app.baseURL` to your HTTPS URL, and point the virtual host document root at `public/`. The root `.htaccess` provides extra protection for local XAMPP use.

## Tests

```powershell
composer test
npm.cmd ci
npm.cmd test
```

PHP tests cover persistence, validation, comment cascades, password handling, and login rate limiting. Browser tests need installed Google Chrome and port 8081 available. They cover authentication, CSRF, issue CRUD, comments, drag-and-drop persistence, filters, projects, shortcuts, and mobile layout. Screenshots are written to `build/screenshots/`.

Default tests use isolated SQLite databases, regardless of the active application connection. To test MySQL migration and repository behavior in a temporary database:

```powershell
$env:ORBIT_TEST_MYSQL='1'
php vendor/bin/phpunit tests/integration/MySqlMigrationTest.php
```

For browser tests on MySQL, first create a separate empty test database and set `ORBIT_TEST_MYSQL=1` and `ORBIT_TEST_MYSQL_DATABASE` to that name before running `npm.cmd test`. Never use `orbit_ticketing` as a test database. The local verification used `orbit_browser_tests_20260929`.

## Main files

| File | Purpose |
| --- | --- |
| `app/Controllers/Workspace.php` | Pages, authentication, JSON endpoints |
| `app/Controllers/Team.php` | Staff teammate create and password reset |
| `app/Controllers/ClientPortal.php` | Client sign-in, submissions, public replies, staff client management |
| `app/Controllers/SessionProtection.php` | Shared session CSRF protection |
| `app/Libraries/WorkspaceStore.php` | Schema, demo data, validation, persistence |
| `app/Libraries/MySqlDatabase.php` | MySQL connection and schema |
| `app/Libraries/SqliteToMysql.php` | Verified SQLite import and ID preservation |
| `app/Commands/MigrateMysql.php` | Backup, migration, and connection switch |
| `app/Views/workspace.php` | Workspace and dialogs |
| `app/Views/issue.php` | Dedicated issue management page |
| `public/assets/issue.js` | Issue editing, comments, deletion, and unsaved-change protection |
| `app/Views/login.php` | Sign-in page |
| `public/assets/app.js` | UI state, interactions, API calls |
| `public/assets/app.css` | Responsive styling |
| `app/Views/portal/` | Client portal and management screens |
| `public/assets/portal.css` | Responsive client portal styling |

The UI and sample content are original and inspired by Linear's focused workflow. This project is not affiliated with Linear.
# Issue discussions

The issue page supports editing and deleting your own comments, emoji reactions, threaded replies, resolving and reopening threads, and following or unfollowing a thread. The comment menu also copies permanent links or the thread as Markdown and creates issues or sub-issues from a comment.

Use **@ Mention** to notify a teammate. Replies to followed threads and mentions appear under **Updates** on the issue page; these are in-app notifications. Internal comments and attachments require a staff login and are not exposed in the client portal.

Attach or paste screenshots, or upload PDF and plain text files. Each comment allows five files, up to 2 MB each and 6 MB per upload. Deleted comments leave a placeholder to preserve replies. Their attachments become inaccessible; retained files are not automatically purged from disk. Editing detects conflicting changes and asks you to reload.

