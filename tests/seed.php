<?php
require __DIR__ . '/../config.php';
$pdo = db();
foreach (['tx_images','transactions','class_roster','semesters','operation_logs','login_history','payment_orders','fee_payments','import_batches','users','health_ack','backup_runs','bot_notifications','bot_bindings','security_events','blocked_ips'] as $t) {
    try { $pdo->exec("DELETE FROM `$t`"); } catch (\Throwable $e) {}
}
$h = password_hash('admin123', PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO users (username,password,roles,must_change_password) VALUES (:u,:p,:r,0)")->execute([':u'=>'admin',':p'=>$h,':r'=>'["head_teacher","admin"]']);
$hs = password_hash('student123', PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO users (username,password,roles,must_change_password) VALUES (:u,:p,:r,0)")->execute([':u'=>'张三',':p'=>$hs,':r'=>'["student"]']);
foreach (['张三','李四','王五','赵六'] as $n) $pdo->prepare("INSERT IGNORE INTO class_roster (name) VALUES (:n)")->execute([':n'=>$n]);
$pdo->prepare("INSERT INTO semesters (name,start_date,end_date,status) VALUES ('2025秋季学期','2025-09-01','2026-02-28','active')")->execute();
$sem = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO transactions (type,sub_category,amount,expected_amount,per_person,date,description,payer_ids,category,recorded_by,semester_id) VALUES ('income','班费收缴',400,400,100,'2025-09-10','第一次班费','all','班费',1,:s)")->execute([':s'=>$sem]);
$pdo->prepare("INSERT INTO transactions (type,sub_category,amount,date,description,category,recorded_by,semester_id) VALUES ('expense','活动支出',50,'2025-10-01','班级活动','活动支出',1,:s)")->execute([':s'=>$sem]);
echo "seeded sem={$sem}\n";
