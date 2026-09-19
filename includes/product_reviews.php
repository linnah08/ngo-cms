<?php
/**
 * Product reviews — schema + data helpers.
 * Pure functions take rows shaped like the product_reviews table.
 */

/** @return array{count:int, avg:float} */
function product_reviews_aggregate(array $reviews): array
{
    $count = count($reviews);
    if ($count === 0) return ['count' => 0, 'avg' => 0.0];
    $sum = 0;
    foreach ($reviews as $r) $sum += (int)$r['rating'];
    return ['count' => $count, 'avg' => round($sum / $count, 1)];
}

/**
 * Build the JSON-LD fragment (aggregateRating + review[]) from approved review rows.
 * Returns [] when there are no reviews — caller leaves the Product schema untouched.
 * $limit caps how many individual review nodes are emitted; reviewCount stays over all rows.
 */
function product_reviews_schema(array $reviews, int $limit = 5): array
{
    $agg = product_reviews_aggregate($reviews);
    if ($agg['count'] === 0) return [];

    $nodes = [];
    foreach (array_slice($reviews, 0, $limit) as $r) {
        $nodes[] = [
            '@type'        => 'Review',
            'author'       => ['@type' => 'Person', 'name' => $r['author_name']],
            'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int)$r['rating'], 'bestRating' => 5],
            'reviewBody'   => $r['content'],
            'datePublished'=> substr((string)$r['created_at'], 0, 10),
        ];
    }

    return [
        'aggregateRating' => [
            '@type'       => 'AggregateRating',
            'ratingValue' => $agg['avg'],
            'reviewCount' => $agg['count'],
        ],
        'review' => $nodes,
    ];
}

/** Fetch approved reviews for a product, newest first. */
function product_reviews_fetch_approved(PDO $pdo, int $product_id): array
{
    $stmt = $pdo->prepare(
        "SELECT author_name, rating, content, created_at, verified_purchase
         FROM product_reviews
         WHERE product_id = ? AND status = 'approved'
         ORDER BY created_at DESC"
    );
    $stmt->execute([$product_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * True if a paid, non-cancelled order exists for this email containing this
 * product — an order that was never paid is not a purchase.
 * orders.items is a JSON array with no cross-engine-portable way to search its
 * contents in SQL (MariaDB 10.5 has no JSON_TABLE; JSON_CONTAINS wildcard paths
 * aren't supported the way this needs) — decode in PHP instead. Order counts
 * per email are small, so this is cheap. Computed once at submission time by
 * the caller and stored on the review row, not recomputed on every render.
 *
 * Known limitation: only relies on `product_id` inside each items[] entry,
 * which the primary checkout (checkout/index.php) always writes. Orders from
 * a historical import or a manually created invoice that skip that path
 * wouldn't carry it, so a genuine buyer whose only order came through one of
 * those paths won't get a verified badge — a false negative, not a security
 * issue (it never grants a badge incorrectly).
 */
function product_review_is_verified_purchase(PDO $pdo, string $email, int $product_id): bool
{
    if ($email === '' || $product_id <= 0) return false;

    $stmt = $pdo->prepare(
        "SELECT items FROM orders WHERE customer_email = ? AND payment_status = 'paid' AND status != 'cancelled'"
    );
    $stmt->execute([$email]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $itemsJson) {
        $items = json_decode((string)$itemsJson, true) ?: [];
        foreach ($items as $item) {
            if ((int)($item['product_id'] ?? 0) === $product_id) {
                return true;
            }
        }
    }
    return false;
}

/** Store a one-shot review feedback message, separate from the generic cart flash. */
function product_review_flash_set(string $type, string $message = ''): void
{
    start_session();
    $_SESSION['product_review_flash'] = ['type' => $type, 'message' => $message];
}

/** Return and clear the one-shot review feedback message, or null if none. */
function product_review_flash_get(): ?array
{
    start_session();
    if (empty($_SESSION['product_review_flash'])) {
        return null;
    }
    $f = $_SESSION['product_review_flash'];
    unset($_SESSION['product_review_flash']);
    return $f;
}
