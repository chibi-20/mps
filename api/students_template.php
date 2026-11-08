<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$session = require_login();
if (!in_array($session['role'] ?? '', ['admin','teacher'], true)) { http_response_code(403); exit('Access denied.'); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="students-import-template.csv"');
$out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['LRN','NAME','SEX']); fputcsv($out,['123456789012','Juan Dela Cruz','Male']); fclose($out);
