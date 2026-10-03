<?php
/**
 * Editing a post that is already scheduled in Buffer. Buffer's API has `editPost`
 * (there is no `updatePost` — verified 03.10.2026). A post deleted in Buffer answers
 * NotFoundError; Buffer reports errors with HTTP 200, so the body decides.
 */

/**
 * The editPost mutation: new text, time and photos for a scheduled post.
 * $assets_gql is a ready `assets: [...],` fragment (or '' to clear the photos);
 * $metadata is the inner part of `metadata: { … }` (or '' to leave it out).
 */
function buffer_edit_post_query(string $id, string $text, string $due_at, string $assets_gql, string $metadata): string {
    $assets = $assets_gql !== '' ? rtrim($assets_gql, ', ') : 'assets: []';
    return 'mutation { editPost(input: {
    id: ' . json_encode($id) . ',
    text: ' . json_encode($text, JSON_UNESCAPED_UNICODE) . ',
    dueAt: ' . json_encode($due_at) . ',
    mode: customScheduled,
    schedulingType: automatic,
    ' . $assets . ($metadata !== '' ? ',
    metadata: { ' . $metadata . ' }' : '') . '
  }) {
    __typename
    ... on PostActionSuccess { post { id } }
    ... on MutationError { message }
  } }';
}

/**
 * What an editPost answer means: 'ok' (edited), 'gone' (the post was deleted in Buffer —
 * create a fresh one) or 'error' (anything else — do NOT create a second post).
 *
 * @return array{status: string, message: string}
 */
function buffer_edit_outcome(?array $resp): array {
    $edit = $resp['data']['editPost'] ?? null;
    if (isset($edit['post']['id'])) return ['status' => 'ok', 'message' => ''];
    if (($edit['__typename'] ?? '') === 'NotFoundError') return ['status' => 'gone', 'message' => (string) ($edit['message'] ?? '')];
    $msg = $edit['message'] ?? ($resp['errors'][0]['message'] ?? '');
    return ['status' => 'error', 'message' => $msg !== '' ? (string) $msg : 'Buffer не отговори.'];
}
