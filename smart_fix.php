<?php
$filePath = __DIR__ . "/tools/ghazali (14).sql";
echo "Reading file...\n";
$content = file_get_contents($filePath);
$current = $content;
$maxTries = 10;

function hasArabic($str) {
    return preg_match("/\p{Arabic}/u", $str);
}

for ($i = 0; $i < $maxTries; $i++) {
    if (hasArabic($current)) {
        echo "Found valid Arabic at iteration $i! Stopping.\n";
        break;
    }
    $prev = $current;
    $current = mb_convert_encoding($current, "UTF-8", "Windows-1252");
    if ($current === $prev) {
        echo "No more changes at iteration $i\n";
        break;
    }
    echo "Tried iteration $i\n";
}

echo "Writing back fixed file...\n";
file_put_contents($filePath, $current);
echo "✅ Done!\n";
?>
