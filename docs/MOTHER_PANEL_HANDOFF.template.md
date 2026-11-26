# MOTHER_PANEL_HANDOFF — قالب ملف التسليم

> انسخ هذا الملف باسم **`MOTHER_PANEL_HANDOFF.md`** إلى جذر مجلد اللوحة (أو الصقه في تبويب «ملف التسليم» داخل لوحة تحكم التطبيق)، واملأ كل الأقسام قبل اعتبار التثبيت مكتملاً.
> الأقسام الخمسة إلزامية: **API** · **الجداول ونطاق app_id** · **الصلاحيات** · **المتطلبات** · **خطوات التحقق**.
> لا تضع أي مفاتيح سرية هنا — القيم الحساسة تُطلب من المطوّر وتُخزَّن كمتغيرات بيئة.

# MOTHER_PANEL_HANDOFF — <اسم التطبيق>

## 1. API
<!-- كتلة JSON واحدة على الأقل: تُستخدم لتسجيل الإعدادات والمتطلبات والحد الأدنى للإصدار وتبويبات اللوحة -->
```json
{
  "min_version": "1.0.0",
  "panel_tabs": ["overview", "users", "sessions", "devices", "releases", "content", "requirements", "handoff", "settings"],
  "settings": {
    "auth_mandatory_before_open": "1",
    "cross_device_sync": "1",
    "startup_sub_updates": "1"
  },
  "requirements": [
    { "title": "نغمات تركيز mp3", "category": "audio", "priority": "high", "source": "Freesound", "source_url": "https://freesound.org", "status": "missing", "notes": "ترخيص CC0" },
    { "title": "أيقونة تكيفية 1024", "category": "icons", "priority": "critical", "source": "Iconoir", "status": "provided" }
  ]
}
```

- نقاط النهاية المستخدمة: `status` · `auth/register` · `auth/login` · `auth/session` · `auth/logout` · `sync` · `releases/check` · `content` · `licenses/verify` · `telemetry` · `control`
- الترويسات: `X-App-Token` (دائم) و`X-Session-Token` (بعد الدخول)
- كل الردود JSON، وتُقرأ الأخطاء من `error.code`

## 2. الجداول ونطاق app_id
<!-- لا جداول مكرّرة لكل تطبيق -->
| الجدول | يُستخدم؟ | ملاحظة النطاق |
|---|---|---|
| `app_users` | نعم | كل استعلام مقيّد بـ `app_id`، و`UNIQUE(app_id,email)` |
| `app_sessions` | نعم | إلغاء الجلسة عبر `revoked` |
| `devices` | نعم | `UNIQUE(app_id,device_uid)` |
| `releases` | نعم | `full` / `sub` + `min_version` |
| `content_packs` | نعم | `UNIQUE(app_id,pack_key)` |
| `app_settings_kv` | نعم | إعدادات سحابية فقط |
| `app_requirements` | نعم | النواقص والمصادر |

## 3. الصلاحيات
| الصلاحية | متى تُطلب | لماذا |
|---|---|---|
| `INTERNET` | دائماً | اللوحة الأم والمزامنة أونلاين |
| `POST_NOTIFICATIONS` | عند أول تشغيل | تنبيهات المزامنة والتحديث |
| `READ_MEDIA_IMAGES` | عند فتح قبو الصور | اختيار الصور |
| `READ_MEDIA_VIDEO` | عند فتح قبو الفيديو | اختيار الفيديو |
| `QUERY_ALL_PACKAGES` | عند تفعيل إخفاء التطبيقات | إخفاء/قفل التطبيقات |

> أي إصدار يغيّر الصلاحيات يجب أن يُنشر بـ `permissions_changed = 1` مع `permissions_note`، حتى يُعيد التطبيق طلبها.

## 4. المتطلبات الناقصة
<!-- شكل بديل مقبول إن لم تستخدم JSON: - [priority|category] العنوان | المصدر | ملاحظة -->
- [critical|audio] نغمات تركيز (mp3) | Freesound | ترخيص CC0
- [high|images] خلفيات AMOLED | Unsplash | مقاس 1080×2400
- [medium|fonts] خط Cairo للنصوص العربية | Google Fonts | وزن 400/700

## 5. خطوات التحقق
1. `GET /api/v1/status` يعيد `ok: true` مع `app_id` الصحيح.
2. `POST /api/v1/auth/register` ثم `auth/login` يعيدان `session_token`.
3. `POST /api/v1/sync` بـ `action=push` يزيد `sync_version`، و`pull` من جهاز آخر يعيد نفس البيانات.
4. `POST /api/v1/releases/check` يعيد `min_version` و`force_update` بشكل صحيح.
5. في لوحة التحكم: تبويب «ملف التسليم» يظهر ✓ واللافتة ⛔ تختفي، وتبويب «المتطلبات» يعرض البنود أعلاه.
6. تشغيل `mother_verify_run()` يعيد `passed: true`.
