<section class="issue-client-conversation client-conversation" data-feed="<?= site_url('api/issues/' . $issue['id'] . '/client-conversation') ?>">
    <div class="activity-heading"><h2>Client conversation <span data-message-count><?= count($clientMessages) ?></span></h2><p>Shared with <?= esc($client['name']) ?></p></div>
    <p class="client-reply-hint">Replies here are visible in the client portal. Use Internal activity below for private team notes.</p>
    <div data-message-list><?= view('portal/messages', ['messages' => $clientMessages]) ?></div>
    <form id="client-reply-form" data-client-reply action="<?= site_url('api/issues/' . $issue['id'] . '/client-conversation') ?>">
        <label for="client-reply-body">Reply to client</label>
        <textarea id="client-reply-body" name="body" required maxlength="5000" rows="4" placeholder="Write a reply to <?= esc($client['name']) ?>…"></textarea>
        <p class="client-reply-hint">Visible to the client</p>
        <button class="button primary" type="submit">Send reply to client</button>
    </form>
    <p data-conversation-error class="form-error" role="alert" hidden></p>
    <p data-conversation-notice class="client-reply-notice" role="status" hidden></p>
</section>
