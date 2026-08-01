<?php
/**
 * CRM Common Functions
 */

if (!function_exists('has_permission_v3')) {
    function has_permission_v3($permission_code, $branch_id = null)
    {
        global $pdo, $user_role, $user_branch_id, $user_role_id;
        if ($user_role == 'developer') return true;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions_unified rp JOIN unified_permissions p ON rp.permission_id = p.id WHERE rp.role_id = ? AND p.permission_code = ?");
        $stmt->execute([$user_role_id, $permission_code]);
        return $stmt->fetchColumn() > 0;
    }
}

/**
 * Check if CRM is enabled
 */
function is_crm_enabled()
{
    global $pdo;
    return get_module_status($pdo, 'enable_crm');
}
