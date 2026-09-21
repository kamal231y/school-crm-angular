<?php
/**
 * School Mini CRM - API front controller
 * Sab requests yahin aati hain: /api/index.php/<route>
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // production: 0, debug ke liye 1

// ---- CORS ----
require_once __DIR__ . '/core/Config.php';
Config::load(__DIR__ . '/config/config.php');

header('Access-Control-Allow-Origin: ' . Config::get('cors_origin', '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Autoload ----
spl_autoload_register(function (string $class): void {
    foreach (['core', 'lib', 'controllers', 'config'] as $dir) {
        $file = __DIR__ . "/$dir/$class.php";
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
require_once __DIR__ . '/config/database.php';

// ---- Fatal error -> JSON ----
set_exception_handler(function (Throwable $e): void {
    Response::error('Server error: ' . $e->getMessage(), 500);
});

// ---- Routes ----
$r = new Router();

// auth
$r->post('auth/login',            [AuthController::class, 'login']);
$r->post('auth/logout',           [AuthController::class, 'logout']);
$r->get('auth/me',                [AuthController::class, 'me']);
$r->post('auth/change-password',  [AuthController::class, 'changePassword']);

// dashboard
$r->get('dashboard',              [ReportController::class, 'dashboard']);

// admission / students
$r->get('students',               [StudentController::class, 'index']);
$r->post('students',              [StudentController::class, 'store']);
$r->get('students/{id}',          [StudentController::class, 'show']);
$r->put('students/{id}',          [StudentController::class, 'update']);
$r->delete('students/{id}',       [StudentController::class, 'destroy']);

// attendance
$r->get('attendance/sheet',       [AttendanceController::class, 'sheet']);
$r->get('attendance/monthly',     [AttendanceController::class, 'monthly']);
$r->post('attendance',            [AttendanceController::class, 'store']);

// fees
$r->get('fees/invoices',          [FeeController::class, 'invoices']);
$r->post('fees/invoices',         [FeeController::class, 'createInvoice']);
$r->post('fees/payments',         [FeeController::class, 'pay']);
$r->get('fees/receipt/{id}',      [FeeController::class, 'receipt']);
$r->get('fees/defaulters',        [FeeController::class, 'defaulters']);

// exams & report card
$r->get('exams',                  [ExamController::class, 'index']);
$r->post('exams',                 [ExamController::class, 'store']);
$r->get('exams/{id}/marks',       [ExamController::class, 'marksSheet']);
$r->post('exams/{id}/marks',      [ExamController::class, 'saveMarks']);
$r->post('exams/{id}/publish',    [ExamController::class, 'publish']);
$r->get('report-card/{id}',       [ExamController::class, 'reportCard']);

// sms
$r->get('sms/logs',               [SmsController::class, 'logs']);
$r->get('sms/templates',          [SmsController::class, 'templates']);
$r->put('sms/templates/{id}',     [SmsController::class, 'updateTemplate']);
$r->post('sms/send',              [SmsController::class, 'send']);

// reports
$r->get('reports/students',       [ReportController::class, 'students']);
$r->get('reports/attendance',     [ReportController::class, 'attendance']);
$r->get('reports/fees',           [ReportController::class, 'fees']);

// masters
$r->get('classes',                [MasterController::class, 'classes']);
$r->post('classes',               [MasterController::class, 'storeClass']);
$r->get('subjects',               [MasterController::class, 'subjects']);
$r->post('subjects',              [MasterController::class, 'storeSubject']);
$r->get('fee-heads',              [MasterController::class, 'feeHeads']);
$r->post('fee-heads',             [MasterController::class, 'storeFeeHead']);
$r->get('users',                  [MasterController::class, 'users']);
$r->get('settings',               [MasterController::class, 'settings']);

// ---- Dispatch ----
$path = $_SERVER['PATH_INFO'] ?? ($_GET['_route'] ?? '');
if ($path === '' || $path === '/') {
    Response::ok(['name' => 'School CRM API', 'version' => '1.0'], 'API is running');
}
$r->dispatch($path);
