<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AJAX endpoints behind an admin page must refuse the roles that page refuses. These three
 * checked only that someone was logged in: an author could reorder partners (Админ → Партньори
 * is admin-only), and a shop manager could rewrite an article's social posts (the article
 * editor is admin or author only).
 */
#[Group('admin')]
final class RoleGuardedAjaxTest extends TestCase
{
    public static function refused(): array
    {
        return [
            'author reorders partners'          => ['admin/partners-reorder.php', 'author'],
            'shop manager reorders partners'    => ['admin/partners-reorder.php', 'shop_admin'],
            'shop manager edits social posts'   => ['admin/social-ajax.php', 'shop_admin'],
            'shop manager edits LinkedIn posts' => ['admin/linkedin-ajax.php', 'shop_admin'],
        ];
    }

    #[DataProvider('refused')]
    public function test_role_the_admin_page_refuses_is_refused(string $rel, string $role): void
    {
        $before = is_file(PARTNERS_FILE) ? file_get_contents(PARTNERS_FILE) : null;
        $r = run_admin_page($rel, [], ['role' => $role, 'json' => ['order' => [], 'action' => 'save', 'slug' => 'x']]);
        $this->assertSame(403, $r['status']);
        $this->assertSame($before, is_file(PARTNERS_FILE) ? file_get_contents(PARTNERS_FILE) : null);
    }

    public static function allowed(): array
    {
        return [
            'admin reorders partners'  => ['admin/partners-reorder.php', 'admin'],
            'author edits social posts' => ['admin/social-ajax.php', 'author'],
            'author edits LinkedIn posts' => ['admin/linkedin-ajax.php', 'author'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_role_the_admin_page_allows_gets_past_the_check(string $rel, string $role): void
    {
        // No real body: the endpoint answers with its own error, but not with "Access denied".
        $r = run_admin_page($rel, [], ['role' => $role, 'method' => 'GET']);
        $this->assertNotSame(403, $r['status']);
        $this->assertStringNotContainsString('Access denied', $r['body']);
    }
}
