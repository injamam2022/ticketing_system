<?php

namespace App\Libraries;

use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Shared persistence for staff and client portals; MySQL or isolated SQLite stores. */
class WorkspaceStore
{
    use DiscussionFeatures;
    use StatusFeatures;
    private PDO $db;
    private string $driver;
    public const STATUSES = ['Backlog', 'Todo', 'In progress', 'In review', 'Done'];
    public const PRIORITIES = ['No priority', 'Urgent', 'High', 'Medium', 'Low'];
    public const USER_COLORS = ['#9b8afb', '#e6a36e', '#79bfa5', '#75b8e9', '#e48588', '#dfb766'];

    public function __construct(string|PDO|null $path = null, bool $seed = true)
    {
        $configuredDriver = getenv('workspace.databaseDriver');
        $configuredDriver = $configuredDriver === false ? env('workspace.databaseDriver', 'sqlite') : $configuredDriver;
        if ($path instanceof PDO || ($path === null && $configuredDriver === 'mysql')) {
            $this->db = $path instanceof PDO ? $path : MySqlDatabase::connect();
            $this->driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($this->driver !== 'mysql') { throw new InvalidArgumentException('An injected connection must use MySQL.'); }
            MySqlDatabase::schema($this->db);
            StatusSchema::seed($this->db);
            if ($seed && !(int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn()) { $this->seed(); }
            return;
        }
        $this->driver = 'sqlite';
        $this->db = new PDO('sqlite:' . ($path ?? env('workspace.databasePath', WRITEPATH . 'workspace.sqlite')), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL, password TEXT NOT NULL, color TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS projects (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", color TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS issues (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", status TEXT NOT NULL, priority TEXT NOT NULL, assignee_id INTEGER REFERENCES users(id), project_id INTEGER REFERENCES projects(id), label TEXT NOT NULL DEFAULT "", due_date TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, issue_id INTEGER NOT NULL REFERENCES issues(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id), body TEXT NOT NULL, created_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS login_attempts (fingerprint TEXT PRIMARY KEY, attempts INTEGER NOT NULL, started INTEGER NOT NULL);');
        StatusSchema::create($this->db);
        StatusSchema::seed($this->db);
        if ($seed && !(int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
            $this->seed();
        }
        $this->migrateClients();
        DiscussionSchema::migrate($this->db);
        TicketImages::schema($this->db);
    }

    private function migrateClients(): void
    {
        // Additive migration preserves existing workspaces and their issue IDs.
        $this->db->exec('CREATE TABLE IF NOT EXISTS clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, company TEXT NOT NULL, email TEXT NOT NULL UNIQUE COLLATE NOCASE, password TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1);
            CREATE TABLE IF NOT EXISTS client_projects (client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE, PRIMARY KEY (client_id, project_id));
            CREATE TABLE IF NOT EXISTS client_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, issue_id INTEGER NOT NULL REFERENCES issues(id) ON DELETE CASCADE, client_id INTEGER REFERENCES clients(id), staff_id INTEGER REFERENCES users(id), body TEXT NOT NULL, created_at TEXT NOT NULL, CHECK ((client_id IS NULL) != (staff_id IS NULL)));');
        $columns = array_column($this->db->query('PRAGMA table_info(issues)')->fetchAll(), 'name');
        if (!in_array('client_id', $columns, true)) {
            $this->db->exec('ALTER TABLE issues ADD COLUMN client_id INTEGER REFERENCES clients(id)');
        }
        $this->db->exec('CREATE INDEX IF NOT EXISTS issues_client ON issues(client_id); CREATE INDEX IF NOT EXISTS client_messages_issue ON client_messages(issue_id);');
    }

    public function client(int $id): array|false
    {
        return $this->run('SELECT id,name,company,email,active FROM clients WHERE id=?', [$id])->fetch();
    }

    public function clientByEmail(string $email): array|false
    {
        return $this->run('SELECT * FROM clients WHERE email=?', [strtolower(trim($email))])->fetch();
    }

    public function clients(): array
    {
        $clients = $this->db->query('SELECT id,name,company,email,active FROM clients ORDER BY name')->fetchAll();
        foreach ($clients as &$client) {
            $client['project_ids'] = array_column($this->run('SELECT project_id FROM client_projects WHERE client_id=?', [$client['id']])->fetchAll(), 'project_id');
        }
        return $clients;
    }

    public function saveClient(array $input, ?int $id = null): int
    {
        if ($id && !$this->client($id)) { throw new InvalidArgumentException('Client not found.'); }
        foreach (['name' => 100, 'company' => 100, 'email' => 254] as $field => $limit) {
            if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '' || mb_strlen($input[$field]) > $limit) { throw new InvalidArgumentException('Enter a valid ' . $field . '.'); }
        }
        $email = strtolower(trim($input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Enter a valid email address.'); }
        $existing = $this->clientByEmail($email);
        if ($existing && (int) $existing['id'] !== $id) { throw new InvalidArgumentException('A client already uses that email address.'); }
        $password = $input['password'] ?? '';
        if (!is_string($password) || ((!$id || $password !== '') && (strlen($password) < 12 || strlen($password) > 72))) { throw new InvalidArgumentException('Use a password between 12 and 72 bytes.'); }
        $projects = $input['projects'] ?? [];
        if (!is_array($projects)) { throw new InvalidArgumentException('Choose valid projects.'); }
        $projectIds = [];
        foreach ($projects as $project) {
            if (!filter_var($project, FILTER_VALIDATE_INT) || !$this->run('SELECT id FROM projects WHERE id=?', [$project])->fetch()) { throw new InvalidArgumentException('Choose valid projects.'); }
            $projectIds[] = (int) $project;
        }
        $active = isset($input['active']) ? 1 : 0;
        if ($active && !$projectIds) { throw new InvalidArgumentException('Assign at least one project to an active client.'); }
        $this->db->beginTransaction();
        try {
            if ($id) {
                $this->run('UPDATE clients SET name=?, company=?, email=?, active=? WHERE id=?', [trim($input['name']), trim($input['company']), $email, $active, $id]);
                if ($password !== '') { $this->run('UPDATE clients SET password=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]); }
            } else {
                $this->run('INSERT INTO clients (name,company,email,password,active) VALUES (?,?,?,?,?)', [trim($input['name']), trim($input['company']), $email, password_hash($password, PASSWORD_DEFAULT), $active]);
                $id = (int) $this->db->lastInsertId();
            }
            $this->run('DELETE FROM client_projects WHERE client_id=?', [$id]);
            foreach (array_unique($projectIds) as $project) { $this->run('INSERT INTO client_projects VALUES (?,?)', [$id, $project]); }
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
        return $id;
    }

    public function clientProjects(int $client): array
    {
        return $this->run('SELECT projects.id,projects.name,projects.color FROM projects JOIN client_projects cp ON cp.project_id=projects.id JOIN clients ON clients.id=cp.client_id WHERE cp.client_id=? AND clients.active=1 ORDER BY projects.name', [$client])->fetchAll();
    }

    public function clientTickets(int $client): array
    {
        // Client responses deliberately exclude staff identities and internal comments.
        return $this->run('SELECT i.id,i.title,i.description,i.status,i.priority,i.created_at,i.updated_at,p.name AS project_name FROM issues i JOIN projects p ON p.id=i.project_id JOIN client_projects cp ON cp.project_id=i.project_id AND cp.client_id=i.client_id JOIN clients c ON c.id=i.client_id WHERE i.client_id=? AND c.active=1 ORDER BY i.id DESC', [$client])->fetchAll();
    }

    public function clientTicket(int $client, int $issue): array
    {
        foreach ($this->clientTickets($client) as $ticket) { if ((int) $ticket['id'] === $issue) { return $ticket; } }
        throw new \DomainException('Ticket not found or access has been removed.');
    }

    public function submitClientTicket(int $client, array $input, array $images = []): int
    {
        $project = $input['project_id'] ?? null;
        if (!filter_var($project, FILTER_VALIDATE_INT) || !in_array((int) $project, array_column($this->clientProjects($client), 'id'), true)) { throw new InvalidArgumentException('Choose one of your assigned projects.'); }
        $description = $input['description'] ?? '';
        if (!is_string($description) || trim($description) === '') { throw new InvalidArgumentException('Describe your request so our team can help.'); }
        $type = $input['type'] ?? 'Support';
        if (!in_array($type, ['Support', 'Bug', 'Feature'], true)) { throw new InvalidArgumentException('Choose a valid request type.'); }
        if (count($images) > 5) { throw new InvalidArgumentException('Attach up to 5 images per ticket.'); }
        $total = 0;
        foreach ($images as $image) {
            if (!isset($image['storage_key'], $image['filename'], $image['mime'], $image['size']) || !is_string($image['storage_key']) || !preg_match('/^[a-f0-9]{32}$/', $image['storage_key']) || !is_string($image['filename']) || mb_strlen($image['filename']) > 200 || !in_array($image['mime'], TicketImages::MIME_TYPES, true) || !is_int($image['size']) || $image['size'] < 1 || $image['size'] > 2 * 1024 * 1024) { throw new InvalidArgumentException('Invalid ticket image.'); }
            $total += $image['size'];
        }
        if ($total > 6 * 1024 * 1024) { throw new InvalidArgumentException('Keep the total image size up to 6 MB.'); }
        $this->db->beginTransaction();
        try {
            // Only these client-editable fields enter the staff issue system.
            $id = $this->saveIssue(['title' => $input['title'] ?? '', 'description' => $description, 'project_id' => (int) $project, 'priority' => $input['priority'] ?? 'Medium', 'status' => $this->defaultStatus(), 'label' => $type]);
            $this->run('UPDATE issues SET client_id=? WHERE id=?', [$client, $id]);
            foreach ($images as $image) { $this->run('INSERT INTO ticket_images (issue_id,storage_key,filename,mime,size) VALUES (?,?,?,?,?)', [$id, $image['storage_key'], $image['filename'], $image['mime'], $image['size']]); }
            $this->db->commit();
            return $id;
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function clientMessages(int $issue): array
    {
        return $this->run('SELECT m.id,m.body,m.created_at,COALESCE(c.name,u.name) AS name,CASE WHEN m.staff_id IS NULL THEN 0 ELSE 1 END AS from_team FROM client_messages m LEFT JOIN clients c ON c.id=m.client_id LEFT JOIN users u ON u.id=m.staff_id WHERE m.issue_id=? ORDER BY m.id', [$issue])->fetchAll();
    }

    public function ticketImages(int $issue, ?int $client = null): array
    {
        if ($client !== null) { $this->clientTicket($client, $issue); }
        return $this->run('SELECT id,filename,mime,size FROM ticket_images WHERE issue_id=? ORDER BY id', [$issue])->fetchAll();
    }

    public function ticketImage(int $id, ?int $client = null): array
    {
        $image = $this->run('SELECT * FROM ticket_images WHERE id=?', [$id])->fetch();
        if (!$image) { throw new \DomainException('Image not found.'); }
        if ($client !== null) { $this->clientTicket($client, (int) $image['issue_id']); }
        return $image;
    }

    public function addClientMessage(int $issue, mixed $body, ?int $client = null, ?int $staff = null): void
    {
        if ($client !== null) { $this->clientTicket($client, $issue); }
        elseif ($staff === null || !($this->issue($issue)['client_id'] ?? null)) { throw new \DomainException('Client ticket not found.'); }
        if (!is_string($body) || trim($body) === '' || mb_strlen($body) > 5000) { throw new InvalidArgumentException('Write a reply between 1 and 5,000 characters.'); }
        $this->db->beginTransaction();
        try {
            $now = gmdate('c');
            $this->run('INSERT INTO client_messages (issue_id,client_id,staff_id,body,created_at) VALUES (?,?,?,?,?)', [$issue, $client, $staff, trim($body), $now]);
            $this->run('UPDATE issues SET updated_at=? WHERE id=?', [$now, $issue]);
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }

    public function clientConversation(int $id, ?int $client = null): array
    {
        $ticket = $client === null ? $this->issue($id) : $this->clientTicket($client, $id);
        if (!$ticket || ($client === null && !$ticket['client_id'])) { throw new InvalidArgumentException('Client ticket not found.'); }
        $category = $this->run('SELECT category FROM workspace_statuses WHERE name=?', [$ticket['status']])->fetchColumn();
        return ['messages' => $this->clientMessages($id), 'ticket' => ['status' => $ticket['status'], 'priority' => $ticket['priority'], 'updatedLabel' => date('M j, Y · H:i', strtotime($ticket['updated_at'])) . ' UTC', 'closed' => in_array($category, ['done', 'canceled'], true)]];
    }

    private function run(string $sql, array $args = []): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($args);
        return $query;
    }

    public function userByEmail(string $email): array|false
    {
        return $this->run('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))])->fetch();
    }

    public function user(int $id): array|false
    {
        return $this->run('SELECT id, name, email, color FROM users WHERE id = ?', [$id])->fetch();
    }

    public function saveUser(array $input, ?int $id = null): int
    {
        if ($id && !$this->user($id)) { throw new InvalidArgumentException('Teammate not found.'); }
        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100) { throw new InvalidArgumentException('Enter a name up to 100 characters.'); }
        if (!is_string($email) || !filter_var(strtolower(trim($email)), FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) { throw new InvalidArgumentException('Enter a valid email address.'); }
        $email = strtolower(trim($email));
        $existing = $this->userByEmail($email);
        if ($existing && (int) $existing['id'] !== (int) $id) { throw new InvalidArgumentException('A teammate already uses that email address.'); }
        $password = $input['password'] ?? '';
        if (!is_string($password) || ((!$id || $password !== '') && (strlen($password) < 12 || strlen($password) > 72))) { throw new InvalidArgumentException('Use a password between 12 and 72 bytes.'); }
        $color = $input['color'] ?? self::USER_COLORS[array_rand(self::USER_COLORS)];
        if (!is_string($color) || !preg_match('/^#[a-fA-F0-9]{6}$/', $color)) { throw new InvalidArgumentException('Choose a valid color.'); }
        if ($id) {
            $this->run('UPDATE users SET name=?, email=?, color=? WHERE id=?', [trim($name), $email, $color, $id]);
            if ($password !== '') { $this->run('UPDATE users SET password=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]); }
            return $id;
        }
        $this->run('INSERT INTO users (name, email, password, color) VALUES (?,?,?,?)', [trim($name), $email, password_hash($password, PASSWORD_DEFAULT), $color]);
        return (int) $this->db->lastInsertId();
    }

    /** True when local/demo seed credentials remain configured (never in production). */
    public static function demoLoginEnabled(): bool
    {
        return ENVIRONMENT !== 'production' && (string) env('workspace.ownerPassword', 'demo12345') === 'demo12345';
    }

    public function loginAllowed(string $key): bool
    {
        $this->run('DELETE FROM login_attempts WHERE started < ?', [time() - 900]);
        return (int) $this->run('SELECT attempts FROM login_attempts WHERE fingerprint = ?', [$key])->fetchColumn() < 10;
    }

    public function recordLogin(string $key, bool $success): void
    {
        if ($success) {
            $this->run('DELETE FROM login_attempts WHERE fingerprint = ?', [$key]);
        } else {
            $upsert = $this->driver === 'mysql' ? 'ON DUPLICATE KEY UPDATE attempts = attempts + 1' : 'ON CONFLICT(fingerprint) DO UPDATE SET attempts = attempts + 1';
            $this->run('INSERT INTO login_attempts (fingerprint, attempts, started) VALUES (?, 1, ?) ' . $upsert, [$key, time()]);
        }
    }

    public function data(): array
    {
        return [
            'statuses' => $this->statuses(),
            'users' => $this->db->query('SELECT id, name, email, color FROM users')->fetchAll(),
            'projects' => $this->db->query('SELECT * FROM projects ORDER BY id')->fetchAll(),
            'issues' => $this->db->query('SELECT issues.*, clients.name AS client_name, clients.company AS client_company, (SELECT COUNT(*) FROM comments WHERE issue_id = issues.id) AS comment_count FROM issues LEFT JOIN clients ON clients.id=issues.client_id ORDER BY issues.id DESC')->fetchAll(),
        ];
    }

    public function issue(int $id): array|false
    {
        return $this->run('SELECT * FROM issues WHERE id = ?', [$id])->fetch();
    }

    public function saveIssue(array $input, ?int $id = null): int
    {
        $old = $id ? $this->issue($id) : [];
        if ($id && !$old) { throw new InvalidArgumentException('Issue not found.'); }
        $value = array_merge(['title' => '', 'description' => '', 'status' => $this->defaultStatus(), 'priority' => 'No priority', 'assignee_id' => null, 'project_id' => null, 'label' => '', 'due_date' => null], $old ?: [], $input);
        foreach (['title', 'description', 'status', 'priority', 'label'] as $field) {
            if (!is_string($value[$field])) { throw new InvalidArgumentException('Invalid ' . $field . '.'); }
            $value[$field] = trim($value[$field]);
        }
        if ($value['title'] === '' || mb_strlen($value['title']) > 200) { throw new InvalidArgumentException('Add a title between 1 and 200 characters.'); }
        if (mb_strlen($value['description']) > 20000 || mb_strlen($value['label']) > 40) { throw new InvalidArgumentException('Description or label is too long.'); }
        if (!in_array($value['status'], array_column($this->statuses(), 'name'), true) || !in_array($value['priority'], self::PRIORITIES, true)) { throw new InvalidArgumentException('Choose a valid status and priority.'); }
        foreach (['assignee_id' => 'users', 'project_id' => 'projects'] as $field => $table) {
            $value[$field] = $value[$field] === '' ? null : $value[$field];
            if ($value[$field] !== null && (!filter_var($value[$field], FILTER_VALIDATE_INT) || !$this->run("SELECT id FROM $table WHERE id = ?", [$value[$field]])->fetch())) { throw new InvalidArgumentException('Choose a valid ' . ($field === 'assignee_id' ? 'assignee.' : 'project.')); }
        }
        $date = $value['due_date'];
        if ($date && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date)) { throw new InvalidArgumentException('Choose a valid due date.'); }
        $fields = ['title', 'description', 'status', 'priority', 'assignee_id', 'project_id', 'label', 'due_date'];
        $values = array_map(fn ($field) => $field === 'due_date' ? ($value[$field] ?: null) : $value[$field], $fields);
        $values[] = gmdate('c');
        if ($id) {
            $values[] = $id;
            $this->run('UPDATE issues SET title=?, description=?, status=?, priority=?, assignee_id=?, project_id=?, label=?, due_date=?, updated_at=? WHERE id=?', $values);
            return $id;
        }
        $values[] = gmdate('c');
        $this->run('INSERT INTO issues (title, description, status, priority, assignee_id, project_id, label, due_date, updated_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)', $values);
        return (int) $this->db->lastInsertId();
    }

    public function deleteIssue(int $id): void
    {
        if (!$this->issue($id)) { throw new InvalidArgumentException('Issue not found.'); }
        $this->run('DELETE FROM issues WHERE id=?', [$id]);
    }

    public function comments(int $id): array
    {
        return $this->run('SELECT comments.*, users.name, users.color FROM comments JOIN users ON users.id = comments.user_id WHERE issue_id = ? ORDER BY comments.id', [$id])->fetchAll();
    }

    public function addComment(int $issue, int $user, mixed $body): void
    {
        if (!$this->issue($issue)) { throw new InvalidArgumentException('Issue not found.'); }
        if (!is_string($body) || trim($body) === '' || mb_strlen($body) > 5000) { throw new InvalidArgumentException('Write a comment between 1 and 5,000 characters.'); }
        $this->run('INSERT INTO comments (issue_id,user_id,body,created_at) VALUES (?,?,?,?)', [$issue, $user, trim($body), gmdate('c')]);
    }

    public function saveProject(array $input): int
    {
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $color = $input['color'] ?? '#9b8afb';
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 80 || !is_string($description) || mb_strlen($description) > 1000 || !is_string($color) || !preg_match('/^#[a-fA-F0-9]{6}$/', $color)) { throw new InvalidArgumentException('Enter a project name (up to 80 characters), description (up to 1,000), and valid color.'); }
        $this->run('INSERT INTO projects (name,description,color) VALUES (?,?,?)', [trim($name), trim($description), $color]);
        return (int) $this->db->lastInsertId();
    }

    private function seed(): void
    {
        $ownerEmail = env('workspace.ownerEmail');
        $ownerPassword = env('workspace.ownerPassword');
        if (ENVIRONMENT === 'production') {
            if (!is_string($ownerEmail) || !filter_var(strtolower(trim($ownerEmail)), FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Set workspace.ownerEmail in .env before the first database access.');
            }
            if (!is_string($ownerPassword) || strlen($ownerPassword) < 12 || strlen($ownerPassword) > 72) {
                throw new RuntimeException('Set workspace.ownerPassword (12–72 bytes) in .env before the first database access.');
            }
            if ($ownerPassword === 'demo12345') {
                throw new RuntimeException('Choose a unique workspace.ownerPassword; demo12345 is not allowed in production.');
            }
            $this->run('INSERT INTO users (name, email, password, color) VALUES (?,?,?,?)', [
                'Owner', strtolower(trim($ownerEmail)), password_hash($ownerPassword, PASSWORD_DEFAULT), self::USER_COLORS[0],
            ]);
            return;
        }

        $this->db->beginTransaction();
        try {
            $password = password_hash((string) ($ownerPassword ?: 'demo12345'), PASSWORD_DEFAULT);
            $email = is_string($ownerEmail) && filter_var(strtolower(trim($ownerEmail)), FILTER_VALIDATE_EMAIL)
                ? strtolower(trim($ownerEmail)) : 'alex@orbit.local';
            foreach ([[1, 'Alex Morgan', $email, $password, '#9b8afb'], [2, 'Jamie Chen', 'jamie@orbit.local', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), '#e6a36e'], [3, 'Sam Rivera', 'sam@orbit.local', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), '#79bfa5']] as $user) { $this->run('INSERT INTO users VALUES (?,?,?,?,?)', $user); }
            foreach ([['Platform', 'A faster, more reliable foundation for everything we build.', '#9b8afb'], ['Website redesign', 'A fresh home for our product and the people who use it.', '#75b8e9'], ['Mobile app', 'Make great work happen from anywhere.', '#e4a66e']] as $project) { $this->run('INSERT INTO projects (name,description,color) VALUES (?,?,?)', $project); }
            $rows = [
                ['Set up error monitoring and alerts', 'Done', 'High', 2, 1, 'Improvement'],
                ['Create reusable button components', 'Done', 'Medium', 1, 2, 'Design'],
                ['Add keyboard shortcuts for navigation', 'Done', 'Low', 1, 1, 'Feature'],
                ['Explore offline mode for the mobile app', 'Backlog', 'Low', 3, 3, 'Feature'],
                ['Add support for custom workspace themes', 'Backlog', 'No priority', null, 1, 'Feature'],
                ['Design the notification preferences page', 'Backlog', 'Low', 2, 2, 'Design'],
                ['Improve empty states across the app', 'Todo', 'Medium', 1, 2, 'Design'],
                ['Add CSV export for project reports', 'Todo', 'Low', 3, 1, 'Feature'],
                ['Write onboarding email sequence', 'Todo', 'Medium', 2, 2, 'Improvement'],
                ['Build a reusable date picker', 'Todo', 'High', 1, 1, 'Feature'],
                ['Fix layout shift on the pricing page', 'Todo', 'High', 2, 2, 'Bug'],
                ['Update mobile navigation patterns', 'In review', 'Medium', 3, 3, 'Design'],
                ['Review accessibility of form components', 'In review', 'High', 2, 2, 'Improvement'],
                ['Implement workspace search', 'In progress', 'High', 1, 1, 'Feature'],
                ['Refine the onboarding experience', 'In progress', 'Medium', 2, 2, 'Design'],
                ['Fix session timeout on mobile', 'In progress', 'Urgent', 3, 3, 'Bug'],
                ['Optimize dashboard loading performance', 'In progress', 'High', 1, 1, 'Improvement'],
            ];
            foreach ($rows as $row) {
                [$title, $status, $priority, $assignee, $project, $label] = $row;
                $this->saveIssue(['title' => $title, 'description' => "## Overview\n" . $title . ". Focus on a clear, consistent experience across the product.\n\nAcceptance criteria:\n• Works across desktop and mobile\n• Handles loading, empty, and error states\n• Ready for team review", 'status' => $status, 'priority' => $priority, 'assignee_id' => $assignee, 'project_id' => $project, 'label' => $label]);
            }
            $this->addComment(17, 2, 'The initial profiling looks good. Let’s focus on the first load and keep the interface responsive.');
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
}
