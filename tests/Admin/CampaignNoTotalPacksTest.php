<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * „Общо налични бройки за наградата“ (lafetki_total_packs) was one site's field
 * that nothing in ngo-cms reads. The Кампания page no longer shows or saves it;
 * a value already stored stays in the settings table untouched.
 */
#[Group('admin')]
final class CampaignNoTotalPacksTest extends TestCase
{
    public function testCampaignPageNeitherShowsNorSavesTheField(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/campaign.php');
        $this->assertStringNotContainsString('lafetki_total_packs', $src);
        $this->assertStringNotContainsString('Ледоразбивачи', $src);
    }
}
