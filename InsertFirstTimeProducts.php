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

// تنظیمات اتصال به پایگاه داده (تنظیمات مربوطه را جایگزین کنید)
$host     = 'localhost';
$dbname   = 'your_database';
$user     = 'your_username';
$password = 'your_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("اتصال به پایگاه داده با خطا مواجه شد: " . $e->getMessage());
}

// تابع ساده برای تولید slug از عنوان (مشابه sanitize_title در وردپرس)
function sanitize_title($title) {
    $title = strtolower(trim($title));
    $title = preg_replace('/[^a-z0-9-]+/', '-', $title);
    return trim($title, '-');
}

// تنظیمات اجرای اسکریپت
$max_execution_time = 30; // ثانیه
$buffer_time        = 5;
$allowed_time       = $max_execution_time - $buffer_time;
$start_time         = microtime(true);

// ذخیره مقدار صفحه در یک فایل (برای شبیه‌سازی get_option/update_option)
$option_file = 'import_page.txt';
if (file_exists($option_file)) {
    $page = (int) file_get_contents($option_file);
} else {
    $page = 1;
}

$per_page = 100;
$api_url  = "http://109.122.229.114:5000/api/products?page={$page}&per_page={$per_page}";

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
    if (file_exists($option_file)) {
        unlink($option_file); // حذف فایل تنظیمات در صورت اتمام پردازش
    }
    echo "✅ همه محصولات وارد شدند!";
    exit;
}

foreach ($data['products'] as $article) {
    // استخراج اطلاعات محصول
    $fldId     = $article['A_Code'];
    $fldC_Kala = $article['A_Code_C'];
    $title     = $article['A_Name'];
    $price     = !empty($article['Sel_Price']) ? $article['Sel_Price'] : 0;
    $offPrice  = (!empty($article['PriceTakhfif']) && $article['PriceTakhfif'] > 0) ? $article['PriceTakhfif'] : $price;
    $stock     = !empty($article['Exist']) ? $article['Exist'] : 0;
    $status    = ($article['IsActive']) ? 'publish' : 'draft';

    // بررسی وجود محصول در پایگاه داده (با مقایسه عنوان)
    $stmt = $pdo->prepare("SELECT id FROM products WHERE title = ? LIMIT 1");
    $stmt->execute([$title]);
    $existing_product = $stmt->fetchColumn();

    if (!$existing_product) {
        // ایجاد محصول جدید
        $stmt = $pdo->prepare("INSERT INTO products (title, content, status, type, author) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, '', $status, 'product', 1]);
        $product_id = $pdo->lastInsertId();

        // ذخیره متادیتای محصول
        $meta = [
            '_regular_price' => $price,
            '_sale_price'    => $offPrice,
            '_stock'         => $stock,
            '_A_Code'        => $fldId,
            '_fldC_Kala'     => $fldC_Kala,
            '_visibility'    => 'visible'
        ];

        foreach ($meta as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO product_meta (product_id, meta_key, meta_value) VALUES (?, ?, ?)");
            $stmt->execute([$product_id, $key, $value]);
        }

        // افزودن دسته‌بندی‌ها
        $main_category_name = isset($article['Main_Category']['M_groupname']) ? $article['Main_Category']['M_groupname'] : 'دسته‌بندی نامشخص';
        $sub_category_name  = isset($article['Sub_Category']['S_groupname']) ? $article['Sub_Category']['S_groupname'] : null;

        // پردازش دسته‌بندی اصلی
        $stmt = $pdo->prepare("SELECT id FROM product_categories WHERE name = ?");
        $stmt->execute([$main_category_name]);
        $main_category_id = $stmt->fetchColumn();
        if (!$main_category_id) {
            $main_slug = sanitize_title($main_category_name);
            $stmt = $pdo->prepare("INSERT INTO product_categories (name, slug, parent_id) VALUES (?, ?, ?)");
            $stmt->execute([$main_category_name, $main_slug, 0]);
            $main_category_id = $pdo->lastInsertId();
        }

        // پردازش دسته‌بندی فرعی (در صورت وجود)
        if ($sub_category_name) {
            $stmt = $pdo->prepare("SELECT id FROM product_categories WHERE name = ?");
            $stmt->execute([$sub_category_name]);
            $sub_category_id = $stmt->fetchColumn();
            if (!$sub_category_id) {
                $sub_slug = sanitize_title($sub_category_name);
                $stmt = $pdo->prepare("INSERT INTO product_categories (name, slug, parent_id) VALUES (?, ?, ?)");
                $stmt->execute([$sub_category_name, $sub_slug, $main_category_id]);
                $sub_category_id = $pdo->lastInsertId();
            }
            // ارتباط محصول با هر دو دسته (اصلی و فرعی)
            $stmt = $pdo->prepare("INSERT INTO product_category_relationships (product_id, category_id) VALUES (?, ?), (?, ?)");
            $stmt->execute([$product_id, $main_category_id, $product_id, $sub_category_id]);
        } else {
            // ارتباط محصول تنها با دسته‌بندی اصلی
            $stmt = $pdo->prepare("INSERT INTO product_category_relationships (product_id, category_id) VALUES (?, ?)");
            $stmt->execute([$product_id, $main_category_id]);
        }
    }

    // بررسی زمان سپری شده جهت جلوگیری از اتمام زمان اجرا
    if ((microtime(true) - $start_time) >= $allowed_time) {
        file_put_contents($option_file, $page);
        echo "⏳ زمان اجرا تمام شد. پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.";
        exit;
    }
}

// به صفحه بعدی برویم
$page++;
file_put_contents($option_file, $page);
echo "پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.";
