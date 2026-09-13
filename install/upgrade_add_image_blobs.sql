-- شغّل هذا الملف مرة واحدة فقط إذا كنت قد نصّبت الموقع مسبقاً (قبل التحويل لتخزين الصور
-- داخل MySQL مباشرة) وتريد إضافة أعمدة بيانات الصور الجديدة دون إعادة التنصيب من جديد.
-- الأعمدة القديمة (image/logo/img/app_logo) تبقى كما هي وتُستخدم الآن كاسم تعريفي فقط.
ALTER TABLE categories ADD COLUMN image_data MEDIUMBLOB NULL DEFAULT NULL AFTER image;
ALTER TABLE categories ADD COLUMN image_mime VARCHAR(100) NULL DEFAULT NULL AFTER image_data;

ALTER TABLE companies ADD COLUMN logo_data MEDIUMBLOB NULL DEFAULT NULL AFTER logo;
ALTER TABLE companies ADD COLUMN logo_mime VARCHAR(100) NULL DEFAULT NULL AFTER logo_data;

ALTER TABLE products ADD COLUMN img_data MEDIUMBLOB NULL DEFAULT NULL AFTER img;
ALTER TABLE products ADD COLUMN img_mime VARCHAR(100) NULL DEFAULT NULL AFTER img_data;

ALTER TABLE services ADD COLUMN img_data MEDIUMBLOB NULL DEFAULT NULL AFTER img;
ALTER TABLE services ADD COLUMN img_mime VARCHAR(100) NULL DEFAULT NULL AFTER img_data;

ALTER TABLE settings ADD COLUMN app_logo_data MEDIUMBLOB NULL DEFAULT NULL AFTER app_logo;
ALTER TABLE settings ADD COLUMN app_logo_mime VARCHAR(100) NULL DEFAULT NULL AFTER app_logo_data;
