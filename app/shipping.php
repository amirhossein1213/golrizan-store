<?php
declare(strict_types=1);

function normalize_digits(string $value): string
{
    return strtr(trim($value), [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}

function normalize_place_name(string $value): string
{
    $value = strtr(trim($value), ['ي'=>'ی','ك'=>'ک','ۀ'=>'ه','ة'=>'ه', "\u{200C}"=>' ']);
    $value = preg_replace('/^استان\s+/u', '', $value) ?: $value;
    return preg_replace('/\s+/u', ' ', $value) ?: $value;
}

function iran_provinces(): array
{
    return ['آذربایجان شرقی','آذربایجان غربی','اردبیل','اصفهان','البرز','ایلام','بوشهر','تهران','چهارمحال و بختیاری','خراسان جنوبی','خراسان رضوی','خراسان شمالی','خوزستان','زنجان','سمنان','سیستان و بلوچستان','فارس','قزوین','قم','کردستان','کرمان','کرمانشاه','کهگیلویه و بویراحمد','گلستان','گیلان','لرستان','مازندران','مرکزی','هرمزگان','همدان','یزد'];
}

function validate_iran_postal_code(string $value): array
{
    $normalized = normalize_digits($value);
    if (preg_match('/[^0-9\s-]/u', $normalized)) return ['valid'=>false,'postal_code'=>'','message'=>'کدپستی فقط باید شامل رقم باشد.'];
    $postal = preg_replace('/[\s-]+/', '', $normalized) ?: '';
    if (!preg_match('/^\d{10}$/', $postal)) return ['valid'=>false,'postal_code'=>$postal,'message'=>'کدپستی باید دقیقاً ۱۰ رقم باشد.'];
    if ($postal[0] === '0' || preg_match('/^(\d)\1{9}$/', $postal) || in_array($postal, ['1234567890','9876543210'], true)) {
        return ['valid'=>false,'postal_code'=>$postal,'message'=>'کدپستی واردشده معتبر نیست.'];
    }
    return ['valid'=>true,'postal_code'=>$postal,'message'=>'کدپستی از نظر ساختار معتبر است.'];
}
