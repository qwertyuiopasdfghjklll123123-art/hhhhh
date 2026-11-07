#!/usr/bin/env python3
"""يحوّل نسخة fastcrand القديمة (SQLite + ملفات JSON) إلى ملف JSON واحد بصيغة fastcrand-db."""
import sqlite3, json, sys, os, datetime, base64
TABLES = ['users','pending_email_changes','orders','payment_methods','tickets','ticket_messages','recharge_requests','asiacell_flows','ai_conversations','ai_messages','notifications','referrals','referral_history','announcements','custom_services','custom_orders','favorites','service_filters','site_settings','coupons','coupon_uses','reviews','providers','levels','master_key_log']
FILES = ['fc_vapid.json','fc_push_subs.json','fc_services_cache.json']
src, out = sys.argv[1], sys.argv[2]
db = sqlite3.connect(os.path.join(src, 'fastcrand.db')); db.text_factory = lambda b: b.decode('utf-8', 'replace')
have = [r[0] for r in db.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'")]
seq = {}
try: seq = dict(db.execute('select name, seq from sqlite_sequence').fetchall())
except Exception: pass
doc = {'format':'fastcrand-db','version':1,'origin':'fastcrand-python-sqlite','created':datetime.datetime.now().astimezone().isoformat(timespec='seconds'),'tables':{},'files':{}}
skipped=[]
for t in have:
    if t not in TABLES: skipped.append(t); continue
    cur = db.execute(f'select * from "{t}" order by rowid')
    cols = [d[0] for d in cur.description]
    rows = []
    for r in cur:
        row=[]
        for v in r:
            if isinstance(v, (bytes, bytearray)): v = base64.b64encode(v).decode()
            row.append(v)
        rows.append(row)
    doc['tables'][t] = {'columns': cols, 'autoinc': int(seq.get(t, 0)), 'rows': rows}
for f in FILES:
    p = os.path.join(src, f)
    if os.path.isfile(p):
        with open(p, encoding='utf-8') as fh: doc['files'][f] = json.load(fh)
with open(out, 'w', encoding='utf-8') as fh:
    fh.write('{"format":"fastcrand-db","version":1,')
    fh.write('"origin":%s,"created":%s,"tables":{\n' % (json.dumps(doc['origin']), json.dumps(doc['created'])))
    first=True
    for t,d in doc['tables'].items():
        fh.write(('' if first else ',\n') + json.dumps(t) + ':{"columns":' + json.dumps(d['columns']) + ',"autoinc":%d,"rows":[\n' % d['autoinc'])
        fh.write(',\n'.join(json.dumps(r, ensure_ascii=False, separators=(',',':')) for r in d['rows']))
        fh.write(']}'); first=False
    fh.write('\n},"files":' + json.dumps(doc['files'], ensure_ascii=False, separators=(',',':')) + '}')
print('tables:', {t: len(d['rows']) for t,d in doc['tables'].items()})
print('skipped tables:', skipped); print('files:', list(doc['files']))
print('size', os.path.getsize(out))
