<?php
$nProj = (int)val('SELECT COUNT(*) FROM projects WHERE hidden=0');
$nApps = (int)val('SELECT COUNT(*) FROM apps WHERE hidden=0 AND ignored=0');
$dl = (int)val('SELECT COALESCE(SUM(f.gh_count),0) FROM app_files f JOIN apps a ON a.id=f.app_id WHERE a.hidden=0 AND a.ignored=0 AND f.hidden=0') + (int)val('SELECT COUNT(*) FROM downloads');
$projOrder = projects_order_sql();
$appOrder = apps_order_sql();
$featured = rows("SELECT * FROM projects WHERE hidden=0 AND featured=1 ORDER BY $projOrder LIMIT 6") ?: rows("SELECT * FROM projects WHERE hidden=0 ORDER BY $projOrder LIMIT 6");
$apps = rows("SELECT a.*, ((SELECT COALESCE(SUM(gh_count),0) FROM app_files f WHERE f.app_id=a.id AND f.hidden=0) + (SELECT COUNT(*) FROM downloads d WHERE d.app_id=a.id)) AS dl_total FROM apps a WHERE a.hidden=0 AND a.ignored=0 ORDER BY $appOrder LIMIT 3");
$name = setting_l('name'); $bd = setting('birthdate');
$ld = ['@context' => 'https://schema.org', '@type' => 'Person', 'name' => $name, 'jobTitle' => setting_l('title'), 'url' => site_origin() . '/' . cur_lang() . '/', 'sameAs' => array_values(array_filter(array_map(fn($l) => safe_url((string)$l['extra']), items('link'))))];
page_start(['title' => '', 'jsonld' => $ld]);
$words = array_filter(array_map('trim', explode(',', setting_l('focus'))));
$parts = explode(' ', $name); $last = array_pop($parts);
?>
<section class="hero" id="home">
  <div class="hero-orb o1"></div><div class="hero-orb o2"></div>
  <div class="container hero-grid">
    <div>
      <?php if (setting('available', '1') === '1'): ?><span class="badge"><i></i><?= e(setting_l('availability') ?: t('badge_available')) ?></span><?php endif; ?>
      <p class="eyebrow"><?= e(t('hero_greeting')) ?></p>
      <h1 class="hero-title"><?= e(implode(' ', $parts)) ?> <span class="accent"><?= e($last) ?></span></h1>
      <p class="hero-role"><?= e(setting_l('title')) ?></p>
      <?php if ($words): ?><p class="term" dir="ltr"><span class="prompt">&gt;</span> <span data-typed="<?= e(implode('|', $words)) ?>"><?= e(reset($words)) ?></span><span class="caret"></span></p><?php endif; ?>
      <p class="hero-tagline"><?= e(setting_l('tagline')) ?></p>
      <div class="hero-cta"><a href="<?= lurl('projects') ?>" class="btn btn-gold"><?= e(t('hero_cta_projects')) ?></a><a href="<?= lurl('contact') ?>" class="btn btn-ghost"><?= e(t('hero_cta_contact')) ?></a></div>
      <div class="hero-stats">
        <?php if (setting('show_age', '1') === '1' && $bd): ?><div class="hero-stat"><div class="num" data-counter="<?= age_from($bd) ?>">0</div><div class="label"><?= e(t('stat_age')) ?></div></div><?php endif; ?>
        <div class="hero-stat"><div class="num" data-counter="<?= $nProj ?>">0</div><div class="label"><?= e(t('stat_projects')) ?></div></div>
        <?php if ($nApps): ?><div class="hero-stat"><div class="num" data-counter="<?= $nApps ?>">0</div><div class="label"><?= e(t('stat_apps')) ?></div></div><?php endif; ?>
        <?php if ($dl): ?><div class="hero-stat"><div class="num" data-counter="<?= $dl ?>">0</div><div class="label"><?= e(t('stat_downloads')) ?></div></div><?php endif; ?>
        <div class="hero-stat"><div class="num" data-counter="<?= count(LANGS) ?>">0</div><div class="label"><?= e(t('stat_langs')) ?></div></div>
      </div>
    </div>
    <div class="hero-portrait"><div class="hero-portrait-ring"><img src="<?= e(avatar_url()) ?>" alt="<?= e($name) ?>" width="320" height="320"></div></div>
  </div>
</section>

<?php if (($job = setting_l('current_job')) !== ''): ?>
<section class="now-strip"><div class="container"><div class="now-card reveal"><span class="chip gold"><?= e(t('now_label')) ?></span><p><?= e($job) ?></p><a href="<?= lurl('resume') ?>"><?= e(t('nav_resume')) ?> →</a></div></div></section>
<?php endif; ?>

<section id="about"><div class="container about-grid">
  <div class="about-text reveal"><p class="eyebrow"><?= e(t('about_eyebrow')) ?></p><h2 class="section-title"><?= e(t('about_title')) ?></h2>
    <?php $bio = preg_split('/\R\R/', setting_l('bio'))[0] ?? ''; ?><p><?= e($bio) ?></p><a class="btn btn-ghost" href="<?= lurl('about') ?>"><?= e(t('read_more')) ?></a></div>
  <div class="reveal">
    <div class="timeline-card"><h4><?= e(t('fact_role_label')) ?></h4><p><?= e(t('fact_role_value')) ?></p></div>
    <?php if (setting_l('spoken')): ?><div class="timeline-card"><h4><?= e(t('fact_lang_label')) ?></h4><p><?= e(setting_l('spoken')) ?></p></div><?php endif; ?>
    <div class="timeline-card"><h4><?= e(t('fact_status_label')) ?></h4><p><?= e(setting_l('availability') ?: t('fact_status_value')) ?></p></div>
  </div></div></section>

<?php if ($skills = items('skill')): ?><section id="skills"><div class="container"><?php sec_head(t('skills_eyebrow'), t('skills_title'), t('skills_sub')); skills_grid($skills); ?></div></section><?php endif; ?>
<?php if ($featured): ?><section id="projects"><div class="container"><?php sec_head(t('projects_eyebrow'), t('sec_featured'), t('projects_sub'), lurl('projects')); ?><div class="projects-grid"><?php foreach ($featured as $p) project_card($p); ?></div></div></section><?php endif; ?>
<?php if ($apps): ?><section><div class="container"><?php sec_head(t('nav_apps'), t('sec_apps'), '', lurl('apps')); ?><div class="grid"><?php foreach ($apps as $a) app_card($a); ?></div></div></section><?php endif; ?>
<?php if ($sv = items('service')): ?><section id="services"><div class="container"><?php sec_head(t('services_eyebrow'), t('services_title'), t('services_sub'), lurl('services')); ?><div class="services-grid"><?php foreach (array_slice($sv, 0, 6) as $s) service_card($s); ?></div></div></section><?php endif; ?>
<?php if ($lr = items('learning')): ?><section><div class="container"><?php sec_head(t('nav_learning'), t('sec_learning')); learning_list($lr); ?></div></section><?php endif; ?>
<section id="contact"><div class="container contact-grid">
  <div class="reveal"><p class="eyebrow"><?= e(t('contact_eyebrow')) ?></p><h2 class="section-title"><?= e(t('contact_title')) ?></h2><p class="section-sub"><?= e(setting_l('contact_text') ?: t('contact_sub')) ?></p><a class="btn btn-gold" href="<?= lurl('contact') ?>"><?= e(t('hero_cta_contact')) ?></a></div>
  <?php social_list(); ?></div></section>
<?php page_end(); ?>
