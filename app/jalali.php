<?php
declare(strict_types=1);

/**
 * ابزار تاریخ هجری شمسی فروشگاه گلریزان.
 * تاریخ‌ها در دیتابیس به‌صورت میلادی ذخیره می‌شوند و فقط ورودی/نمایش کاربر شمسی است.
 */
function jalali_ascii_digits(string $value): string
{
    return strtr(trim($value), [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '٫'=>'.','٬'=>',',
    ]);
}

function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $gDaysInMonth = [31,28,31,30,31,30,31,31,30,31,30,31];
    $jDaysInMonth = [31,31,31,31,31,31,30,30,30,30,30,29];
    $gy -= 1600;
    $gm -= 1;
    $gd -= 1;
    $gDayNo = 365 * $gy + intdiv($gy + 3, 4) - intdiv($gy + 99, 100) + intdiv($gy + 399, 400);
    for ($i = 0; $i < $gm; $i++) $gDayNo += $gDaysInMonth[$i];
    if ($gm > 1 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) $gDayNo++;
    $gDayNo += $gd;

    $jDayNo = $gDayNo - 79;
    $jNp = intdiv($jDayNo, 12053);
    $jDayNo %= 12053;
    $jy = 979 + 33 * $jNp + 4 * intdiv($jDayNo, 1461);
    $jDayNo %= 1461;
    if ($jDayNo >= 366) {
        $jy += intdiv($jDayNo - 1, 365);
        $jDayNo = ($jDayNo - 1) % 365;
    }
    for ($i = 0; $i < 11 && $jDayNo >= $jDaysInMonth[$i]; $i++) $jDayNo -= $jDaysInMonth[$i];
    return [$jy, $i + 1, $jDayNo + 1];
}

function jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    $gDaysInMonth = [31,28,31,30,31,30,31,31,30,31,30,31];
    $jDaysInMonth = [31,31,31,31,31,31,30,30,30,30,30,29];
    $jy -= 979;
    $jm -= 1;
    $jd -= 1;
    $jDayNo = 365 * $jy + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4);
    for ($i = 0; $i < $jm; $i++) $jDayNo += $jDaysInMonth[$i];
    $jDayNo += $jd;

    $gDayNo = $jDayNo + 79;
    $gy = 1600 + 400 * intdiv($gDayNo, 146097);
    $gDayNo %= 146097;
    $leap = true;
    if ($gDayNo >= 36525) {
        $gDayNo--;
        $gy += 100 * intdiv($gDayNo, 36524);
        $gDayNo %= 36524;
        if ($gDayNo >= 365) $gDayNo++;
        else $leap = false;
    }
    $gy += 4 * intdiv($gDayNo, 1461);
    $gDayNo %= 1461;
    if ($gDayNo >= 366) {
        $leap = false;
        $gDayNo--;
        $gy += intdiv($gDayNo, 365);
        $gDayNo %= 365;
    }
    for ($i = 0; $gDayNo >= $gDaysInMonth[$i] + ($i === 1 && $leap ? 1 : 0); $i++) {
        $gDayNo -= $gDaysInMonth[$i] + ($i === 1 && $leap ? 1 : 0);
    }
    return [$gy, $i + 1, $gDayNo + 1];
}

function valid_jalali_date(int $jy, int $jm, int $jd): bool
{
    if ($jy < 1 || $jy > 3177 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return false;
    if ($jm > 6 && $jd > 30) return false;
    try {
        [$gy,$gm,$gd] = jalali_to_gregorian($jy,$jm,$jd);
        if (!checkdate($gm,$gd,$gy)) return false;
        [$checkY,$checkM,$checkD] = gregorian_to_jalali($gy,$gm,$gd);
        return $checkY === $jy && $checkM === $jm && $checkD === $jd;
    } catch (Throwable) {
        return false;
    }
}

function jalali_parse_date(string $value): ?string
{
    $value = jalali_ascii_digits($value);
    $value = str_replace(['-','.','\\'], '/', $value);
    $value = preg_replace('/\s+/u', '', $value) ?: $value;
    if (!preg_match('#^(\d{3,4})/(\d{1,2})/(\d{1,2})$#', $value, $m)) return null;
    $jy=(int)$m[1]; $jm=(int)$m[2]; $jd=(int)$m[3];
    if (!valid_jalali_date($jy,$jm,$jd)) return null;
    [$gy,$gm,$gd] = jalali_to_gregorian($jy,$jm,$jd);
    return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
}

function jalali_parse_datetime(string $value): ?string
{
    $value = jalali_ascii_digits($value);
    $value = trim(str_replace(['T','،',','], ' ', $value));
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    if (!preg_match('#^(\d{3,4}[/.\\-]\d{1,2}[/.\\-]\d{1,2})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $value, $m)) return null;
    $date = jalali_parse_date($m[1]);
    if ($date === null) return null;
    $hour = isset($m[2]) ? (int)$m[2] : 0;
    $minute = isset($m[3]) ? (int)$m[3] : 0;
    $second = isset($m[4]) ? (int)$m[4] : 0;
    if ($hour > 23 || $minute > 59 || $second > 59) return null;
    return sprintf('%s %02d:%02d:%02d', $date, $hour, $minute, $second);
}

function jalali_format(string|int|null $value = null, bool $withTime = true, bool $persianDigits = true): string
{
    if ($value === null || $value === '') return '';
    $timestamp = is_int($value) ? $value : strtotime($value);
    if ($timestamp === false) return '';
    [$jy,$jm,$jd] = gregorian_to_jalali((int)date('Y',$timestamp),(int)date('n',$timestamp),(int)date('j',$timestamp));
    $formatted = sprintf('%04d/%02d/%02d', $jy,$jm,$jd);
    if ($withTime) $formatted .= '، ' . date('H:i', $timestamp);
    return $persianDigits && function_exists('fa_number') ? fa_number($formatted) : $formatted;
}

function jalali_date_format(string|int|null $value = null, bool $persianDigits = true): string
{
    return jalali_format($value, false, $persianDigits);
}

function jalali_datetime_input(string|int|null $value = null): string
{
    return jalali_format($value ?? time(), true, true);
}

function jalali_today(bool $persianDigits = true): string
{
    return jalali_date_format(time(), $persianDigits);
}

function jalali_year(bool $persianDigits = true): string
{
    [$jy] = gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
    return $persianDigits && function_exists('fa_number') ? fa_number((string)$jy) : (string)$jy;
}

function jalali_month_start_gregorian(): string
{
    [$jy,$jm] = gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
    [$gy,$gm,$gd] = jalali_to_gregorian($jy,$jm,1);
    return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
}

function jalali_month_start(bool $persianDigits = true): string
{
    return jalali_date_format(jalali_month_start_gregorian(), $persianDigits);
}

function jalali_compact_date(bool $fourDigitYear = true): string
{
    [$jy,$jm,$jd] = gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
    $year = $fourDigitYear ? sprintf('%04d',$jy) : substr((string)$jy,-2);
    return $year . sprintf('%02d%02d',$jm,$jd);
}
