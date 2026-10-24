<?php $code = 'ORB-' . str_pad($issue['id'], 3, '0', STR_PAD_LEFT); ?>
<!doctype html>
<html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($code . ' · ' . $issue['title']) ?> · JIS</title>
    <link rel="icon" href="<?= base_url('assets/jis-logo.png') ?>" type="image/png">
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>?v=<?= filemtime(FCPATH . 'assets/app.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/issue.css') ?>?v=<?= filemtime(FCPATH . 'assets/issue.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/discussion.css') ?>">
</head><body class="issue-page">
<header class="issue-page-header"><a class="brand" href="<?= site_url() ?>"><img class="brand-logo" src="<?= base_url('assets/jis-logo.png') ?>" alt=""> JIS</a><nav aria-label="Breadcrumb"><a href="<?= site_url() ?>">Workspace</a><span>/</span><strong><?= esc($code) ?></strong></nav><a class="button subtle" id="back-to-issues" href="<?= site_url() ?>">← Back to issues</a></header>
<main class="issue-page-main">
    <div class="issue-page-heading"><div><span class="heading-label">ISSUE DETAILS</span><h1><?= esc($code) ?> <span id="issue-status-badge"><?= esc($issue['status']) ?></span></h1><p>Created <?= esc(date('M j, Y', strtotime($issue['created_at']))) ?> · <span id="saved-at">Last updated <?= esc(date('M j, Y', strtotime($issue['updated_at']))) ?></span></p></div><button class="button primary" form="issue-form" id="save-issue" type="submit">Save changes</button></div>
    <div id="issue-notice" class="issue-notice" hidden role="status"></div>
    <p id="issue-error" class="form-error" hidden role="alert"></p>
    <?php if ($client): ?><div id="client-request-info" class="client-request-info"><span>Client request · <?= esc($client['name']) ?> / <?= esc($client['company']) ?></span><a href="<?= site_url('clients/tickets/' . $issue['id']) ?>">Open client conversation →</a></div><?php endif ?>
    <?= view('ticket_image_gallery', ['images' => $ticketImages, 'staff' => true]) ?>
    <form id="issue-form" class="issue-page-grid">
        <section class="issue-content-panel"><label for="issue-title" class="field-heading">Title</label><textarea id="issue-title" name="title" class="issue-title-input" aria-label="Issue title" required maxlength="200" rows="1"><?= esc($issue['title']) ?></textarea><label for="issue-description" class="field-heading description-heading">Description</label><textarea id="issue-description" name="description" class="description-input" maxlength="20000" placeholder="Add context and acceptance criteria…"><?= esc($issue['description']) ?></textarea></section>
        <aside class="issue-properties-panel"><h2>Properties</h2><div class="issue-properties">
            <label>Status<select id="edit-status" name="status"><?php foreach ($statuses as $value): ?><option <?= $issue['status'] === $value ? 'selected' : '' ?>><?= esc($value) ?></option><?php endforeach ?></select></label>
            <label>Priority<select id="edit-priority" name="priority"><?php foreach (\App\Libraries\WorkspaceStore::PRIORITIES as $value): ?><option <?= $issue['priority'] === $value ? 'selected' : '' ?>><?= esc($value) ?></option><?php endforeach ?></select></label>
            <label>Name of Institute<select id="edit-institute" name="institute_id"><option value="">No institute</option><?php foreach ($institutes as $institute): ?><option value="<?= (int) $institute['id'] ?>" <?= (string) ($issue['institute_id'] ?? '') === (string) $institute['id'] ? 'selected' : '' ?>><?= esc($institute['name']) ?></option><?php endforeach ?></select></label>
            <label>Project<select id="edit-project" name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= $project['id'] ?>" <?= (string) $issue['project_id'] === (string) $project['id'] ? 'selected' : '' ?>><?= esc($project['name']) ?><?php if (!empty($project['institute_name'])): ?> · <?= esc($project['institute_name']) ?><?php endif ?></option><?php endforeach ?></select></label>
            <label>Assignee<select id="edit-assignee" name="assignee_id"><option value="">Unassigned</option><?php foreach ($users as $user): ?><option value="<?= $user['id'] ?>" <?= $issue['assignee_id'] === $user['id'] ? 'selected' : '' ?>><?= esc($user['name']) ?></option><?php endforeach ?></select></label>
            <label>Label<input id="edit-label" name="label" maxlength="40" value="<?= esc($issue['label']) ?>" list="labels" placeholder="Add label"><datalist id="labels"><option>Feature</option><option>Bug</option><option>Design</option><option>Improvement</option><option>Support</option></datalist></label>
            <label>Due date<input id="edit-due" name="due_date" type="date" value="<?= esc($issue['due_date'] ?? '') ?>"></label>
        </div><div class="issue-delete-area"><p id="delete-confirmation" hidden>Delete this issue and all its comments? This cannot be undone.</p><button class="button subtle danger" id="delete-issue" type="button">Delete issue</button></div></aside>
    </form>
    <?php if ($client): ?><?= view('issue_client_conversation', ['issue' => $issue, 'client' => $client, 'clientMessages' => $clientMessages]) ?><?php endif ?>
    <section class="issue-page-activity" id="activity-section"><div class="activity-heading"><h2>Internal activity <span id="activity-count"><?= count($comments) ?></span></h2><p>Visible only to your team</p></div><div id="comments-list">
        <?php if (!$comments): ?><p class="no-comments">Start the conversation. Share an update or ask a question.</p><?php endif ?>
        <?php foreach ($comments as $comment): ?><article class="comment"><span class="avatar" style="--avatar:<?= esc($comment['color']) ?>"><?= esc(mb_substr($comment['name'], 0, 1)) ?></span><div><strong><?= esc($comment['name']) ?></strong><time><?= esc(date('M j, Y · H:i', strtotime($comment['created_at']))) ?> UTC</time><p><?= esc($comment['body']) ?></p></div></article><?php endforeach ?>
    </div><form id="comment-form"><label class="field-heading" for="comment-body">Add a comment</label><textarea id="comment-body" disabled required maxlength="5000" placeholder="Share an update with your team…"></textarea><button class="button" type="submit">Post comment →</button></form><p id="comment-error" class="form-error" hidden role="alert"></p></section>
</main>
<script>window.ISSUE_PAGE = <?= json_encode(['id' => $issue['id'], 'code' => $code, 'api' => site_url('api/'), 'home' => site_url(), 'csrf' => $csrf, 'discussion' => $discussion, 'users' => $users, 'issueUrl' => site_url('issues/'), 'related' => $relatedIssues, 'reactions' => \App\Libraries\WorkspaceStore::REACTIONS], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= base_url('assets/issue.js') ?>" defer></script>
<script src="<?= base_url('assets/discussion.js') ?>" defer></script>
<script src="<?= base_url('assets/mentions.js') ?>" defer></script>
<script src="<?= base_url('assets/client-conversation.js') ?>" defer></script>
</body></html>
