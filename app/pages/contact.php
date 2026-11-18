<?php
start_session();
if (setting('contact_enabled', '1') !== '1') { page_start(['title' => t('nav_contact')]); echo '<section class="container doc narrow"><p class="lead">' . e(t('contact_disabled')) . '</p>'; social_list(); echo '</section>'; page_end(); return; }
$msg = ''; $err = ''; $old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = array_map(fn($v) => is_string($v) ? trim($v) : '', $_POST);
    if (!csrf_ok()) $err = t('f_err_csrf');
    elseif (($old['website'] ?? '') !== '') { $msg = t('f_sent'); $old = []; }                 // honeypot: pretend success
    elseif (!rate_hit('contact:' . hmac(client_ip()), 5, 3600)) $err = t('f_err_rate');
    elseif (mb_strlen($old['name'] ?? '') < 2 || !filter_var($old['email'] ?? '', FILTER_VALIDATE_EMAIL) || mb_strlen($old['body'] ?? '') < 10 || mb_strlen($old['body'] ?? '') > 5000) $err = t('f_err_fields');
    else {
        ins('messages', ['name' => mb_substr($old['name'], 0, 150), 'email' => mb_substr($old['email'], 0, 190), 'phone' => mb_substr($old['phone'] ?? '', 0, 50), 'company' => mb_substr($old['company'] ?? '', 0, 150),
            'kind' => mb_substr($old['kind'] ?? '', 0, 100), 'budget' => mb_substr($old['budget'] ?? '', 0, 100), 'subject' => mb_substr($old['subject'] ?? '', 0, 255), 'body' => $old['body'], 'ip_hash' => hmac(client_ip()), 'ts' => time()]);
        $msg = t('f_sent'); $old = [];
    }
}
page_start(['title' => t('nav_contact'), 'desc' => setting_l('contact_text')]);
page_head(t('contact_eyebrow'), t('contact_title'), setting_l('contact_text') ?: t('contact_sub'));
$f = fn($k) => e($old[$k] ?? '');
?>
<section><div class="container contact-grid">
  <div>
    <?php if ($msg): ?><div class="note ok"><?= e($msg) ?></div><?php endif; ?><?php if ($err): ?><div class="note bad"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="contact-form" novalidate><?= csrf_field() ?>
      <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">
      <label><?= e(t('f_name')) ?> *<input name="name" required maxlength="150" value="<?= $f('name') ?>"></label>
      <label><?= e(t('f_email')) ?> *<input name="email" type="email" required maxlength="190" dir="ltr" value="<?= $f('email') ?>"></label>
      <div class="two"><label><?= e(t('f_phone')) ?><input name="phone" maxlength="50" dir="ltr" value="<?= $f('phone') ?>"></label><label><?= e(t('f_company')) ?><input name="company" maxlength="150" value="<?= $f('company') ?>"></label></div>
      <div class="two"><label><?= e(t('f_kind')) ?><input name="kind" list="kinds" maxlength="100" value="<?= $f('kind') ?>"><datalist id="kinds"><?php foreach (items('service') as $s): ?><option value="<?= e(tr($s, 'title')) ?>"><?php endforeach; ?></datalist></label><label><?= e(t('f_budget')) ?><input name="budget" maxlength="100" value="<?= $f('budget') ?>"></label></div>
      <label><?= e(t('f_subject')) ?><input name="subject" maxlength="255" value="<?= $f('subject') ?>"></label>
      <label><?= e(t('f_message')) ?> *<textarea name="body" rows="6" required maxlength="5000"><?= $f('body') ?></textarea></label>
      <button class="btn btn-gold" type="submit"><?= e(t('f_send')) ?></button>
    </form>
  </div>
  <?php social_list(); ?>
</div></section>
<?php page_end(); ?>
