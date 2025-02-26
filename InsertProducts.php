<?php

require_once 'constant.php';
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ خطای اتصال به پایگاه داده: " . $e->getMessage());
}

// تنظیمات اجرای اسکریپت
$max_execution_time = 30; // ثانیه
$buffer_time = 5;
$allowed_time = $max_execution_time - $buffer_time;
$start_time = microtime(true);

$page_file = __DIR__ . '/my_product_import_page.txt';

// اگر فایل صفحه وجود داشته باشد، شماره صفحه را خوانده می‌کنیم
if (file_exists($page_file)) {
    $content = file_get_contents($page_file);
    $page = filter_var($content, FILTER_VALIDATE_INT, ["options" => ["default" => 1, "min_range" => 1]]);
} else {
    $page = 1;
}

$per_page = 100;

// URL API برای دریافت اولین صفحه
$api_url =  BASE_URL . "products?page={$page}&per_page={$per_page}";

// دریافت داده‌ها از API
$response = file_get_contents($api_url);
if ($response === false) {
    error_log("❌ خطا در دریافت API صفحه {$page}");
    exit;
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("❌ خطا در رمزگشایی JSON: " . json_last_error_msg());
    exit;
}

if (!isset($data['pagination']) || !isset($data['products'])) {
    error_log("❌ ساختار API نادرست است!");
    exit;
}

// استخراج اطلاعات pagination
$current_page = $data['pagination']['current_page'];
$total_pages = $data['pagination']['total_pages'];

// اگر تمام صفحات پردازش شده باشند
if ($current_page > $total_pages) {
    unlink($page_file); // حذف فایل صفحه
    echo "✅ همه محصولات وارد شدند!";
    exit;
}

// پردازش محصولات صفحه فعلی
foreach ($data['products'] as $article) {
    $fldId = $article['A_Code'];
    $fldC_Kala = $article['A_Code_C'];
    $title = $article['A_Name'];
    $price = $article['Sel_Price'] ?: 0;
    $offPrice = ($article['PriceTakhfif'] > 0) ? $article['PriceTakhfif'] : $price;
    $stock = $article['Exist'] ?: 0;
    $status = ($article['IsActive']) ? 'publish' : 'draft';

    // بررسی وجود محصول در پایگاه داده
    $stmt = $pdo->prepare("SELECT ID FROM wp_posts WHERE post_type = 'product' AND post_title = :title LIMIT 1");
    $stmt->execute([':title' => $title]);
    $existing_product = $stmt->fetchColumn();

    if (!$existing_product) {
        // ایجاد محصول جدید
        $insert_stmt = $pdo->prepare("
            INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_author, post_date, post_date_gmt)
            VALUES (:title, '', :status, 'product', 1, NOW(), NOW())
        ");
        $insert_stmt->execute([':title' => $title, ':status' => $status]);
        $product_id = $pdo->lastInsertId();

        if ($product_id) {
            // ذخیره متا داده‌ها
            $meta_data = [
                '_regular_price' => $price,
                '_sale_price' => $offPrice,
                '_stock' => $stock,
                '_A_Code' => $fldId,
                '_fldC_Kala' => $fldC_Kala,
                '_visibility' => 'visible',
            ];
            foreach ($meta_data as $meta_key => $meta_value) {
                $pdo->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, :meta_key, :meta_value)")
                    ->execute([':post_id' => $product_id, ':meta_key' => $meta_key, ':meta_value' => $meta_value]);
            }

            // **افزودن دسته‌بندی‌ها**
            $main_category_name = $article['Main_Category']['M_groupname'] ?? 'دسته‌بندی نامشخص';
            $sub_category_name = $article['Sub_Category']['S_groupname'] ?? null;

            // اضافه کردن دسته‌بندی اصلی
            $main_category_slug = strtolower(str_replace(' ', '-', $main_category_name));
            $main_category_stmt = $pdo->prepare("SELECT term_id FROM wp_terms WHERE name = :name");
            $main_category_stmt->execute([':name' => $main_category_name]);
            $main_category_id = $main_category_stmt->fetchColumn();

            if (!$main_category_id) {
                $pdo->prepare("INSERT INTO wp_terms (name, slug) VALUES (:name, :slug)")
                    ->execute([':name' => $main_category_name, ':slug' => $main_category_slug]);
                $main_category_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO wp_term_taxonomy (term_id, taxonomy, parent) VALUES (:term_id, 'product_cat', 0)")
                    ->execute([':term_id' => $main_category_id]);
            }

            // اضافه کردن دسته‌بندی فرعی
            if ($sub_category_name) {
                $sub_category_slug = strtolower(str_replace(' ', '-', $sub_category_name));
                $sub_category_stmt = $pdo->prepare("SELECT term_id FROM wp_terms WHERE name = :name");
                $sub_category_stmt->execute([':name' => $sub_category_name]);
                $sub_category_id = $sub_category_stmt->fetchColumn();

                if (!$sub_category_id) {
                    $pdo->prepare("INSERT INTO wp_terms (name, slug) VALUES (:name, :slug)")
                        ->execute([':name' => $sub_category_name, ':slug' => $sub_category_slug]);
                    $sub_category_id = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO wp_term_taxonomy (term_id, taxonomy, parent) VALUES (:term_id, 'product_cat', :parent)")
                        ->execute([':term_id' => $sub_category_id, ':parent' => $main_category_id]);
                }

                // اضافه کردن دسته‌بندی‌ها به محصول
                $pdo->prepare("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (:object_id, :term_taxonomy_id)")
                    ->execute([':object_id' => $product_id, ':term_taxonomy_id' => $sub_category_id]);
            } else {
                $pdo->prepare("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (:object_id, :term_taxonomy_id)")
                    ->execute([':object_id' => $product_id, ':term_taxonomy_id' => $main_category_id]);
            }
        }
    }

    // بررسی زمان سپری شده
    if ((microtime(true) - $start_time) >= $allowed_time) {
        file_put_contents($page_file, $page);
        echo "⏳ زمان اجرا تمام شد. پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.";
        exit;
    }
}

// به صفحه بعدی برویم
$page++;
file_put_contents($page_file, $page);
echo "پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.";