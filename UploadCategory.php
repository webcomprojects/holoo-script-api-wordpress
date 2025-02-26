<?php
require_once 'constant.php';
require_once 'db.php';
$table_prefix = 'wp_';
// تنظیمات زمان اجرا
$max_execution_time = 30;
$buffer_time = 5;
$allowed_time = $max_execution_time - $buffer_time;
$start_time = microtime(true);


try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ خطای اتصال به پایگاه داده: " . $e->getMessage());
}

// تابع جایگزین sanitize_title
function sanitize_slug($title)
{
    $slug = preg_replace('/[^a-z0-9_\s-]/', '', strtolower($title));
    $slug = preg_replace('/[\s-]+/', ' ', $slug);
    $slug = preg_replace('/[\s_]/', '-', $slug);
    return $slug;
}

// دریافت offset از دیتابیس
$import_offset = 0;
try {
    $stmt = $pdo->prepare("SELECT option_value FROM {$table_prefix}options WHERE option_name = 'my_category_import_offset'");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) {
        $import_offset = (int) $result['option_value'];
    }
} catch (PDOException $e) {
    die("خطا در دریافت offset: " . $e->getMessage());
}

// دریافت داده‌ها از API
$api_url = BASE_URL . 'categories';
$response = file_get_contents($api_url);
$data = json_decode($response, true);

if (!$data) {
    die('خطا در دریافت API یا رمزگشایی JSON');
}

$total_items = count($data);

for ($i = $import_offset; $i < $total_items; $i++) {
    $category = $data[$i];
    $name = $category['M_groupname'];
    $slug = sanitize_slug($name);

    // بررسی وجود دسته‌بندی
    try {
        $stmt = $pdo->prepare("
            SELECT t.term_id 
            FROM {$table_prefix}terms t
            INNER JOIN {$table_prefix}term_taxonomy tt ON t.term_id = tt.term_id
            WHERE t.name = ? AND tt.taxonomy = 'product_cat'
        ");
        $stmt->execute([$name]);
        $existing_term = $stmt->fetch();

        $term_id = $existing_term['term_id'] ?? null;
    } catch (PDOException $e) {
        error_log("خطا در بررسی دسته موجود: " . $e->getMessage());
        continue;
    }

    // ایجاد دسته جدید
    if (!$term_id) {
        try {
            $pdo->beginTransaction();

            // درج در جدول terms
            $stmt = $pdo->prepare("
                INSERT INTO {$table_prefix}terms (name, slug, term_group)
                VALUES (?, ?, 0)
            ");
            $stmt->execute([$name, $slug]);
            $term_id = $pdo->lastInsertId();

            // درج در جدول term_taxonomy
            $stmt = $pdo->prepare("
                INSERT INTO {$table_prefix}term_taxonomy 
                (term_id, taxonomy, description, parent, count)
                VALUES (?, 'product_cat', ?, 0, 0)
            ");
            $description = 'کد دسته: ' . $category['M_groupcode'];
            $stmt->execute([$term_id, $description]);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("خطا در ایجاد دسته '$name': " . $e->getMessage());
            $term_id = null;
        }
    }

    // پردازش زیردسته‌ها
    if ($term_id && !empty($category['sub_categories'])) {
        foreach ($category['sub_categories'] as $sub) {
            // بررسی زمان
            if ((microtime(true) - $start_time) >= $allowed_time) {
                update_import_offset($pdo, $table_prefix, $i);
                echo "⏳ زمان اجرا به پایان نزدیک شد";
                exit;
            }

            $sub_name = $sub['S_groupname'];
            $sub_slug = sanitize_slug($sub_name);

            // بررسی وجود زیردسته
            try {
                $stmt = $pdo->prepare("
                    SELECT t.term_id 
                    FROM {$table_prefix}terms t
                    INNER JOIN {$table_prefix}term_taxonomy tt 
                        ON t.term_id = tt.term_id
                    WHERE t.name = ? 
                        AND tt.taxonomy = 'product_cat'
                        AND tt.parent = ?
                ");
                $stmt->execute([$sub_name, $term_id]);
                $existing_sub = $stmt->fetch();

                if ($existing_sub)
                    continue;
            } catch (PDOException $e) {
                error_log("خطا در بررسی زیردسته: " . $e->getMessage());
                continue;
            }

            // ایجاد زیردسته
            try {
                $pdo->beginTransaction();

                // درج در terms
                $stmt = $pdo->prepare("
                    INSERT INTO {$table_prefix}terms 
                    (name, slug, term_group)
                    VALUES (?, ?, 0)
                ");
                $stmt->execute([$sub_name, $sub_slug]);
                $sub_term_id = $pdo->lastInsertId();

                // درج در term_taxonomy
                $stmt = $pdo->prepare("
                    INSERT INTO {$table_prefix}term_taxonomy 
                    (term_id, taxonomy, description, parent, count)
                    VALUES (?, 'product_cat', ?, ?, 0)
                ");
                $sub_description = 'کد زیر دسته: ' . $sub['S_groupcode'];
                $stmt->execute([
                    $sub_term_id,
                    $sub_description,
                    $term_id
                ]);

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("خطا در ایجاد زیردسته '$sub_name': " . $e->getMessage());
            }
        }
    }

    // به‌روزرسانی offset
    update_import_offset($pdo, $table_prefix, $i + 1);

    // بررسی زمان
    if ((microtime(true) - $start_time) >= $allowed_time) {
        echo "⏳ تا دسته‌بندی شماره " . ($i + 1) . " پردازش شده";
        exit;
    }
}

// حذف offset پس از اتمام
try {
    $stmt = $pdo->prepare("
        DELETE FROM {$table_prefix}options 
        WHERE option_name = 'my_category_import_offset'
    ");
    $stmt->execute();
} catch (PDOException $e) {
    error_log("خطا در حذف offset: " . $e->getMessage());
}

echo "دسته‌بندی‌ها با موفقیت ثبت شدند.";

// تابع به‌روزرسانی offset
function update_import_offset($pdo, $prefix, $value)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}options 
            (option_name, option_value, autoload) 
            VALUES ('my_category_import_offset', ?, 'no') 
            ON DUPLICATE KEY UPDATE option_value = ?
        ");
        $stmt->execute([$value, $value]);
    } catch (PDOException $e) {
        error_log("خطا در به‌روزرسانی offset: " . $e->getMessage());
    }
}
?>