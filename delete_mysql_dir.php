<?php
// Try to find XAMPP MySQL data directory
$possible_paths = [
    'C:\\xampp\\mysql\\data\\ghazali',
    'C:\\xampp\\mysql\\data\\ghazali\\',
];

echo "<h1>Cleaning up MySQL data directory for 'ghazali'</h1>";

foreach ($possible_paths as $path) {
    if (is_dir($path)) {
        echo "<p style='color:orange'>Found directory: $path</p>";

        // Rename instead of delete for safety
        $backup_path = $path . '_old_' . date('YmdHis');
        if (rename($path, $backup_path)) {
            echo "<p style='color:green'>✅ Renamed to $backup_path (safe backup, you can delete later)</p>";
        } else {
            echo "<p style='color:red'>❌ Could not rename $path. Try manually via File Explorer.</p>";
            echo "<p>Please go to: $path, rename it to 'ghazali_old' manually, then re-run restore_database.php!</p>";
        }
        exit;
    }
}

echo "<p style='color:blue'>Could not find ghazali directory in common XAMPP paths.</p>";
echo "<p>Please find your MySQL data directory (usually in C:\\xampp\\mysql\\data\\), then rename the 'ghazali' folder to 'ghazali_old', then re-run restore_database.php!</p>";
?>
