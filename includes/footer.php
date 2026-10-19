<?php $u = current_user(); ?>
<?php if ($u): ?>
    </main>
    <footer class="foot noprint">
      <span>&copy; <?= date('Y') ?> Lufwanyama District Telemedicine</span>
      <span>Signed in as <?= e($u['full_name']) ?> &middot; <a href="<?= e(url('/logout.php')) ?>">Sign out</a></span>
    </footer>
  </div>
</div>
<?php else: ?>
</main>
<footer class="foot foot--pub noprint">
  <span>&copy; <?= date('Y') ?> Lufwanyama District Telemedicine</span>
  <span>Built for rural connectivity &middot; your data stays private</span>
</footer>
<?php endif; ?>

<div id="net" class="net" role="alert" hidden><?= e(t('offline')) ?></div>
<script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
