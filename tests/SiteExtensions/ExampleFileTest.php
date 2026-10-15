<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The example extensions file stays valid: every entry in it is accepted. */
final class ExampleFileTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['_om_site_extensions_file']);
    }

    public function test_every_example_entry_is_accepted(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/site_extensions.php';
        $file = dirname(__DIR__, 2) . '/includes/site-extensions.example.php';
        $GLOBALS['_om_site_extensions_file'] = $file;
        $raw = require $file;
        $this->assertSame(array_keys($raw['recipients']), array_keys(donation_recipients()));
        $this->assertSame(array_keys($raw['roles']), array_keys(site_admin_roles()));
        $this->assertSame(array_keys($raw['order_filters']), array_keys(site_order_filters()));
        $this->assertSame(array_keys($raw['reports']), array_keys(site_reports()));
        $this->assertSame(array_keys($raw['document_types']), array_keys(site_document_types()));
        $this->assertSame(array_keys($raw['admin_nav']), array_keys(site_admin_nav()));
    }

    public function test_the_real_site_file_is_never_part_of_the_repository(): void
    {
        $tracked = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && git ls-files includes/site-extensions.php 2>/dev/null');
        $this->assertSame('', trim((string) $tracked), 'includes/site-extensions.php belongs to each site, never to a release');
    }
}
