<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $u = require_login();
    $pdo = db();
    $cid = (int)$u['company_id'];

    $catId = (int)($_GET['category_id'] ?? 0);

    // First, load the company's active categories so category ownership is explicit.
    $catStmt = $pdo->prepare('SELECT id, name, expense_type FROM expense_categories WHERE company_id=? AND active=1 ORDER BY id');
    $catStmt->execute([$cid]);
    $categories = $catStmt->fetchAll();

    // Load active items for this company directly. Do not use a JOIN or hidden <option> filtering.
    $itemStmt = $pdo->prepare('SELECT id, name, category_id FROM expense_items WHERE company_id=? AND active=1 ORDER BY name, id');
    $itemStmt->execute([$cid]);
    $items = $itemStmt->fetchAll();

    // Optional legacy fallback: an item attached to a category owned by this company.
    if (!$items) {
        $fallback = $pdo->prepare('SELECT DISTINCT ei.id, ei.name, ei.category_id
            FROM expense_items ei
            INNER JOIN expense_categories ec ON ec.id = ei.category_id
            WHERE ec.company_id=? AND ec.active=1 AND ei.active=1
            ORDER BY ei.name, ei.id');
        $fallback->execute([$cid]);
        $items = $fallback->fetchAll();
    }

    if ($catId > 0) {
        $linked = array_values(array_filter($items, static fn(array $it): bool => (int)($it['category_id'] ?? 0) === $catId));
        // If category has linked items, return only those; otherwise return all active company items.
        if ($linked) $items = $linked;
    }

    echo json_encode([
        'ok' => true,
        'company_id' => $cid,
        'category_id' => $catId,
        'count' => count($items),
        'categories' => $categories,
        'items' => array_map(static fn(array $it): array => [
            'id' => (int)$it['id'],
            'name' => (string)$it['name'],
            'category_id' => (int)($it['category_id'] ?? 0),
        ], $items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
