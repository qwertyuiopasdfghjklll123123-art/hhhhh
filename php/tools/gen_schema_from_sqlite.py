#!/usr/bin/env python3
"""
يولّد قسم الجداول (MySQL) الموجود في php/sql/database.sql من قاعدة SQLite التي ينشئها init_db() الأصلي في app.py.

الاستعمال:
    python3 gen_schema_from_sqlite.py /path/to/fastcrand.db > /tmp/schema_part.sql   # ثم ادمجه يدوياً في sql/database.sql

قواعد التحويل (لتطابق سلوك SQLite بأقصى ما يمكن):
  INTEGER -> INT (أو BIGINT للكميات)     REAL -> DOUBLE  (وليس DECIMAL حتى يرجع PDO أرقاماً لا نصوصاً)
  TEXT    -> VARCHAR(n) للحقول القصيرة المحدّدة، و LONGTEXT لما عداها (الإيصالات base64 ...)
  created_at ... DEFAULT (strftime('%s','now')) -> DEFAULT (UNIX_TIMESTAMP())
  التجميع utf8mb4_bin = مقارنة حسّاسة لحالة الأحرف مثل BINARY في SQLite.
  الفهرس الجزئي UNIQUE(ext_ref) WHERE ext_ref!='' -> عمود مُولَّد مخفي __fc_ext_uq (NULL عندما ext_ref='')
  مع UNIQUE عليه، فتبقى قيمة ext_ref الافتراضية '' كما كانت تماماً.
"""
import re
import sqlite3
import sys

# ---- أنواع MySQL الصريحة لكل أعمدة TEXT (أي TEXT غير مذكور هنا يصبح LONGTEXT مع تحذير) ----
TEXT_TYPES = {
    'ai_conversations.title': 'VARCHAR(500)',
    'ai_messages.role': 'VARCHAR(32)',
    'ai_messages.content': 'LONGTEXT',
    'announcements.id': 'VARCHAR(64)',
    'announcements.text': 'LONGTEXT',
    'announcements.type': 'VARCHAR(32)',
    'asiacell_flows.data': 'LONGTEXT',
    'coupon_uses.coupon_id': 'VARCHAR(64)',
    'coupons.id': 'VARCHAR(64)',
    'coupons.code': 'VARCHAR(190)',
    'coupons.expires_at': 'VARCHAR(64)',
    'custom_orders.id': 'VARCHAR(64)',
    'custom_orders.service_id': 'VARCHAR(64)',
    'custom_orders.service_name': 'VARCHAR(512)',
    'custom_orders.type': 'VARCHAR(32)',
    'custom_orders.package': 'LONGTEXT',
    'custom_orders.link': 'LONGTEXT',
    'custom_orders.comments': 'LONGTEXT',
    'custom_orders.status': 'VARCHAR(32)',
    'custom_services.id': 'VARCHAR(64)',
    'custom_services.name': 'VARCHAR(512)',
    'custom_services.type': 'VARCHAR(32)',
    'custom_services.desc_text': 'LONGTEXT',
    'custom_services.icon': 'VARCHAR(255)',
    'custom_services.color': 'VARCHAR(64)',
    'custom_services.image': 'LONGTEXT',
    'custom_services.packages': 'LONGTEXT',
    'favorites.service_id': 'VARCHAR(190)',
    'favorites.service_name': 'VARCHAR(512)',
    'favorites.platform': 'VARCHAR(64)',
    'favorites.category': 'VARCHAR(512)',
    'favorites.rate': 'VARCHAR(64)',
    'levels.name': 'VARCHAR(255)',
    'levels.name_en': 'VARCHAR(255)',
    'levels.color': 'VARCHAR(64)',
    'levels.icon': 'VARCHAR(255)',
    'levels.benefits': 'LONGTEXT',
    'master_key_log.endpoint': 'VARCHAR(512)',
    'master_key_log.method': 'VARCHAR(16)',
    'master_key_log.ip': 'VARCHAR(64)',
    'notifications.id': 'VARCHAR(64)',
    'notifications.type': 'VARCHAR(32)',
    'notifications.title': 'VARCHAR(512)',
    'notifications.text': 'LONGTEXT',
    'notifications.icon': 'VARCHAR(64)',
    'notifications.goto': 'VARCHAR(512)',
    'orders.order_id': 'VARCHAR(190)',
    'orders.service': 'VARCHAR(190)',
    'orders.service_name': 'VARCHAR(1024)',
    'orders.link': 'LONGTEXT',
    'orders.charge': 'VARCHAR(64)',
    'orders.status': 'VARCHAR(64)',
    'orders.comments': 'LONGTEXT',
    'orders.refill_days': 'VARCHAR(255)',
    'orders.display_id': 'VARCHAR(64)',
    'payment_methods.name': 'VARCHAR(255)',
    'payment_methods.icon': 'VARCHAR(255)',
    'payment_methods.number': 'VARCHAR(512)',
    'payment_methods.exchange_rate': 'VARCHAR(64)',
    'payment_methods.currency': 'VARCHAR(32)',
    'payment_methods.note': 'LONGTEXT',
    'payment_methods.bot_link': 'VARCHAR(1024)',
    'payment_methods.method_type': 'VARCHAR(32)',
    'payment_methods.method_extras': 'LONGTEXT',
    'payment_methods.image': 'LONGTEXT',
    'pending_email_changes.new_email': 'VARCHAR(255)',
    'pending_email_changes.code': 'VARCHAR(32)',
    'providers.name': 'VARCHAR(255)',
    'providers.api_url': 'VARCHAR(1024)',
    'providers.api_key': 'VARCHAR(512)',
    'recharge_requests.method': 'VARCHAR(255)',
    'recharge_requests.status': 'VARCHAR(32)',
    'recharge_requests.receipt': 'LONGTEXT',
    'recharge_requests.ext_ref': 'VARCHAR(255)',
    'referral_history.from_order': 'VARCHAR(190)',
    'referrals.code': 'VARCHAR(64)',
    'reviews.user_name': 'VARCHAR(255)',
    'reviews.text': 'LONGTEXT',
    'service_filters.filter_type': 'VARCHAR(64)',
    'service_filters.filter_key': 'VARCHAR(500)',
    'service_filters.platform': 'VARCHAR(64)',
    'service_filters.custom_name': 'VARCHAR(1024)',
    'service_filters.custom_category': 'VARCHAR(1024)',
    'site_settings.key': 'VARCHAR(190)',
    'site_settings.value': 'LONGTEXT',
    'ticket_messages.sender': 'VARCHAR(32)',
    'ticket_messages.msg_type': 'VARCHAR(32)',
    'ticket_messages.text': 'LONGTEXT',
    'ticket_messages.voice_url': 'VARCHAR(1024)',
    'tickets.subject': 'VARCHAR(1024)',
    'tickets.category': 'VARCHAR(255)',
    'tickets.status': 'VARCHAR(32)',
    'users.name': 'VARCHAR(255)',
    'users.email': 'VARCHAR(255)',
    'users.password': 'VARCHAR(255)',
    'users.verify_token': 'VARCHAR(255)',
    'users.google_id': 'VARCHAR(255)',
    'users.avatar': 'LONGTEXT',
    'users.username': 'VARCHAR(255)',
    'users.telegram_chat_id': 'VARCHAR(64)',
    'users.api_key': 'VARCHAR(255)',
    'users.public_id': 'VARCHAR(64)',
}
# أعمدة INTEGER التي قد تحمل أرقاماً كبيرة -> BIGINT
BIGINT_COLS = {'orders.quantity', 'custom_orders.quantity', 'custom_services.min_qty', 'custom_services.max_qty',
               'favorites.min_qty', 'favorites.max_qty'}

# فهارس إضافية للأداء فقط (لا تغيّر السلوك) + الفهارس الأصلية
EXTRA_INDEXES = {
    'users': ['KEY `idx_users_api_key` (`api_key`)', 'KEY `idx_users_public_id` (`public_id`)',
              'KEY `idx_users_google_id` (`google_id`)', 'KEY `idx_users_verify_token` (`verify_token`)',
              'KEY `idx_users_username` (`username`)'],
    'orders': ['KEY `idx_orders_user` (`user_id`, `created_at` DESC)', 'KEY `idx_orders_status` (`status`)',
               'KEY `idx_orders_display_id` (`display_id`)', 'KEY `idx_orders_order_id` (`order_id`)'],
    'notifications': ['KEY `idx_notif_user` (`user_id`, `created_at`)'],
    'ticket_messages': ['KEY `idx_tm_ticket` (`ticket_id`, `id`)'],
    'tickets': ['KEY `idx_tk_user` (`user_id`)'],
    'recharge_requests': ['UNIQUE KEY `idx_recharge_ext_ref` (`__fc_ext_uq`)', 'KEY `idx_rr_user` (`user_id`)',
                          'KEY `idx_rr_status` (`status`)'],
    'custom_orders': ['KEY `idx_co_user` (`user_id`)'],
    'referral_history': ['KEY `idx_rh_ref` (`referrer_id`)'],
    'ai_conversations': ['KEY `idx_ai_conv_user` (`user_id`, `updated_at` DESC)'],
    'ai_messages': ['KEY `idx_ai_msg_conv` (`conversation_id`, `id`)'],
    'favorites': ['UNIQUE KEY `idx_fav_user_svc` (`user_id`, `service_id`)'],
}
EXTRA_COLUMNS = {
    'recharge_requests': ["`__fc_ext_uq` VARCHAR(255) GENERATED ALWAYS AS (NULLIF(`ext_ref`, '')) STORED"],
}
# جداول مفتاحها النصي: SQLite يحتفظ بترتيب الإدخال (rowid) عند تساوي قيم الفرز، فنضيف عموداً مخفياً
# (يبدأ بـ __fc_ فيُخفى من الصفوف) لنستعمله كمعيار ثانوي ASC بالاستعلامات: ORDER BY created_at DESC, __fc_seq ASC
SEQ_TABLES = ['notifications', 'announcements', 'custom_services', 'custom_orders', 'coupons']
for _t in SEQ_TABLES:
    EXTRA_COLUMNS.setdefault(_t, []).append('`__fc_seq` BIGINT NOT NULL AUTO_INCREMENT')
    EXTRA_INDEXES.setdefault(_t, []).append('UNIQUE KEY `uq_fc_seq` (`__fc_seq`)')
# ترتيب الإنشاء = ترتيب ظهور الجداول في init_db الأصلي
ORDER = ['users', 'pending_email_changes', 'orders', 'payment_methods', 'tickets', 'ticket_messages',
         'recharge_requests', 'asiacell_flows', 'ai_conversations', 'ai_messages', 'notifications', 'referrals',
         'referral_history', 'announcements', 'custom_services', 'custom_orders', 'favorites', 'service_filters',
         'site_settings', 'coupons', 'coupon_uses', 'reviews', 'providers', 'levels', 'master_key_log']


def q(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "''") + "'"


def conv_default(raw, mysql_type):
    """raw = نص الافتراضي كما في SQLite (أو None)"""
    if raw is None:
        return None
    raw = raw.strip()
    if raw.upper() == 'NULL':
        return 'NULL'
    if re.fullmatch(r"\(?\s*strftime\('%s'\s*,\s*'now'\)\s*\)?", raw):
        return '(UNIX_TIMESTAMP())'
    m = re.fullmatch(r"'(.*)'", raw, re.S) or re.fullmatch(r'"(.*)"', raw, re.S)
    if m:
        val = m.group(1).replace("''", "'")
        lit = q(val)
        # TEXT/BLOB في MySQL لا يقبل افتراضياً حرفياً: نلفّه بقوسين (تعبير)
        return '(' + lit + ')' if mysql_type.endswith('TEXT') else lit
    if re.fullmatch(r'-?\d+(\.\d+)?', raw):
        return raw
    raise SystemExit('default غير معروف: ' + raw)


def main(db):
    c = sqlite3.connect(db)
    tables = [r[0] for r in c.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'")]
    assert set(tables) == set(ORDER), (set(tables) ^ set(ORDER))
    uniq_cols = {}  # (table, col) -> True للأعمدة UNIQUE المعلنة بالعمود
    fallbacks = []
    out = ['-- ملف مولَّد تلقائياً بواسطة tools/gen_schema_from_sqlite.py من مخطط app.py الأصلي (init_db).',
           '-- (قسم الجداول من sql/database.sql — الملف الذي يُستورد من phpMyAdmin).',
           '-- المتطلبات: MySQL >= 8.0.13  أو  MariaDB >= 10.2.1',
           '']
    for t in ORDER:
        cols = list(c.execute(f'PRAGMA table_info("{t}")'))
        sql_raw = c.execute("select sql from sqlite_master where name=?", (t,)).fetchone()[0]
        pk_cols = [r[1] for r in cols if r[5]]
        auto = 'AUTOINCREMENT' in sql_raw.upper()
        lines = []
        # قيود UNIQUE
        unique_inline = set(re.findall(r'(\w+)\s+\w+[^,\n]*\bUNIQUE\b(?!\s*\()', sql_raw))
        table_uniques = [tuple(x.strip() for x in m.split(',')) for m in re.findall(r'UNIQUE\s*\(([^)]*)\)', sql_raw)]
        types = {}
        for cid, name, typ, notnull, dflt, pk in cols:
            key = f'{t}.{name}'
            typ = (typ or '').upper()
            if typ == 'INTEGER':
                mt = 'BIGINT' if key in BIGINT_COLS else 'INT'
            elif typ == 'REAL':
                mt = 'DOUBLE'
            elif typ == 'TEXT':
                mt = TEXT_TYPES.get(key)
                if mt is None:
                    mt = 'LONGTEXT'
                    fallbacks.append(key)
            else:
                raise SystemExit('نوع غير معروف ' + typ + ' في ' + key)
            types[name] = mt
            parts = [f'`{name}`', mt]
            if pk and auto and name == 'id':
                parts += ['NOT NULL', 'AUTO_INCREMENT']
            else:
                if notnull or (pk and len(pk_cols) == 1):
                    parts.append('NOT NULL')
                d = conv_default(dflt, mt)
                if d is not None:
                    parts.append('DEFAULT ' + d)
                elif not notnull and not pk:
                    parts.append('NULL')
            lines.append('  ' + ' '.join(parts))
        for extra in EXTRA_COLUMNS.get(t, []):
            lines.append('  ' + extra)
        if pk_cols:
            lines.append('  PRIMARY KEY (' + ', '.join(f'`{x}`' for x in pk_cols) + ')')
        for name in sorted(unique_inline):
            if name not in pk_cols:
                lines.append(f'  UNIQUE KEY `uq_{t}_{name}` (`{name}`)')
        for tu in table_uniques:
            lines.append(f'  UNIQUE KEY `uq_{t}_' + '_'.join(tu) + '` (' + ', '.join(f'`{x}`' for x in tu) + ')')
        for ix in EXTRA_INDEXES.get(t, []):
            lines.append('  ' + ix)
        out.append(f'CREATE TABLE IF NOT EXISTS `{t}` (')
        out.append(',\n'.join(lines))
        out.append(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;')
        out.append('')
    sys.stdout.write('\n'.join(out))
    if fallbacks:
        sys.stderr.write('تحذير: أعمدة TEXT بلا نوع صريح (صارت LONGTEXT): ' + ', '.join(fallbacks) + '\n')


if __name__ == '__main__':
    main(sys.argv[1])
