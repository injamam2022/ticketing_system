<?php $values = array_merge($record ?? ['name' => '', 'color' => '#9b88bd', 'category' => 'todo'], $draft); ?>
<form class="portal-form" method="post" action="<?= site_url('statuses' . ($record ? '/' . $record['id'] : '')) ?>">
    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
    <label>Status name<input name="name" required maxlength="30" value="<?= esc($values['name']) ?>" placeholder="e.g. Client Approval"></label>
    <label>Color<input type="color" name="color" value="<?= esc(preg_match('/^#[0-9a-fA-F]{6}$/', $values['color']) ? $values['color'] : '#9b88bd') ?>" required></label>
    <label>Category<select name="category"><?php foreach ($categories as $key => $label): ?><option value="<?= esc($key) ?>" <?= $values['category'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?></select></label>
    <button class="button primary"><?= $record ? 'Save status' : 'Add status' ?></button>
</form>
