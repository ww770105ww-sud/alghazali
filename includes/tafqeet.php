<?php
/**
 * Tafqeet PHP - تحويل الأرقام إلى كلمات باللغة العربية
 * لخدمة العمليات الخلفية والطباعة
 */
function tafqeet_php($n, $currencyName = "ريال") {
    if ($n === "" || !is_numeric($n) || $n == 0) return "";

    $ones = ["", "واحد", "اثنان", "ثلاثة", "أربعة", "خمسة", "ستة", "سبعة", "ثمانية", "تسعة", "عشرة", "أحد عشر", "اثنا عشر", "ثلاثة عشر", "أربعة عشر", "خمسة عشر", "ستة عشر", "سبعة عشر", "ثمانية عشر", "تسعة عشر"];
    $tens = ["", "", "عشرون", "ثلاثون", "أربعون", "خمسون", "ستون", "سبعون", "ثمانون", "تسعون"];
    $hundreds = ["", "مائة", "مائتان", "ثلاثمائة", "أربعمائة", "خمسمائة", "ستمائة", "سبعمائة", "ثمانمائة", "تسعمائة"];

    $convertPart = function($num) use ($ones, $tens, $hundreds) {
        $partStr = "";
        $h = floor($num / 100);
        $t = floor(($num % 100) / 10);
        $o = $num % 10;

        if ($h > 0) $partStr .= $hundreds[$h] . ($num % 100 > 0 ? " و " : "");
        if ($t > 1) {
            $partStr .= $ones[$o] . ($o > 0 ? " و " : "") . $tens[$t];
        } else {
            $partStr .= $ones[$num % 100];
        }
        return $partStr;
    };

    $result = "";
    $amount = floor($n);
    $fractions = round(($n - $amount) * 100);

    if ($amount == 0) {
        $result = "صفر";
    } else {
        // ملايين
        if ($amount >= 1000000) {
            $m = floor($amount / 1000000);
            if ($m == 1) $result .= "مليون";
            else if ($m == 2) $result .= "مليونان";
            else if ($m <= 10) $result .= $convertPart($m) . " ملايين";
            else $result .= $convertPart($m) . " مليون";
            $amount %= 1000000;
            if ($amount > 0) $result .= " و ";
        }
        // آلاف
        if ($amount >= 1000) {
            $k = floor($amount / 1000);
            if ($k == 1) $result .= "ألف";
            else if ($k == 2) $result .= "ألفان";
            else if ($k <= 10) $result .= $convertPart($k) . " آلاف";
            else $result .= $convertPart($k) . " ألف";
            $amount %= 1000;
            if ($amount > 0) $result .= " و ";
        }
        // مئات وآحاد
        if ($amount > 0) $result .= $convertPart($amount);
    }

    $result = "فقط " . $result . " " . $currencyName;
    if ($fractions > 0) {
        $result .= " و " . $convertPart($fractions) . " هللة";
    }
    return $result . " لا غير";
}
