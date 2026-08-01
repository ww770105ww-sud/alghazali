-- ========================================================
-- سكريبت إصلاح/إعادة إدخال فئات المصروفات
-- المشكلة: الأسماء العربية استُبدلت بعلامات ؟ عند الإدخال الأولي
-- بسبب خطأ في إعدادات الترميز وقت الإدخال (تم إصلاحه الآن).
-- طريقة الاستخدام: عدل الأسماء الموجودة بين علامتي '' أدناه
-- ثم نفذ الاستعلامات إما من phpMyAdmin أو من سطر الأوامر.
-- ========================================================

-- 1) احذف هذه الأسطر إذا كنت لا تريد إعادة تعيين الأرقام التسلسلية:
-- ALTER TABLE expenses_categories AUTO_INCREMENT = 1;

-- 2) تحديث الفئات الخمس الحالية (استبدل الأسماء بما يناسبك)
-- ملاحظة: الأكواد category_code فريدة لذا احتفظ بها فريدة
UPDATE expenses_categories SET
  category_name_ar = 'مصاريف التذاكر والسفر',
  category_name    = 'Tickets & Travel',
  description      = 'تذاكر طيران، حافلات، قطار'
WHERE id = 1;

UPDATE expenses_categories SET
  category_name_ar = 'مصاريف التأشيرات والخدمات الحكومية',
  category_name    = 'Visa & Government Fees',
  description      = 'تأشيرات دخول، عمرة، عمل، ختم واستخراج'
WHERE id = 2;

UPDATE expenses_categories SET
  category_name_ar = 'الرواتب والعمولات',
  category_name    = 'Salaries & Commissions',
  description      = 'رواتب الموظفين، عمولات المندوبين'
WHERE id = 3;

UPDATE expenses_categories SET
  category_name_ar = 'إيجار ومصاريف المكتب',
  category_name    = 'Rent & Office Expenses',
  description      = 'إيجار، كهرباء، ماء، إنترنت، صيانة'
WHERE id = 4;

UPDATE expenses_categories SET
  category_name_ar = 'التسويق والإعلان',
  category_name    = 'Marketing & Advertisement',
  description      = 'إعلانات، منصات تواصل اجتماعي، مطبوعات'
WHERE id = 5;

-- 3) إذا أردت زيادة فئات جديدة بدلاً من تعديل القديمة استخدم النموذج التالي:
-- INSERT INTO expenses_categories (category_name, category_name_ar, category_code, description, status)
-- VALUES ('Transportation', 'النقل والشحن', 'EXP-TRANS', 'مصاريف نقل وتوصيل وخدمات شحن', 1);

-- تحقق من النتيجة
SELECT id, category_code, category_name_ar, category_name, status FROM expenses_categories;
