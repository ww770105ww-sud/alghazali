<?php
ini_set("memory_limit", "-1");
$filePath = __DIR__ . "/tools/ghazali (14).sql";
echo "Reading file...\n";
$content = file_get_contents($filePath);
$current = $content;
// We know we messed up 4 times, so convert back 4 times!
for ($i = 0; $i < 4; $i++) {
    echo "Converting back iteration $i...\n";
    $current = mb_convert_encoding($current, "Windows-1252", "UTF-8");
}
echo "Writing back...\n";
file_put_contents($filePath, $current);
echo "✅ Done!\n";
?>
