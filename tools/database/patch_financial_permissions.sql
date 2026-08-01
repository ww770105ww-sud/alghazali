-- تصحيح صلاحيات الحذف والعكس لـ سندات القبض والصرف
-- المطور ومدير النظام لديهم كل الصلاحيات تلقائياً

-- 1. إضافة الصلاحيات (إذا لم تكن موجودة)
INSERT IGNORE INTO unified_permissions (permission_code, display_name, category, is_active, created_at)
VALUES
  ('receipt_reverse',         'عكس سند قبض مرحل',              'finance', 1, NOW()),
  ('payment_reverse',         'عكس سند صرف مرحل',              'finance', 1, NOW()),
  ('receipt_delete_original', 'حذف سند قبض أصلي (مسودة/ملغي)', 'finance', 1, NOW()),
  ('receipt_delete_reversal', 'حذف سند قبض عكسي',              'finance', 1, NOW()),
  ('payment_delete_original', 'حذف سند صرف أصلي (مسودة/ملغي)', 'finance', 1, NOW()),
  ('payment_delete_reversal', 'حذف سند صرف عكسي',              'finance', 1, NOW());

-- 2. منح الصلاحيات لـ developer (المعرّف إما بالاسم أو role_id=2)
INSERT IGNORE INTO role_permissions_unified (role_id, permission_id, granted_by, granted_at)
SELECT r.id, up.id, 1, NOW()
  FROM roles r
 INNER JOIN unified_permissions up
    ON up.permission_code IN (
     'receipt_reverse','payment_reverse',
     'receipt_delete_original','receipt_delete_reversal',
     'payment_delete_original','payment_delete_reversal'
   )
 WHERE (r.id = 2 OR LOWER(CONVERT(r.name USING utf8mb4) COLLATE utf8mb4_unicode_ci) = 'developer');

-- 3. منح الصلاحيات لـ admin (مدير النظام)
INSERT IGNORE INTO role_permissions_unified (role_id, permission_id, granted_by, granted_at)
SELECT r.id, up.id, 1, NOW()
  FROM roles r
 INNER JOIN unified_permissions up
    ON up.permission_code IN (
     'receipt_reverse','payment_reverse',
     'receipt_delete_original','receipt_delete_reversal',
     'payment_delete_original','payment_delete_reversal'
   )
 WHERE (LOWER(CONVERT(r.name USING utf8mb4) COLLATE utf8mb4_unicode_ci) IN ('admin','super_admin')
        OR CONVERT(r.display_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE '%مدير النظام%')
   AND NOT (r.id = 2 OR LOWER(CONVERT(r.name USING utf8mb4) COLLATE utf8mb4_unicode_ci) = 'developer');
