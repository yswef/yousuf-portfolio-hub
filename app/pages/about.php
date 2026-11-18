<?php
page_start(['title' => t('nav_about'), 'desc' => setting_l('bio')]);
page_head(t('about_eyebrow'), t('about_title'), t('about_page_sub'));
?>
<section><div class="container about-grid">
  <div class="about-text prose reveal" dir="auto"><?= md(setting_l('bio')) ?></div>
  <div class="reveal">
    <?php if ($j = setting_l('current_job')): ?><div class="timeline-card now"><h4><?= e(t('fact_now_label')) ?></h4><p><?= e($j) ?></p></div><?php endif; ?>
    <div class="timeline-card"><h4><?= e(t('fact_role_label')) ?></h4><p><?= e(setting_l('title')) ?></p></div>
    <?php if (setting_l('spoken')): ?><div class="timeline-card"><h4><?= e(t('fact_lang_label')) ?></h4><p><?= e(setting_l('spoken')) ?></p></div><?php endif; ?>
    <div class="timeline-card"><h4><?= e(t('fact_status_label')) ?></h4><p><?= e(setting_l('availability') ?: t('fact_status_value')) ?></p></div>
  </div></div></section>
<?php if ($sk = items('skill')): ?><section><div class="container"><?php sec_head(t('skills_eyebrow'), t('skills_title')); skills_grid($sk); ?></div></section><?php endif; ?>
<section><div class="container contact-grid"><div class="reveal"><h2 class="section-title"><?= e(t('sec_links')) ?></h2><a class="btn btn-gold" href="<?= lurl('contact') ?>"><?= e(t('hero_cta_contact')) ?></a></div><?php social_list(); ?></div></section>
<?php page_end(); ?>
