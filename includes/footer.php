<?php $u = current_user(); ?>
</main>
<?php if ($u): ?>
    <footer class="site-footer noprint">
      <div class="site-footer__bar">
        <span>&copy; <?= date('Y') ?> Lufwanyama District Telemedicine</span>
        <span>Built for rural connectivity &middot; your data stays private</span>
      </div>
    </footer>
  </div>
</div>
<?php else: ?>
<footer class="site-footer noprint">
  <div class="pub">
    <div class="site-footer__bar">
      <span>&copy; <?= date('Y') ?> Lufwanyama District Telemedicine</span>
      <span>Built for rural connectivity &middot; your data stays private</span>
    </div>
  </div>
</footer>
<?php endif; ?>

<div id="net" class="net" role="alert" hidden><?= e(t('offline')) ?></div>
<script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
