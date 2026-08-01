# 🧠 برومبت شامل لإرساله إلى الذكاء الاصطناعي
## موضوع: تحسين شامل للإجراءات المخزنة في قاعدة بيانات نظام وكالة سفر (ghazali)

---

## 📋 معلومات عامة عن المشروع

أنا مطور PHP وأعمل على نظام ERP لإدارة وكالة سفر وسياحة باسم المشروع: `ghazali`.
تقنية قاعدة البيانات: **MySQL / MariaDB 10.4.28**
الرمز: **utf8mb4_unicode_ci** (العربية مدعومة كلياً).
المحرك: **InnoDB** (يدعم المعاملات).

كود التطبيق يستخدم PDO مع Prepared Statements.

---

## 🗄️ هيكل الجداول الأساسية الموجودة (للمعرفة فقط — لا تعديل عليها)

### جدول `sequence_numbers` (موجود بالفعل ويستخدمه الدالة fn_get_next_sequence):
```
+---------------+-------------+------+-----+---------------------+-------------------------------+
| Field         | Type        | Null | Key | Default             | Extra                         |
+---------------+-------------+------+-----+---------------------+-------------------------------+
| id            | int(11)     | NO   | PRI | NULL                | auto_increment                |
| sequence_name | varchar(50) | NO   | UNI | NULL                |                               |
| last_number   | int(11)     | NO   |     | 0                   |                               |
| year          | varchar(4)  | NO   |     | NULL                |                               |
| updated_at    | timestamp   | NO   |     | current_timestamp() | on update current_timestamp() |
+---------------+-------------+------+-----+---------------------+-------------------------------+
```
محتوى مثال:
| sequence_name      | last_number | year |
| invoice           |           3 | 26   |
| receipt         |           8 | 2026 |
| payment         |           9 | 26   |
| journal         |           6 | 2026 |
| purchase        |          13 | 26   |
| busflight_invoice | 5      | 2026 |

### جدول `account_balances_unified`:
يحتوي على الحقول: id, account_id, branch_id, **currency_id**, **currency_code**(مكرر من جدول currencies), opening_balance, current_balance, current_balance_base, credit_limit, debit_limit, is_frozen, last_updated, cost_center_id, opening_balance_base

### جدول `invoices`:
يحتوي على أعمدة ENUM التالية:
- `invoice_category` enum('sales','purchase')
- `payment_type` enum('cash','credit','bank_transfer','agent','branch')
- `payment_status` enum('unpaid','partial','fully_paid')
- `invoice_status` enum('draft','posted','cancelled')

---

## 🔧 الدوال المساعدة الموجودة (لا تقم بتعديلها، فقط استخدمها كما هي):

### 1. `fn_sanitize_safe(p_input TEXT, p_strip_tags INT)` → تقوم بتنقية المدخلات النصية
### 2. `fn_get_next_sequence(p_sequence_name VARCHAR(50))` → تقوم بإرجاع رقم تسلسلي جديد بصيغة PREFIX-YY-NNNNN
   تستخدم الجدول sequence_numbers مع INSERT ... ON DUPLICATE KEY UPDATE،
   وتبعاً لاسم التسلسل تعطي بادئة مختلفة:
   receipt→RCT, payment→PMT, invoice→INV, purchase→PUR, journal→JRN,
   busflight_invoice→BFI, umrah_invoice→UMR, work_visa_invoice→WVI,
   family_visit_invoice→FVI, passport_invoice→PSI, purchase_invoice→PUI
   **ملاحظة**: هذه الدالة تعمل داخل المعاملة نفسها، لذا عدم وجود حجز قد يسبب تضارباً (race condition) — اتركها كما هي إلا إذا طلبت صراحةً تعديلها.
### 3. `fn_get_default_leaf_account(p_parent_account_code VARCHAR(50))` → إرجاع معرف حساب ورقة تحت أصل معين
### 4. `fn_convert_currency`, `fn_convert_to_base_currency`

---

## 📚 الإجراءات المساعدة الموجودة (لا تقم بتعديلها — فقط احتفظ باستدعائها):

- `sp_rebuild_balances()` — يعيد بناء الأرصدة من جدول movements إلى جدول account_balances_unified
- `sp_validate_journal_balance(p_transaction_id INT)` — يتحقق من توازن مدين = دائن في سطور اليومية، ويلقي SIGNAL عند عدم التوازن
- `sp_recalculate_invoice_payment(p_invoice_id INT)` — يحدث amount_received و payment_status في الفاتورة
- `sp_update_account_balances(p_transaction_id INT)` — واجهة تستدعي sp_rebuild_balances()

---

## 🎯 قائمة الإجراءات المخزنة المطلوب إعادة كتابتها وتحسينها (8 إجراءات):

1. `sp_create_invoice`
2. `sp_create_receipt_voucher`
3. `sp_create_payment_voucher`
4. `sp_post_invoice`
5. `sp_post_receipt_voucher`
6. `sp_post_payment_voucher`
7. `sp_unpost_invoice`
8. `sp_ensure_opening_balance`

---

## 🔴 المشاكل الحالية التي يجب حلها مع كل إجراء:

### ⚠️ المشكلة 1: غياب المعاملات (Transactions) — (تم إضافة جزئياً مع الأخطاء)
- **الحالة الحالية**: في sp_create_invoice يحتوي على HANDLER مكرر و DECLARE بعد START TRANSACTION (خطأ ترتيبي في MySQL)
- **المطلوب الصحيح لكل إجراء**:
  1. جميع تعريفات `DECLARE` المتغيرات (بمحتوياتها DEFAULT).
  2. تعريفات `CURSOR` إن وجدت.
  3. تعريفات `DECLARE CONTINUE HANDLER FOR NOT FOUND` إن وجدت.
  4. **ثم** `DECLARE EXIT HANDLER FOR SQLEXCEPTION` مع:
     ```sql
     BEGIN
         ROLLBACK;
         RESIGNAL;
     END
     ```
  5. **ثم** `START TRANSACTION;`
  6. منطق الإجراء بالكامل.
  7. **ثم** `COMMIT;`

---

### ⚠️ المشكلة 2: LAST_INSERT_ID() في توليد الأرقام التسلسلية
- **مكانها**:
  في sp_create_invoice يوجد كود احتياطي يستخدم LAST_INSERT_ID()+1 عند فشل fn_get_next_sequence.
  ```sql
  COALESCE(
      fn_get_next_sequence(...),
      CONCAT('INV-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(LAST_INSERT_ID()+1, 4, '0'))
  )
  ```
- **المشكلة**: LAST_INSERT_ID() غير موثوق في بيئة متعددة المستخدمين ويعطي أرقام مكررة
- **المطلوب**: الاعتماد الكامل والكامل على `fn_get_next_sequence()` فقط وحذف البديل الذي يستخدم LAST_INSERT_ID() — وإذا فشلت الدالة فليكن هناك SIGNAL خطأ واضح بدلاً من توليد رقم عشوائي.

---

### ⚠️ المشكلة 3: عدم التحقق من وجود السجلات قبل التحديث والحذف
- **المشكلة**: في `sp_post_invoice`, `sp_unpost_invoice`, `sp_post_receipt_voucher`, `sp_post_payment_voucher`
  عمليات UPDATE و DELETE تحدث دون التحقق من عدد الصفوف المتأثرة (ROW_COUNT()).
- **المطلوب**: بعد كل عملية UPDATE أو DELETE الهامة:
  ```sql
  UPDATE ... WHERE id = p_id;
  IF ROW_COUNT() = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'اسم_الإجراء: فشل التحديث - السجل غير موجود';
  END IF;
  ```

---

### ⚠️ المشكلة 4: تجاهل الاستثناءات باستخدام CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END — "الابتلاع الصامت للأخطاء
- **الأماكن**:
  في كل الإجراءات يوجد تقريباً كود التالي لجلب عنوان IP من جدول التدقيق:
  ```sql
  BEGIN
      DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
      SET v_created_ip = (SELECT SUBSTRING_INDEX(...) FROM audit_logs WHERE user_id = p_created_by LIMIT 1);
  END;
  ```
- **المشكلة**: أي خطأ آخر غير متعلق بعمليات الداخلية (مثل قفل الجدول، انقطاع الاتصال، ...) سوف يتم ابتلاعه بصمت كله.
- **المطلوب**:
  طريقة آمنة: استعلام منع الخطأ المقصود فقط وترك باقي الأخطاء تمر:
  ```sql
  BEGIN
      DECLARE EXIT HANDLER FOR 1146 BEGIN END; -- Table doesn't exist
      DECLARE EXIT HANDLER FOR SQLSTATE '23000 BEGIN END;
      -- أو طريقة أفضل: الحصول على القيمة بشكل آمن مع SELECT MAX أو COALESCE مع فرع في متغير
  ```
  أو على الأقل: احفظ رسالة الخطأ في متغير أو اترك للمعالجة قبل الاختيار تحذيريّا.
  الطريقة المفضلة: استعلام COUNT(*) قبل الاختيار حتى نعرف ما إذا كانت هناك بيانات أم لا، بدلاً من الاعتماد على ابتلاع الخطأ.

---

### ⚠️ المشكلة 5: رسائل الخطأ تكشف تفاصيل داخلية عن قاعدة البيانات
- **مثال موجود:
  `'sp_post_invoice: المعاملة #123 غير متوازنة — مدين=1000 ، دائن=900 ، الفرق=100`
  أو رسائل خطأ MySQL الأصلية التي تمر عبر RESIGNAL (مثل "Column X not found" أو "Duplicate entry ..."
- **المشكلة**: عند وصول هذه الرسائل إلى المستخدم النهائي تكشف بنية قاعدة البيانات.
- **المطلوب**:
  ✅ في `DECLARE EXIT HANDLER FOR SQLEXCEPTION` الجديد، بدلاً من RESIGNAL المباشر:
  ```sql
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
      ROLLBACK;
      -- تسجيل الخطأ الأصلي في متغيرات DIAGNOSTICS
      GET STACKED DIAGNOSTICS CONDITION 1
          @_err_sqlstate = RETURNED_SQLSTATE,
          @_err_msg = MESSAGE_TEXT,
          @_err_no = MYSQL_ERRNO;
      -- هنا: إنشاء رسالة عامة حسب نوع الخطأ:
      IF @_err_sqlstate = '45000' THEN
          -- خطأ منطقي من أنا صراحه فلا مشكلة — نعيد رسالة المستخدم
          RESIGNAL;
      ELSE
          -- خطأ نظامي (مثل اختصار جدول، مفتاح أجنبي، تكرار، ...) أخفي رسالة عامة
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
              'حدث خطأ داخلي أثناء تنفيذ العملية. يرجى التحقق من البيانات أو مراجعة الدعم الفني.';
      END IF;
  END;
  ```
  ⚠️ IMPORTANT: استخدام GET STACKED DIAGNOSTICS في MariaDB قد يختلف قليلاً، تأكد من التوافق.
  إذا لم يكن GET STACKED DIAGNOSTICS متوفراً:
  ```sql
  -- احتفظ برسالة الخطأ الأصلية فقط للأخطاء التي نطلقها أننا باستخدام SIGNAL SQLSTATE '45000' (رسائل منطقية)، واختفاء لرسائل عامة للأخطاء النظامية).

  **القاعدة**: رسائل SIGNAL التي تطلقها أنت (تحقق من الصلاحيات، تحقق من الوجود، التحقق من العملات، الخ保持不变 باللغة العربية واضحة). رسائل خطأ النظام (MySQL errors) التي لا نحتفظ بها فقط في السجل أو نحولها لرسالة عامة قبل إعادتها إلى التطبيق.

---

### ⚠️ المشكلة 6: التحقق من صلاحيات المستخدم داخل الإجراءات (اختيارية ولكن مرغوب بها بشدة)
- **المشكلة**: أي مستخدم يستطيع استدعاء الإجراء بدون تحقق من صلاحياته.
- **المطلوب**:
  كل إجراء (ما عدا sp_ensure_opening_balance الذي يُستدعى من عمليات إنشاء حسابات) يستلم معامل `p_user_id` (المتغيرات p_created_by أو p_posted_by الموجود بالفعل) ويفحص:
  ```sql
  -- مثال:
  DECLARE v_user_role VARCHAR(50);
  DECLARE v_user_active TINYINT(1);
  SELECT role, is_active INTO v_user_role, v_user_active FROM users WHERE id = p_posted_by;
  IF v_user_active <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'اسم_الإجراء: حساب المستخدم غير مفعل';
  END IF;
  ```
  💡 ملاحظة: لا تضيف أعمدة جديدة ولا تجريتها إلا مع عدم وجود جدول roles و users — افحص إن وجد. إن لم يكن موجود (أو لم تكن معلومات كافية) اكتب التعليق فقط واترك كود التحقق داخل BEGIN واتبع ما هو موجود حالياً لعدم كسر التوافق.

---

### ⚠️ المشكلة 7: التحقق من وجود السجل قبل المعاملات المالية (sp_post_xxx و sp_unpost_invoice) قبل حدوث حالة سبق أن تمت إضافته وإن لم يكن هناك سجل يُرجى الإشارة إليه بشكل خاص.

---

## ✅ ما يجب الاحتفاظ به دون أي تغيير:

1. جميع معاملات المعاملات (IN و OUT parameters) لكل إجراء — **بالضبط كما هي (عدد ونوع واسم) — لتوافق التطبيق PHP عليها.
2. جميع الاستخدام `fn_sanitize_safe(...)` لتنقية المدخلات النصية.
3. جميع التحققات الحالية (مطابقة العملات، حد الائتمان، فواتير partial في cash_bank_account المخصصات، توازن اليومية، الخ).
4. جميع عمليات INSERT و UPDATE و DELETE مع حقولها وقيمها.
5. استدعاءات الإجراءات الأخرى (sp_validate_journal_balance, sp_recalculate_invoice_payment, sp_update_account_balances
6. هيكل الجداول — لا تغيير في بنية الجداول أبداً.
7. عمليات التدقيق (INSERT في جدول `audit_logs` بكل محتواها.
8. تسميات labels المعاملات المعاملات المعاملات BEGIN ... END و labes واسم المعاملة باللغة العربية.

---

## 💡 تنسيق الإخراج المطلوب منك:

لكل إجراء من الإجراءات الثمانية:
1. أولاً: قائمة صغيرة بالتغييرات التي قمت بها مقارنة بالأصل (نقاط)
2. ثانياً: الكود الكامل للـ PROCEDURE مع:
   ```sql
   DROP PROCEDURE IF EXISTS `sp_xxx`$$
   CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_xxx` ( ... )
   MODIFIES SQL DATA SQL SECURITY INVOKER COMMENT '...'
   sp_xxx_body:BEGIN
       -- جميع المتغيرات
       -- المؤشرات
       -- CONTINUE HANDLERs
       -- EXIT HANDLER مع معالجة الأخطاء الذكية
       -- START TRANSACTION
       -- منطق الإجراء كاملاً
       -- COMMIT
   END$$
   ```
3. استخدم DELIMITER $$ قبل وبعدمجموعة الإجراءات.

---

## 📝 ملاحظات إضافية:

- إذا واجهت مشكلة في استخدام GET STACKED DIAGNOSTICS في EXIT HANDLER (بسبب إصدار MariaDB، استخدم الحل البديل:
  احتفظ بمتغير حالة `DECLARE v_error_message TEXT DEFAULT NULL` قبل بداية المعاملة، وكلما وصلت خطأ واجهت مشكلة SYSTEM عيناه في هذا المتغير قبل الإشارة إلى SIGNAL.
- لا تحذف أي متغيرات DECLARE مهما كان سبب — حتى لو بدت لك غير مستخدمة.
- إذا كنت غير متأكد من أن كود التحقق من صلاحيات المستخدم بسبب عدم وضوح جدول المستخدمين، أضف تعليقاً توضيحياً واترك مكانًا محاط بـ `/* TODO: التحقق من صلاحيات المستخدم هنا */` دون كود تجريبي بسيط.
- الرسائل SIGNAL واضحة ومفصلة للمستخدم النهائي في حالات التحقق (خطأ منطقية (SQLSTATE 45000) ورسائل عامة جداً لباقي الأخطاء.
- استخدم BEGIN ... END المعاملات المعاملة المعاملة المعاملة.
---
اتمام العملية إنشاء سند صرف/قبض: تأكد من أن تخصيص المبالغ للفواتير يحدث داخل نفس المعاملة ويتحقق من عدم تجاوز المتبقي قبل الإدراج.

---

## 🎁 مثال على التنسيق النهائي المطلوب:

```
DELIMITER $$

-- =====================================================================
-- 1. sp_create_invoice
-- التغييرات:
--   [x] إصلاح ترتيب DECLARE قبل START TRANSACTION
--   [x] دمج EXIT HANDLER مكرر في واحد
--   [x] حذف LAST_INSERT_ID() من توليد الأرقام التسلسلية
--   [x] إضافة تحقق ROW_COUNT() بعد INSERT في invoices
--   [x] تحسين معالجة CONTINUE HANDLER لجلب IP بدون ابتلاع كل الأخطاء
--   [x] فصل أخطاء منطقية (45000) مرئية وأخطاء نظامية مخفية
-- =====================================================================

DROP PROCEDURE IF EXISTS `sp_create_invoice`$$
CREATE ... الكود الكامل ... END$$

-- =====================================================================
-- 2. sp_create_receipt_voucher
-- =====================================================================
... الخ
```

وش هكذا حتى الانتهاء من الـ 8 إجراءات. أجمعها مع دقة والحفاظ على كامل التوافق مع التطبيق.
