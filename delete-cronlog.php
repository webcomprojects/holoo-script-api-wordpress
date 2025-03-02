<?php

$cron_file_path = dirname(__FILE__) . '/../cron.log';

if (file_exists($cron_file_path)) {
    if (unlink($cron_file_path)) {
        echo "فایل cron.job با موفقیت حذف شد.";
    } else {
        echo "خطا: نتوانست فایل cron.job را حذف کند.";
    }
} else {
    echo "فایل cron.job وجود ندارد.";
}

