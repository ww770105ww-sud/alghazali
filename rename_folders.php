<?php
$dataDir = "C:\\xampp\\mysql\\data";
$oldDir = $dataDir . "\\ghazali";
$backupDir = $dataDir . "\\ghazali_old";
$newDir = $dataDir . "\\ghazali_fixed";
$targetDir = $dataDir . "\\ghazali";

echo "Starting folder rename process...\n";

// First, try to rename ghazali to ghazali_old
if (is_dir($oldDir)) {
    echo "Renaming ghazali to ghazali_old...\n";
    if (rename($oldDir, $backupDir)) {
        echo "Successfully renamed ghazali to ghazali_old!\n";
    } else {
        echo "Failed to rename ghazali to ghazali_old. Please stop MySQL first from XAMPP Control Panel!\n";
        exit(1);
    }
} else {
    echo "ghazali folder doesn't exist, skipping...\n";
}

// Now rename ghazali_fixed to ghazali
if (is_dir($newDir)) {
    echo "Renaming ghazali_fixed to ghazali...\n";
    if (rename($newDir, $targetDir)) {
        echo "Successfully renamed ghazali_fixed to ghazali!\n";
        echo "\nDatabase is now fixed! Please start MySQL from XAMPP Control Panel!\n";
    } else {
        echo "Failed to rename ghazali_fixed to ghazali!\n";
        exit(1);
    }
} else {
    echo "ghazali_fixed folder not found!\n";
    exit(1);
}
?>