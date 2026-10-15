<?php if (!$messages): ?><p class="portal-muted">No replies yet. Replies sent to the client appear here.</p><?php endif ?>
<?php foreach ($messages as $message): ?>
<article class="portal-message" data-message-id="<?= (int) $message['id'] ?>">
    <div class="message-avatar <?= $message['from_team'] ? 'team' : '' ?>"><?= esc(mb_substr($message['name'], 0, 1)) ?></div>
    <div><header><strong><?= esc($message['name']) ?></strong><?php if ($message['from_team']): ?><span class="team-badge">TEAM</span><?php endif ?><time><?= esc(date('M j, Y · H:i', strtotime($message['created_at']))) ?> UTC</time></header><p class="preserve-text"><?= esc($message['body']) ?></p></div>
</article>
<?php endforeach ?>
