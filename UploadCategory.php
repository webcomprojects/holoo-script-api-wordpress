<?php

require_once 'constant.php';
require_once 'db.php';

// اتصال به دیتابیس با PDO
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ خطای اتصال به پایگاه داده: " . $e->getMessage());
}

// تنظیمات زمان اجرا
$max_execution_time = 30; // ثانیه
$buffer_time = 5;
$allowed_time = $max_execution_time - $buffer_time;
$start_time = microtime(true);

// دریافت offset از دیتابیس
$stmt = $pdo->prepare("SELECT option_value FROM " . DB_PREFIX . "options WHERE option_name = 'my_category_import_offset' LIMIT 1");
$stmt->execute();
$import_offset = (int) $stmt->fetchColumn();
$import_offset = $import_offset ?: 0;

// دریافت داده API
$api_url = BASE_URL . 'categories';
$data = json_decode(file_get_contents($api_url), true);
if (!$data) exit('خطا در دریافت API یا رمزگشایی JSON');

$total_items = count($data);

for ($i = $import_offset; $i < $total_items; $i++) {
    $category = $data[$i];
    $name = $category['M_groupname'];
    $slug = sanitize_title($name); // تابع تطهیر اسلاگ
    
    // چک کردن وجود دسته بندی
    $term_id = get_term_id_by_name($pdo, $name);
    
    if (!$term_id) {
        // ایجاد دسته جدید
        $term_id = create_term($pdo, $name, $slug, $category['M_groupcode']);
    }
    
    if ($term_id && !empty($category['sub_categories'])) {
        foreach ($category['sub_categories'] as $sub) {
            if ((microtime(true) - $start_time) >= $allowed_time) break 2;
            
            $sub_name = $sub['S_groupname'];
            $sub_slug = sanitize_title($sub_name);
            
            // چک کردن وجود زیردسته
            $child_id = get_term_id_by_parent($pdo, $sub_name, $term_id);
            
            if (!$child_id) {
                create_term($pdo, $sub_name, $sub_slug, $sub['S_groupcode'], $term_id);
            }
        }
    }
    
    // بروزرسانی offset
    update_option($pdo, 'my_category_import_offset', $i + 1);
    
    if ((microtime(true) - $start_time) >= $allowed_time) {
        echo "⏳ زمان اجرای اسکریپت به پایان نزدیک شد. تا دسته شماره " . ($i + 1) . " پردازش شده است.";
        exit;
    }
}

// حذف offset پس از اتمام
delete_option($pdo, 'my_category_import_offset');
echo "✅ دسته‌بندی‌ها با موفقیت ثبت شدند";

// توابع کمکی
function get_term_id_by_name(PDO $pdo, string $term_name): ?int {
    $stmt = $pdo->prepare("SELECT t.term_id FROM " . DB_PREFIX . "terms t
        JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id
        WHERE t.name = ? AND tt.taxonomy = 'product_cat'");
    $stmt->execute([$term_name]);
    return $stmt->fetchColumn() ?: null;
}

function get_term_id_by_parent(PDO $pdo, string $term_name, int $parent_id): ?int {
    $stmt = $pdo->prepare("SELECT term_id FROM " . DB_PREFIX . "term_taxonomy
        WHERE parent = ? AND taxonomy = 'product_cat'
        AND term_id IN (SELECT term_id FROM " . DB_PREFIX . "terms
            WHERE name = ?)");
    $stmt->execute([$parent_id, $term_name]);
    return $stmt->fetchColumn() ?: null;
}

function create_term(PDO $pdo, string $name, string $slug, string $code, int $parent = 0): ?int {
    try {
        $pdo->beginTransaction();
        
        // ذخیره در terms
        $insert_term = $pdo->prepare("INSERT INTO " . DB_PREFIX . "terms (name, slug) VALUES (?, ?)");
        $insert_term->execute([$name, $slug]);
        $term_id = $pdo->lastInsertId();
        
        // ذخیره در term_taxonomy
        $insert_tax = $pdo->prepare("INSERT INTO " . DB_PREFIX . "term_taxonomy (term_id, taxonomy, description, parent)
            VALUES (?, 'product_cat', ?, ?)");
        $insert_tax->execute([$term_id, "کد دسته: $code", $parent]);
        
        $pdo->commit();
        return $term_id;
    } catch (Exception $e) {
        error_log("خطا در ایجاد دسته '$name': " . $e->getMessage());
        return null;
    }
}

function update_option(PDO $pdo, string $option_name, $value) {
    $value = serialize($value);
    $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "options (option_name, option_value)
        VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value = ?");
    $stmt->execute([$option_name, $value, $value]);
}

function delete_option(PDO $pdo, string $option_name) {
    $pdo->exec("DELETE FROM " . DB_PREFIX . "options WHERE option_name = '$option_name'");
}

function sanitize_title(string $title): string {
    return preg_replace('/[^a-z0-9]+/i', '-', trim($title));
}
?>