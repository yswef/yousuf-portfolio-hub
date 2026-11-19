# نظام وحدات لوحة التحكم (Modules) — دليل الدمج

لوحة التحكم = **نواة** (`core/` + `admin/core/`) + **وحدات** (`admin/modules/<id>/`). كل قسم في اللوحة وحدة مستقلة.
لإضافة لوحة تطبيق/موقع آخر: ضع مجلد وحدة جديد في `admin/modules/` — يظهر في القائمة وتُنشأ جداوله تلقائياً عند أول فتح للوحة. لا تعديل على النواة.

## بنية الوحدة
```
admin/modules/<id>/
  module.php     ← يُرجع مصفوفة الوصف (manifest) — الملف الوحيد المطلوب
  view.php       ← اختياري (إن لم تستخدم callable)
  seed.json ...  ← أي ملفات تحتاجها الوحدة
```
اسم المجلد = معرّف الوحدة. المجلدات التي تبدأ بـ `_` معطّلة (انظر `_example/`).

## مفاتيح module.php
| المفتاح | النوع | الوصف |
|---|---|---|
| `title` | `['ar'=>..,'en'=>..]` | عنوان القائمة |
| `icon` | string | إيموجي/رمز |
| `group` | `content` \| `app` \| `system` | مجموعة القائمة. **لوحات التطبيقات الأخرى: `app`** |
| `order` | int | الترتيب |
| `version` | int | ارفعه عند تغيير `schema` فتُنفَّذ الجداول من جديد (آمن: `IF NOT EXISTS`) |
| `schema` | `fn(array $T): string[]` | أوامر SQL. `$T['id']` مفتاح أساسي تلقائي، `$T['big']` نص طويل، `$T['tail']` ملحق MySQL. تعمل على MySQL وSQLite |
| `seed` | `fn(): void` | يُنفَّذ مرة واحدة بعد إنشاء الجداول لأول مرة |
| `actions` | `['name'=>fn(): void]` | معالجات POST. النموذج يرسل `m=<id>&do=<name>` + `_csrf` (يُفحص تلقائياً قبل التنفيذ) |
| `view` | `fn(array $mod): void` أو اسم ملف | واجهة الصفحة (تطبع HTML) |
| `badge` | `fn(): int` | عدّاد أحمر بجانب القائمة |
| `widgets` | `fn(): array` | بطاقات KPI في الرئيسية: `[['label'=>..,'value'=>..,'link'=>?]]` |
| `download` | `fn(string $what): void` | تنزيلات عبر `?m=<id>&dl=<what>` (CSV/تقارير) |

## وحدة CRUD جاهزة (أقصر طريق)
`return crud_module([...])` يبني قائمة + نموذج + حفظ + حذف + رفع صور من وصف الحقول فقط. انظر `_example/module.php` (عملاء) و`posts/module.php`.
أنواع الحقول `t`: `text url date num textarea md select(opts) check slug(from) i18n i18n_area image ts_created ts_updated hidden(value)`.
خيارات: `label req max rows default help dir`. وحدة بعدة أنواع على جدول واحد: `types` (انظر `content/module.php`). خطافات: `before_save(&$data,$old)`, `after_delete($row)`, `list_top`, `edit_extra($row)`, `extra_actions`.

## دوال النواة المتاحة داخل الوحدات
- قاعدة البيانات: `q($sql,$params)` `rows()` `row()` `val()` `ins($table,$data)` `upd($table,$data,$where,$params)` `table_exists()` — كلها Prepared Statements.
- إعدادات: `setting($k)` `set_setting($k,$v)` · ترجمة: `L($json)` `tr($row,'title')` `i18n_post($name)` `t($key)`
- طلب: `P($k)` نص · `PN($k)` عدد · `PB($k)` checkbox · `flash($msg,$ok)` · `adm_back($module,$query)` · `adm_url($module,$query)` · `audit($action,$detail)`
- واجهة: `adm_h($title)` `f_open($m,$do,$hidden,$multipart,$class)` `f_in` `f_ta` `f_chk` `f_sel` `f_i18n` `f_image` `btn_post` · حماية: `e()` للإخراج دائماً
- ملفات: `handle_image_upload($_FILES['x'])` يعيد اسم الملف · `upload_url($name)` · `delete_upload($name)`
- أمان: CSRF والجلسة والتحقق بخطوتين ومحدّد المحاولات في النواة — لا تعدّلها.

## الدمج بالذكاء الاصطناعي (الطريقة الأسهل)
أرسل للذكاء الاصطناعي: (1) هذا الملف، (2) مجلد `admin/modules/_example/`، (3) لوحة التطبيق الآخر (ملفاتها)، ثم الطلب التالي:

> حوّل لوحة التحكم المرفقة إلى وحدة (module) تلتزم بالعقد الموضّح في MODULES.md: مجلد `admin/modules/<اسم>/module.php` (+ ملفات مساعدة). حوّل كل صفحة إلى `view`/`crud_module`، وكل معالج POST إلى `actions`، وكل جدول إلى `schema` بصيغة `$T` (مع `CREATE TABLE IF NOT EXISTS`). لا تغيّر أي ملف في `core/` أو `admin/core/`. استخدم `e()` لكل إخراج و`q()`/`ins()`/`upd()` لكل استعلام. أرجع **فقط** الملفات الجديدة، مع بيان أسماء الجداول ومعرّف الوحدة. إن كان شيء غير واضح فاسألني قبل التخمين.

ثم ضع المجلد الناتج في `admin/modules/`، وافتح اللوحة: تُنشأ الجداول ويظهر القسم تلقائياً. للتراجع: غيّر اسم المجلد إلى `_اسمه`.

## حزمة اللوحة المستقلة
`admin-panel.zip` يحتوي: `core/` + `admin/` (نواة + وحدات الأمان والفحص والإعدادات + المثال) + `install.php` + `config.sample.php`. تعمل وحدها على أي استضافة PHP+MySQL؛ ومنها يمكن بناء لوحة لتطبيق آخر ثم دمجها مع هذا الموقع بنسخ مجلد وحدتها.
