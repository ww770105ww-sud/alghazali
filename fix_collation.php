
<?php
// Fix collation issues in all PHP files
$files = [
    'admin/umrah.php',
    'includes/db.php'
];

$count = 0;
foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $original = $content;
        
        // Remove explicit COLLATE utf8mb4_unicode_ci clauses
        $content = preg_replace('/\s*COLLATE\s+utf8mb4_unicode_ci/i', '', $content);
        
        // If db.php, change the default collation there too
        if ($file === 'includes/db.php') {
            $content = str_replace("utf8mb4_unicode_ci", "utf8mb4_general_ci", $content);
        }
        
        if ($content !== $original) {
            file_put_contents($file, $content);
            echo "Fixed $file\n";
            $count++;
        } else {
            echo "$file is already okay\n";
        }
    } else {
        echo "File not found: $file\n";
    }
}

echo "\nTotal files modified: $count\n";
