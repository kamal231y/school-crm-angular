# School Mini CRM

Core PHP (REST API) + AngularJS 1.8 school management system.

Login/logout, admission, student report, attendance, fees, report card, SMS.

---

## Requirements

| Thing | Version | Kyun |
|---|---|---|
| PHP | **8.0+** | typed properties, `match()`, constructor promotion |
| MySQL / MariaDB | 5.7+ / 10.3+ | |
| PHP extensions | `pdo_mysql`, `curl` | curl sirf real SMS gateway ke liye |
| Apache | mod_rewrite on | pretty URLs (`/api/students`) |

mbstring optional hai — na ho to code `strlen` par fallback kar leta hai.

---

## Setup (5 minute)

**1. Database banayein**

```bash
mysql -u root -p < database/schema.sql
```

Isse `school_crm` database, saari tables aur seed data (3 users, 3 classes,
10 subjects, 3 students, fee heads, 5 SMS templates) ban jaata hai.

**2. DB credentials set karein**

`api/config/config.php` kholein aur `db` block me apne details daalein —
ya environment variables use karein (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).

**3. Files ko web root me rakhein**

```
htdocs/school-crm/
├── api/     <- backend
├── app/     <- frontend
└── database/
```

**4. Browser me kholein**

```
http://localhost/school-crm/app/index.html
```

### Demo logins

| Email | Password | Role | Kya kar sakta hai |
|---|---|---|---|
| admin@school.test | admin123 | admin | sab kuch |
| teacher@school.test | admin123 | teacher | admission, attendance, marks |
| accounts@school.test | admin123 | accountant | fees, invoices, SMS |

> **Production me pehla kaam:** ye teeno passwords badlein (app ke andar hi
> change-password endpoint hai), aur `api/config/config.php` me
> `cors_origin` ko `*` se apne domain par set karein.

---

## SMS setup

Default driver `log` hai — koi real SMS nahi jaata, sab kuch
`api/storage/sms.log` me likha jaata hai. Development ke liye yahi rakhein.

Live karne ke liye `api/config/config.php` me driver badlein:

```php
'sms' => [
    'driver'    => 'msg91',      // log | msg91 | fast2sms | twilio
    'sender_id' => 'SUNRSE',
    'msg91'     => ['auth_key' => 'YOUR_KEY', ...],
],
```

India ke liye MSG91 ya Fast2SMS theek rahenge. Dhyaan rahe — TRAI ke DLT
rules ke hisaab se sender ID aur har template pehle se registered hona
chahiye, warna messages block ho jaayenge.

### Automatic SMS kab jaata hai

| Template | Trigger |
|---|---|
| `admission` | naya admission save hone par |
| `absent` | attendance me absent mark karne par (checkbox on ho to) |
| `fee_receipt` | payment record karne par (checkbox on ho to) |
| `result` | exam publish karne par |
| `fee_due` | manual — SMS screen se "overdue guardians" chunkar |

Template body admin SMS → Templates tab se edit ho sakti hai.
Placeholders: `{name}` `{father}` `{class}` `{amount}` `{date}` `{school}` etc.

---

## API endpoints

Base: `/api/index.php/<route>` (mod_rewrite on ho to sirf `/api/<route>`).
Login ke alawa har request me header chahiye: `Authorization: Bearer <token>`.

```
POST   auth/login                 email, password -> token
POST   auth/logout
GET    auth/me
POST   auth/change-password

GET    dashboard                  counts, today's attendance, collection trend

GET    students                   ?search= &class_id= &status= &page=
POST   students                   naya admission (+ SMS + auto invoices)
GET    students/{id}              profile + fees + attendance + exams + sms
PUT    students/{id}
DELETE students/{id}              soft delete (status = left)

GET    attendance/sheet           ?class_id= &date=
POST   attendance                 rows[] save (+ absent SMS)
GET    attendance/monthly         ?class_id= &month=YYYY-MM

GET    fees/invoices              ?student_id= &status= &class_id=
POST   fees/invoices
POST   fees/payments              payment + receipt no (+ SMS)
GET    fees/receipt/{id}
GET    fees/defaulters

GET    exams                      ?class_id=
POST   exams
GET    exams/{id}/marks           marks entry grid
POST   exams/{id}/marks
POST   exams/{id}/publish         result SMS sabko
GET    report-card/{studentId}    ?exam_id=

GET    sms/logs                   GET sms/templates    PUT sms/templates/{id}
POST   sms/send                   audience: class | selected | defaulters

GET    reports/students           ?class_id= &gender= &from= &to=
GET    reports/attendance         ?class_id= &from= &to=
GET    reports/fees               ?from= &to=

GET    classes      POST classes
GET    subjects     POST subjects
GET    fee-heads    POST fee-heads
GET    users        GET  settings
```

Response shape hamesha ek jaisa hai:

```json
{ "success": true,  "message": "OK", "data": { } }
{ "success": false, "message": "Please correct the highlighted fields",
  "errors": { "guardian_phone": "Guardian Phone must be a valid mobile number" } }
```

---

## Project structure

```
api/
├── index.php              front controller — routes yahin defined hain
├── .htaccess              pretty URLs + Authorization header pass-through
├── config/
│   ├── config.php         DB, CORS, token TTL, SMS gateway
│   └── database.php       PDO singleton + all/one/run/insert/update helpers
├── core/
│   ├── Config.php         config + DB settings table
│   ├── Request.php        JSON body, query, bearer token
│   ├── Response.php       JSON responses (ok / error / paginated)
│   ├── Validator.php      rule-based validation
│   ├── Auth.php           token issue/verify/revoke, role guards, activity log
│   └── Router.php         {id} placeholders ke saath minimal router
├── lib/Sms.php            gateway wrapper + 4 drivers, har message log hota hai
├── controllers/           Auth, Student, Attendance, Fee, Exam, Sms, Report, Master
└── storage/               sms.log yahan banti hai (browser se blocked)

app/
├── index.html             shell + sidebar + ng-view
├── css/app.css            poora design system
├── js/
│   ├── app.js             routes, HTTP interceptor, rupee filter
│   ├── services/          api, auth, toast
│   └── controllers/       ek file per module
└── views/                 15 templates

database/schema.sql        14 tables + seed data
```

---

## Security notes

Jo ho chuka hai:

- Passwords `password_hash()` (bcrypt) se — plain text kahin nahi
- **Saari** queries prepared statements se — SQL injection se bacha hua
- Token expiry DB me, logout par token delete, purane token auto-clean
- Role guards har write endpoint par (`Auth::can('admin','accountant')`)
- `activity_log` table me login, admission, payment, marks — sab record
- `storage/` aur `.sql`/`.log` files `.htaccess` se browser ke liye blocked

Live karne se pehle jo karna hai:

- HTTPS lagaayein — token plain HTTP par jaayega to intercept ho sakta hai
- `config.php` me `cors_origin` apne domain par set karein
- `api/index.php` me `display_errors` `'0'` hi rakhein (already hai)
- DB user ko sirf `school_crm` par grant dein, root use na karein
- Seed passwords badlein

---

## Testing status

Ye code MariaDB par end-to-end chalakar dekha gaya hai —
login → admission → attendance → fee payment → marks entry → report card →
result publish, aur saare 22 GET endpoints MySQL 8 ke default
`ONLY_FULL_GROUP_BY` sql_mode me PASS hue.

Testing ke dauraan 3 bug mile aur fix kiye gaye:

1. **`mb_strlen()` crash** — mbstring har shared host par nahi hoti, isse login
   hi fail ho raha tha. Ab `strlen` fallback hai.
2. **Report card FAIL bole, SMS PASS bheje** — publish endpoint sirf overall
   percentage dekh raha tha jabki report card subject-wise pass marks.
   Ab dono ek hi rule par hain.
3. **6 GROUP BY queries MySQL 8 par tootti thi** — dashboard, defaulters,
   attendance report `ONLY_FULL_GROUP_BY` me error de rahe the.
   Sab fix karke strict mode me dobara verify kiya gaya.

---

## Aage kya add kar sakte hain

- Student photo upload (`uploads_dir` config me already hai)
- Bulk admission Excel/CSV import
- Fee invoice auto-generation har mahine (cron job)
- Timetable aur teacher attendance
- Parent login portal — apne bachche ka attendance/result dekhne ke liye
- Report card PDF download (abhi browser print use hota hai)
