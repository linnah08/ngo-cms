<?php
/**
 * "Share this page" links — share intents for the CURRENT page (vs
 * social-links.php, which links to our own profiles).
 *
 * Set before requiring: $share_url (absolute), $share_title.
 * Falls back to the current request URI / $page_title. Respects $lang.
 *
 * Instagram is intentionally absent: it has no web share intent.
 */
$share_url   = $share_url   ?? rtrim(SITE_URL, '/') . strtok($_SERVER['REQUEST_URI'], '?');
$share_title = $share_title ?? ($page_title ?? '');
$_sh_lang = (($lang ?? 'bg') === 'en') ? 'en' : 'bg';
$_sh_u = rawurlencode($share_url);
$_sh_t = rawurlencode($share_title);

$_sh_targets = [
    [
        'href' => "https://www.facebook.com/sharer/sharer.php?u={$_sh_u}",
        'aria' => $_sh_lang === 'en' ? 'Share on Facebook (opens in new tab)' : 'Сподели във Facebook (отваря нов прозорец)',
        'path' => 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
    ],
    [
        'href' => "https://www.linkedin.com/sharing/share-offsite/?url={$_sh_u}",
        'aria' => $_sh_lang === 'en' ? 'Share on LinkedIn (opens in new tab)' : 'Сподели в LinkedIn (отваря нов прозорец)',
        'path' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
    ],
    [
        'href' => "mailto:?subject={$_sh_t}&body={$_sh_u}",
        'aria' => $_sh_lang === 'en' ? 'Share by email' : 'Сподели по имейл',
        'path' => 'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
    ],
];

foreach ($_sh_targets as $_sh):
    $_sh_blank = str_starts_with($_sh['href'], 'mailto:') ? '' : 'target="_blank" rel="noopener"';
    ?>
  <a href="<?= h($_sh['href']) ?>" <?= $_sh_blank ?>
     class="btn btn--outline" style="font-size:.8rem;padding:.45rem .9rem;display:inline-flex;align-items:center;gap:.4rem;"
     aria-label="<?= h($_sh['aria']) ?>"><svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="flex-shrink:0;"><path d="<?= $_sh['path'] ?>"/></svg></a>
<?php endforeach; ?>
  <button type="button" class="btn btn--outline om-share-copy" data-url="<?= h($share_url) ?>"
          style="font-size:.8rem;padding:.45rem .9rem;display:inline-flex;align-items:center;gap:.4rem;cursor:pointer;"
          aria-label="<?= $_sh_lang === 'en' ? 'Copy link' : 'Копирай линк' ?>"><svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="flex-shrink:0;"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg><span><?= $_sh_lang === 'en' ? 'Copy link' : 'Копирай линк' ?></span></button>
  <span class="om-share-copied" role="status" aria-live="polite"
        style="display:none;font-size:.8rem;color:var(--teal);align-self:center;"><?= $_sh_lang === 'en' ? 'Copied!' : 'Копирано!' ?></span>
<?php if (empty($GLOBALS['__om_share_js'])): $GLOBALS['__om_share_js'] = true; ?>
<script>
(function(){
  document.addEventListener('click', function(e){
    var b = e.target.closest('.om-share-copy');
    if (!b || !navigator.clipboard) return;
    e.preventDefault();
    navigator.clipboard.writeText(b.getAttribute('data-url')).then(function(){
      var s = b.parentNode.querySelector('.om-share-copied');
      if (!s) return;
      s.style.display = 'inline';
      setTimeout(function(){ s.style.display = 'none'; }, 2000);
    });
  });
})();
</script>
<?php endif; ?>
