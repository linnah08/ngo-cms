<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecting Buffer in Settings. The setup used to ask for `account.currentOrganization`,
 * which Buffer now answers with "Not authorized to access this resource" for API keys
 * (verified 04.10.2026) — so no new key could be connected. `account.organizations` works.
 */
final class BufferSetupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/buffer.php';
    }

    public function testOrganizationQueryDoesNotAskForCurrentOrganization(): void
    {
        $q = buffer_organizations_query();
        $this->assertStringNotContainsString('currentOrganization', $q);
        $this->assertStringContainsString('organizations { id name }', $q);
    }

    public function testPicksTheOnlyOrganization(): void
    {
        $orgs = [['id' => 'o1', 'name' => 'My Organization']];
        $pick = buffer_pick_organization($orgs, fn($id) => [['id' => 'c1', 'name' => 'x', 'service' => 'facebook']]);
        $this->assertSame('o1', $pick['org']['id']);
        $this->assertSame('c1', $pick['channels'][0]['id']);
    }

    public function testSkipsOrganizationsWithoutSupportedChannels(): void
    {
        $orgs = [['id' => 'o1', 'name' => 'Empty'], ['id' => 'o2', 'name' => 'Real']];
        $channels = [
            'o1' => [['id' => 'c0', 'name' => 'y', 'service' => 'youtube']],
            'o2' => [['id' => 'c2', 'name' => 'z', 'service' => 'linkedin']],
        ];
        $pick = buffer_pick_organization($orgs, fn($id) => $channels[$id]);
        $this->assertSame('o2', $pick['org']['id']);
    }

    public function testNoSupportedChannelsAnywhereFallsBackToFirstOrganization(): void
    {
        $orgs = [['id' => 'o1', 'name' => 'A'], ['id' => 'o2', 'name' => 'B']];
        $pick = buffer_pick_organization($orgs, fn($id) => [['id' => 'c-' . $id, 'name' => 'y', 'service' => 'youtube']]);
        $this->assertSame('o1', $pick['org']['id']);
        $this->assertSame('c-o1', $pick['channels'][0]['id']);
    }

    public function testNoOrganizationsGivesNull(): void
    {
        $this->assertNull(buffer_pick_organization([], fn($id) => []));
    }
}
