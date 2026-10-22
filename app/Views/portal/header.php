<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($pageTitle) ?> · Orbit</title>
    <link rel="icon" href="<?= base_url('favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/portal.css') ?>">
</head>
<body class="portal-body <?= esc($bodyClass ?? '') ?>">
<header class="portal-header">
    <a class="brand" href="<?= site_url(($staff ?? false) ? '' : 'portal') ?>"><span class="brand-mark">◒</span> orbit <span class="portal-brand-label"><?= ($staff ?? false) ? 'CLIENT MANAGEMENT' : 'CLIENT PORTAL' ?></span></a>
    <nav aria-label="Portal navigation">
    <?php if ($staff ?? false): ?>
        <a href="<?= site_url() ?>">← Workspace</a><a href="<?= site_url('team') ?>">Team</a><a href="<?= site_url('clients') ?>">Clients</a><a href="<?= site_url('statuses') ?>">Manage statuses</a><a href="<?= site_url('institutes') ?>">Masters</a>
    <?php elseif (isset($client)): ?>
        <a href="<?= site_url('portal') ?>">My tickets</a><span class="portal-identity"><?= esc($client['name']) ?></span>
        <form method="post" action="<?= site_url('portal/logout') ?>"><input type="hidden" name="csrf" value="<?= esc($csrf) ?>"><button class="button subtle">Sign out</button></form>
    <?php else: ?>
        <a href="<?= site_url('login') ?>">Team sign-in →</a>
    <?php endif ?>
    </nav>
</header>
<main class="portal-main">
<?php if ($error): ?><div class="portal-notice error" role="alert"><?= esc($error) ?></div><?php endif ?>
<?php if ($success): ?><div class="portal-notice success" role="status"><?= esc($success) ?></div><?php endif ?>
