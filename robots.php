<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
if ((string)setting('seo_indexing_enabled','1') !== '1') {
    echo "User-agent: *\nDisallow: /\n";
    exit;
}
echo "User-agent: *
";
echo "Allow: /
";
echo "Allow: " . (string)(parse_url(app_url('api/torob-feed.php'), PHP_URL_PATH) ?: '/api/torob-feed.php') . "
";
echo "Allow: " . (string)(parse_url(app_url('api/torob-products-v3.php'), PHP_URL_PATH) ?: '/api/torob-products-v3.php') . "
";
echo "Disallow: " . (string)(parse_url(app_url('admin/'), PHP_URL_PATH) ?: '/admin/') . "
";
echo "Disallow: " . (string)(parse_url(app_url('api/'), PHP_URL_PATH) ?: '/api/') . "
";
echo "Disallow: " . (string)(parse_url(app_url('install/'), PHP_URL_PATH) ?: '/install/') . "
";
echo "Sitemap: " . app_url('sitemap.xml') . "
";
