# FinanceService.php — مراجعة وتحسين شامل (Single Source of Truth)

## التحليل والاستعداد
- [x] قراءة FinanceService.php الحالي بالكامل وفهم كل الدوال
- [x] تحديد كل الملفات المستدعاة (4 ملفات) للحفاظ على التوافق
- [x] فحص كل الإجراءات المخزنة (SPs) وتأكيد وجود START TRANSACTION/COMMIT/ROLLBACK داخلية
- [x] استخراج مخططات الجداول: branches, customers, suppliers, currencies, invoices, financial_transactions, payment_allocations, journal_lines, audit_logs, unified_accounts, account_balances_unified, users
- [x] تأكيد مخطط audit_logs (لا توجد branch_id/source_type/source_id — سنستخدم JSON في new_values)
- [x] تأكيد دعم currencies لأسعار صرف شراء/بيع
- [x] تأكيد unified_accounts (account_type, normal_balance, is_active, account_status, deleted_at)
- [x] إنشاء نسخة احتياطية من الملف الأصلي
- [x] تأكيد تواقيع دوال accounting_functions.php المساعدة

## كتابة الملف المُحسّن
- [x] كتابة FinanceService.php المُحسّن بكل المتطلبات الـ14 (1674 سطرًا، 48 دالة)
  - [x] 1. طبقة التحقق (validateFinancialPayload + 11 دالة تحقق مستقلة)
  - [x] 2. منع الدفع الزائد (overpayment) قبل allocatePayment و receiveInvoicePayment و processServiceOperation
  - [x] 3. منع التكرار في payment_allocations + فحص تجاوز إجمالي الفاتورة
  - [x] 4. كاش على مستوى الصنف (7 مصفوفات كاش)
  - [x] 5. أمان: إزالة fallback والتحقق من وجود المستخدم ونشاطه
  - [x] 6. audit logging: writeAuditLog لكل العمليات
  - [x] 7. التحقق من حالة الفاتورة قبل الترحيل (draft فقط)
  - [x] 8. التحقق من حالة السند قبل الترحيل (draft فقط)
  - [x] 9. مراجعة normalizeFinancialPayload
  - [x] 10. مراجعة أسعار الصرف: resolveExchangeRate
  - [x] 11. معالجة الأخطاء: استثناءات عربية واضحة
  - [x] 12. المعاملات: executeAtomically + توثيق مشكلة SPs
  - [x] 13. المراجعة المحاسبية: توثيق القيود
  - [x] 14. جودة الكود: SOLID, Clean Code, DRY, PSR-12

## التحقق والاختبار
- [x] فحص الصياغة: php -l (لا أخطاء)
- [x] التحقق من وجود كل الدوال الجديدة
- [x] التحقق من التوافق: جميع 16 دالة public بنفس التواقيع
- [x] اختبار التدفق: نقدي + صندوق (نجح — فاتورة + سند قبض + تخصيص + ترحيل + قيود)
- [x] اختبار منع الدفع الزائد (نجح)
- [x] اختبار منع التكرار (نجح)
- [x] اختبار منع الترحيل المزدوج (نجح)
- [x] اختبار الكاش (نجح)
- [x] اختبار أسعار الصرف buy/sell/average (نجح)
- [x] اختبار رفض المستخدم غير الموجود (نجح)
- [x] اختبار سجل التدقيق audit log (نجح)
- [x] توافق الملفات الأربعة المستدعية (مؤكد)

## التقرير النهائي
- [x] كتابة تقرير شامل بالعربية (docs/FinanceService_Review_Report_AR.md)
- [x] تنظيف ملفات الاختبار
