-- ============================================================
-- School Mini CRM - MySQL schema
-- Run:  mysql -u root -p < database/schema.sql
-- ============================================================

DROP DATABASE IF EXISTS school_crmkk;
CREATE DATABASE school_crmkk DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE school_crmkk;

-- ---------- Users & auth ----------
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(120) NOT NULL UNIQUE,
  phone         VARCHAR(20)  DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','teacher','accountant') NOT NULL DEFAULT 'teacher',
  status        TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE auth_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  token      CHAR(64) NOT NULL UNIQUE,
  user_agent VARCHAR(255) DEFAULT NULL,
  ip         VARCHAR(45) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_token_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE activity_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT DEFAULT NULL,
  action     VARCHAR(60) NOT NULL,
  detail     VARCHAR(255) DEFAULT NULL,
  ip         VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- Academic setup ----------
CREATE TABLE classes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(40) NOT NULL,          -- Class 6
  section    VARCHAR(10) NOT NULL DEFAULT 'A',
  session    VARCHAR(12) NOT NULL,          -- 2026-27
  teacher_id INT DEFAULT NULL,
  UNIQUE KEY uq_class (name, section, session),
  CONSTRAINT fk_class_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE subjects (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  class_id   INT NOT NULL,
  name       VARCHAR(60) NOT NULL,
  code       VARCHAR(20) DEFAULT NULL,
  max_marks  INT NOT NULL DEFAULT 100,
  pass_marks INT NOT NULL DEFAULT 33,
  CONSTRAINT fk_subject_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Admission / students ----------
CREATE TABLE students (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  admission_no   VARCHAR(25) NOT NULL UNIQUE,
  roll_no        VARCHAR(15) DEFAULT NULL,
  first_name     VARCHAR(60) NOT NULL,
  last_name      VARCHAR(60) DEFAULT NULL,
  gender         ENUM('male','female','other') NOT NULL DEFAULT 'male',
  dob            DATE DEFAULT NULL,
  class_id       INT DEFAULT NULL,
  father_name    VARCHAR(100) DEFAULT NULL,
  mother_name    VARCHAR(100) DEFAULT NULL,
  guardian_phone VARCHAR(20) NOT NULL,
  email          VARCHAR(120) DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  category       VARCHAR(30) DEFAULT NULL,
  admission_date DATE NOT NULL,
  status         ENUM('active','left','alumni') NOT NULL DEFAULT 'active',
  photo          VARCHAR(255) DEFAULT NULL,
  created_by     INT DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
  INDEX idx_student_class (class_id),
  INDEX idx_student_name (first_name, last_name)
) ENGINE=InnoDB;

-- ---------- Attendance ----------
CREATE TABLE attendance (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  student_id  INT NOT NULL,
  class_id    INT NOT NULL,
  att_date    DATE NOT NULL,
  status      ENUM('present','absent','late','leave','holiday') NOT NULL DEFAULT 'present',
  remarks     VARCHAR(160) DEFAULT NULL,
  marked_by   INT DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (student_id, att_date),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  INDEX idx_att_date (att_date, class_id)
) ENGINE=InnoDB;

-- ---------- Fees ----------
CREATE TABLE fee_heads (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  class_id  INT DEFAULT NULL,             -- NULL = applies to all classes
  title     VARCHAR(80) NOT NULL,         -- Tuition Fee, Transport, Exam Fee
  amount    DECIMAL(10,2) NOT NULL,
  frequency ENUM('monthly','quarterly','yearly','one_time') NOT NULL DEFAULT 'monthly',
  CONSTRAINT fk_head_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE fee_invoices (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no  VARCHAR(25) NOT NULL UNIQUE,
  student_id  INT NOT NULL,
  fee_head_id INT DEFAULT NULL,
  title       VARCHAR(100) NOT NULL,
  period      VARCHAR(20) DEFAULT NULL,    -- Jul-2026 / Q1
  amount      DECIMAL(10,2) NOT NULL,
  discount    DECIMAL(10,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  due_date    DATE NOT NULL,
  status      ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  created_by  INT DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  INDEX idx_inv_status (status, due_date)
) ENGINE=InnoDB;

CREATE TABLE fee_payments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  receipt_no  VARCHAR(25) NOT NULL UNIQUE,
  invoice_id  INT NOT NULL,
  student_id  INT NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  mode        ENUM('cash','upi','cheque','card','netbanking') NOT NULL DEFAULT 'cash',
  txn_ref     VARCHAR(60) DEFAULT NULL,
  paid_on     DATE NOT NULL,
  note        VARCHAR(160) DEFAULT NULL,
  received_by INT DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
  INDEX idx_pay_date (paid_on)
) ENGINE=InnoDB;

-- ---------- Exams & report card ----------
CREATE TABLE exams (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80) NOT NULL,          -- Half Yearly
  session   VARCHAR(12) NOT NULL,
  class_id  INT NOT NULL,
  exam_date DATE DEFAULT NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_exam_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE marks (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  exam_id         INT NOT NULL,
  student_id      INT NOT NULL,
  subject_id      INT NOT NULL,
  marks_obtained  DECIMAL(6,2) NOT NULL DEFAULT 0,
  remarks         VARCHAR(120) DEFAULT NULL,
  entered_by      INT DEFAULT NULL,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mark (exam_id, student_id, subject_id),
  CONSTRAINT fk_mark_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_mark_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_mark_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- SMS ----------
CREATE TABLE sms_templates (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(40) NOT NULL UNIQUE,   -- admission, absent, fee_receipt, result
  title     VARCHAR(80) NOT NULL,
  body      TEXT NOT NULL,                 -- supports {name} {class} {amount} ...
  active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE sms_logs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT DEFAULT NULL,
  mobile     VARCHAR(20) NOT NULL,
  template   VARCHAR(40) DEFAULT NULL,
  message    TEXT NOT NULL,
  status     ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  response   VARCHAR(255) DEFAULT NULL,
  sent_by    INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sms_date (created_at)
) ENGINE=InnoDB;

CREATE TABLE settings (
  skey  VARCHAR(60) PRIMARY KEY,
  svalue TEXT
) ENGINE=InnoDB;

-- ============================================================
-- Seed data
-- password for all seed users = admin123
-- ============================================================
INSERT INTO users (name, email, phone, password_hash, role) VALUES
('Principal Sharma','admin@school.test','9810000001','$2y$10$4UXxQUK.hNXTJgPIHis8iulJ3F06hRmFLGrU3A9q2oWx7W131yFjO','admin'),
('Anita Verma','teacher@school.test','9810000002','$2y$10$4UXxQUK.hNXTJgPIHis8iulJ3F06hRmFLGrU3A9q2oWx7W131yFjO','teacher'),
('Ramesh Gupta','accounts@school.test','9810000003','$2y$10$4UXxQUK.hNXTJgPIHis8iulJ3F06hRmFLGrU3A9q2oWx7W131yFjO','accountant');

INSERT INTO classes (name, section, session, teacher_id) VALUES
('Class 6','A','2026-27',2),
('Class 7','A','2026-27',2),
('Class 8','B','2026-27',2);

INSERT INTO subjects (class_id, name, code, max_marks, pass_marks) VALUES
(1,'Hindi','HIN',100,33),(1,'English','ENG',100,33),(1,'Mathematics','MAT',100,33),
(1,'Science','SCI',100,33),(1,'Social Science','SST',100,33),
(2,'Hindi','HIN',100,33),(2,'English','ENG',100,33),(2,'Mathematics','MAT',100,33),
(2,'Science','SCI',100,33),(2,'Social Science','SST',100,33);

INSERT INTO students (admission_no, roll_no, first_name, last_name, gender, dob, class_id, father_name, mother_name, guardian_phone, address, admission_date, created_by) VALUES
('ADM2026001','01','Aarav','Singh','male','2014-04-12',1,'Rajeev Singh','Neha Singh','9810011111','Kavi Nagar, Ghaziabad','2026-04-01',1),
('ADM2026002','02','Diya','Sharma','female','2014-08-03',1,'Manoj Sharma','Sunita Sharma','9810022222','Raj Nagar, Ghaziabad','2026-04-01',1),
('ADM2026003','03','Kabir','Khan','male','2013-11-20',2,'Imran Khan','Farah Khan','9810033333','Vasundhara, Ghaziabad','2026-04-02',1);

INSERT INTO fee_heads (class_id, title, amount, frequency) VALUES
(1,'Tuition Fee',1500.00,'monthly'),
(2,'Tuition Fee',1800.00,'monthly'),
(NULL,'Transport Fee',900.00,'monthly'),
(NULL,'Exam Fee',600.00,'one_time');

INSERT INTO exams (name, session, class_id, exam_date, published) VALUES
('Half Yearly','2026-27',1,'2026-09-15',0),
('Half Yearly','2026-27',2,'2026-09-15',0);

INSERT INTO sms_templates (code, title, body) VALUES
('admission','Admission confirmed','Dear {father}, admission of {name} is confirmed in {class}. Admission No: {admission_no}. - {school}'),
('absent','Absent alert','Dear {father}, your ward {name} of {class} was marked ABSENT on {date}. - {school}'),
('fee_receipt','Fee receipt','Received Rs {amount} against {title} for {name}. Receipt No: {receipt_no}. Thank you. - {school}'),
('fee_due','Fee reminder','Dear {father}, fee of Rs {amount} for {name} ({title}) is due on {due_date}. Please pay on time. - {school}'),
('result','Result published','Result of {name} for {exam}: {percentage}% , Grade {grade}, Result {result}. - {school}');

INSERT INTO settings (skey, svalue) VALUES
('school_name','Sunrise Public School'),
('school_address','Kavi Nagar, Ghaziabad, UP 201002'),
('session','2026-27'),
('sms_driver','log');
