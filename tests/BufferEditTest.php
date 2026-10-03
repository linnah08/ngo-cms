<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rescheduling a post that is already in Buffer. The code used to call `updatePost`,
 * which Buffer's API doesn't have ("Cannot query field updatePost", verified 03.10.2026),
 * so every reschedule fell through to creating a second post while the first stayed queued.
 */
final class BufferEditTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/buffer.php';
    }

    public function testEditRequestCarriesTextDatePhotosAndMetadata(): void
    {
        $q = buffer_edit_post_query('abc123', 'Нов "текст"', '2026-10-10T06:00:00Z',
            'assets: [{ image: { url: "https://x.org/a.jpg" } }],', 'facebook: { type: post }');
        $this->assertStringContainsString('editPost(input: {', $q);
        $this->assertStringContainsString('id: "abc123"', $q);
        $this->assertStringContainsString('text: "Нов \"текст\""', $q);
        $this->assertStringContainsString('dueAt: "2026-10-10T06:00:00Z"', $q);
        $this->assertStringContainsString('assets: [{ image: { url: "https://x.org/a.jpg" } }]', $q);
        $this->assertStringContainsString('metadata: { facebook: { type: post } }', $q);
        $this->assertStringContainsString('mode: customScheduled', $q);
        $this->assertStringContainsString('... on MutationError { message }', $q);
    }

    public function testNoPhotosClearsThemAndNoMetadataIsLeftOut(): void
    {
        $q = buffer_edit_post_query('abc', 't', '2026-10-10T06:00:00Z', '', '');
        $this->assertStringContainsString('assets: []', $q);
        $this->assertStringNotContainsString('metadata', $q);
    }

    public function testSuccess(): void
    {
        $r = buffer_edit_outcome(['data' => ['editPost' => ['post' => ['id' => 'abc']]]]);
        $this->assertSame('ok', $r['status']);
    }

    public function testDeletedInBufferMeansCreateAFreshOne(): void
    {
        $r = buffer_edit_outcome(['data' => ['editPost' => ['__typename' => 'NotFoundError', 'message' => 'Document not found']]]);
        $this->assertSame('gone', $r['status']);
    }

    /** Any other failure must not create a second post. */
    public function testOtherFailuresAreErrorsNotRecreates(): void
    {
        foreach ([
            ['data' => ['editPost' => ['__typename' => 'InvalidInputError', 'message' => 'Invalid post']]],
            ['data' => ['editPost' => ['message' => 'Invalid post']]],
            ['errors' => [['message' => 'Cannot query field']]],
            null,
            [],
        ] as $resp) {
            $r = buffer_edit_outcome($resp);
            $this->assertSame('error', $r['status'], json_encode($resp));
            $this->assertNotSame('', $r['message']);
        }
    }

    public function testNoCodeCallsTheRemovedMutation(): void
    {
        foreach (glob(dirname(__DIR__) . '/admin/*.php') as $f) {
            $this->assertStringNotContainsString('updatePost', (string) file_get_contents($f), basename($f));
        }
    }
}
