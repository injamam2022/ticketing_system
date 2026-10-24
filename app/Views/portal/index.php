<?php $closedStatuses = array_column(array_filter($statusDefinitions, fn ($status) => in_array($status['category'], ['done', 'canceled'], true)), 'name'); ?>
<?php $pageTitle = 'Your support tickets'; $open = count(array_filter($tickets, fn ($ticket) => !in_array($ticket['status'], $closedStatuses, true))); ?>
<?= $this->include('portal/header') ?>
<section class="portal-hero"><div><span class="portal-eyebrow"><?= esc($client['company']) ?> / SUPPORT SPACE</span><h1>How can we help, <?= esc(explode(' ', $client['name'])[0]) ?>?</h1><p>Your projects and requests, all in one place. We’re here to help you move forward.</p></div><a class="button primary" href="#new-ticket">+ Submit a ticket</a></section>
<div class="portal-stats"><div><span>Assigned projects</span><strong><?= count($projects) ?></strong></div><div><span>Open requests</span><strong><?= $open ?></strong></div><div><span>Resolved requests</span><strong><?= count($tickets) - $open ?></strong></div></div>
<div class="portal-columns">
    <section class="portal-ticket-list">
        <div class="section-title"><h2>Your tickets <span><?= count($tickets) ?></span></h2><span class="portal-muted">Updates from your project team</span></div>
        <div class="portal-list-tools"><input type="search" id="ticket-search" aria-label="Search your tickets" placeholder="Search tickets by title or project…"><select id="ticket-status" aria-label="Filter ticket status"><option value="">All requests</option><option value="open">Open</option><option value="done">Resolved</option></select></div>
        <div class="portal-panel request-list">
        <?php foreach ($tickets as $ticket): ?>
            <a class="portal-ticket-row" data-ticket-status="<?= in_array($ticket['status'], $closedStatuses, true) ? 'done' : 'open' ?>" href="<?= site_url('portal/tickets/' . $ticket['id']) ?>">
                <div class="request-icon"><?= in_array($ticket['status'], $closedStatuses, true) ? '✓' : '↗' ?></div><div class="request-content"><span class="request-reference">ORB-<?= str_pad($ticket['id'], 3, '0', STR_PAD_LEFT) ?> · <?= !empty($ticket['institute_name']) ? esc($ticket['institute_name']) . ' · ' : '' ?><?= esc($ticket['project_name']) ?></span><h3><?= esc($ticket['title']) ?></h3><span class="portal-muted">Submitted <?= esc(date('M j, Y', strtotime($ticket['created_at']))) ?> · <?= esc($ticket['priority']) ?> priority<?= !empty($ticket['due_date']) ? ' · Due ' . esc(date('M j, Y', strtotime($ticket['due_date']))) : '' ?></span></div><span class="portal-status <?= in_array($ticket['status'], $closedStatuses, true) ? 'resolved' : '' ?>"><?= esc($ticket['status']) ?></span>
            </a>
        <?php endforeach ?>
        <div class="portal-empty" id="ticket-empty" <?= $tickets ? 'hidden' : '' ?>><span>✦</span><h3><?= $tickets ? 'No matching tickets' : 'A clear space for your requests' ?></h3><p><?= $tickets ? 'Try another search or status.' : 'Submit your first ticket and follow its progress here.' ?></p></div>
        </div>
        <div class="assigned-projects"><h3>Your projects</h3><?php foreach ($projects as $project): ?><span><i style="background:<?= esc($project['color']) ?>"></i><?= esc($project['name']) ?></span><?php endforeach ?><?php if (!$projects): ?><p>Your team hasn’t assigned any projects yet. Contact them to get access.</p><?php endif ?></div>
    </section>
    <section class="portal-panel new-ticket-panel" id="new-ticket">
        <div class="ticket-form-heading"><span class="ticket-form-icon" aria-hidden="true">↗</span><div><span class="portal-eyebrow">LET'S GET IT SORTED</span><h2>Submit a ticket</h2></div></div><p>Describe the problem and add screenshots so your team can help faster.</p>
        <form class="portal-form" id="ticket-submit-form" method="post" enctype="multipart/form-data" action="<?= site_url('portal/tickets') ?>">
            <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
            <?php
            $institutes = $institutes ?? [];
            $linkedProjects = count(array_filter($projects, fn ($project) => !empty($project['institute_id'])));
            $instituteRequired = $projects && $institutes && $linkedProjects === count($projects);
            ?>
            <?php if ($institutes): ?><label>Institute<select name="institute_id" id="ticket-institute" <?= $instituteRequired ? 'required' : '' ?>><option value="">Choose your institute</option><?php foreach ($institutes as $institute): ?><option value="<?= (int) $institute['id'] ?>" <?= (string) ($old['institute_id'] ?? '') === (string) $institute['id'] ? 'selected' : '' ?>><?= esc($institute['name']) ?></option><?php endforeach ?></select></label><?php endif ?>
            <label><?= $linkedProjects ? 'Project work' : 'Project' ?><select name="project_id" id="ticket-project" required><option value=""><?= $linkedProjects ? 'Choose your master\'s project work' : 'Choose your project' ?></option><?php foreach ($projects as $project): ?><option value="<?= $project['id'] ?>" data-institute="<?= esc((string) ($project['institute_id'] ?? '')) ?>" <?= (string) ($old['project_id'] ?? '') === (string) $project['id'] ? 'selected' : '' ?>><?= esc($project['name']) ?></option><?php endforeach ?></select></label>
            <?php if ($institutes): ?><p class="upload-help">Choose your institute and the master's project work. The ticket is filed under the institute you select.</p><?php endif ?>
            <label>Ticket title<input name="title" required maxlength="200" placeholder="A short summary of your request" value="<?= esc($old['title'] ?? '') ?>"></label>
            <div class="form-pair"><label>Due date<input type="date" name="due_date" required value="<?= esc($old['due_date'] ?? '') ?>"></label><label>Priority<select name="priority"><?php foreach (['Medium', 'Low', 'High', 'Urgent'] as $priority): ?><option <?= ($old['priority'] ?? '') === $priority ? 'selected' : '' ?>><?= $priority ?></option><?php endforeach ?></select></label></div>
            <label>What can we help with?<textarea name="description" required maxlength="20000" rows="8" placeholder="What happened? What did you expect? Include any steps to reproduce the problem."><?= esc($old['description'] ?? '') ?></textarea></label>
            <div class="ticket-upload-section">
                <div class="upload-section-title"><strong>Screenshots &amp; images</strong><span>Optional</span></div>
                <input class="ticket-file-input" id="ticket-images" type="file" name="images[]" accept="image/png,image/jpeg,image/gif,image/webp" multiple aria-label="Attach images" aria-describedby="ticket-image-help ticket-image-error">
                <label class="ticket-dropzone" for="ticket-images" id="ticket-dropzone"><span class="upload-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg></span><strong>Choose images <span>or drag them here</span></strong><span>PNG, JPG, GIF or WebP</span></label>
                <p class="upload-help" id="ticket-image-help">Up to 5 images · 2 MB each · 6 MB total. You can add more before submitting.</p>
                <?php if ($error): ?><p class="upload-help">If you attached images, please select them again before submitting.</p><?php endif ?>
                <p class="upload-error" id="ticket-image-error" role="alert" hidden></p>
                <div class="ticket-upload-previews" id="ticket-image-previews" aria-live="polite"></div>
            </div>
            <button class="button primary" type="submit" <?= !$projects ? 'disabled' : '' ?>>Submit ticket <span>→</span></button><small>Your ticket will go directly to the project team.</small>
        </form>
    </section>
</div>
<?= $this->include('portal/footer') ?>
