# اللوحة الأم (Mother Panel) — مواصفة الربط بين الموقع والتطبيقات

هذه الوثيقة هي **العقد** بين موقع اللوحة الأم (هذا المستودع) وأي تطبيق يُدار منه (مثال: **Y- Dimensions**).
التطبيق مبني بـ **PHP خالص على Hostinger** (ليس WordPress)، ويعمل **أونلاين دائماً**: لا فتح بدون تسجيل دخول، ولا عمل بدون مزامنة.

## 1. القواعد الخمس غير القابلة للتفاوض
1. **تسجيل الدخول إلزامي قبل الفتح** (`auth_mandatory_before_open`).
2. **المزامنة إلزامية** بين الأجهزة عبر `app_id` (`cross_device_sync`).
3. **قاعدة بيانات واحدة موحّدة** ولا جداول مكرّرة لكل تطبيق: كل صف يحمل `app_id`، وكل استعلام مقيّد بـ `app_id`.
4. **كل الردود JSON فقط** من `/api/...` — لا HTML أبداً حتى في الأخطاء.
5. **ملف `MOTHER_PANEL_HANDOFF.md` إلزامي**: لا يُعدّ التثبيت مكتملاً قبل قراءته وتنفيذ بنوده وتسجيل نتيجة التحقق.

## 2. المصادقة على مستوى التطبيق (App Token)
- المفتاح يُولَّد من: **لوحة التحكم → لوحات الإدارة → 🎛 لوحة تحكم التطبيق → تبويب الإعدادات → توليد توكن API**.
- يُخزَّن كبصمة SHA-256 فقط (`boards.api_token_hash`) — اعرضه مرة واحدة واحفظه في متغير بيئة على الاستضافة.
- يُرسل في كل طلب: `X-App-Token: <token>` أو `Authorization: Bearer <token>`.

كل طلب ناجح يعيد: `{ "ok": true, "req_id": "...", "ts": 1690000000, ... }`
وكل خطأ: `{ "ok": false, "req_id": "...", "ts": ..., "error": { "code": "...", "message": "..." } }`
`req_id` يُسجَّل في `audit_log` و`sync_log` لتتبّع أي شكوى من المستخدمين.

## 3. نقاط النهاية — `/api/v1/...`

| النقطة | الطريقة | الحقول الأساسية | الوظيفة |
|---|---|---|---|
| `status` | GET | — | هوية التطبيق، الإصدارات، الإعدادات، الحزم، التطبيقات الشقيقة |
| `auth/register` | POST | `email`, `password` (8+), `display_name`, `device_uid`, `platform`, `device_name`, `app_version` | إنشاء حساب داخل نطاق `app_id` + جلسة |
| `auth/login` | POST | `email`, `password`, `device_uid`, … | تسجيل دخول + جلسة (`session_token`) |
| `auth/session` | GET | رأس `X-Session-Token` | التحقق من الجلسة وإرجاع بيانات المستخدم |
| `auth/logout` | POST | رأس `X-Session-Token` | إلغاء الجلسة الحالية |
| `sync` | POST | `action: push\|pull`, `sync_data` (كائن), `base_version`, `force` + رأس `X-Session-Token` | مزامنة البيانات بين الأجهزة |
| `releases/check` | POST | `version`, `version_code`, `sub_version`, `pack_version` | تحديث كامل (APK) + تحديثات فرعية + حزم جديدة |
| `content` | GET | `pack_key` (اختياري) | حزم المحتوى والإعدادات السحابية |
| `licenses/verify` / `licenses/activate` | POST | `code` (أو `license_key`)، و`holder` للتفعيل | التحقق من الترخيص وتفعيله |
| `telemetry` | POST | `event: ping\|download\|review\|metric`, `metric`, `val`, `rating`, `body` | عدّادات التنزيل، تقييمات، قياسات |
| `control` | GET/POST | `op: overview\|users\|user_status\|devices\|releases\|save_settings\|requirements` | لوحة تحكم داخل التطبيق، مقيّدة بـ `app_id` |

**تعارض المزامنة:** إذا أرسلت `base_version` أقدم من نسخة السحابة وبدون `force`، يعيد الخادم `409 sync_conflict` مع `server_version` و`server_data` — اعرض للمستخدم خيار الدمج أو الاستبدال.

**التحديث الذكي:** كل إصدار يحمل `release_type` = `full` (APK كامل) أو `sub` (بيانات فقط عند التشغيل)، مع `min_version` و`permissions_changed`. عند تغيّر الصلاحيات يجب إعادة طلبها داخل التطبيق.

## 4. الجداول المشتركة المسموح بها (كلها بنطاق `app_id`)

| الجدول | القيد | الغرض |
|---|---|---|
| `app_users` | `UNIQUE(app_id, email)` | حسابات التطبيق (نفس البريد مسموح في تطبيق آخر) |
| `app_sessions` | فهرس `(app_id, token_hash)` | جلسات الأجهزة (`expires`, `revoked`) |
| `devices` | `UNIQUE(app_id, device_uid)` | الأجهزة المسجّلة وحالتها |
| `releases` | `(app_id, release_type, is_active)` | إصدارات كاملة وفرعية |
| `content_packs` | `UNIQUE(app_id, pack_key)` | بيانات/إعدادات تُسحب عند التشغيل |
| `app_settings_kv` | `UNIQUE(app_id, cfg_key)` | إعدادات سحابية لكل تطبيق |
| `asset_sources` | — | مصادر الأصول (صوت، صورة، أيقونة، خط، فيديو، بيانات) |
| `app_requirements` | — | متطلبات كل تطبيق (ناقص/تم توفيره + مصدر + أولوية) |
| `sync_log` | `(app_id, ...)` | سجل المزامنة لكل طلب |

**ممنوع** إنشاء `users_<app>` أو أي جدول مكرّر لكل تطبيق. أي جدول جديد يجب أن يكون مشتركاً وبعمود `app_id`.

## 5. تبويبات لوحة تحكم التطبيق (داخل الموقع)
`admin/?m=boards&panel=<id>&tab=...`

`overview` · `users` · `sessions` · `devices` · `releases` · `content` · `licenses` · `requirements` · `handoff` · `settings`

تنفّذ كل العمليات على الجداول أعلاه مع تحقّق CSRF، وتُسجَّل في `audit_log`.

## 6. متطلبات التطبيقات (وحدة مستقلة)
`admin/?m=requirements`

- **① مصادر الأشياء**: صوتيات، صور، أيقونات، خطوط، فيديو، بيانات — مع الرابط والترخيص والملاحظات.
- **② متطلبات التطبيقات**: لكل تطبيق عدد النواقص، ترتيب «الناقص أولاً» ثم الأولوية (حرجة → عالية → متوسطة → منخفضة)، وزر واحد **✓ تم توفيره**.

## 7. ملف التسليم `MOTHER_PANEL_HANDOFF.md`
- يُوضع داخل مجلد اللوحة أو يُلصق نصياً في **تبويب التسليم** ثم يُضغط «قراءة وتنفيذ والتحقق الآن».
- يُستخرج تلقائياً من `panel.zip` إن وُجد.
- الأقسام الإلزامية الخمسة (تُكتشف بالمحتوى لا بالترقيم): **API**، **الجداول ونطاق `app_id`**، **الصلاحيات**، **المتطلبات**، **خطوات التحقق**.
- كتلة ` ```json ` واحدة على الأقل تُسجّل `requirements` و`settings` و`min_version` و`panel_tabs`.
- النتيجة تُحفظ في `boards.handoff_status` (`pending` → `verified` / `failed`) و`handoff_report`، وتظهر لافتة ⛔ في كل تبويبات اللوحة حتى تُحلّ.
- القالب الجاهز: [`docs/MOTHER_PANEL_HANDOFF.template.md`](MOTHER_PANEL_HANDOFF.template.md).

## 8. الزر العائم للعودة
- صفحات الموقع العامة: زر ثابت أسفل الشاشة → **⌂ الموقع الأساسي**.
- المواقع الخاصة/المقفلة (`/s/<slug>/`) وصفحات التطبيقات: زر → **🔒 العودة للوحة التحكم الأساسية** فقط.
- سياسة CSP للمواقع العامة تتضمّن `sandbox … allow-top-navigation-by-user-activation` حتى يعمل الزر داخل الإطار دون كسر العزل.

## 9. التحقق الآلي
```php
require ROOT . '/core/bootstrap.php';
$r = mother_verify_run();                 // فحوصات: بصمات الملفات، الجداول المكرّرة، نطاق app_id، JSON فقط، الأزرار العائمة، بوابة التسليم، وحدة المتطلبات
mother_verify_write_report($r);           // data/mother-verify-<timestamp>.md
```
تمرير الفحص شرط لنشر أي تعديل على طبقة الربط.
