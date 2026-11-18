<?php
declare(strict_types=1);

function blog_status_labels(): array
{
    return ['draft'=>'پیش‌نویس','published'=>'منتشرشده','archived'=>'بایگانی‌شده'];
}

function blog_post_url(array $post): string
{
    return app_url('blog/' . rawurlencode((string)$post['slug']));
}

function blog_plain_text(string $content): string
{
    $content = preg_replace('/^#{1,3}\s+/mu', '', $content) ?? $content;
    $content = preg_replace('/^[-*]\s+/mu', '', $content) ?? $content;
    return trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '');
}

function blog_excerpt(array $post, int $length = 180): string
{
    $excerpt = trim((string)($post['excerpt'] ?? ''));
    if ($excerpt === '') $excerpt = blog_plain_text((string)($post['content'] ?? ''));
    return text_length($excerpt) > $length ? rtrim(text_substr($excerpt, 0, $length - 1)) . '…' : $excerpt;
}

function blog_reading_minutes(string $content): int
{
    $plain = blog_plain_text($content);
    if ($plain === '') return 1;
    $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return max(1, (int)ceil(count($words) / 180));
}

function blog_render_content(string $content): string
{
    $lines = preg_split('/\R/u', trim($content)) ?: [];
    $html = '';
    $paragraph = [];
    $inList = false;
    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if (!$paragraph) return;
        $html .= '<p>' . implode('<br>', array_map('e', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $closeList = static function () use (&$html, &$inList): void {
        if (!$inList) return;
        $html .= '</ul>';
        $inList = false;
    };
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();
            $level = min(4, strlen($matches[1]) + 1);
            $html .= '<h' . $level . '>' . e(trim($matches[2])) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches)) {
            $flushParagraph();
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . e(trim($matches[1])) . '</li>';
            continue;
        }
        $closeList();
        $paragraph[] = $trimmed;
    }
    $flushParagraph();
    $closeList();
    return $html;
}

function blog_categories(bool $activeOnly = true): array
{
    $where = $activeOnly ? ' WHERE bc.is_active=1' : '';
    $sql = "SELECT bc.*,COUNT(CASE WHEN bp.status='published' AND bp.published_at<=NOW() THEN 1 END) post_count
            FROM blog_categories bc LEFT JOIN blog_posts bp ON bp.category_id=bc.id{$where}
            GROUP BY bc.id ORDER BY bc.sort_order,bc.name";
    return db()->query($sql)->fetchAll();
}

function blog_latest_posts(int $limit = 6): array
{
    $limit = max(1, min(24, $limit));
    $stmt = db()->prepare("SELECT bp.*,bc.name category_name,bc.slug category_slug,a.full_name author_name
        FROM blog_posts bp JOIN blog_categories bc ON bc.id=bp.category_id
        LEFT JOIN admins a ON a.id=bp.author_id
        WHERE bp.status='published' AND bp.published_at<=NOW() AND bc.is_active=1
        ORDER BY bp.is_featured DESC,bp.published_at DESC,bp.id DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function blog_find_post_by_slug(string $slug, bool $includeUnpublished = false): ?array
{
    $where = $includeUnpublished ? '' : " AND bp.status='published' AND bp.published_at<=NOW() AND bc.is_active=1";
    $stmt = db()->prepare("SELECT bp.*,bc.name category_name,bc.slug category_slug,a.full_name author_name
        FROM blog_posts bp JOIN blog_categories bc ON bc.id=bp.category_id
        LEFT JOIN admins a ON a.id=bp.author_id WHERE bp.slug=?{$where} LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function blog_related_posts(array $post, int $limit = 3): array
{
    $limit = max(1, min(6, $limit));
    $stmt = db()->prepare("SELECT bp.*,bc.name category_name,bc.slug category_slug
        FROM blog_posts bp JOIN blog_categories bc ON bc.id=bp.category_id
        WHERE bp.status='published' AND bp.published_at<=NOW() AND bc.is_active=1 AND bp.id<>?
        ORDER BY (bp.category_id=?) DESC,bp.published_at DESC,bp.id DESC LIMIT ?");
    $stmt->bindValue(1, (int)$post['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$post['category_id'], PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function blog_count_view_once(int $postId): bool
{
    if ($postId < 1) return false;
    $userAgent = strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($userAgent === '' || preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|headless|monitor|uptime|preview/i', $userAgent)) return false;
    $views = is_array($_SESSION['_blog_counted_views'] ?? null) ? $_SESSION['_blog_counted_views'] : [];
    $now = time();
    foreach ($views as $id => $timestamp) if ((int)$timestamp < $now - 86400) unset($views[$id]);
    if ((int)($views[$postId] ?? 0) >= $now - 21600) {
        $_SESSION['_blog_counted_views'] = $views;
        return false;
    }
    try {
        db()->prepare('UPDATE blog_posts SET views_count=views_count+1 WHERE id=?')->execute([$postId]);
    } catch (Throwable $exception) {
        log_error($exception);
        return false;
    }
    $views[$postId] = $now;
    $_SESSION['_blog_counted_views'] = array_slice($views, -100, null, true);
    return true;
}

function save_blog_upload(array $file): string
{
    return save_managed_image_upload($file, 'blog', 5 * 1024 * 1024, 320, 180, 2400);
}

function delete_blog_upload(string $path): void
{
    delete_managed_upload($path);
}
