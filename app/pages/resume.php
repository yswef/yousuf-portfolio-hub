<?php
page_start(['title' => t('resume_title')]);
page_head(t('nav_resume'), setting_l('name'), setting_l('title'));
?>
<section class="resume"><div class="container">
  <p class="no-print"><button class="btn btn-ghost" data-print><?= e(t('print')) ?></button></p>
  <?php if ($ex = items('experience')): ?><h2 class="section-title"><?= e(t('sec_exp')) ?></h2><?php timeline($ex); endif; ?>
  <?php if ($ed = items('education')): ?><h2 class="section-title" style="margin-top:50px"><?= e(t('sec_edu')) ?></h2><?php timeline($ed); endif; ?>
  <?php if ($sk = items('skill')): ?><h2 class="section-title" style="margin-top:50px"><?= e(t('skills_title')) ?></h2><?php skills_grid($sk); endif; ?>
  <?php if ($lr = items('learning')): ?><h2 class="section-title" style="margin-top:50px"><?= e(t('sec_learning')) ?></h2><?php learning_list($lr); endif; ?>
  <?php if (setting_l('spoken')): ?><h2 class="section-title" style="margin-top:50px"><?= e(t('fact_lang_label')) ?></h2><p><?= e(setting_l('spoken')) ?></p><?php endif; ?>
</div></section>
<?php page_end(); ?>
