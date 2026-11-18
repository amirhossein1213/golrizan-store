<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$pageKey = 'faq';
$pageTitle = trim((string)setting('faq_page_title','پرسش‌های متداول')) . ' | ' . setting('store_name');
$pageDescription = seo_clean_text((string)setting('faq_page_intro','پاسخ پرسش‌های رایج درباره خرید، پرداخت، ارسال، گارانتی و حساب کاربری.'), 170);
$canonical = app_url('faq.php');
$breadcrumbs = [['name'=>'خانه','url'=>app_url()],['name'=>'پرسش‌های متداول','url'=>$canonical]];
$faqGroups = faq_public_groups();
$faqEntities = [];
foreach ($faqGroups as $group) foreach ($group['items'] as $item) $faqEntities[] = ['@type'=>'Question','name'=>(string)$item['question'],'acceptedAnswer'=>['@type'=>'Answer','text'=>(string)$item['answer']]];
$additionalSeoGraph = $faqEntities ? [['@type'=>'FAQPage','@id'=>$canonical . '#faq','mainEntity'=>$faqEntities]] : [];
require BASE_PATH . '/app/views/header.php';
?>
<main class="faq-page"><section class="page-hero faq-hero"><div class="container"><div class="breadcrumb"><a href="<?=e(app_url())?>">خانه</a><span>/</span><span>پرسش‌های متداول</span></div><span class="faq-hero__eyebrow">راهنمای سریع مشتریان</span><h1><?=e((string)setting('faq_page_title','پرسش‌های متداول'))?></h1><p><?=e((string)setting('faq_page_intro','پاسخ پرسش‌های رایج درباره خرید، پرداخت، ارسال، گارانتی و حساب کاربری.'))?></p></div></section>
<section class="section"><div class="container faq-page__layout"><aside class="faq-page__aside"><strong>دسته‌های راهنما</strong><?php foreach($faqGroups as $group):?><a href="#faq-<?=e($group['key'])?>"><?=e($group['title'])?><span><?=fa_number(count($group['items']))?></span></a><?php endforeach?><a href="<?=e(app_url('trust.php'))?>">مرکز اعتماد فروشگاه</a></aside><div class="faq-page__groups"><?php foreach($faqGroups as $group):?><section id="faq-<?=e($group['key'])?>"><h2><?=e($group['title'])?></h2><?php foreach($group['items'] as $item):?><details class="faq-item"><summary><?=e($item['question'])?><span aria-hidden="true">+</span></summary><div><p><?=nl2br(e($item['answer']))?></p></div></details><?php endforeach?></section><?php endforeach?><?php if(!$faqGroups):?><div class="content-card"><p>هنوز پرسشی برای نمایش ثبت نشده است.</p></div><?php endif?></div></div></section>
<section class="section faq-page__contact"><div class="container"><div><h2>پاسخ خودتان را پیدا نکردید؟</h2><p>پشتیبانی فروشگاه برای پرسش‌های مربوط به کالا و سفارش در دسترس است.</p></div><a class="btn btn-primary" href="<?=e(app_url('contact.php'))?>">ارتباط با پشتیبانی</a></div></section></main>
<?php require BASE_PATH . '/app/views/footer.php';
