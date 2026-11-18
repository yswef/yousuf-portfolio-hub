<?php
page_start(['title' => t('services_title'), 'desc' => t('services_page_sub')]);
page_head(t('services_eyebrow'), t('services_title'), t('services_page_sub'));
?>
<section><div class="container"><div class="services-grid"><?php foreach (items('service') as $s) service_card($s); ?></div>
<p style="margin-top:40px"><a class="btn btn-gold" href="<?= lurl('contact') ?>"><?= e(t('hero_cta_contact')) ?></a></p></div></section>
<?php page_end(); ?>
