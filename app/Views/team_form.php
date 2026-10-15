<?php
if ($draft ?? []) {
    $record = array_merge($record ?? [], $draft);
}
$existing = isset($record['id']);
?>
<form class="portal-form client-form" method="post" action="<?= site_url('team' . ($existing ? '/' . $record['id'] : '')) ?>">
    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
    <label>Name<input name="name" required maxlength="100" autocomplete="off" value="<?= esc($record['name'] ?? '') ?>" placeholder="e.g. Alex Morgan"></label>
    <label>Email address<input type="email" name="email" required maxlength="254" autocomplete="off" value="<?= esc($record['email'] ?? '') ?>" placeholder="you@company.com"></label>
    <label><?= $existing ? 'New password (optional)' : 'Password' ?><input type="password" name="password" <?= !$existing ? 'required' : '' ?> minlength="12" maxlength="72" autocomplete="new-password" placeholder="<?= $existing ? 'Leave blank to keep current password' : 'At least 12 characters' ?>"></label>
    <label>Avatar color<input type="color" name="color" value="<?= esc($record['color'] ?? ($colors[0] ?? '#9b8afb')) ?>"></label>
    <button class="button primary" type="submit"><?= $existing ? 'Save teammate' : 'Add teammate' ?> →</button>
    <small><?= $existing ? 'A blank password keeps the current password.' : 'Share credentials securely. No invitation email is sent.' ?></small>
</form>
