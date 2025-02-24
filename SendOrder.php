<?php

require_once 'constant.php';
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ خطای اتصال به پایگاه داده: " . $e->getMessage());
}

// تابع برای ارسال سفارش به سیستم حسابداری
function send_order_to_accounting_system($order_id, $pdo) {
    try {
        // دریافت اطلاعات سفارش
        $stmt = $pdo->prepare("SELECT * FROM wp_posts WHERE ID = :order_id AND post_type = 'shop_order'");
        $stmt->execute([':order_id' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            error_log('Order not found for ID: ' . $order_id);
            return;
        }

        // دریافت اطلاعات کاربر
        $user_id = get_post_meta($pdo, $order['ID'], '_customer_user', true);
        if (!$user_id) {
            error_log('User not found for Order ID: ' . $order_id);
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM wp_users WHERE ID = :user_id");
        $stmt->execute([':user_id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log('User not found for Order ID: ' . $order_id);
            return;
        }

        $mobile = get_user_meta($pdo, $user['ID'], 'billing_phone', true) ?: $user['user_login'];

        // دریافت اطلاعات ارسال
        $shipping_state = get_post_meta($pdo, $order['ID'], '_shipping_state', true);
        $shipping_city = get_post_meta($pdo, $order['ID'], '_shipping_city', true);
        $formatted_address = get_post_meta($pdo, $order['ID'], '_formatted_shipping_address', true);
        $shipping_postcode = get_post_meta($pdo, $order['ID'], '_shipping_postcode', true);

        $address = 'استان: ' . $shipping_state . ' شهر: ' . $shipping_city . ' | ' . $formatted_address . ' کدپستی: ' . $shipping_postcode;

        // ثبت کاربر
        $customer_data = http_build_query([
            'phoneNumber' => $mobile,
            'fullName' => $user['first_name'] . ' ' . $user['last_name'],
            'address' => $address,
            'createDate' => time(),
            'fldFeeTip' => ''
        ]);

        $customer_url = BASE_URL . 'customer/register';
        $customer_response = file_get_contents($customer_url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $customer_data
            ]
        ]));

        if ($customer_response === false) {
            error_log('Failed to register customer for Order ID: ' . $order_id);
            return;
        }

        // دریافت اطلاعات سفارش
        $order_total = get_post_meta($pdo, $order['ID'], '_order_total', true);
        $shipping_cost = get_post_meta($pdo, $order['ID'], '_order_shipping', true);
        $transaction_id = get_post_meta($pdo, $order['ID'], '_transaction_id', true);

        // دریافت جزئیات محصولات
        $stmt = $pdo->prepare("SELECT * FROM wp_woocommerce_order_items WHERE order_id = :order_id");
        $stmt->execute([':order_id' => $order_id]);
        $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $order_data = [];
        $order_data['orderVal.OrderTitle.FldMobile'] = $mobile;
        $order_data['orderVal.OrderTitle.FldTotalFaktor'] = $order_total - $shipping_cost;
        $order_data['orderVal.OrderTitle.FldTakhfifVizhe'] = 0;
        $order_data['orderVal.OrderTitle.FldTozihFaktor'] = 'هزینه ارسال: ' . $shipping_cost;
        $order_data['orderVal.OrderTitle.FldAddress'] = $address;
        $order_data['orderVal.OrderTitle.FldPayId'] = $transaction_id;

        $item_index = 0;
        foreach ($order_items as $item) {
            $item_meta_stmt = $pdo->prepare("SELECT * FROM wp_woocommerce_order_itemmeta WHERE order_item_id = :item_id");
            $item_meta_stmt->execute([':item_id' => $item['order_item_id']]);
            $item_meta = $item_meta_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $product_id = $item_meta['_product_id'];
            $quantity = $item_meta['_qty'];
            $subtotal = $item_meta['_line_subtotal'];
            $total = $item_meta['_line_total'];

            $stmt = $pdo->prepare("SELECT * FROM wp_posts WHERE ID = :product_id AND post_type = 'product'");
            $stmt->execute([':product_id' => $product_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            $order_data['orderVal.OrderDetails[' . $item_index . '].FldC_Kala'] = get_post_meta($pdo, $product_id, '_sku', true);
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldN_Kala'] = $product['post_title'];
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldFee'] = $total;
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldFeeBadAzTakhfif'] = $subtotal;
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldN_Vahed'] = 'وجود ندارد'; // این بخش باید بر اساس محصول تنظیم شود
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldN_Vahed_Kol'] = ''; // این بخش باید بر اساس محصول تنظیم شود
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldTedad'] = $quantity;
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldTedadKol'] = $quantity;
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldTedadDarKarton'] = 0;
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldTozihat'] = get_post_meta($pdo, $order['ID'], '_customer_note', true);
            $order_data['orderVal.OrderDetails[' . $item_index . '].FldACode_C'] = $order_id;
            $order_data['orderVal.OrderDetails[' . $item_index . '].A_Code'] = $product_id;

            $item_index++;
        }

        // ارسال اطلاعات سفارش
        $order_url = BASE_URL . 'api/orders/save';
        $order_response = file_get_contents($order_url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($order_data)
            ]
        ]));

        if ($order_response === false) {
            error_log('Failed to save order for Order ID: ' . $order_id);
            return;
        }

        // ذخیره وضعیت ارسال به سیستم حسابداری
        update_post_meta($pdo, $order_id, '_sent_to_accounting', 'yes');
    } catch (Exception $e) {
        error_log('Error processing order: ' . $e->getMessage());
    }
}

// تابع جایگزین برای get_post_meta
function get_post_meta($pdo, $post_id, $meta_key, $single = false) {
    $stmt = $pdo->prepare("SELECT meta_value FROM wp_postmeta WHERE post_id = :post_id AND meta_key = :meta_key");
    $stmt->execute([':post_id' => $post_id, ':meta_key' => $meta_key]);
    $result = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $single ? ($result ? $result[0] : null) : $result;
}

// تابع جایگزین برای update_post_meta
function update_post_meta($pdo, $post_id, $meta_key, $meta_value) {
    $stmt = $pdo->prepare("REPLACE INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, :meta_key, :meta_value)");
    $stmt->execute([':post_id' => $post_id, ':meta_key' => $meta_key, ':meta_value' => $meta_value]);
}

// تابع جایگزین برای get_user_meta
function get_user_meta($pdo, $user_id, $meta_key, $single = false) {
    $stmt = $pdo->prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = :user_id AND meta_key = :meta_key");
    $stmt->execute([':user_id' => $user_id, ':meta_key' => $meta_key]);
    $result = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $single ? ($result ? $result[0] : null) : $result;
}

// اجرای تابع برای یک سفارش خاص
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
if ($order_id) {
    send_order_to_accounting_system($order_id, $pdo);
} else {
    error_log('No order ID provided.');
}