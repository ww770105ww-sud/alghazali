# 🕌 نظام الغزالي لإدارة السفر والسياحة (Alghazali Travel & Tourism Management System)

<div dir="rtl">

نظام إدارة متكامل وشامل مخصص لشركات السفر والسياحة والعمرة والحج، مبني باستخدام **PHP 8.2** و**MariaDB/MySQL** بدون أي إطار عمل (No Framework)، يعتمد على PDO الأصلي مع معمارية نظيفة قابلة للصيانة والتوسع. يغطي النظام العمليات المالية المحاسبية الكاملة (قيد مزدوج)، إدارة العملاء والموردين، الحجوزات والخدمات، الموارد البشرية، CRM، التقارير، الأمن، والتحكم متعدد الفروع.

---

## 📑 فهرس المحتويات

1. [نظرة عامة على المشروع](#نظرة-عاملة-على-المشروع)
2. [اللغة البرمجية والإصدارات](#اللغة-البرمجية-والإصدارات)
3. [المعمارية وطريقة العمل](#المعمارية-وطريقة-العمل)
4. [المتطلبات](#المتطلبات)
5. [قاعدة البيانات](#قاعدة-البيانات)
6. [المكتبات المستخدمة (Frontend)](#المكتبات-المستخدمة-frontend)
7. [المكتبات والخدمات الخلفية (Backend)](#المكتبات-والخدمات-الخلفية-backend)
8. [الميزات الأمنية](#الميزات-الأمنية)
9. [طرق التشغيل](#طرق-التشغيل)
10. [هيكل المشروع](#هيكل-المشروع)
11. [ملف البيئة (.env)](#ملف-البيئة-env)
12. [الوحدات والخدمات الرئيسية](#الوحدات-والخدمات-الرئيسية)
13. [المساهمة والتطوير](#المساهمة-والتطوير)
14. [ملخص بالإنجليزية (English Summary)](#english-summary)

---

## نظرة عامة على المشروع

نظام الغزالي هو منصة برمجية متكاملة لإدارة جميع جوانب عمل شركة سفر وسياحة. تم تصميمه بعناية ليكون النواة المركزية لجميع العمليات اليومية والإدارية والمالية. يوفر النظام واجهة عربية كاملة (RTL) مبنية على Bootstrap 5.3، مع دعم عمليات AJAX سلسة لتحسين تجربة المستخدم.

يتميز النظام بأنه لا يعتمد على أي إطار عمل (Framework) مثل Laravel أو CodeIgniter، بل يستخدم PHP الأصلي مع تنظيم معماري محكم يفصل المهام والخدمات في ملفات ووحدات مستقلة. هذا يجعل النظام خفيفًا وسريعًا وسهل النشر على أي خادم يدعم PHP دون الحاجة لتثبيت أدوات إضافية مثل Composer.

### الأهداف الرئيسية

إدارة المعاملات المالية بنظام القيد المزدوج الكامل (Double-Entry Bookkeeping)، يشمل ذلك الفواتير وسندات القبض والدفع والمصروفات والتسويات. إدارة العملاء والموردين وكلائهم وأرصدتهم وسجل تعاملاتهم. إدارة الخدمات مثل العمرة والتأشيرات والطيران والفنادق والحج. نظام تقارير مالية وإدارية شامل. نظام أمن وصلاحيات متعدد المستخدمين والفروع. وحدة CRM متكاملة لإدارة العملاء المحتملين والصفقات وخطوط الإنتاج. تكامل مع واتساب للتواصل المباشر مع العملاء.

---

## اللغة البرمجية والإصدارات

| المكون | الإصدار المطلوب | الإصدار المستخدم في التطوير |
|--------|------------------|------------------------------|
| **PHP** | 8.1 كحد أدنى (يُوصى بـ 8.2+) | PHP 8.2.4 |
| **MariaDB** | 10.4 كحد أدنى (يُوصى بـ 10.6+) | MariaDB 10.4.28 |
| **MySQL** | 8.0 كحد أدنى | — |
| **Apache/Nginx** | أي إصدار حديث يدعم PHP | Apache 2.4 |
| **phpMyAdmin** | 5.0+ (لإدارة قاعدة البيانات) | phpMyAdmin 5.2.3 |
| **المتصفح** | Chrome/Firefox/Edge/Safari حديث | — |

> ⚠️ **ملاحظة هامة:** النظام يستخدم ميزات PHP 8.x مثل Typed Properties و Named Arguments و Match Expressions و Nullsafe Operator. تأكد من تثبيت PHP 8.1 على الأقل. قاعدة البيانات تم تصديرها من MariaDB 10.4.28 وتعمل أيضًا على MariaDB 10.11 و MySQL 8.0.

---

## المعمارية وطريقة العمل

### نمط المعمارية

يتبع النظام نمط **MVC مبسط بدون إطار عمل** (Lightweight MVC without Framework):

- **النماذج (Models):** جداول قاعدة البيانات + دوال PHP في `includes/` للتعامل معها
- **العروض (Views):** ملفات PHP في `admin/` تحتوي HTML + Bootstrap للواجهة
- **المتحكمات (Controllers):** ملفات PHP في `admin/` التي تستقبل الطلبات وتوجهها
- **الخدمات (Services):** فئات PHP في `core/` تحتوي المنطق المركزي (مثل `FinanceService`)

### كيف يعمل النظام

عند وصول طلب من المتصفح، يقوم خادم الويب (Apache) بتمرير الطلب إلى ملف PHP المطلوب مباشرة (مثل `admin/index.php`). يقوم الملف بتحميل ملفات التهيئة الأساسية (`includes/db.php` للاتصال بقاعدة البيانات، `includes/session_config.php` لإعداد الجلسة، `includes/functions.php` للدوال المساعدة، `includes/security.php` للحماية). بعد التحقق من صلاحيات المستخدم عبر `check_access()`، يتم تنفيذ المنطق المطلوب ثم عرض الواجهة باستخدام `includes/header.php` و`includes/footer.php`.

العمليات المالية كلها تمر عبر `FinanceService` الذي يمثل **المصدر الوحيد للحقيقة** (Single Source of Truth) لجميع العمليات المحاسبية. يستخدم نمط `executeAtomically()` للتعامل الآمن مع المعاملات المتداخلة (Nested Transactions) عبر التحقق من `inTransaction()` قبل أي `commit` أو `rollback`.

### معالجة AJAX

يدعم النظام عمليات AJAX واسعة النطاق. الطلبات AJAX ترسل عبر jQuery إلى نفس ملفات PHP التي تتحقق من وجود `X-Requested-With: XMLHttpRequest` أو معامل `ajax=1` ثم تعيد استجابة JSON بدلاً من HTML. يتم التحقق من CSRF Token في كل طلب POST عبر `verify_csrf_token()` باستخدام `hash_equals()` لمنع توقيت الهجمات.

---

## المتطلبات

### متطلبات الخادم (Server Requirements)

- **PHP 8.1+** مع الإضافات التالية مفعّلة: `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, `gd` أو `imagick`, `fileinfo`, `xml`
- **MariaDB 10.4+** أو **MySQL 8.0+** مع دعم `utf8mb4`
- **خادم ويب:** Apache 2.4+ (مع `mod_rewrite` و `mod_headers`) أو Nginx + PHP-FPM
- **مساحة تخزين:** 500MB على الأقل (للمشروع + رفع الملفات)
- **ذاكرة PHP:** `memory_limit` بحد أدنى 256MB (يُوصى بـ 512MB)

### متطلبات العميل (Client Requirements)

- متصفح ويب حديث (Chrome 90+, Firefox 88+, Edge 90+, Safari 14+)
- تفعيل JavaScript
- اتصال إنترنت (لتحميل مكتبات CDN)

---

## قاعدة البيانات

### المواصفات

| الخاصية | القيمة |
|---------|--------|
| **نوع قاعدة البيانات** | MariaDB / MySQL |
| **الإصدار المستخدم** | MariaDB 10.4.28 |
| **الإصدار الأدنى المطلوب** | MariaDB 10.4 / MySQL 8.0 |
| **ترميز الأحرف** | `utf8mb4` |
| **الترتيب (Collation)** | `utf8mb4_unicode_ci` |
| **عدد الجداول** | 115 جدول |
| **عدد الإجراءات المخزنة (Stored Procedures)** | 26 إجراء + دوال |
| **عدد العروض (Views)** | 4 عروض |
| **ملف SQL الكامل** | `tools/database/alghazali.sql` (~4.7MB) |
| **اسم قاعدة البيانات الافتراضي** | `alghazali` (قابل للتغيير من `.env`) |

### الجداول الرئيسية

#### الجداول المالية المحاسبية

- `financial_transactions` — جدول القيود المحاسبية (نظام القيد المزدوج)
- `invoices` — الفواتير
- `receipt_vouchers` — سندات القبض
- `payment_vouchers` — سندات الدفع
- `expenses` — المصروفات
- `expense_vouchers` — سندات المصروفات
- `expense_approvals` — موافقات المصروفات (متعددة المستويات)
- `expense_categories` — تصنيفات المصروفات
- `cost_centers` — مراكز التكلفة
- `budgets` — الميزانيات
- `budget_allocations` — تخصيصات الميزانية
- `fiscal_periods` — الفترات المالية
- `account_balances_unified` — الأرصدة الموحدة
- `currencies` — العملات
- `currency_exchange_rates_history` — سجل أسعار الصرف
- `currency_exchange_transactions` — معاملات صرف العملات

#### جداول العملاء والموردين والوكلاء

- `customers` — العملاء
- `agents` — الوكلاء
- `branches` — الفروع
- `employees` — الموظفون
- `employee_attendance` — الحضور والانصراف
- `attendance_locations` — مواقع الحضور
- `attendance_attempts` — محاولات تسجيل الحضور
- `family_relationships` — العلاقات الأسرية
- `family_requirements` — متطلبات العائلة
- `family_visit_individuals` — أفراد زيارة العائلة
- `family_visit_requests` — طلبات زيارة العائلة

#### جداول الحجوزات والخدمات

- `batches` — الدفعات/المجموعات
- `group_members` — أعضاء المجموعة
- `group_messages` — رسائل المجموعة
- `booking_modifications` — تعديلات الحجز
- `booking_notifications` — إشعارات الحجز
- `booking_refunds` — استرداد الحجوزات
- `booking_status_logs` — سجل حالات الحجز
- `booking_tickets` — تذاكر الحجز
- `booking_workflow` — سير عمل الحجز
- `bus_flight_bookings` — حجوزات الطيران والحافلات
- `documents` — المستندات

#### جداول النظام والأمن

- `audit_logs` — سجل التدقيق
- `blocked_devices` — الأجهزة المحظورة
- `contact_messages` — رسائل التواصل
- `countries` — الدول
- `cities` — المدن
- `nationalities` — الجنسيات
- `sequence_numbers` — المولّد التلقائي للتسلسلات (عبر `fn_get_next_sequence`)

### الإجراءات المخزنة (Stored Procedures)

يحتوي النظام على 26 إجراء ودالة مخزنة في قاعدة البيانات، من أبرزها:

- `fn_get_next_sequence` — مولّد التسلسلات الذكي (يحافظ على التسلسل حتى عند الحذف)
- `fn_sanitize_input` / `fn_sanitize_safe` / `fn_sanitize_text` — دوال التنقية على مستوى قاعدة البيانات
- إجراءات إدارية متنوعة للعمليات المالية والتقارير

### استيراد قاعدة البيانات

```bash
# الطريقة الأولى: عبر سطر الأوامر
mysql -u root -p alghazali < tools/database/alghazali.sql

# الطريقة الثانية: عبر phpMyAdmin
# 1. أنشئ قاعدة بيانات باسم alghazali بترميز utf8mb4_unicode_ci
# 2. اذهب إلى تبويب "Import"
# 3. اختر ملف tools/database/alghazali.sql
# 4. اضغط "Go" للتنفيذ
```


---

## المكتبات المستخدمة (Frontend)

النظام يعتمد على مكتبات Frontend عبر **CDN** (لا يتطلب npm أو Composer للواجهة الأمامية). جميع المكتبات محمّلة من jsDelivr و cdnjs و unpkg.

### إطار العمل والأساسيات

| المكتبة | الإصدار | الوظيفة | المصدر |
|---------|---------|---------|--------|
| **Bootstrap** | 5.3.0 (RTL) | إطار واجهة المستخدم، شبكة، مكونات | jsDelivr |
| **jQuery** | 3.6.0 | معالجة DOM و AJAX | jsDelivr |
| **Font Awesome** | 6.0.0 / 6.4.0 | الأيقونات والرموز | cdnjs |

### مكتبات النماذج والتفاعل

| المكتبة | الإصدار | الوظيفة | المصدر |
|---------|---------|---------|--------|
| **Select2** | 4.1.0-rc.0 | قوائم منسدلة متقدمة مع بحث ودعم AJAX | jsDelivr |
| **Select2 Bootstrap 5 Theme** | 1.3.0 | ثيم Select2 متوافق مع Bootstrap 5 | jsDelivr |
| **SweetAlert2** | 11 | نوافذ تنبيه وتأكيد جميلة | jsDelivr |
| **TinyMCE** | 6 | محرر نصوص غني (WYSIWYG) | cdn.tiny.cloud |
| **Cropper.js** | 1.5.13 | قص وتدوير الصور | cdnjs |
| **Interact.js** | — | سحب وإفلات العناصر | jsDelivr |

### مكتبات الرسوم والمخططات

| المكتبة | الإصدار | الوظيفة | المصدر |
|---------|---------|---------|--------|
| **ApexCharts** | أحدث | مخططات تفاعلية للوحات المعلومات | jsDelivr |
| **JsBarcode** | 3.11.5 | توليد الباركود للوثائق والخدمات | jsDelivr |

### مكتبات الخرائط والموقع

| المكتبة | الإصدار | الوظيفة | المصدر |
|---------|---------|---------|--------|
| **Leaflet** | 1.9.4 | خرائط تفاعلية (لتحديد مواقع الحضور) | unpkg |
| **Leaflet Control Geocoder** | أحدث | البحث عن العناوين والترميز الجغرافي | unpkg |

### مكتبات الذكاء الاصطناعي والاتصال

| المكتبة | الإصدار | الوظيفة | المصدر |
|---------|---------|---------|--------|
| **Tesseract.js** | 5 | التعرف الضوئي على الحروف OCR (لقص جوازات السفر) | jsDelivr |
| **Simple Peer** | 9.11.1 | اتصال WebRTC للدردشة المرئية | jsDelivr |

### مكتبات الحركة والتأثيرات

| المكتبة | الإصدار | الوظيفة | المصدر |
|---------|---------|---------|--------|
| **AOS** | (Animate On Scroll) | حركات وتأثيرات عند التمرير | unpkg |

### الخطوط (Google Fonts)

| الخط | الاستخدام |
|------|-----------|
| **Cairo** | الخط الرئيسي للواجهة العربية (300-900) |
| **Tajawal** | خط عربي بديل (400, 700) |
| **Noto Sans Arabic** | خط عربي إضافي (300-700) |
| **Inter** | خط لاتيني للأرقام والنصوص الإنجليزية (300-800) |

### ملفات CSS و JS الخاصة بالمشروع

يحتوي المشروع على 6 ملفات CSS و 6 ملفات JS خاصة به موجودة في مجلد `assets/`، تتعامل مع التنسيقات المخصصة والتفاعلات الخاصة بالنظام.

---

## المكتبات والخدمات الخلفية (Backend)

### مكتبات PHP الأصلية (بدون Composer)

النظام لا يستخدم Composer ولا أي مكتبات PHP خارجية. جميع المنطق مكتوب بـ PHP الأصلي. فيما يلي أهم الفئات (Classes) والدوال (Functions):

#### الفئات (Classes)

| الفئة | الملف | الأسطر | الوظيفة |
|------|------|--------|---------|
| **FinanceService** | `core/FinanceService.php` | 1,674 | المصدر المركزي الوحيد لجميع العمليات المالية — قيد مزدوج كامل، فواتير، سندات قبض/دفع، مصروفات، موافقات متعددة المستويات |
| **SafeDB** | `includes/SafeDB.php` | 158 | غلاف آمن لـ PDO مع insert/update/delete/select محمّنة بالكامل |
| **BranchMiddleware** | `includes/BranchMiddleware.php` | — | نظام صلاحيات متعدد الفروع (Multi-Branch RBAC) |
| **CurrencyExchange** | `includes/CurrencyExchange.php` | — | خدمة صرف العملات وتتبع أسعار الصرف |
| **InvoiceManager** | `includes/InvoiceManager.php` | — | إدارة الفواتير |
| **ServiceFinancialEngine** | `includes/ServiceFinancialEngine.php` | — | محرك مالي للخدمات يفوّض العمليات إلى FinanceService |
| **WhatsAppService** | `includes/WhatsAppService.php` | — | تكامل واتساب لإرسال الرسائل والإشعارات |

#### FinanceService — الطرق العامة (Public Methods)

```php
__construct(PDO $pdo, ?int $userId = null)
normalizeFinancialPayload(array $data): array          // تنظيف وتطبيع البيانات المالية
executeAtomically(callable $callback)                   // معاملات آمنة متداخلة
createInvoiceDraft(array $data, string $category): int  // إنشاء فاتورة مسودة
postInvoice(int $invoiceId): void                       // ترحيل الفاتورة
createReceiptVoucherDraft(array $data): int             // إنشاء سند قبض مسودة
createPaymentVoucherDraft(array $data): int             // إنشاء سند دفع مسودة
allocatePayment(int $voucherId, int $invoiceId, float $amount): void  // تخصيص الدفعة
postReceiptVoucher(int $voucherId): void                // ترحيل سند القبض
postPaymentVoucher(int $voucherId): void                // ترحيل سند الدفع
recalculateInvoicePaymentStatus(int $invoiceId): void   // إعادة حساب حالة دفع الفاتورة
processServiceOperation(array $data): array             // معالجة عملية خدمة كاملة
receiveInvoicePayment(array $data): int                 // استلام دفعة فاتورة
createExpenseVoucherDraft(array $data): int             // إنشاء سند مصروف مسودة
postExpenseVoucher(int $voucherId): void                // ترحيل سند المصروف
processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment): void  // موافقة متعددة المستويات
```

#### الدوال الرئيسية (Functions)

| الدالة | الملف | الوظيفة |
|-------|------|---------|
| `verify_csrf_token()` | `includes/functions.php` | التحقق من CSRF Token باستخدام `hash_equals()` |
| `generate_csrf_token()` | `includes/functions.php` | توليد CSRF Token |
| `csrf_input()` | `includes/functions.php` | طباعة حقل CSRF مخفي في النماذج |
| `check_access()` | `includes/functions.php` | التحقق من صلاحيات المستخدم |
| `getSettings()` | `includes/functions.php` | جلب إعدادات النظام |
| `resolve_transaction_pricing()` | `includes/functions.php` | تحديد تسعير المعاملة |
| `normalize_service_target()` | `includes/functions.php` | تحديد هدف الخدمة (وكيل/فرع) |
| `require_csrf()` | `includes/security.php` | إلزام CSRF للطلبات |
| `rate_limit()` | `includes/security.php` | تحديد معدل الطلبات (Rate Limiting) باستخدام flock |
| `json_exception_response()` | `includes/security.php` | استجابة JSON موحدة للأخطاء |
| `php_create_invoice()` | `includes/accounting_functions.php` | إنشاء فاتورة (توافق قديم) |
| `php_create_invoice_and_post()` | `includes/accounting_functions.php` | إنشاء وترحيل فاتورة |
| `tafqeet()` | `includes/tafqeet.php` | تحويل الأرقام إلى كلمات عربية (للوثائق المالية) |

---

## الميزات الأمنية

### الحماية من SQL Injection

- استخدام **PDO Prepared Statements** في جميع الاستعلامات (`ATTR_EMULATE_PREPARES => false` للتحقق الصارم من الأنواع)
- فئة **SafeDB** تغلف جميع عمليات قاعدة البيانات بـ Prepared Statements
- دوال تنقية على مستوى قاعدة البيانات (`fn_sanitize_input`, `fn_sanitize_safe`, `fn_sanitize_text`)

### الحماية من CSRF

- توليد CSRF Token فريد لكل جلسة
- التحقق الإلزامي في كل طلب POST عبر `verify_csrf_token()` باستخدام `hash_equals()` (مقاوم لهجمات التوقيت)
- دالة `require_csrf()` و `require_csrf_for_actions()` للتحقق المشروط

### إدارة الجلسات (Session Security)

- مسار حفظ مخصص للجلسات (بعيد عن المسار الافتراضي)
- `httponly = true` (منع الوصول عبر JavaScript)
- `samesite = Strict` (منع CSRF عبر الجلسات)
- `secure = auto` (تفعيل HTTPS تلقائيًا في بيئة الإنتاج)
- إعادة توليد Session ID بعد تسجيل الدخول

### Rate Limiting

- تحديد معدل الطلبات لكل IP/مستخدم عبر `rate_limit()` باستخدام flock (قفل ملفي آمن)
- يحمي من هجمات Brute Force و DDoS البسيطة

### التحكم بالوصول (RBAC)

- نظام صلاحيات قائم على الأدوار (Role-Based Access Control)
- صلاحيات على مستوى الصفحة وعلى مستوى الإجراء
- نظام متعدد الفروع (Multi-Branch) عبر `BranchMiddleware`
- تخزين مؤقت للصلاحيات (Caching) مع `clearUserPermissionsCache()`

### حماية الملفات

- ملف `.htaccess` يحجب ملفات `fix*.php` و `debug*.php` و `check*.php` من الوصول العام
- ملف `.gitignore` يستثني ملفات الجلسات والسجلات و `.env` والملفات المؤقتة

### التدقيق (Audit Logging)

- جدول `audit_logs` لتسجيل جميع العمليات الحساسة
- تسجيل من قام بالعملية ومتى ومن أي جهاز

---

## طرق التشغيل

### الطريقة الأولى: XAMPP / WAMP (للمبتدئين والمطورين المحليين)

هذه أسهل طريقة للتشغيل على نظام Windows أو macOS أو Linux.

**الخطوة 1 — تثبيت XAMPP:**

قم بتحميل XAMPP من الموقع الرسمي: https://www.apachefriends.org/download.html — تأكد من اختيار إصدار PHP 8.1 أو أحدث. ثبّت XAMPP واتبع معالج التثبيت.

**الخطوة 2 — نسخ المشروع:**

```bash
cd C:\xampp\htdocs   # على Windows
# أو
cd /opt/lampp/htdocs # على Linux/Mac

git clone https://github.com/ww770105ww-sud/alghazali.git
```

**الخطوة 3 — إعداد قاعدة البيانات:**

1. شغّل Apache و MySQL من لوحة تحكم XAMPP
2. افتح phpMyAdmin على http://localhost/phpmyadmin
3. أنشئ قاعدة بيانات جديدة باسم `alghazali` بترميز `utf8mb4_unicode_ci`
4. اذهب إلى تبويب Import واختر `tools/database/alghazali.sql` ثم اضغط Go

**الخطوة 4 — إعداد ملف البيئة:**

```bash
cd alghazali
cp .env.example .env
```

عدّل ملف `.env` وضع بيانات اتصالك:
```env
DB_HOST=127.0.0.1
DB_NAME=alghazali
DB_USER=root
DB_PASS=        # كلمة مرور MySQL (فارغة افتراضيًا في XAMPP)
TIMEZONE=Asia/Riyadh
```

**الخطوة 5 — التشغيل:**

افتح المتصفح على: http://localhost/alghazali/

### الطريقة الثانية: خادم PHP المدمج (للتطوير السريع)

يحتوي المشروع على ملف `router.php` يتيح التشغيل بخادم PHP المدمج بدون Apache:

```bash
cd alghazali
php -S localhost:8000 router.php
```

ثم افتح: http://localhost:8000/

> هذه الطريقة مناسبة للتطوير السريع والاختبار فقط، ولا تُوصى للإنتاج.

### الطريقة الثالثة: خادم إنتاج (Apache/Nginx + PHP-FPM)

**على Apache:**

```apache
<VirtualHost *:80>
    ServerName alghazali.example.com
    DocumentRoot /var/www/alghazali
    
    <Directory /var/www/alghazali>
        AllowOverride All
        Require all granted
    </Directory>
    
    # تفعيل mod_rewrite للروابط النظيفة
    RewriteEngine On
</VirtualHost>
```

**على Nginx:**

```nginx
server {
    listen 80;
    server_name alghazali.example.com;
    root /var/www/alghazali;
    index index.php index.html;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    
    # منع الوصول للملفات الحساسة
    location ~ /\.(env|git|htaccess) {
        deny all;
    }
    
    location ~ ^/(fix|debug|check).*\.php$ {
        deny all;
    }
}
```

**على خادم الإنتاج، تأكد من:**
- تفعيل HTTPS (SSL/TLS Certificate)
- تعيين `display_errors = Off` في `php.ini`
- تفعيل `opcache` لتسريع PHP
- ضبط `memory_limit = 512M` و `upload_max_filesize = 50M`

---

## هيكل المشروع

```
alghazali/
├── admin/                      # صفحات لوحة الإدارة (180 ملف PHP)
│   ├── index.php               # الصفحة الرئيسية (لوحة المعلومات)
│   ├── crm/                    # وحدة CRM (27 ملف)
│   │   ├── contacts.php        # إدارة جهات الاتصال
│   │   ├── deals.php           # إدارة الصفقات
│   │   ├── pipelines.php       # خطوط الإنتاج
│   │   ├── tasks.php           # المهام
│   │   ├── calendar.php        # التقويم
│   │   ├── campaigns.php       # الحملات التسويقية
│   │   ├── chatbot.php         # روبوت الدردشة
│   │   ├── automation.php      # الأتمتة
│   │   ├── webhooks.php        # الويب هوك
│   │   ├── analytics.php       # التحليلات
│   │   ├── ai-assistant.php    # المساعد الذكي
│   │   └── ...                 # المزيد
│   ├── hajj.php                # إدارة الحج (مع OCR للجوازات)
│   ├── umrah.php               # إدارة العمرة
│   ├── invoices.php            # الفواتير
│   ├── customers.php           # العملاء
│   ├── agents.php              # الوكلاء
│   ├── expenses.php            # المصروفات
│   ├── employees.php           # الموظفون
│   ├── reports.php             # التقارير
│   └── ...                     # 170+ صفحة أخرى
├── core/                       # الخدمات المركزية
│   ├── FinanceService.php      # الخدمة المالية المركزية (1,674 سطر)
│   └── bookings/               # منطق الحجوزات
├── includes/                   # الملفات المشتركة
│   ├── db.php                  # اتصال قاعدة البيانات (PDO)
│   ├── SafeDB.php              # غلاف PDO الآمن
│   ├── functions.php           # الدوال المساعدة (3,749 سطر)
│   ├── accounting_functions.php # الدوال المحاسبية (2,180 سطر)
│   ├── security.php            # دوال الحماية (CSRF, Rate Limit)
│   ├── session_config.php      # إعداد الجلسات
│   ├── BranchMiddleware.php    # صلاحيات متعدد الفروع
│   ├── ServiceFinancialEngine.php # محرك الخدمات المالي
│   ├── InvoiceManager.php      # مدير الفواتير
│   ├── CurrencyExchange.php    # صرف العملات
│   ├── WhatsAppService.php     # خدمة واتساب
│   ├── tafqeet.php             # تحويل الأرقام لكلمات عربية
│   ├── header.php              # رأس الصفحة (تحميل Bootstrap, CSS, JS)
│   ├── footer.php              # تذييل الصفحة
│   ├── nationalities.php       # قائمة الجنسيات
│   └── ...
├── assets/                     # الأصول الثابتة
│   ├── css/                    # ملفات CSS المخصصة (6 ملفات)
│   ├── sounds/                 # ملفات صوتية
│   └── uploads/                # ملفات المستخدمين المرفوعة
├── tools/                      # أدوات المساعدة
│   ├── database/
│   │   └── alghazali.sql       # ملف قاعدة البيانات الكامل (~4.7MB)
│   ├── apply_*.php             # سكريبتات إصلاح قاعدة البيانات
│   ├── verify_database_fixes.php
│   └── run_integration_test.php
├── docs/                       # التوثيق
│   └── FinanceService_Review_Report_AR.md  # تقرير مراجعة الخدمة المالية
├── sessions/                   # ملفات الجلسات (مستثناة من Git)
├── storage/                    # التخزين المؤقت
├── supabase/                   # تكامل Supabase (إن وجد)
├── uploads/                    # الملفات المرفوعة
├── .env.example                # نموذج ملف البيئة
├── .gitignore                  # ملفات مستثناة من Git
├── .htaccess                   # إعدادات Apache
├── router.php                  # موجه خادم PHP المدمج
├── README.md                   # هذا الملف
└── ...                         # ملفات أخرى
```

### إحصائيات المشروع

| المؤشر | القيمة |
|--------|--------|
| إجمالي ملفات PHP | 346 ملف |
| إجمالي ملفات JavaScript | 6 ملفات |
| إجمالي ملفات CSS | 6 ملفات |
| إجمالي أسطر كود PHP | ~123,830 سطر |
| صفحات لوحة الإدارة | 180 صفحة |
| صفحات وحدة CRM | 27 صفحة |
| جداول قاعدة البيانات | 115 جدول |
| الإجراءات المخزنة | 26 إجراء/دالة |
| العروض (Views) | 4 عروض |
| حجم ملف قاعدة البيانات | ~4.7MB |


---

## ملف البيئة (.env)

يستخدم النظام ملف `.env` لإعدادات البيئة الحساسة (مستثنى من Git للأمان). انسخ `.env.example` إلى `.env` وعدّله حسب بيئتك:

```env
# 1. إعدادات الاتصال بقاعدة البيانات
DB_HOST=127.0.0.1
DB_NAME=alghazali
DB_USER=root
DB_PASS=your_password_here

# 2. إعدادات المنطقة الزمنية
TIMEZONE=Asia/Riyadh
```

| المتغير | الوصف | القيمة الافتراضية |
|---------|-------|-------------------|
| `DB_HOST` | عنوان خادم قاعدة البيانات | `127.0.0.1` |
| `DB_NAME` | اسم قاعدة البيانات | `alghazali` |
| `DB_USER` | اسم مستخدم قاعدة البيانات | `root` |
| `DB_PASS` | كلمة مرور قاعدة البيانات | — |
| `TIMEZONE` | المنطقة الزمنية | `Asia/Riyadh` |

> 🔒 **تنبيه أمني:** لا ترفع ملف `.env` إلى Git أبدًا. إنه مذكور في `.gitignore`. استخدم `.env.example` كنموذج للمطورين الجدد.

---

## الوحدات والخدمات الرئيسية

### 1. الوحدة المالية المحاسبية (Finance & Accounting)

هذه هي الوحدة الأهم في النظام، مبنية حول `FinanceService` كـ **المصدر الوحيد للحقيقة** (Single Source of Truth). تدعم:

- **نظام القيد المزدوج الكامل** (Double-Entry Bookkeeping) — كل عملية مالية تنشئ قيدين متوازنين (مدين/دائم)
- **الفواتير** — إنشاء مسودات، ترحيل، إعادة حساب حالة الدفع
- **سندات القبض** (Receipt Vouchers) — استلام المدفوعات من العملاء
- **سندات الدفع** (Payment Vouchers) — دفع المستحقات للموردين
- **تخصيص المدفوعات** (Payment Allocation) — ربط الدفعات بفواتير محددة
- **المصروفات** — إنشاء وترحيل سندات المصروفات
- **موافقات المصروفات متعددة المستويات** (Multi-Level Expense Approval)
- **صرف العملات** — تتبع أسعار الصرف وتسجيل معاملات الصرف
- **الميزانيات ومراكز التكلفة** — تتبع المصروفات حسب المركز
- **تحويل الأرقام إلى كلمات عربية** — عبر `tafqeet.php` لوثائق الفواتير

### 2. وحدة إدارة العملاء (Customer Management)

- تسجيل بيانات العملاء الكاملة
- تتبع الأرصدة وسجل التعاملات
- العلاقات الأسرية ومتطلبات العائلة
- طلبات زيارة العائلة وأفرادها
- الوثائق والمستندات

### 3. وحدة إدارة الوكلاء والفروع (Agents & Branches)

- إدارة الوكلاء وعمولاتهم
- نظام متعدد الفروع مع صلاحيات منفصلة لكل فرع
- تحديد أهداف الوكلاء والفروع

### 4. وحدة الحجوزات والخدمات (Bookings & Services)

- إدارة الحج (مع OCR تلقائي لجوازات السفر عبر Tesseract.js)
- إدارة العمرة
- حجوزات الطيران والحافلات
- الدفعات والمجموعات وأعضائها
- سير عمل الحجز الكامل (من الإنشاء إلى التأكيد)
- التعديلات والإشعارات والاسترداد
- تذاكر الحجز

### 5. وحدة الموارد البشرية (HR)

- إدارة الموظفين
- الحضور والانصراف (مع تحديد الموقع الجغرافي عبر Leaflet)
- منع تسجيل الحضور من أجهزة غير مصرح بها

### 6. وحدة CRM (Customer Relationship Management)

وحدة متكاملة في `admin/crm/` تحتوي على:

- **جهات الاتصال** (Contacts) — إدارة قاعدة بيانات العملاء المحتملين
- **الشركات** (Companies) — إدارة الشركات والعملاء المؤسسيين
- **الصفقات** (Deals) — متابعة الصفقات التجارية
- **خطوط الإنتاج** (Pipelines) — مراحل الصفقات
- **المهام** (Tasks) — إدارة المهام والمتابعة
- **التقويم** (Calendar) — جدولة المواعيد
- **الحملات** (Campaigns) — الحملات التسويقية
- **روبوت الدردشة** (Chatbot) — روبوت دردشة آلي
- **الأتمتة** (Automation) — أتمتة العمليات
- **الويب هوك** (Webhooks) — تكامل مع أنظمة خارجية
- **التحليلات** (Analytics) — لوحات معلومات وتحليلات
- **المساعد الذكي** (AI Assistant) — مساعد مدعوم بالذكاء الاصطناعي
- **الرسائل الواردة** (Inbox) — صندوق رسائل موحد
- **البث** (Broadcast) — إرسال رسائل جماعية
- **الملفات** (Files) — إدارة الملفات
- **الملاحظات** (Notes) — ملاحظات على جهات الاتصال
- **القوالب** (Templates) — قوالب الرسائل والبريد
- **سجل النشاط** (Activity Logs) — تتبع كل العمليات
- **سجلات API** (API Logs) — مراقبة استدعاءات API
- **سجلات الويب هوك** (Webhook Logs) — مراقبة عمليات الويب هوك

### 7. وحدة التقارير (Reports)

- تقارير مالية (الميزانية العمومية، قائمة الدخل، التدفقات النقدية)
- تقارير إدارية (أداء الوكلاء، الفروع، الموظفين)
- تقارير الحجوزات والخدمات
- مخططات تفاعلية عبر ApexCharts

### 8. وحدة تكامل واتساب (WhatsApp Integration)

- إرسال رسائل وإشعارات للعملاء عبر واتساب
- عبر `WhatsAppService.php`
- تكامل مع نظام البث في CRM

---

## المساهمة والتطوير

### إعداد بيئة التطوير

```bash
# 1. استنساخ المستودع
git clone https://github.com/ww770105ww-sud/alghazali.git
cd alghazali

# 2. إعداد ملف البيئة
cp .env.example .env
# عدّل القيم حسب بيئتك

# 3. استيراد قاعدة البيانات
mysql -u root -p alghazali < tools/database/alghazali.sql

# 4. تشغيل الخادم المحلي
php -S localhost:8000 router.php
# أو استخدم XAMPP/WAMP
```

### قواعد الكود

- استخدم **PHP 8.2+** features (Typed Properties, Match, Named Arguments)
- جميع استعلامات قاعدة البيانات يجب أن تستخدم **PDO Prepared Statements** أو **SafeDB**
- جميع النماذج يجب أن تحتوي على **CSRF Token** عبر `csrf_input()`
- جميع العمليات المالية يجب أن تمر عبر **FinanceService** فقط
- استخدم `executeAtomically()` للمعاملات المتداخلة
- لا تستخدم `rollBack()` أو `commit()` بدون التحقق من `inTransaction()` أولاً
- استخدم `normalizeFinancialPayload()` لتنظيف البيانات المالية قبل الإدخال
- اكتب التعليقات بالعربية أو الإنجليزية بوضوح
- اختبر الكود قبل الرفع

### هيكل Git

- `main` — الفرع الرئيسي المستقر
- أنشئ فرعًا جديدًا لكل ميزة: `git checkout -b feature/new-feature`
- ارفع الفرع وافتح Pull Request

---

</div>

---

## English Summary

**Alghazali Travel & Tourism Management System** is a comprehensive enterprise application built with **PHP 8.2** and **MariaDB 10.4+** (no framework, native PDO). It is designed for travel and tourism companies, covering hajj/umrah operations, visa services, flight bookings, and full financial management.

### Key Technical Details

- **Language:** PHP 8.2 (no framework — native MVC pattern)
- **Database:** MariaDB 10.4.28 / MySQL 8.0+ — 115 tables, 26 stored procedures, 4 views, utf8mb4 charset
- **Frontend:** Bootstrap 5.3 RTL, jQuery 3.6, Select2, SweetAlert2, ApexCharts, Tesseract.js (OCR), Leaflet (maps), Cropper.js, JsBarcode, AOS, TinyMCE, Simple Peer (WebRTC), Font Awesome 6, Google Fonts (Cairo, Tajawal, Inter)
- **Backend:** PDO with `ATTR_EMULATE_PREPARES=false`, SafeDB wrapper, FinanceService (1,674 lines — Single Source of Truth for all financial operations with full double-entry bookkeeping), BranchMiddleware (multi-branch RBAC), WhatsAppService, CurrencyExchange
- **Security:** CSRF protection (`hash_equals`), Rate limiting (flock-based), Secure sessions (httponly, samesite=Strict, secure auto-detect), SQL injection prevention (PDO prepared statements), .htaccess file protection, Audit logging
- **Scale:** 346 PHP files, ~123,830 lines of code, 180 admin pages, 27 CRM pages
- **Database file:** `tools/database/alghazali.sql` (~4.7MB)

### Running the Project

1. **XAMPP/WAMP:** Clone to `htdocs`, import `tools/database/alghazali.sql` via phpMyAdmin, copy `.env.example` to `.env`, visit `http://localhost/alghazali/`
2. **PHP built-in server:** `php -S localhost:8000 router.php`
3. **Production:** Apache with `mod_rewrite` or Nginx with PHP-FPM, enable HTTPS, set `display_errors=Off`

### Repository

- **GitHub:** https://github.com/ww770105ww-sud/alghazali.git
- **Branch:** `main`
- **License:** Proprietary

---

<div dir="rtl" align="center">

### 🏢 نظام الغزالي لإدارة السفر والسياحة

**تطوير فريق الغزالي** — جميع الحقوق محفوظة © 2024

</div>
