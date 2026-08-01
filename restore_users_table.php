<?php
require_once __DIR__ . '/includes/db.php';

try {
    // First, let's create the table with a temporary name
    $sql = "CREATE TABLE IF NOT EXISTS `users_temp` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `username` varchar(50) NOT NULL,
        `password` varchar(255) NOT NULL,
        `role_id` int(11) DEFAULT NULL,
        `user_type` enum('admin','developer','branch','agent','employee','other') DEFAULT 'employee',
        `branch_id` int(11) DEFAULT NULL,
        `agent_id` int(11) DEFAULT NULL,
        `employee_id` int(11) DEFAULT NULL,
        `branch_scope` enum('single_branch','all_branches','custom_branches') DEFAULT 'single_branch',
        `status` enum('active','inactive') DEFAULT 'active',
        `chart_account_id` int(11) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `full_name` varchar(100) DEFAULT NULL,
        `profile_image` varchar(255) DEFAULT NULL,
        `last_seen` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `is_online` tinyint(1) DEFAULT 0,
        `notification_enabled` tinyint(1) DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`),
        KEY `fk_user_role` (`role_id`),
        KEY `fk_user_branch` (`branch_id`),
        KEY `idx_user_role` (`role_id`),
        KEY `idx_user_branch` (`branch_id`),
        KEY `idx_user_agent` (`agent_id`)
    ) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    echo "Temporary users table created successfully.\n";

    // Insert default users
    $users = [
        [
            'id' => 1,
            'username' => 'admin',
            'password' => '$2y$10$qApbBxsnyBnS3QL8G5bce.7rj/de32MM1E6hHaC2/yItqVIiaeQZq',
            'role_id' => 2,
            'user_type' => 'developer',
            'branch_scope' => 'single_branch',
            'status' => 'active',
            'created_at' => '2026-02-17 00:46:25',
            'full_name' => 'مدير النظام',
            'last_seen' => '2026-05-18 01:29:28',
            'is_online' => 1,
            'notification_enabled' => 1
        ],
        [
            'id' => 2,
            'username' => 'محمد',
            'password' => '$2y$10$eJpHB/SvaLnbq7ckURjAX.DUasJsNPBl2O36/dI6gdRw2puXNOnr.',
            'role_id' => 2,
            'user_type' => 'developer',
            'branch_scope' => 'single_branch',
            'status' => 'active',
            'created_at' => '2026-02-17 03:56:54',
            'full_name' => 'محمد الغزالي',
            'last_seen' => '2026-06-20 03:20:43',
            'is_online' => 1,
            'notification_enabled' => 1
        ]
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO `users_temp` (
        `id`, `username`, `password`, `role_id`, `user_type`,
        `branch_scope`, `status`, `created_at`, `full_name`,
        `last_seen`, `is_online`, `notification_enabled`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($users as $user) {
        $stmt->execute([
            $user['id'], $user['username'], $user['password'],
            $user['role_id'], $user['user_type'], $user['branch_scope'],
            $user['status'], $user['created_at'], $user['full_name'],
            $user['last_seen'], $user['is_online'], $user['notification_enabled']
        ]);
    }

    echo "Default users inserted into temp table.\n";

    // Now rename the temp table to users
    try {
        $pdo->exec("DROP TABLE IF EXISTS `users`");
    } catch (PDOException $e) {
        echo "Couldn't drop users table: " . $e->getMessage() . "\n";
    }

    $pdo->exec("RENAME TABLE `users_temp` TO `users`");
    echo "Table renamed to users successfully.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>