<?php

require_once 'constant.php';
require_once(dirname(__FILE__) . '/../wp-config.php');
global $wpdb;

$max_execution_time = MAX_EXECUTION_TIME; 
$buffer_time = BUFFER_TIME; 
$allowed_time = $max_execution_time - $buffer_time;
$start_time = microtime(true);

$import_offset = (int) get_option('my_category_import_offset', 0);

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
    $slug = sanitize_title($name);

    // بررسی وجود دسته بندی با تابع ناحساس حروف بزرگ/کوچک
    $existing_term_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT term_id FROM $wpdb->terms 
             WHERE taxonomy = %s AND LOWER(name) = LOWER(%s)",
            'product_cat',
            $name
        )
    );

    if ($existing_term_id) {
        $term_id = $existing_term_id;
    } else {
        $result = wp_insert_term($name, 'product_cat', [
            'slug' => $slug,
            'description' => 'کد دسته: ' . $category['M_groupcode'],
            'parent' => 0
        ]);
        if (is_wp_error($result)) {
            error_log("خطا در ایجاد دسته '$name': " . $result->get_error_message());
            $term_id = null;
        } else {
            $term_id = $result['term_id'];
        }
    }

    if ($term_id && !empty($category['sub_categories'])) {
        foreach ($category['sub_categories'] as $sub) {
            if ((microtime(true) - $start_time) >= $allowed_time) {
                update_option('my_category_import_offset', $i);
                echo "⏳ زمان اجرای اسکریپت به پایان نزدیک شد. لطفاً برای ادامه اجرا دوباره اقدام کنید.\n";
                exit;
            }
            $sub_name = $sub['S_groupname'];
            $sub_slug = sanitize_title($sub_name);

            // بررسی وجود زیردسته بندی با تابع ناحساس حروف بزرگ/کوچک
            $child_terms = get_terms([
                'taxonomy' => 'product_cat',
                'parent' => $term_id,
                'hide_empty' => false,
            ]);
            $exists = false;
            if (!is_wp_error($child_terms)) {
                foreach ($child_terms as $child_term) {
                    if (strcasecmp($child_term->name, $sub_name) === 0) {
                        $exists = true;
                        break;
                    }
                }
            }
            if ($exists) {
                continue;
            }

            $sub_result = wp_insert_term($sub_name, 'product_cat', [
                'slug' => $sub_slug,
                'description' => 'کد زیر دسته: ' . $sub['S_groupcode'],
                'parent' => $term_id
            ]);
            if (is_wp_error($sub_result)) {
                error_log("خطا در ایجاد زیردسته '$sub_name': " . $sub_result->get_error_message());
            }
        }
    }

    update_option('my_category_import_offset', $i + 1);

    if ((microtime(true) - $start_time) >= $allowed_time) {
        echo "⏳ زمان اجرای اسکریپت به پایان نزدیک شد. تا دسته شماره " . ($i + 1) . " پردازش شده است.\n";
        exit;
    }
}

delete_option('my_category_import_offset');
echo "✅ تمام دسته‌بندی‌ها با موفقیت ایمپورت شدند.\n";