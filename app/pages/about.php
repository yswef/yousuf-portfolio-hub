<?php
$bioText = setting_l('bio') ?: setting_l('tagline') ?: t('hero_tagline');
$name = setting_l('name');
$role = setting_l('title') ?: t('fact_role_value');
$exp = items('experience', 4);
$lr = items('learning', 4);
page_start(['title' => t('nav_about'), 'desc' => $bioText]);
page_head(t('about_eyebrow'), t('about_title'), t('about_page_sub'));
?>
<section><div class="container about-grid">
  <div class="about-text prose reveal" dir="auto">
    <div class="about-profile-bar">
      <img src="<?= e(avatar_url()) ?>" alt="<?= e($name) ?>" width="72" height="72">
      <div>
        <h3><?= e($name) ?></h3>
        <p class="muted" style="margin:0"><?= e($role) ?></p>
      </div>
    </div>
    <?= md($bioText) ?>
    <div class="cta" style="margin-top:24px">
      <a class="btn btn-gold" href="<?= lurl('projects') ?>"><?= e(t('hero_cta_projects')) ?></a>
      <a class="btn btn-ghost" href="<?= lurl('resume') ?>"><?= e(t('nav_resume')) ?></a>
    </div>
  </div>
  <div class="reveal">
    <?php if ($j = setting_l('current_job')): ?><div class="timeline-card now"><h4><?= e(t('fact_now_label')) ?></h4><p><?= e($j) ?></p></div><?php endif; ?>
    <div class="timeline-card"><h4><?= e(t('fact_role_label')) ?></h4><p><?= e($role) ?></p></div>
    <?php if ($fc = setting_l('focus')): ?><div class="timeline-card"><h4><?= e(t('fact_focus_label')) ?></h4><p><?= e($fc) ?></p></div><?php endif; ?>
    <?php if ($sp = setting_l('spoken')): ?><div class="timeline-card"><h4><?= e(t('fact_lang_label')) ?></h4><p><?= e($sp) ?></p></div><?php endif; ?>
    <div class="timeline-card"><h4><?= e(t('fact_status_label')) ?></h4><p><?= e(setting_l('availability') ?: t('fact_status_value')) ?></p></div>
  </div>
</div></section>
<?php if ($exp): ?><section><div class="container"><?php sec_head(t('nav_resume'), t('sec_exp'), '', lurl('resume')); timeline($exp); ?></div></section><?php endif; ?>
<?php if ($sk = items('skill')): ?><section><div class="container"><?php sec_head(t('skills_eyebrow'), t('skills_title'), t('skills_sub')); skills_grid($sk); ?></div></section><?php endif; ?>
<?php if ($lr): ?><section><div class="container"><?php sec_head(t('nav_learning'), t('sec_learning'), '', lurl('learning')); learning_list($lr); ?></div></section><?php endif; ?>
<section><div class="container contact-grid"><div class="reveal"><p class="eyebrow"><?= e(t('contact_eyebrow')) ?></p><h2 class="section-title"><?= e(t('sec_links')) ?></h2><p class="section-sub"><?= e(setting_l('contact_text') ?: t('contact_sub')) ?></p><a class="btn btn-gold" href="<?= lurl('contact') ?>"><?= e(t('hero_cta_contact')) ?></a></div><?php social_list(); ?></div></section>
<?php page_end(); ?>
