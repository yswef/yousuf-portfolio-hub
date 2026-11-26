# سجل الملفات المتغيّرة لكل وسم (Changed-files log)

> إلزامي بحسب `AGENTS.md`. كل إصدار (tag) له قسم هنا يذكر **كل مسار** تغيّر ونوع العملية.
> المصدر الآلي الكامل (بصمة SHA-256 لكل ملف) موجود في `data/update/manifests/<id>.json|.md`.

## v2.4.0 — 2026-11-25 — تحديث آمن (ملف بملف) + قواعد الذكاء الاصطناعي + إصلاح الهاتف

- الملفات: 15 (إضافة 7 · استبدال 8 · حذف 0)
- المسارات:
  - `core/update.php`                        add      — محرّك التحديث الآمن (stage → log → replace one by one → verify → rollback)
  - `update_start.php`                       add      — بدء التحديث عبر AJAX (جلسة أدمن + CSRF)
  - `update_step.php`                        add      — تنفيذ/متابعة خطوة + بثّ التقدّم (توكن لكل تحديث)
  - `admin/core/update_wait.php`             add      — شاشة «جاري التحديث» للوحة (تعمل قبل تحميل النواة)
  - `cron_update.php`                        add      — استئناف التحديث من السيرفر بدون متصفح
  - `data/update/.htaccess`                  add      — منع الوصول إلى مساحة التحديث عبر الويب
  - `AGENTS.md`                              add      — قواعد إلزامية لوكيل الذكاء الاصطناعي
  - `UPDATE_CHANGELOG.md`                    add      — هذا الملف
  - `core/bootstrap.php`                     replace  — تحميل `update.php`، منع عرض الأخطاء للزوار، صفحة خطأ قاتلة أنيقة
  - `index.php`                              replace  — بوابة صيانة (503) أثناء التحديث + صفحة خطأ مؤقتة مُنسّقة بدل النص المجرّد
  - `admin/index.php`                        replace  — تحويل إلى شاشة التقدّم أثناء التحديث قبل تحميل النواة
  - `admin/modules/updater/module.php`       replace  — واجهة التحديث الجديدة: شارة عائمة + شريط تقدّم + سجل الملفات
  - `admin/core/ui.php`                      replace  — حقن الشارة العائمة + إصلاح صفحة الخطأ + إصدار CSS
  - `admin/assets/admin.css`                 replace  — تصلّب الهاتف (منع التمرير الأفقي) + أنماط الشارة العائمة
  - `assets/css/style.css`                   replace  — تصلّب الهاتف للواجهة العامة
  - `app/layout.php`                         replace  — رفع إصدار `style.css` إلى `v=5`
  - `.gitignore`                             replace  — السماح بتتبّع `data/update/.htaccess`
- قاعدة البيانات: **لا تغييرات · لا هجرات جديدة**
- ملاحظات: المسار القديم `updater_apply_from_zip()` لم يبقَ مستخدماً في أي عملية تحديث — سبب خطأ 500 السابق.
