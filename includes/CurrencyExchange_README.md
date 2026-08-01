# فئة CurrencyExchange - نظام تصريف العملات

## وصف الفئة

فئة `CurrencyExchange` هي نظام شامل لإدارة عمليات تصريف العملات في وكالة الغزالي للسفريات والسياحة. الفئة متوافقة مع قاعدة البيانات الموحدة وتستخدم الإجراءات المخزنة لضمان سلامة البيانات والترحيل المحاسبي التلقائي.

## الميزات الرئيسية

- ✅ **إدارة العملات**: جلب العملات النشطة والعملة الأساسية
- ✅ **تحويل العملات**: تحويل حسابي بين العملات المختلفة
- ✅ **تنفيذ العمليات**: تنفيذ عمليات التصريف مع الترحيل المحاسبي
- ✅ **سجل العمليات**: جلب تفاصيل عمليات التصريف التاريخية
- ✅ **الإحصائيات**: إحصائيات شاملة لعمليات التصريف
- ✅ **إدارة الأسعار**: تحديث أسعار الصرف
- ✅ **حساب الأرباح**: حساب الربح/الخسارة المتوقع
- ✅ **واجهات تفاعلية**: صفحات ويب حديثة للاستخدام

## صفحات الويب المتاحة

### 1. صفحة تصريف العملات العامة
**الملف:** `currency_exchange.php`
- واجهة عامة لتصريف العملات
- مناسبة للعملاء والموظفين
- عرض أسعار الصرف الحالية
- نموذج تفاعلي مع حساب تلقائي للمبالغ

### 2. صفحة لوحة التحكم الإدارية
**الملف:** `admin/currency_exchange_new.php`
- واجهة إدارية مع شريط جانبي
- إحصائيات شهرية
- جدول مفصل لسجل العمليات
- تحقق من صلاحيات المستخدمين

## طريقة الاستخدام

### التهيئة

```php
require_once 'includes/CurrencyExchange.php';

// إنشاء كائن التصريف
$exchange = new CurrencyExchange($pdo_connection);
```

### جلب العملات

```php
// جلب جميع العملات النشطة
$currencies = $exchange->getAllCurrencies();

// جلب العملة الأساسية
$baseCurrency = $exchange->getBaseCurrency();

// جلب عملة محددة
$currency = $exchange->getCurrencyById(1);
```

### تحويل العملات (حسابياً)

```php
// تحويل مبلغ من عملة إلى أخرى
$convertedAmount = $exchange->convertAmount(1000, 1, 2); // من العملة 1 إلى العملة 2
```

### تنفيذ عملية تصريف

```php
$data = [
    'branch_id' => 1,
    'from_currency_id' => 1,
    'from_amount' => 1000,
    'to_currency_id' => 2,
    'to_amount' => 2.5,
    'exchange_rate' => 400,
    'from_account_id' => 1,
    'to_account_id' => 2,
    'notes' => 'عملية تصريف عادية',
    'created_by' => 1
];

$result = $exchange->executeExchange($data);
if ($result) {
    echo "تمت العملية بنجاح! رقم المعاملة: " . $result['transaction_number'];
} else {
    echo "خطأ: " . $exchange->getError();
}
```

### جلب سجل العمليات

```php
// جلب آخر 10 عمليات
$history = $exchange->getExchangeHistory(10);

// جلب عمليات فترة معينة
$history = $exchange->getExchangeHistory(50, '2024-01-01', '2024-12-31');
```

### جلب تفاصيل عملية محددة

```php
$details = $exchange->getExchangeDetails(1);
if ($details) {
    echo "رقم المعاملة: " . $details['transaction_number'];
    echo "المبلغ المصدر: " . $details['from_amount'] . " " . $details['from_currency_code'];
}
```

### جلب الإحصائيات

```php
// إحصائيات الشهر الحالي
$stats = $exchange->getStatistics('month');

// إحصائيات اليوم
$stats = $exchange->getStatistics('today');

// إحصائيات السنة
$stats = $exchange->getStatistics('year');
```

### تحديث أسعار الصرف

```php
$rates = [
    2 => ['sell' => 535, 'buy' => 530], // دولار أمريكي
    3 => ['sell' => 142, 'buy' => 140]  // ريال سعودي
];

if ($exchange->updateExchangeRates($rates)) {
    echo "تم تحديث الأسعار بنجاح";
} else {
    echo "خطأ: " . $exchange->getError();
}
```

### حساب الربح/الخسارة المتوقع

```php
$profitLoss = $exchange->calculateExpectedProfitLoss(1000, 1, 2500, 2);
echo "الربح/الخسارة المتوقع: " . $profitLoss;
```

## معالجة الأخطاء

```php
// جلب آخر خطأ
$error = $exchange->getError();

// جلب رسالة النجاح
$success = $exchange->getSuccess();
```

## الجداول المطلوبة

الفئة تعتمد على الجداول التالية في قاعدة البيانات:

- `currencies` - جدول العملات
- `currency_exchange_transactions` - معاملات التصريف
- `unified_accounts` - الحسابات الموحدة
- `users` - المستخدمين
- `branches` - الفروع
- `financial_transactions` - المعاملات المالية
- `journal_lines` - قيود اليومية

## الإجراءات المخزنة المطلوبة

- `sp_currency_exchange` - إجراء تنفيذ عملية التصريف

## الملفات المرتبطة

- `includes/db.php` - اتصال قاعدة البيانات
- `includes/CurrencyExchange.php` - الفئة الرئيسية
- `includes/test_currency_exchange_class.php` - ملف الاختبار
- `includes/example_currency_exchange_class.php` - مثال واجهة ويب
- `currency_exchange.php` - صفحة التصريف العامة
- `admin/currency_exchange_new.php` - صفحة لوحة التحكم

## الاختبار

لتشغيل الاختبارات:

```bash
cd includes
php test_currency_exchange_class.php
```

## عرض الصفحات

### صفحة التصريف العامة:
```bash
php -S localhost:8000 currency_exchange.php
```

### صفحة لوحة التحكم:
```bash
php -S localhost:8000 admin/currency_exchange_new.php
```

## الميزات التفاعلية

### 1. حساب تلقائي للمبالغ
- تحديث تلقائي للمبلغ المستلم عند تغيير المبلغ المدفوع
- حساب سعر الصرف تلقائياً عند اختيار العملات

### 2. عرض أسعار الصرف
- جدول أسعار الصرف الحالية
- تمييز العملة الأساسية
- عرض أسعار الشراء والبيع

### 3. إحصائيات تفاعلية
- إحصائيات شهرية
- عدد العمليات لكل زوج عملات
- تحديث تلقائي للإحصائيات

### 4. واجهة مستخدم حديثة
- تصميم متجاوب مع Bootstrap 5
- أيقونات واضحة ومعبرة
- تأكيد العمليات بصرياً

## الإصدار

**الإصدار:** 2.0
**تاريخ التحديث:** 2026-04-17
**المطور:** نظام وكالة الغزالي للسفريات والسياحة

---

*هذه الفئة جزء من النظام المحاسبي الموحد لوكالة الغزالي للسفريات والسياحة*

```php
$data = [
    'branch_id' => 1,
    'from_currency_id' => 1,
    'from_amount' => 1000,
    'to_currency_id' => 2,
    'to_amount' => 850,
    'exchange_rate' => 0.85,
    'from_account_id' => 100,
    'to_account_id' => 101,
    'notes' => 'تصريف عملة',
    'created_by' => 1
];

$result = $exchange->executeExchange($data);
echo "رقم العملية: " . $result['number'];
```

### 4. البيانات المرجعية

#### `getAllCurrencies()`
جلب جميع العملات النشطة.

```php
$currencies = $exchange->getAllCurrencies();
foreach ($currencies as $currency) {
    echo $currency['currency_name'] . " (" . $currency['currency_code'] . ")\n";
}
```

#### `getBaseCurrency()`
جلب العملة الأساسية.

```php
$base_currency = $exchange->getBaseCurrency();
echo "العملة الأساسية: " . $base_currency['currency_name'];
```

#### `getAccountsByCurrency($currency_id)`
جلب الحسابات المتاحة لعملة معينة.

```php
$accounts = $exchange->getAccountsByCurrency(1);
```

### 5. السجلات والإحصائيات

#### `getExchangeHistory($limit = 50)`
جلب سجل عمليات التصريف.

```php
$history = $exchange->getExchangeHistory(20);
foreach ($history as $record) {
    echo "من " . $record['from_currency_code'] . " إلى " . $record['to_currency_code'] . "\n";
}
```

#### `getExchangeStatistics($date_from = null, $date_to = null)`
جلب إحصائيات عمليات التصريف.

```php
$stats = $exchange->getExchangeStatistics('2024-01-01', '2024-12-31');
echo "إجمالي العمليات: " . $stats['total_exchanges'];
```

### 6. التحقق والحسابات

#### `validateExchangeData($data)`
التحقق من صحة بيانات عملية التصريف.

```php
$errors = $exchange->validateExchangeData($data);
if (!empty($errors)) {
    foreach ($errors as $error) {
        echo "خطأ: " . $error . "\n";
    }
}
```

#### `calculateConvertedAmount($amount, $exchange_rate, $direction = 'to')`
حساب المبلغ المحول.

```php
$converted = $exchange->calculateConvertedAmount(1000, 0.85, 'to');
```

#### `calculateProfitLoss($from_amount, $to_amount, $exchange_rate)`
حساب الربح/الخسارة.

```php
$profit_loss = $exchange->calculateProfitLoss(1000, 850, 0.85);
```

### 7. إدارة العمليات

#### `getExchangeDetails($transaction_id)`
جلب تفاصيل عملية تصريف محددة.

```php
$details = $exchange->getExchangeDetails(123);
```

#### `cancelExchange($transaction_id, $cancelled_by)`
إلغاء عملية تصريف.

```php
try {
    $exchange->cancelExchange(123, 1);
    echo "تم إلغاء العملية بنجاح";
} catch (Exception $e) {
    echo "خطأ: " . $e->getMessage();
}
```

#### `updateExchangeRates($rates)`
تحديث أسعار الصرف.

```php
$rates = [
    2 => ['sell' => 0.85, 'buy' => 0.83],
    3 => ['sell' => 0.75, 'buy' => 0.73]
];
$exchange->updateExchangeRates($rates);
```

## ملاحظات مهمة

1. جميع العمليات تتطلب اتصال صحيح بقاعدة البيانات
2. الإجراءات المخزنة والدوال يجب أن تكون متوفرة في قاعدة البيانات
3. استخدم `validateExchangeData()` للتحقق من صحة البيانات قبل التنفيذ
4. جميع المبالغ يجب أن تكون موجبة
5. لا يمكن تصريف العملة لنفسها

## أمثلة متقدمة

### مثال كامل لعملية تصريف

```php
try {
    // التحقق من البيانات
    $errors = $exchange->validateExchangeData($data);
    if (!empty($errors)) {
        throw new Exception(implode(', ', $errors));
    }

    // تنفيذ العملية
    $result = $exchange->executeExchange($data);

    // عرض النتائج
    echo "تم تنفيذ العملية بنجاح\n";
    echo "رقم العملية: " . $result['number'] . "\n";
    echo "الربح/الخسارة: " . $result['profit_loss'] . "\n";

} catch (Exception $e) {
    echo "خطأ: " . $e->getMessage();
}
```
