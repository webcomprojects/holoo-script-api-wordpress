<?php
// require_once('../wp-config.php');
// require_once('../wp-load.php');
// global $wpdb;

// // تنظیمات اجرای اسکریپت
// $max_execution_time = 30; // ثانیه
// $buffer_time = 5; 
// $allowed_time = $max_execution_time - $buffer_time;
// $start_time = microtime(true);

// $page = (int) get_option('my_product_import_page', 1);
// $per_page = 100;
// $api_url = "http://109.122.229.114:5000/api/products?page={$page}&per_page={$per_page}";

// // دریافت داده‌ها از API
// $response = file_get_contents($api_url);
// if ($response === false) {
//     error_log("❌ خطا در دریافت API صفحه {$page}");
//     exit;
// }

// $data = json_decode($response, true);
// if (json_last_error() !== JSON_ERROR_NONE) {
//     error_log("❌ خطا در رمزگشایی JSON: " . json_last_error_msg());
//     exit;
// }

// if (!isset($data['products']) || empty($data['products'])) {
//     delete_option('my_product_import_page'); // حذف مقدار صفحه در صورت اتمام پردازش
//     echo "✅ همه محصولات وارد شدند!";
//     exit;
// }

// foreach ($data['products'] as $article) {
//     $fldId = $article['A_Code'];
//     $fldC_Kala = $article['A_Code_C'];
//     $title = $article['A_Name'];
//     $price = $article['Sel_Price'] ?: 0;
//     $offPrice = ($article['PriceTakhfif'] > 0) ? $article['PriceTakhfif'] : $price;
//     $stock = $article['Exist'] ?: 0;
//     $status = ($article['IsActive']) ? 'publish' : 'draft';

//     // بررسی وجود محصول در ووکامرس
//     $existing_product = $wpdb->get_var($wpdb->prepare(
//         "SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'product' AND post_title = %s LIMIT 1",
//         $title
//     ));

//     if (!$existing_product) {
//         // ایجاد محصول جدید
//         $post_data = [
//             'post_title'   => $title,
//             'post_content' => '',
//             'post_status'  => $status,
//             'post_type'    => 'product',
//             'post_author'  => 1,
//         ];

//         $product_id = wp_insert_post($post_data);
//         if ($product_id) {
//             update_post_meta($product_id, '_regular_price', $price);
//             update_post_meta($product_id, '_sale_price', $offPrice);
//             update_post_meta($product_id, '_stock', $stock);
//             update_post_meta($product_id, '_A_Code', $fldId);
//             update_post_meta($product_id, '_fldC_Kala', $fldC_Kala);
//             update_post_meta($product_id, '_visibility', 'visible');

//             // **افزودن دسته‌بندی‌ها**
//             $main_category_name = $article['Main_Category']['M_groupname'] ?? 'دسته‌بندی نامشخص';
//             $sub_category_name = $article['Sub_Category']['S_groupname'] ?? null;

//             $main_category_term = wp_insert_term($main_category_name, 'product_cat', ['slug' => sanitize_title($main_category_name)]);
//             $main_category_id = is_wp_error($main_category_term) ? get_term_by('name', $main_category_name, 'product_cat')->term_id : $main_category_term['term_id'];

//             if ($sub_category_name) {
//                 $sub_category_term = wp_insert_term($sub_category_name, 'product_cat', [
//                     'slug'   => sanitize_title($sub_category_name),
//                     'parent' => $main_category_id
//                 ]);

//                 $sub_category_id = is_wp_error($sub_category_term) ? get_term_by('name', $sub_category_name, 'product_cat')->term_id : $sub_category_term['term_id'];
//                 wp_set_object_terms($product_id, [$main_category_id, $sub_category_id], 'product_cat');
//             } else {
//                 wp_set_object_terms($product_id, [$main_category_id], 'product_cat');
//             }
//         }
//     }

//     // بررسی زمان سپری شده
//     if ((microtime(true) - $start_time) >= $allowed_time) {
//         update_option('my_product_import_page', $page);
//         echo "⏳ زمان اجرا تمام شد. پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.";
//         exit;
//     }
// }

// // به صفحه بعدی برویم
// $page++;
// update_option('my_product_import_page', $page);
// echo "پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.";





$db_host = 'localhost'; // آدرس پایگاه داده
$db_name = 'axijrtzi_holooclient'; // نام پایگاه داده
$db_user = 'axijrtzi_holooclient'; // نام کاربری پایگاه داده
$db_pass = 'S+BkYe*oU;MK'; // رمز عبور پایگاه داده

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

// خواندن صفحه فعلی از فایل
$page_file = '../my_product_import_page.txt';
if (file_exists($page_file)) {
    $page = (int) file_get_contents($page_file);
} else {
    $page = 1;
}
$per_page = 100;

// URL API برای دریافت محصولات
$api_url = "http://109.122.229.114:5000/api/products?page={$page}&per_page={$per_page}";

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

if (!isset($data['products']) || empty($data['products'])) {
    unlink($page_file); // حذف فایل صفحه در صورت اتمام پردازش
    echo "✅ همه محصولات وارد شدند!";
    exit;
}

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