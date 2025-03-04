<?php
die;
require_once(dirname(__FILE__) . '/../wp-load.php');

require_once 'constant.php'; 

$max_execution_time = MAX_EXECUTION_TIME;
$buffer_time        = BUFFER_TIME;
$allowed_time       = $max_execution_time - $buffer_time;
$start_time         = microtime(true);

$page_file = __DIR__ . '/my_product_import_page.txt';

if (file_exists($page_file)) {
    $content = file_get_contents($page_file);
    $page    = filter_var($content, FILTER_VALIDATE_INT, ["options" => ["default" => 1, "min_range" => 1]]);
} else {
    $page = 1;
}

$per_page = PER_PAGE;
$api_url  = BASE_URL . "products?page={$page}&per_page={$per_page}";

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

$current_page = $data['pagination']['current_page'];
$total_pages  = $data['pagination']['total_pages'];

if ($current_page > $total_pages) {
    unlink($page_file);
    echo "✅ همه محصولات وارد شدند!\n";
    exit;
}

foreach ($data['products'] as $article) {
    $fldId       = $article['A_Code'];
    $fldC_Kala   = $article['A_Code_C'];
    $title       = $article['A_Name'];
    $price       = !empty($article['Sel_Price']) ? $article['Sel_Price'] : 0;
    $offPrice    = ($article['PriceTakhfif'] > 0) ? $article['PriceTakhfif'] : $price;
    $stock       = !empty($article['Exist']) ? $article['Exist'] : 0;
    $status      = ($article['IsActive']) ? 'publish' : 'draft';

    $existing_product = get_page_by_title($title, OBJECT, 'product');
    
    if (!$existing_product) {
        $post_data = array(
            'post_title'    => $title,
            'post_name'     => sanitize_title($title),
            'post_content'  => '',
            'post_status'   => $status,
            'post_type'     => 'product',
            'post_author'   => 1,
            'post_date'     => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', 1)
        );
        $product_id = wp_insert_post($post_data);
        
        if ($product_id && !is_wp_error($product_id)) {
            $meta_data = array(
                '_regular_price' => $price,
                '_sale_price'    => $offPrice,
                '_price'         => ($offPrice ? $offPrice : $price),
                '_stock'         => $stock,
                '_A_Code'        => $fldId,
                '_fldC_Kala'     => $fldC_Kala,
                '_visibility'    => 'visible'
            );
            foreach ($meta_data as $meta_key => $meta_value) {
                update_post_meta($product_id, $meta_key, $meta_value);
            }
            
            $main_category_name = isset($article['Main_Category']['M_groupname']) ? $article['Main_Category']['M_groupname'] : 'دسته‌بندی نامشخص';
            $sub_category_name  = isset($article['Sub_Category']['S_groupname']) ? $article['Sub_Category']['S_groupname'] : null;
            
            $main_category = term_exists($main_category_name, 'product_cat');
            if (!$main_category) {
                $main_category = wp_insert_term($main_category_name, 'product_cat', array(
                    'slug'   => sanitize_title($main_category_name),
                    'parent' => 0
                ));
                if (!is_wp_error($main_category)) {
                    $main_category_id = $main_category['term_id'];
                } else {
                    $main_category_id = 0;
                }
            } else {
                $main_category_id = $main_category['term_id'];
            }
            
            if ($sub_category_name) {
                $sub_category = term_exists($sub_category_name, 'product_cat');
                if (!$sub_category) {
                    $sub_category = wp_insert_term($sub_category_name, 'product_cat', array(
                        'slug'   => sanitize_title($sub_category_name),
                        'parent' => $main_category_id
                    ));
                    if (!is_wp_error($sub_category)) {
                        $sub_category_id = $sub_category['term_id'];
                    } else {
                        $sub_category_id = 0;
                    }
                } else {
                    $sub_category_id = $sub_category['term_id'];
                }
                wp_set_object_terms($product_id, intval($sub_category_id), 'product_cat');
            } else {
                wp_set_object_terms($product_id, intval($main_category_id), 'product_cat');
            }
        }
    }
    
    if ((microtime(true) - $start_time) >= $allowed_time) {
        file_put_contents($page_file, $page);
        echo "⏳ زمان اجرا تمام شد. پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.\n";
        exit;
    }
}

$page++;
file_put_contents($page_file, $page);
echo "پردازش تا صفحه {$page} انجام شد. لطفاً مجدداً اجرا کنید.\n";
