<?php
page_start(['title' => t('nf_title')]);
?>
<section class="container doc narrow" style="text-align:center;padding-top:140px"><h1>404</h1><p class="lead"><?= e(t('nf_title')) ?> — <?= e(t('nf_text')) ?></p><a class="btn btn-gold" href="<?= lurl() ?>"><?= e(t('go_home')) ?></a></section>
<?php page_end(); ?>
