<?php
require_once('../wp-config.php');
require_once('../wp-load.php');
global $wpdb;

// تنظیمات اجرای اسکریپت
$max_execution_time = 30; // ثانیه
$buffer_time = 5; 
$allowed_time = $max_execution_time - $buffer_time;
$start_time = microtime(true);

$page = (int) get_option('my_product_import_page', 1);
$per_page = 100;
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
    delete_option('my_product_import_page'); // حذف مقدار صفحه در صورت اتمام پردازش
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

    // بررسی وجود محصول در ووکامرس
    $existing_product = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->prefix}posts WHERE post_type = 'product' AND post_title = %s LIMIT 1",
        $title
    ));

    if (!$existing_product) {
        // ایجاد محصول جدید
        $post_data = [
            'post_title'   => $title,
            'post_content' => '',
            'post_status'  => $status,
            'post_type'    => 'product',
            'post_author'  => 1,
        ];

        $product_id = wp_insert_post($post_data);
        if ($product_id) {
            update_post_meta($product_id, '_regular_price', $price);
            update_post_meta($product_id, '_sale_price', $offPrice);
            update_post_meta($product_id, '_stock', $stock);
            update_post_meta($product_id, '_A_Code', $fldId);
            update_post_meta($product_id, '_fldC_Kala', $fldC_Kala);
            update_post_meta($product_id, '_visibility', 'visible');

            // **افزودن دسته‌بندی‌ها**
            $main_category_name = $article['Main_Category']['M_groupname'] ?? 'دسته‌بندی نامشخص';
            $sub_category_name = $article['Sub_Category']['S_groupname'] ?? null;

            $main_category_term = wp_insert_term($main_category_name, 'product_cat', ['slug' => sanitize_title($main_category_name)]);
            $main_category_id = is_wp_error($main_category_term) ? get_term_by('name', $main_category_name, 'product_cat')->term_id : $main_category_term['term_id'];

            if ($sub_category_name) {
                $sub_category_term = wp_insert_term($sub_category_name, 'product_cat', [
                    'slug'   => sanitize_title($sub_category_name),
                    'parent' => $main_category_id
                ]);

                $sub_category_id = is_wp_error($sub_category_term) ? get_term_by('name', $sub_category_name, 'product_cat')->term_id : $sub_category_term['term_id'];
                wp_set_object_terms($product_id, [$main_category_id, $sub_category_id], 'product_cat');
            } else {
                wp_set_object_terms($product_id, [$main_category_id], 'product_cat');
            }
        }
    }

    // بررسی زمان سپری شده
    if ((microtime(true) - $start_time) >= $allowed_time) {
        update_option('my_product_import_page', $page);
        echo "⏳ زمان اجرا تمام شد. پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.";
        exit;
    }
}

// به صفحه بعدی برویم
$page++;
update_option('my_product_import_page', $page);

