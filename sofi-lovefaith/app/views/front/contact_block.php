<?php
/** @var array $b block */
$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);
$sent = !empty($_GET['sent']);
?>
<div class="wrap contact-grid" id="form">
  <div class="contact-side">
    <a class="wa-card" href="<?= e(wa_link()) ?>" target="_blank" rel="noopener">
      <span class="wa-ic"><?= icon('whatsapp', 30) ?></span>
      <span class="wa-kicker"><?= e(bv($b, 'wa_title', 'WhatsApp')) ?></span>
      <span class="wa-num"><?= e(wa_display()) ?></span>
      <span><?= nl2br(e(bv($b, 'wa_text'))) ?></span>
      <span class="wa-cta"><?= e(t('chat_wa')) ?> →</span>
    </a>
    <?php if ($soc = socials()): ?>
    <div class="card">
      <strong class="card-title"><?= e(t('follow_us')) ?></strong>
      <div class="soc-grid">
        <?php foreach ($soc as $k => $u): ?>
          <a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= icon($k, 20) ?><?= e($k === 'instagram' ? '@' . basename(strtok($u, '?')) : ucfirst($k)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <form class="card contact-form" method="post" action="<?= e(url('/contact')) ?>">
    <?= csrf_field() ?>
    <h2><?= e(bv($b, 'form_title')) ?></h2>
    <?php if ($v = bv($b, 'form_text')): ?><p class="muted"><?= e($v) ?></p><?php endif; ?>
    <?php if ($sent): ?><p class="notice ok" role="status"><?= icon('check', 18) ?><?= e(t('form_thanks')) ?></p><?php endif; ?>
    <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <div class="form-grid">
      <label><?= e(t('name')) ?><input name="name" required maxlength="190" value="<?= e($old['name'] ?? '') ?>" autocomplete="name"></label>
      <label><?= e(t('email')) ?><input name="email" type="email" required maxlength="190" value="<?= e($old['email'] ?? '') ?>" autocomplete="email"></label>
      <label><?= e(t('phone')) ?><input name="phone" type="tel" maxlength="60" value="<?= e($old['phone'] ?? '') ?>" autocomplete="tel" placeholder="(656) 000-0000"></label>
      <label><?= e(t('subject')) ?>
        <select name="subject">
          <?php foreach (['subj_product', 'subj_order', 'subj_custom', 'subj_wholesale', 'subj_other'] as $s): ?>
            <option <?= ($old['subject'] ?? '') === t($s) ? 'selected' : '' ?>><?= e(t($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label><?= e(t('message')) ?><textarea name="message" rows="6" required maxlength="5000"><?= e($old['message'] ?? '') ?></textarea></label>
    <label class="check"><input type="checkbox" name="newsletter" value="1"> <?= e(t('newsletter_opt')) ?></label>
    <button class="btn btn-primary block" type="submit"><?= e(t('send')) ?></button>
  </form>
</div>
