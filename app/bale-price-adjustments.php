<?php
declare(strict_types=1);

function bale_price_adjustment_session_matches(?array $session, string $userId, array $steps, array $expected = []): bool
{
    if (!$session || (string)($session['user_id'] ?? '') !== $userId || !in_array((string)($session['step'] ?? ''), $steps, true)) return false;
    $data = is_array($session['data'] ?? null) ? $session['data'] : [];
    foreach ($expected as $key=>$value) if ((string)($data[$key] ?? '') !== (string)$value) return false;
    return true;
}

function bale_price_adjustment_batch_line(array $batch): string
{
    $direction = price_adjustment_direction_labels()[(string)($batch['direction'] ?? '')] ?? 'تغییر';
    return $direction . ' ' . price_adjustment_percent_label((int)($batch['basis_points'] ?? 0))
        . ' — ' . fa_number((int)($batch['product_count'] ?? 0)) . ' محصول'
        . ((int)($batch['variant_count'] ?? 0) > 0 ? ' و ' . fa_number((int)$batch['variant_count']) . ' مدل' : '');
}

function bale_price_adjustment_menu_text(): string
{
    $lines = [
        '📈 افزایش یا کاهش گروهی قیمت‌ها',
        '━━━━━━━━━━━━━━━━━━',
        'قیمت پایه همه محصولات ساده و تمام مدل‌های محصولات متغیر را درصدی افزایش یا کاهش دهید.',
        'قیمت خرید و درصد اختصاصی درگاه‌ها تغییر نمی‌کند. محصولات دارای تخفیف محدود فعال یا زمان‌بندی‌شده برای حفظ کمپین رد می‌شوند.',
    ];
    $latest = price_adjustment_latest_applied_batch();
    if ($latest) $lines[] = "\nآخرین تغییر قابل بازگردانی:\n" . bale_price_adjustment_batch_line($latest);
    return implode("\n", $lines);
}

function bale_price_adjustment_menu_markup(): array
{
    $rows = [
        [[ 'text'=>'📈 افزایش درصدی همه قیمت‌ها','callback_data'=>'bpa:direction:increase' ]],
        [[ 'text'=>'📉 کاهش درصدی همه قیمت‌ها','callback_data'=>'bpa:direction:decrease' ]],
    ];
    $latest = price_adjustment_latest_applied_batch();
    if ($latest) $rows[] = [[ 'text'=>'↩️ بازگردانی آخرین تغییر','callback_data'=>'bpa:undo:'.(int)$latest['id'] ]];
    return bale_product_bot_inline_keyboard($rows);
}

function bale_price_adjustment_start(string $chatId, string $userId): void
{
    bale_product_bot_session_save($chatId, $userId, 'bulk_price_menu', []);
    bale_product_bot_send($chatId, bale_price_adjustment_menu_text(), bale_price_adjustment_menu_markup());
}

function bale_price_adjustment_preview_text(array $preview): string
{
    $direction = price_adjustment_direction_labels()[(string)$preview['direction']] ?? 'تغییر';
    $lines = [
        '⚠️ تأیید تغییر گروهی قیمت',
        '',
        'عملیات: ' . $direction . ' ' . price_adjustment_percent_label((int)$preview['basis_points']),
        'محصولات ساده: ' . fa_number((int)$preview['simple_count']),
        'محصولات متغیر: ' . fa_number((int)$preview['variable_count']),
        'مدل‌های قیمت‌دار: ' . fa_number((int)$preview['variant_count']),
        'ردشده به‌علت تخفیف محدود: ' . fa_number((int)$preview['locked_count']),
        'بدون قیمت مثبت: ' . fa_number((int)$preview['zero_price_count']),
        'بدون تغییر پس از گردکردن: ' . fa_number((int)$preview['unchanged_count']),
    ];
    $samples = is_array($preview['samples'] ?? null) ? $preview['samples'] : [];
    if ($samples) {
        $lines[] = '';
        $lines[] = 'نمونه تغییرها:';
        foreach ($samples as $sample) {
            $lines[] = '• ' . text_substr((string)$sample['title'], 0, 55) . ': '
                . fa_number(format_price((int)$sample['before'])) . ' ← ' . fa_number(format_price((int)$sample['after'])) . ' تومان';
        }
    }
    $lines[] = '';
    $lines[] = 'قیمت‌ها به نزدیک‌ترین تومان گرد می‌شوند و آخرین عملیات قابل بازگردانی است.';
    return implode("\n", $lines);
}

function bale_price_adjustment_result_text(array $batch, bool $reverted = false): string
{
    $lines = [
        $reverted ? '✅ آخرین تغییر گروهی بازگردانی شد' : '✅ تغییر گروهی قیمت انجام شد',
        '',
        bale_price_adjustment_batch_line($batch),
        'محصولات تغییرکرده: ' . fa_number((int)($batch['product_count'] ?? 0)),
        'مدل‌های تغییرکرده: ' . fa_number((int)($batch['variant_count'] ?? 0)),
        'ردشده به‌علت تخفیف محدود: ' . fa_number((int)($batch['skipped_count'] ?? 0)),
    ];
    if (!$reverted) $lines[] = "\nقیمت‌های جدید در سایت و خروجی ترب از همین لحظه استفاده می‌شوند.";
    return implode("\n", $lines);
}

function bale_price_adjustment_message(string $chatId, string $userId, string $step, array $data, array $message, string $text): bool
{
    if ($step === 'bulk_price_percent') {
        try {
            $direction = price_adjustment_normalize_direction((string)($data['direction'] ?? ''));
            $parsed = price_adjustment_parse_percent($text, $direction);
            $preview = price_adjustment_preview($direction, (int)$parsed['basis_points']);
            if ((int)$preview['product_count'] < 1) throw new RuntimeException('با این درصد، هیچ قیمت واجد شرایطی تغییر نمی‌کند.');
            $nonce = bin2hex(random_bytes(8));
            bale_product_bot_session_save($chatId, $userId, 'bulk_price_confirm', [
                'direction'=>$direction,
                'basis_points'=>(int)$parsed['basis_points'],
                'signature'=>(string)$preview['signature'],
                'nonce'=>$nonce,
            ]);
            $markup = bale_product_bot_inline_keyboard([
                [[ 'text'=>'✅ اعمال روی همه قیمت‌ها','callback_data'=>'bpa:apply:'.$nonce ]],
                [[ 'text'=>'↩️ تغییر درصد','callback_data'=>'bpa:direction:'.$direction ]],
            ]);
            bale_product_bot_send($chatId, bale_price_adjustment_preview_text($preview), $markup);
        } catch (Throwable $error) {
            bale_product_bot_send($chatId, '❌ ' . text_substr($error->getMessage(), 0, 500) . "\n\nدرصد را دوباره بفرستید؛ مثال: ۱۰ یا ۷٫۵", bale_product_bot_cancel_keyboard());
        }
        return true;
    }
    if (in_array($step, ['bulk_price_confirm','bulk_price_undo_confirm','bulk_price_menu'], true)) {
        bale_product_bot_send($chatId, 'برای ادامه از دکمه‌های پیام قبلی استفاده کنید یا عملیات را لغو کنید.', bale_product_bot_cancel_keyboard());
        return true;
    }
    return false;
}

function bale_price_adjustment_callback(string $chatId, string $userId, int $messageId, string $dataValue, string $callbackId, ?array $session): bool
{
    if (!str_starts_with($dataValue, 'bpa:')) return false;

    if ($dataValue === 'bpa:menu') {
        bale_product_bot_session_save($chatId, $userId, 'bulk_price_menu', []);
        bale_product_bot_edit($chatId, $messageId, bale_price_adjustment_menu_text(), bale_price_adjustment_menu_markup());
        bale_product_bot_answer_callback($callbackId);
        return true;
    }

    if (preg_match('/^bpa:direction:(increase|decrease)$/', $dataValue, $match)) {
        if (!bale_price_adjustment_session_matches($session, $userId, ['bulk_price_menu','bulk_price_confirm','bulk_price_percent'])) {
            bale_product_bot_answer_callback($callbackId, 'این منو منقضی شده است؛ دوباره از منوی اصلی شروع کنید.');
            return true;
        }
        $direction = (string)$match[1];
        bale_product_bot_session_save($chatId, $userId, 'bulk_price_percent', ['direction'=>$direction]);
        bale_product_bot_answer_callback($callbackId, 'درصد را بفرستید.');
        bale_product_bot_edit($chatId, $messageId, ($direction === 'increase' ? '📈 افزایش همه قیمت‌ها' : '📉 کاهش همه قیمت‌ها')
            . "\n\nدرصد را بین ۰٫۰۱ تا " . ($direction === 'increase' ? '۱۰۰' : '۹۹٫۹۹') . " بفرستید.\nمثال: ۱۰ یا ۷٫۵");
        bale_product_bot_send($chatId, 'درصد موردنظر را فقط به‌صورت عدد بفرستید.', bale_product_bot_cancel_keyboard());
        return true;
    }

    if (preg_match('/^bpa:apply:([a-f0-9]{16})$/', $dataValue, $match)) {
        $nonce = (string)$match[1];
        if (!bale_price_adjustment_session_matches($session, $userId, ['bulk_price_confirm'], ['nonce'=>$nonce])) {
            bale_product_bot_answer_callback($callbackId, 'تأیید منقضی شده است؛ دوباره پیش‌نمایش بگیرید.');
            return true;
        }
        $state = $session['data'];
        try {
            $batch = price_adjustment_apply(
                (string)$state['direction'],
                (int)$state['basis_points'],
                (string)$state['signature'],
                ['source'=>'bale','label'=>'bale:'.$userId,'username'=>'bale:'.$userId,'bale_user_id'=>$userId]
            );
            bale_product_bot_session_delete($chatId);
            bale_product_bot_answer_callback($callbackId, 'قیمت‌ها تغییر کردند.');
            bale_product_bot_edit($chatId, $messageId, bale_price_adjustment_result_text($batch));
            bale_product_bot_send($chatId, 'منوی مدیریت:', bale_product_bot_main_keyboard($userId));
        } catch (Throwable $error) {
            log_error($error);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_answer_callback($callbackId, text_substr($error->getMessage(), 0, 170));
            bale_product_bot_send($chatId, '❌ ' . text_substr($error->getMessage(), 0, 500) . "\n\nبرای تلاش دوباره «تغییر گروهی قیمت» را بزنید.", bale_product_bot_main_keyboard($userId));
        }
        return true;
    }

    if (preg_match('/^bpa:undo:(\d+)$/', $dataValue, $match)) {
        $batchId = (int)$match[1];
        if (!bale_price_adjustment_session_matches($session, $userId, ['bulk_price_menu'])) {
            bale_product_bot_answer_callback($callbackId, 'این منو منقضی شده است؛ دوباره شروع کنید.');
            return true;
        }
        $latest = price_adjustment_latest_applied_batch();
        if (!$latest || (int)$latest['id'] !== $batchId) {
            bale_product_bot_answer_callback($callbackId, 'این عملیات دیگر آخرین تغییر قابل بازگردانی نیست.');
            return true;
        }
        $nonce = bin2hex(random_bytes(8));
        bale_product_bot_session_save($chatId, $userId, 'bulk_price_undo_confirm', ['batch_id'=>$batchId,'nonce'=>$nonce]);
        $markup = bale_product_bot_inline_keyboard([
            [[ 'text'=>'✅ بله، قیمت‌های قبلی برگردد','callback_data'=>'bpa:undo-confirm:'.$batchId.':'.$nonce ]],
            [[ 'text'=>'⬅️ بازگشت','callback_data'=>'bpa:menu' ]],
        ]);
        bale_product_bot_edit($chatId, $messageId, "⚠️ بازگردانی آخرین تغییر؟\n\n" . bale_price_adjustment_batch_line($latest)
            . "\n\nاگر پس از این عملیات قیمت یک محصول یا مدل دستی عوض شده باشد، برای حفظ آن تغییر جدید کل بازگردانی متوقف می‌شود.", $markup);
        bale_product_bot_answer_callback($callbackId);
        return true;
    }

    if (preg_match('/^bpa:undo-confirm:(\d+):([a-f0-9]{16})$/', $dataValue, $match)) {
        $batchId = (int)$match[1];
        $nonce = (string)$match[2];
        if (!bale_price_adjustment_session_matches($session, $userId, ['bulk_price_undo_confirm'], ['batch_id'=>$batchId,'nonce'=>$nonce])) {
            bale_product_bot_answer_callback($callbackId, 'تأیید بازگردانی منقضی شده است.');
            return true;
        }
        try {
            $batch = price_adjustment_revert($batchId, ['source'=>'bale','label'=>'bale:'.$userId,'username'=>'bale:'.$userId,'bale_user_id'=>$userId]);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_answer_callback($callbackId, 'قیمت‌های قبلی برگشتند.');
            bale_product_bot_edit($chatId, $messageId, bale_price_adjustment_result_text($batch, true));
            bale_product_bot_send($chatId, 'منوی مدیریت:', bale_product_bot_main_keyboard($userId));
        } catch (Throwable $error) {
            log_error($error);
            bale_product_bot_session_delete($chatId);
            bale_product_bot_answer_callback($callbackId, text_substr($error->getMessage(), 0, 170));
            bale_product_bot_send($chatId, '❌ ' . text_substr($error->getMessage(), 0, 500) . "\n\nبرای ادامه دوباره «تغییر گروهی قیمت» را بزنید.", bale_product_bot_main_keyboard($userId));
        }
        return true;
    }

    bale_product_bot_answer_callback($callbackId, 'دکمه تغییر قیمت معتبر نیست یا منقضی شده است.');
    return true;
}
