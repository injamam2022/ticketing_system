<?php if ($images): ?>
<section class="ticket-images-section" aria-label="Attached images">
    <div class="ticket-images-heading"><h2>Attached images <span><?= count($images) ?></span></h2><p>Open an image to view it at full size.</p></div>
    <div class="ticket-image-gallery">
        <?php foreach ($images as $image): ?>
        <a class="ticket-image-tile" href="<?= site_url(($staff ? 'clients' : 'portal') . '/images/' . $image['id']) ?>" target="_blank" rel="noopener" aria-label="Open <?= esc($image['filename']) ?> at full size">
            <img src="<?= site_url(($staff ? 'clients' : 'portal') . '/images/' . $image['id']) ?>" alt="<?= esc($image['filename']) ?>" loading="lazy">
            <span><?= esc($image['filename']) ?></span><small><?= number_format($image['size'] / 1024) ?> KB ↗</small>
        </a>
        <?php endforeach ?>
    </div>
</section>
<?php endif ?>
