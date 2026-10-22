<?php $pageTitle = 'Masters'; $staff = true; ?>
<?= $this->include('portal/header') ?>
<section class="portal-hero">
    <div>
        <span class="portal-eyebrow">MASTERS</span>
        <h1>Institutes <span><?= count($institutes) ?></span></h1>
        <p>Project work here is for a master's. Clients choose an institute when they submit a ticket, then pick that institute's project work.</p>
    </div>
    <a class="button primary" href="#add-institute">+ Add institute</a>
</section>
<div class="portal-columns">
    <section>
        <div class="section-title"><h2>Institutes</h2><span class="portal-muted">Master's project work</span></div>
        <?php if (!$institutes): ?><div class="portal-panel portal-empty"><span>◎</span><h3>No institutes yet</h3><p>Add an institute, then add the master's project work clients can submit against.</p></div><?php endif ?>
        <?php foreach ($institutes as $institute): ?>
            <?php
            $works = array_values(array_filter($projects, fn ($project) => (int) ($project['institute_id'] ?? 0) === (int) $institute['id']));
            $unlinked = array_values(array_filter($projects, fn ($project) => empty($project['institute_id'])));
            $open = in_array((string) $editing, [(string) $institute['id'], 'work-' . $institute['id'], 'link-' . $institute['id']], true);
            $workDraft = (string) $editing === 'work-' . $institute['id'] ? $old : [];
            ?>
            <details class="portal-panel client-card" <?= $open ? 'open' : '' ?>>
                <summary>
                    <div class="message-avatar"><?= esc(mb_substr($institute['name'], 0, 1)) ?></div>
                    <div><strong><?= esc($institute['name']) ?></strong><span><?= count($works) ?> master's project <?= count($works) === 1 ? 'work' : 'works' ?></span></div>
                    <span class="edit-hint">Edit ↓</span>
                </summary>
                <form class="portal-form client-form" method="post" action="<?= site_url('institutes/' . $institute['id']) ?>">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <label>Institute name<input name="name" required maxlength="120" value="<?= esc((string) $editing === (string) $institute['id'] ? ($old['name'] ?? $institute['name']) : $institute['name']) ?>"></label>
                    <button class="button primary" type="submit">Save institute →</button>
                </form>
                <div class="master-section">
                    <h3>Master's project work</h3>
                    <?php if (!$works): ?><p class="portal-muted">Nothing linked yet. Add project work, or link a project that already exists.</p><?php endif ?>
                    <ul class="master-works">
                        <?php foreach ($works as $work): ?><li><i style="background:<?= esc($work['color']) ?>"></i><?= esc($work['name']) ?></li><?php endforeach ?>
                    </ul>
                    <form class="portal-form" method="post" action="<?= site_url('institutes/' . $institute['id'] . '/work') ?>">
                        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                        <label>Project work name<input name="name" required maxlength="80" value="<?= esc($workDraft['name'] ?? '') ?>" placeholder="e.g. Thesis portal"></label>
                        <label>Description<textarea name="description" maxlength="1000" rows="3" placeholder="What is this master's project work?"><?= esc($workDraft['description'] ?? '') ?></textarea></label>
                        <label>Color<input type="color" name="color" value="<?= esc($workDraft['color'] ?? '#9b8afb') ?>"></label>
                        <button class="button primary" type="submit">Add project work →</button>
                    </form>
                    <?php if ($unlinked): ?>
                    <form class="portal-form" method="post" action="<?= site_url('institutes/' . $institute['id'] . '/link') ?>">
                        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                        <label>Link an existing project<select name="project_id" required><option value="">Choose a project</option><?php foreach ($unlinked as $project): ?><option value="<?= $project['id'] ?>" <?= (string) ($editing === 'link-' . $institute['id'] ? ($old['project_id'] ?? '') : '') === (string) $project['id'] ? 'selected' : '' ?>><?= esc($project['name']) ?></option><?php endforeach ?></select></label>
                        <button class="button" type="submit">Link project work →</button>
                        <small>Linked work appears when a client on that project changes the institute.</small>
                    </form>
                    <?php endif ?>
                </div>
            </details>
        <?php endforeach ?>
    </section>
    <section class="portal-panel new-ticket-panel" id="add-institute">
        <span class="portal-eyebrow">A PLACE FOR THE WORK</span>
        <h2>Add an institute</h2>
        <p>Clients can switch institute while submitting a ticket. Each institute keeps its own master's project work.</p>
        <form class="portal-form" method="post" action="<?= site_url('institutes') ?>">
            <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
            <label>Institute name<input name="name" required maxlength="120" value="<?= esc($editing === 'new' ? ($old['name'] ?? '') : '') ?>" placeholder="e.g. Northbridge Institute"></label>
            <button class="button primary" type="submit">Add institute →</button>
        </form>
    </section>
</div>
<?= $this->include('portal/footer') ?>
