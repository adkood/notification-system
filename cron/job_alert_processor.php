<?php

define('ROOT_PATH', dirname(__DIR__)); 

require_once ROOT_PATH . '/config/constants.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/email.php';

require_once ROOT_PATH . '/models/Model.php';
require_once ROOT_PATH . '/models/JobAlertSubscription.php';
require_once ROOT_PATH . '/models/Job.php';
require_once ROOT_PATH . '/models/User.php';
require_once ROOT_PATH . '/services/EmailService.php';
require_once ROOT_PATH . '/models/EmailQueue.php';
require_once ROOT_PATH . '/models/EmailTemplate.php';

require_once ROOT_PATH . '/services/JobAlertService.php';

$alertService = new JobAlertService();
$total_queued = 0;

// 1. Process Daily Alerts
$daily_queued = $alertService->processAlerts('daily');
$total_queued += $daily_queued;

// 2. Process Weekly Alerts
if (date('w') === '0') { // 0 = Sunday
    $weekly_queued = $alertService->processAlerts('weekly');
    $total_queued += $weekly_queued;
}

$log_message = date('Y-m-d H:i:s') . " - Job alert emails queued: {$total_queued}.\n";
file_put_contents(ROOT_PATH . '/logs/job_alert.log', $log_message, FILE_APPEND);

echo "Job Alert Processor finished. Total emails queued: {$total_queued}.\n";