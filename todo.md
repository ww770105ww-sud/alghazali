# مراجعة شاملة وإصلاح نظام الإدارة

## المرحلة 1: الاستكشاف والتشخيص
- [ ] استكشاف بنية المشروع وتحديد جميع الملفات المعنية
- [ ] فحص ملفات الاتصال بقاعدة البيانات والإعدادات
- [ ] فحص بنية الجداول المعنية في قاعدة البيانات

## المرحلة 2: إصلاح المشاكل السبع
- [ ] المشكلة 1: hajj.php - "تعذر الاتصال بالخادم"
- [ ] المشكلة 2: passport_transactions.php - "There is no active transaction"
- [ ] المشكلة 3: flight_bookings.php - "Incorrect integer value '' for customer_id"
- [ ] المشكلة 4: bus_bookings.php - نفس الخطأ + إشارة لجدول bus_flight_bookings خاطئ
- [ ] المشكلة 5: process_family_visit.php?action=add - HTTP ERROR 500
- [ ] المشكلة 6: work_visa.php - "Incorrect integer value '' for passports.agent_id"
- [ ] المشكلة 7: postal_services.php - "There is no active transaction"

## المرحلة 3: إصلاح جذري للنظام
- [ ] توحيد إدارة المعاملات (beginTransaction/inTransaction/commit/rollBack)
- [ ] توحيد معالجة الحقول الرقمية الفارغة (NULL بدلاً من '')
- [ ] التحقق من المدخلات الرقمية (customer_id, agent_id, etc.)
- [ ] مراجعة الجداول والملفات الصحيحة لكل صفحة
- [ ] تحسين رسائل الأخطاء (سجلات + رسائل مفيدة)

## المرحلة 4: الاختبار العملي
- [ ] اختبار الحج (hajj)
- [ ] اختبار معاملات الجوازات (passport_transactions)
- [ ] اختبار حجوزات الطيران (flight_bookings)
- [ ] اختبار حجوزات الباص (bus_bookings)
- [ ] اختبار الزيارة العائلية (family_visit)
- [ ] اختبار تأشيرات العمل (work_visa)
- [ ] اختبار الخدمات البريدية (postal_services)

## المرحلة 5: التقرير النهائي
- [ ] كتابة تقرير شامل يوضح: سبب كل مشكلة، الملفات المعدلة، التعديلات، كيفية الاختبار
