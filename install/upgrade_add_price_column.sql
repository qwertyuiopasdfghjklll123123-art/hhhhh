-- شغّل هذا السطر مرة واحدة فقط إذا كنت قد نصّبت الموقع مسبقاً (قبل إضافة حقل السعر)
-- وتريد إضافة عمود السعر إلى جدول المنتجات دون إعادة التنصيب من جديد.
ALTER TABLE products ADD COLUMN price VARCHAR(50) NULL DEFAULT NULL AFTER color;
