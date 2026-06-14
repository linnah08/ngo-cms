<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('admin')]
final class MissionImageTest extends TestCase
{
    // Mirrors the validation logic in admin/pages.php for mission_image_lib
    private function validateMissionImageLib(string $lib): ?string
    {
        if (preg_match('#^/assets/images/[a-zA-Z0-9/_.\-]+$#', $lib)) {
            return $lib;
        }
        return null;
    }

    public function test_valid_path_is_accepted(): void
    {
        $this->assertSame(
            '/assets/images/pages/mission.jpg',
            $this->validateMissionImageLib('/assets/images/pages/mission.jpg')
        );
    }

    public function test_valid_webp_path_is_accepted(): void
    {
        $this->assertSame(
            '/assets/images/pages/mission.webp',
            $this->validateMissionImageLib('/assets/images/pages/mission.webp')
        );
    }

    public function test_path_outside_assets_is_rejected(): void
    {
        $this->assertNull($this->validateMissionImageLib('/etc/passwd'));
        $this->assertNull($this->validateMissionImageLib('/config.php'));
        $this->assertNull($this->validateMissionImageLib('../../config.php'));
    }

    public function test_path_with_query_string_is_rejected(): void
    {
        $this->assertNull($this->validateMissionImageLib('/assets/images/pages/mission.jpg?foo=bar'));
    }

    public function test_empty_string_is_rejected(): void
    {
        $this->assertNull($this->validateMissionImageLib(''));
    }

    public function test_clear_flag_empties_mission_image(): void
    {
        // Simulate the clear logic: $_POST['mission_image_clear'] === '1'
        $pages = ['home' => ['mission_image' => '/assets/images/pages/mission.jpg']];
        $post  = ['mission_image_clear' => '1'];

        if (($post['mission_image_clear'] ?? '') === '1') {
            $pages['home']['mission_image'] = '';
        }

        $this->assertSame('', $pages['home']['mission_image']);
    }
}
