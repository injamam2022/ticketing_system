<?php $pageTitle = 'Manage statuses'; $staff = true; ?>
<?= $this->include('portal/header') ?>
<section class="portal-hero"><div><span class="portal-eyebrow">WORKSPACE SETTINGS</span><h1>Manage statuses</h1><p>Create a workflow that fits your team. These statuses apply to all projects and client tickets.</p></div><a class="button primary" href="#add-status">+ Add status</a></section>
<div class="portal-columns"><section><div class="section-title"><h2>Your workflow</h2><span class="portal-muted">Use arrows to change the list and board order.</span></div>
<?php foreach ($statuses as $index => $status): ?>
<details class="portal-panel status-card" <?= (string) $editing === (string) $status['id'] ? 'open' : '' ?>>
    <summary><span class="project-dot" style="background:<?= esc($status['color']) ?>"></span><strong><?= esc($status['name']) ?></strong><span class="portal-muted"><?= esc($categories[$status['category']]) ?></span><span class="edit-hint">Edit</span></summary>
    <?= view('status_form', ['record' => $status, 'categories' => $categories, 'csrf' => $csrf, 'draft' => (string) $editing === (string) $status['id'] ? $old : []]) ?>
    <form class="status-order" method="post" action="<?= site_url('statuses/' . $status['id'] . '/move') ?>">
        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
        <button class="button subtle" name="direction" value="up" aria-label="Move <?= esc($status['name']) ?> up" <?= $index === 0 ? 'disabled' : '' ?>>↑ Move up</button>
        <button class="button subtle" name="direction" value="down" aria-label="Move <?= esc($status['name']) ?> down" <?= $index === count($statuses) - 1 ? 'disabled' : '' ?>>↓ Move down</button>
    </form>
</details>
<?php endforeach ?>
</section><section class="portal-panel new-ticket-panel" id="add-status"><span class="portal-eyebrow">YOUR TEAM'S WORKFLOW</span><h2>Add a status</h2><p>For example: Blocked, QA, Client Approval, or Ready For Launch.</p>
<?= view('status_form', ['record' => null, 'categories' => $categories, 'csrf' => $csrf, 'draft' => $editing === 'new' ? $old : []]) ?>
<p class="portal-muted">Categories control active work and completion totals. Renaming a status also updates tickets that use it.</p></section></div>
<?= $this->include('portal/footer') ?>
