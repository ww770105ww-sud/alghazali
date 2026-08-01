<?php
$filePath = __DIR__ . "/tools/ghazali (14).sql";
echo "Reading file...\n";
$content = file_get_contents($filePath);
$fixed = $content;
for ($i = 0; $i < 5; $i++) {
    $prev = $fixed;
    $fixed = mb_convert_encoding($fixed, "UTF-8", "Windows-1252");
    if ($fixed === $prev) { echo "No more changes at iteration $i\n"; break; }
    echo "Fixed iteration $i\n";
}
echo "Writing back...\n";
file_put_contents($filePath, $fixed);
echo "✅ DONE!\n";
?>
