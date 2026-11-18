<?php
page_start(['title' => t('social_title')]);
page_head(t('nav_social'), t('social_title'), t('social_sub'));
?>
<section><div class="container narrow"><?php social_list(); ?></div></section>
<?php page_end(); ?>
