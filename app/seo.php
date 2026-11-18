<?php
declare(strict_types=1);

function seo_clean_text(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    if ($text === '') return '';
    return text_length($text) > $limit ? rtrim(text_substr($text, 0, $limit - 1)) . '…' : $text;
}

function seo_default_description(): string
{
    $configured = seo_clean_text((string)setting('seo_default_description', ''), 170);
    if ($configured !== '') return $configured;
    return seo_clean_text(setting('store_name', 'فروشگاه اینترنتی') . '؛ مشاهده قیمت، موجودی، مشخصات و خرید آنلاین با پشتیبانی فروشگاه.', 170);
}

function seo_page_schema(string $title, string $description, string $canonical, array $breadcrumbs = []): array
{
    $storeName = (string)setting('store_name', 'فروشگاه اینترنتی');
    $storeUrl = app_url();
    $organization = [
        '@type'=>'Organization','@id'=>$storeUrl . '#organization','name'=>$storeName,'url'=>$storeUrl,
        'telephone'=>(string)setting('phone', setting('mobile', '')),
        'email'=>(string)setting('email', ''),
    ];
    $logoPath = trim((string)setting('seo_logo_image', ''));
    if ($logoPath !== '') {
        $organization['logo'] = public_image($logoPath);
    } else {
        $organization['logo'] = asset('images/branding/golrizan-logo.webp');
    }
    $graph = [
        $organization,
        ['@type'=>'WebSite','@id'=>$storeUrl . '#website','url'=>$storeUrl,'name'=>$storeName,
            'publisher'=>['@id'=>$storeUrl . '#organization'],
            'potentialAction'=>['@type'=>'SearchAction','target'=>app_url('shop.php?q={search_term_string}'),'query-input'=>'required name=search_term_string']],
        ['@type'=>'WebPage','@id'=>$canonical . '#webpage','url'=>$canonical,'name'=>$title,'description'=>$description,'isPartOf'=>['@id'=>$storeUrl . '#website']],
    ];
    if ($breadcrumbs) {
        $items = [];
        foreach (array_values($breadcrumbs) as $index => $item) {
            if (!is_array($item) || empty($item['name']) || empty($item['url'])) continue;
            $items[] = ['@type'=>'ListItem','position'=>$index + 1,'name'=>(string)$item['name'],'item'=>(string)$item['url']];
        }
        if ($items) $graph[] = ['@type'=>'BreadcrumbList','itemListElement'=>$items];
    }
    return ['@context'=>'https://schema.org','@graph'=>$graph];
}

function seo_json(array $data): string
{
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
}
