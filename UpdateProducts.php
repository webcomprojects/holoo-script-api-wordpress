<?php
require_once(dirname(__FILE__) . '/../wp-load.php');
require_once 'constant.php';

$api_url = BASE_URL . 'updated/products';
$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "Content-Type: application/json\r\n"
    ]
]);

$response = file_get_contents($api_url, false, $context);
if ($response === false) {
    error_log("❌ خطا در دریافت API به روزرسانی محصولات");
    exit;
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("❌ خطا در رمزگشایی JSON: " . json_last_error_msg());
    exit;
}

foreach ($data as $article) {
    $sku = $article['A_Code'];

    $args = array(
        'post_type'      => 'product',
        'post_status'    => array('publish', 'draft'),
        'meta_query'     => array(
            array(
                'key'     => '_sku',
                'value'   => $sku,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    );
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $query->the_post();
        $product_id = get_the_ID();
        wp_reset_postdata();

        $price   = $article['Sel_Price'];
        $offPrice = ($article['PriceTakhfif'] > 0) ? $article['PriceTakhfif'] : $price;
        $stock   = $article['Exist'];
        $status  = ($article['IsActive'] == "true") ? 'publish' : 'draft';

        update_post_meta($product_id, '_regular_price', $price);
        update_post_meta($product_id, '_sale_price', $offPrice);
        update_post_meta($product_id, '_stock', $stock);
        update_post_meta($product_id, '_stock_status', ($stock > 0 ? 'instock' : 'outofstock'));

        $post_data = array(
            'ID'          => $product_id,
            'post_status' => $status
        );
        wp_update_post($post_data);

        $main_category_slug = isset($article['Main_Category']['M_groupcode']) ? $article['Main_Category']['M_groupcode'] : '';
        $main_category_name = isset($article['Main_Category']['M_groupname']) ? $article['Main_Category']['M_groupname'] : 'دسته‌بندی اصلی';
        $main_category_id   = get_or_create_term($main_category_slug, $main_category_name);
        if ($main_category_id) {
            wp_set_object_terms($product_id, intval($main_category_id), 'product_cat');
        }

        if (!empty($article['Sub_Category'])) {
            $sub_category_slug = isset($article['Sub_Category']['S_groupcode']) ? $article['Sub_Category']['S_groupcode'] : '';
            $sub_category_name = isset($article['Sub_Category']['S_groupname']) ? $article['Sub_Category']['S_groupname'] : 'دسته‌بندی فرعی';
            $sub_category_id   = get_or_create_term($sub_category_slug, $sub_category_name);
            if ($sub_category_id) {
                wp_set_object_terms($product_id, intval($sub_category_id), 'product_cat', true);
            }
        }
    }
}


function get_or_create_term($slug, $name) {
    $slug = sanitize_title($slug);
    if (empty($slug)) {
        $slug = sanitize_title($name);
    }
    $term = term_exists($slug, 'product_cat');
    if ($term && !is_wp_error($term)) {
        return is_array($term) ? $term['term_id'] : $term;
    } else {
        $new_term = wp_insert_term($name, 'product_cat', array('slug' => $slug));
        if (!is_wp_error($new_term)) {
            return $new_term['term_id'];
        }
    }
    return 0;
}
?>
