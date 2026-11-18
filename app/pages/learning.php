<?php
page_start(['title' => t('learning_title')]);
page_head(t('nav_learning'), t('learning_title'), t('learning_sub'));
?>
<section><div class="container"><?php learning_list(items('learning')); ?></div></section>
<?php page_end(); ?>
