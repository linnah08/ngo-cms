<?php
/**
 * „Файлове за изтегляне“ under an article: one large button per PDF of this site the
 * article's text links to (file_links_in_html() in includes/files.php). Nothing when
 * it links to none. Needs $article and $lang. Styles are inline so a stale main.css
 * on the server cannot break it.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/files.php';
$_downloads = file_links_in_html((string) ($article['content'] ?? ''));
if ($_downloads):
?>
      <section aria-labelledby="article-downloads-title" style="margin-top:2.5rem;padding:1.25rem;border:1px solid var(--border);border-radius:12px;background:#f8fafb;">
        <h2 id="article-downloads-title" style="font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin:0 0 1rem;"><?= te('news.downloads', 'Файлове за изтегляне', 'Files to download', $lang ?? null) ?></h2>
        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.75rem;">
          <?php foreach ($_downloads as $_d): ?>
          <li style="margin:0;">
            <a href="<?= h($_d['href']) ?>" download target="_blank" rel="noopener"
               aria-label="<?= h(t_or('news.download', 'Изтегли', 'Download', $lang ?? null) . ' „' . $_d['name'] . '“ (PDF, ' . $_d['size'] . ')') ?>"
               style="display:flex;align-items:center;gap:1rem;min-height:44px;padding:.85rem 1rem;background:#fff;border:1px solid var(--border);border-radius:10px;color:inherit;text-decoration:none;">
              <svg aria-hidden="true" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:#026B83;"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"></path><path d="M14 3v5h5"></path><path d="M12 11v6"></path><path d="m9.5 14.5 2.5 2.5 2.5-2.5"></path></svg>
              <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:.15rem;">
                <span style="font-weight:600;line-height:1.35;overflow-wrap:anywhere;"<?= cms_admin('articles') ?>><?= h($_d['name']) ?></span>
                <span style="font-size:.85rem;color:var(--text-muted);"<?= cms_admin('articles') ?>>PDF · <?= h($_d['size']) ?></span>
              </span>
              <span style="flex-shrink:0;padding:.55rem 1rem;border-radius:8px;background:#026B83;color:#fff;font-weight:600;font-size:.9rem;"><?= te('news.download', 'Изтегли', 'Download', $lang ?? null) ?></span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
<?php endif; ?>
