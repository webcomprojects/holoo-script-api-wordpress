<?php

require_once 'constant.php';
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('خطا در اتصال به پایگاه داده: ' . $e->getMessage());
}

// تنظیمات زمان اجرا
$max_execution_time = 30; // حداکثر زمان اجرای اسکریپت (ثانیه)
$buffer_time = 5;         // زمان بافر جهت جلوگیری از اتمام ناگهانی
$allowed_time = $max_execution_time - $buffer_time;
$start_time = microtime(true);

// دریافت offset دسته‌بندی پردازش شده از قبل
$import_offset = (int) get_option_from_db($pdo, 'my_category_import_offset', 0);

// تابع برای خواندن یک گزینه از پایگاه داده
function get_option_from_db($pdo, $option_name, $default = null) {
    $stmt = $pdo->prepare("SELECT option_value FROM wp_options WHERE option_name = :option_name");
    $stmt->execute([':option_name' => $option_name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['option_value'] : $default;
}

// تابع برای ذخیره یک گزینه در پایگاه داده
function update_option_in_db($pdo, $option_name, $value) {
    $stmt = $pdo->prepare("REPLACE INTO wp_options (option_name, option_value) VALUES (:option_name, :option_value)");
    $stmt->execute([':option_name' => $option_name, ':option_value' => $value]);
}

// تابع برای حذف یک گزینه از پایگاه داده
function delete_option_from_db($pdo, $option_name) {
    $stmt = $pdo->prepare("DELETE FROM wp_options WHERE option_name = :option_name");
    $stmt->execute([':option_name' => $option_name]);
}

// تابع برای بررسی وجود یک دسته‌بندی
function term_exists_in_db($pdo, $name, $taxonomy = 'product_cat') {
    $stmt = $pdo->prepare("SELECT term_id FROM wp_terms 
                           INNER JOIN wp_term_taxonomy ON wp_terms.term_id = wp_term_taxonomy.term_id 
                           WHERE name = :name AND taxonomy = :taxonomy");
    $stmt->execute([':name' => $name, ':taxonomy' => $taxonomy]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// تابع برای ایجاد یک دسته‌بندی
function insert_term_in_db($pdo, $name, $slug, $description, $parent, $taxonomy = 'product_cat') {
    try {
        $pdo->beginTransaction();

        // اضافه کردن دسته‌بندی به wp_terms
        $stmt = $pdo->prepare("INSERT INTO wp_terms (name, slug) VALUES (:name, :slug)");
        $stmt->execute([':name' => $name, ':slug' => $slug]);
        $term_id = $pdo->lastInsertId();

        // اضافه کردن دسته‌بندی به wp_term_taxonomy
        $stmt = $pdo->prepare("INSERT INTO wp_term_taxonomy (term_id, taxonomy, description, parent) 
                               VALUES (:term_id, :taxonomy, :description, :parent)");
        $stmt->execute([
            ':term_id' => $term_id,
            ':taxonomy' => $taxonomy,
            ':description' => $description,
            ':parent' => $parent
        ]);

        $pdo->commit();
        return $term_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("خطا در ایجاد دسته '$name': " . $e->getMessage());
        return false;
    }
}

// دریافت داده‌ها از API
$api_url = BASE_URL . 'categories';
$response = file_get_contents($api_url);
$data = json_decode($response, true);

if (!$data) {
    die('خطا در دریافت API یا رمزگشایی JSON');
}

// تعداد کل دسته‌بندی‌ها
$total_items = count($data);

for ($i = $import_offset; $i < $total_items; $i++) {
    $category = $data[$i];

    $name = $category['M_groupname'];
    $slug = sanitize_title($name);

    // بررسی وجود دسته‌بندی
    $existing_term = term_exists_in_db($pdo, $name, 'product_cat');
    if ($existing_term) {
        $term_id = $existing_term['term_id'];
    } else {
        // ایجاد دسته جدید
        $term_id = insert_term_in_db(
            $pdo,
            $name,
            $slug,
            'کد دسته: ' . $category['M_groupcode'],
            0,
            'product_cat'
        );
    }

    // در صورتی که دسته ایجاد یا دریافت شده باشد، زیر دسته‌ها را پردازش می‌کنیم
    if ($term_id && !empty($category['sub_categories'])) {
        foreach ($category['sub_categories'] as $sub) {
            // بررسی زمان: اگر زمان مجاز به پایان نزدیک است، خروج از حلقه
            if ((microtime(true) - $start_time) >= $allowed_time) {
                update_option_in_db($pdo, 'my_category_import_offset', $i);
                echo "⏳ زمان اجرای اسکریپت به پایان نزدیک شد. لطفاً برای ادامه اجرای اسکریپت دوباره اجرا کنید.";
                exit;
            }

            $sub_name = $sub['S_groupname'];
            $sub_slug = sanitize_title($sub_name);

            // بررسی وجود زیر دسته
            $child_term = term_exists_in_db($pdo, $sub_name, 'product_cat');
            if ($child_term && $child_term['parent'] == $term_id) {
                continue;
            }

            // ایجاد زیر دسته
            insert_term_in_db(
                $pdo,
                $sub_name,
                $sub_slug,
                'کد زیر دسته: ' . $sub['S_groupcode'],
                $term_id,
                'product_cat'
            );
        }
    }

    // به‌روزرسانی offset پس از پردازش هر دسته‌بندی
    update_option_in_db($pdo, 'my_category_import_offset', $i + 1);

    // بررسی زمان: اگر زمان سپری شده به مقدار مجاز نزدیک شد، خروج از حلقه
    if ((microtime(true) - $start_time) >= $allowed_time) {
        echo "⏳ زمان اجرای اسکریپت به پایان نزدیک شد. تا دسته‌بندی شماره " . ($i + 1) . " پردازش شده است. لطفاً برای ادامه مجدد اسکریپت دوباره اجرا کنید.";
        exit;
    }
}

// در صورت اتمام پردازش همه دسته‌بندی‌ها، offset حذف یا ریست می‌شود.
delete_option_from_db($pdo, 'my_category_import_offset');
echo "دسته‌بندی‌های ووکامرس با موفقیت ثبت شدند.";
?>