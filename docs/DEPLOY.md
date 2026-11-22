# رفع الموقع على Hostinger (Web Hosting)

المتطلبات: PHP 8.1+ مع `pdo_mysql, mbstring, gd, curl, fileinfo` (كلها متوفرة افتراضياً) وقاعدة MySQL.

1. **قاعدة البيانات:** hPanel ← Databases ← MySQL Databases ← أنشئ قاعدة + مستخدم، واحفظ الاسم والمستخدم وكلمة المرور.
2. **SSL:** hPanel ← Security ← SSL ← فعّله للدومين (الموقع يحوّل تلقائياً إلى https).
3. **الرفع:** ارفع **محتويات** المجلد `site/` إلى `public_html` مباشرة (File Manager أو Git). يجب أن يكون `index.php` داخل `public_html` نفسه.
   - عبر Git: ارفع المشروع إلى مستودع GitHub (ملف `.gitignore` يمنع رفع `config.php` وقاعدة البيانات والصور المرفوعة)، ثم hPanel ← Git ← Create repository ← Deploy.
4. **PHP:** hPanel ← Advanced ← PHP Configuration ← اختر 8.1 أو أحدث.
5. **التثبيت:** افتح `https://دومينك/install.php` ← أدخل بيانات MySQL + حساب المدير ← «تثبيت».
6. **احذف `install.php`** من `public_html` فوراً (اللوحة تحذّرك ما دام موجوداً).
7. ادخل `https://دومينك/admin/` ← وحدة **المشاريع** ← «مزامنة الآن» لجلب مشاريع GitHub.
8. أضف نفسك في وحدة **المحتوى**: عدّل «الخبرات والعمل» (مطعم حنيذ: المسمّى الوظيفي وتاريخ البدء)، ثم **الإعدادات** لنصوص الصفحة الرئيسية والصورة الشخصية.

## مزامنة تلقائية (اختياري)
hPanel ← Advanced ← Cron Jobs ← مرة يومياً:
`php /home/USER/domains/DOMAIN/public_html/cron_sync.php`
(مسار الملف الدقيق يظهر في وحدة المشاريع.) وللزيادة من 60 إلى 5000 طلب/ساعة ضع GitHub token في `config.php` (`github_token`).

## عند ظهور خطأ
1. لوحة التحكم ← **فحص النظام والنسخ** ← «تقرير الفحص (txt)» وأرسله.
2. الأخطاء تُكتب في `data/php-error.log`. للعرض المؤقت على الشاشة: `'debug' => true` في `config.php` (أعده `false` بعدها).
3. صفحة بيضاء/500 بعد الرفع: تأكد أن `.htaccess` مرفوع (ملف مخفي) وأن إصدار PHP ≥ 8.1.

## التحقق الأمني بعد الرفع (كلها يجب أن تعطي 403)
`/config.php` · `/core/db.php` · `/data/php-error.log` · `/lang/ar.json` · `/admin/core/ui.php` · `/admin/modules/projects/module.php`
