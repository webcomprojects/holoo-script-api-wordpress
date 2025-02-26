<?php
die;
require_once 'constant.php';
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("اتصال به پایگاه داده ناموفق: " . $e->getMessage());
}

// تنظیمات زمان اجرا
$max_execution_time = 30;
$buffer_time = 5;
$allowed_time = $max_execution_time - $buffer_time;
$start_time = microtime(true);

// دریافت offset از جدول options
function get_option_pdo($pdo, $option_name, $default = 0) {
    $stmt = $pdo->prepare("SELECT option_value FROM wp_options WHERE option_name = ?");
    $stmt->execute([$option_name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int)$result['option_value'] : $default;
}

$import_offset = get_option_pdo($pdo, 'my_category_import_offset', 0);

// دریافت داده‌ها از API
$api_url = BASE_URL . 'categories';
$response = file_get_contents($api_url);
$data = json_decode($response, true);

if (!$data) {
    die('خطا در دریافت API یا رمزگشایی JSON');
}

// تابع ساخت Slug
function sanitize_title($title) {
    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace('/[^a-z0-9\s-]/u', '', $title);
    $title = preg_replace('/\s+/', ' ', $title);
    $title = trim($title);
    $title = str_replace(' ', '-', $title);
    return $title;
}

// تابع بررسی وجود دسته
function term_exists_pdo($pdo, $name, $taxonomy = 'product_cat') {
    $stmt = $pdo->prepare("
        SELECT t.term_id 
        FROM wp_terms t
        JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
        WHERE t.name = ? AND tt.taxonomy = ?
    ");
    $stmt->execute([$name, $taxonomy]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// تابع ایجاد دسته
function wp_insert_term_pdo($pdo, $name, $taxonomy, $args = []) {
    $slug = $args['slug'] ?? sanitize_title($name);
    $description = $args['description'] ?? '';
    $parent = $args['parent'] ?? 0;

    try {
        // درج در جدول terms
        $stmt = $pdo->prepare("INSERT INTO wp_terms (name, slug, term_group) VALUES (?, ?, 0)");
        $stmt->execute([$name, $slug]);
        $term_id = $pdo->lastInsertId();

        // درج در جدول term_taxonomy
        $stmt = $pdo->prepare("
            INSERT INTO wp_term_taxonomy 
            (term_id, taxonomy, description, parent) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$term_id, $taxonomy, $description, $parent]);

        return ['term_id' => $term_id];
    } catch (PDOException $e) {
        return new WP_Error('db_insert_error', $e->getMessage());
    }
}

// تابع دریافت زیردسته‌ها
function get_terms_pdo($pdo, $taxonomy, $parent = 0) {
    $stmt = $pdo->prepare("
        SELECT t.term_id, t.name 
        FROM wp_terms t
        JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = ? AND tt.parent = ?
    ");
    $stmt->execute([$taxonomy, $parent]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// پردازش دسته‌بندی‌ها
$total_items = count($data);

for ($i = $import_offset; $i < $total_items; $i++) {
    if ((microtime(true) - $start_time) >= $allowed_time) {
        $pdo->prepare("UPDATE wp_options SET option_value = ? WHERE option_name = 'my_category_import_offset'")
            ->execute([$i]);
        echo "⏳ زمان اجرای اسکریپت به پایان نزدیک شد. لطفاً برای ادامه اجرای اسکریپت دوباره اجرا کنید.\n";
        exit;
    }

    $category = $data[$i];
    $name = $category['M_groupname'];
    $slug = sanitize_title($name);

    // بررسی وجود دسته
    $existing_term = term_exists_pdo($pdo, $name);
    if ($existing_term) {
        $term_id = $existing_term['term_id'];
    } else {
        // ایجاد دسته جدید
        $result = wp_insert_term_pdo($pdo, $name, 'product_cat', [
            'slug' => $slug,
            'description' => 'کد دسته: ' . $category['M_groupcode'],
            'parent' => 0
        ]);

        if (is_a($result, 'WP_Error')) {
            error_log("خطا در ایجاد دسته '$name': " . $result->get_error_message());
            $term_id = null;
        } else {
            $term_id = $result['term_id'];
        }
    }

    // پردازش زیردسته‌ها
    if ($term_id && !empty($category['sub_categories'])) {
        foreach ($category['sub_categories'] as $sub) {
            if ((microtime(true) - $start_time) >= $allowed_time) {
                $pdo->prepare("UPDATE wp_options SET option_value = ? WHERE option_name = 'my_category_import_offset'")
                    ->execute([$i]);
                echo "⏳ زمان اجرای اسکریپت به پایان نزدیک شد. تا دسته‌بندی شماره " . ($i + 1) . " پردازش شده است.\n";
                exit;
            }

            $sub_name = $sub['S_groupname'];
            $sub_slug = sanitize_title($sub_name);

            // بررسی وجود زیردسته
            $existing_sub = term_exists_pdo($pdo, $sub_name);
            if ($existing_sub) continue;

            // بررسی وجود زیردسته در والد
            $child_terms = get_terms_pdo($pdo, 'product_cat', $term_id);
            $exists = false;
            foreach ($child_terms as $child) {
                if (strcasecmp($child['name'], $sub_name) === 0) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;

            // ایجاد زیردسته
            $sub_result = wp_insert_term_pdo($pdo, $sub_name, 'product_cat', [
                'slug' => $sub_slug,
                'description' => 'کد زیر دسته: ' . $sub['S_groupcode'],
                'parent' => $term_id
            ]);

            if (is_a($sub_result, 'WP_Error')) {
                error_log("خطا در ایجاد زیر دسته '$sub_name': " . $sub_result->get_error_message());
            }
        }
    }

    // به‌روزرسانی offset
    $pdo->prepare("REPLACE INTO wp_options (option_name, option_value) VALUES ('my_category_import_offset', ?)")
        ->execute([$i + 1]);
}

// حذف offset پس از اتمام
$pdo->prepare("DELETE FROM wp_options WHERE option_name = 'my_category_import_offset'")->execute();
echo "دسته‌بندی‌های ووکامرس با موفقیت ثبت شدند.\n";

class WP_Error {
    private $errors = [];
    public function __construct($code, $message) {
        $this->errors[$code] = $message;
    }
    public function get_error_message() {
        return implode('; ', $this->errors);
    }
}
?>