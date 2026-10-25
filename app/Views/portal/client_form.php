<?php
if ($draft ?? []) {
    $record = array_merge($record ?? [], $draft, ['project_ids' => array_map('intval', $draft['projects'] ?? []), 'active' => isset($draft['active']) ? 1 : 0]);
}
$existing = isset($record['id']);
?>
<form class="portal-form client-form" method="post" action="<?= site_url('clients' . ($existing ? '/' . $record['id'] : '')) ?>">
    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
    <label>Contact name<input name="name" required maxlength="100" autocomplete="off" value="<?= esc($record['name'] ?? '') ?>" placeholder="e.g. Taylor James"></label>
    <label>Company<input name="company" required maxlength="100" value="<?= esc($record['company'] ?? '') ?>" placeholder="e.g. Acme Studio"></label>
    <label>Email address<input type="email" name="email" required maxlength="254" autocomplete="off" value="<?= esc($record['email'] ?? '') ?>" placeholder="client@company.com"></label>
    <label><?= $existing ? 'New password (optional)' : 'Password' ?><input type="password" name="password" <?= !$existing ? 'required' : '' ?> minlength="12" maxlength="72" autocomplete="new-password" placeholder="<?= $existing ? 'Leave blank to keep current password' : 'At least 12 characters' ?>"></label>
    <fieldset><legend>Assigned projects</legend><p>Clients can submit to these projects and view their own tickets.</p><?php foreach ($projects as $project): ?><label class="checkbox-label"><input type="checkbox" name="projects[]" value="<?= $project['id'] ?>" <?= in_array($project['id'], $record['project_ids'] ?? [], true) ? 'checked' : '' ?>><span><?= esc($project['name']) ?></span></label><?php endforeach ?></fieldset>
    <label class="checkbox-label"><input type="checkbox" name="active" value="1" <?= !$record || $record['active'] ? 'checked' : '' ?>> Account active</label>
    <button class="button primary" type="submit"><?= $existing ? 'Save client access' : 'Create client account' ?> →</button>
    <small><?= $existing ? 'Removing a project also removes portal access to its tickets. Deactivating the account blocks access immediately.' : 'Share the credentials securely. No email is sent automatically.' ?></small>
</form>
