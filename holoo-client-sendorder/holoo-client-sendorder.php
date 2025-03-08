<?php
/**
 * Plugin Name: Send Order To Accounting
 * Plugin URI: https://example.com
 * Description: This plugin sends customer and order information to the accounting system after an order is completed in WooCommerce.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL2
 */

// جلوگیری از بارگذاری مستقیم فایل
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once 'constant.php';

// تابع برای ارسال اطلاعات مشتری به حسابداری
function send_customer_to_accounting($order_id) {
    $order = wc_get_order($order_id);
    $user = $order->get_user();
    $mobile = get_user_meta($user->ID, 'billing_phone', true);

    if (!$mobile) {
        return;
    }

    // چک می‌کنیم که آیا مشتری قبلاً در وردپرس ثبت شده است یا نه
    if (!is_customer_exist_in_wordpress($mobile)) {
        // اطلاعات مشتری برای ارسال به API
        $ostan = $order->get_billing_state();
        $city = $order->get_billing_city();
        $address = 'استان: ' . $ostan . ' شهر: ' . $city . ' | ' . $order->get_billing_address_1() . ' کدپستی: ' . $order->get_shipping_postcode();

        $formParams = [
            'phoneNumber' => $mobile,
            'fullName' => $user->get_full_name(),
            'address' => $address,
            'createDate' => time(),
            'fldFeeTip' => ''
        ];

        $url = BASE_URL . 'customer/register';
        $response = send_post_request($url, $formParams);
    } else {
        // مشتری قبلاً در وردپرس ثبت شده است، نیاز به ارسال اطلاعات جدید نیست
        return;
    }
}

// تابع برای چک کردن اینکه آیا مشتری قبلاً در دیتابیس وردپرس موجود است یا نه
function is_customer_exist_in_wordpress($mobile) {
    // چک می‌کنیم که آیا مشتری با شماره موبایل یا نام کاربری مشخص در وردپرس موجود است یا نه
    $user = get_user_by('login', $mobile);  // جستجو بر اساس نام کاربری (موبایل در اینجا نام کاربری است)

    if ($user) {
        return true; // مشتری موجود است
    }

    // در صورتی که نتواستیم کاربر را پیدا کنیم، می‌توانیم جستجو را برای موبایل در فیلدهای اضافی انجام دهیم
    $args = [
        'meta_key' => 'billing_phone',  // جستجو بر اساس شماره موبایل
        'meta_value' => $mobile,
        'fields' => 'ID'  // فقط ID کاربر را برگردانیم
    ];

    $user_query = new WP_User_Query($args);
    if (!empty($user_query->results)) {
        return true; // مشتری موجود است
    }

    return false; // مشتری موجود نیست
}

// تابع برای ارسال درخواست POST با استفاده از file_get_contents
function send_post_request($url, $data) {
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 60
        ]
    ];

    $context  = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === FALSE) {
        // Handle error
        return null;
    }

    return json_decode($response);
}

// تابع برای دریافت شناسه تراکنش
function get_transaction_id($order) {
    // فرض بر این است که شما یک متد برای دریافت شناسه تراکنش در نظر دارید
    // در غیر این صورت می‌توانید آن را بر اساس نیاز خود تغییر دهید
    return $order->get_meta('transaction_id');
}

// تابع برای ارسال سفارش به حسابداری
function send_order_to_accounting($order_id) {
    $order = wc_get_order($order_id);
    // چک می‌کنیم که آیا سفارش قبلاً به حسابداری ارسال شده است یا نه
    if ($order->get_meta('_send_to_accounting') == 0 or empty($order->get_meta('_send_to_accounting'))) {
        // حذف GuzzleHttp\Client و استفاده از file_get_contents

        $ostan = $order->get_billing_state();
        $city = $order->get_billing_city();
        $address = 'استان: ' . $ostan . ' شهر: ' . $city . ' | ' . $order->get_billing_address_1() . ' کدپستی: ' . $order->get_shipping_postcode();

        $FldTozihat = $order->get_meta('description') . ' هزینه ارسال: ' . $order->get_shipping_total();
        
        $formParams = [
            'orderVal.OrderTitle.FldMobile' => $order->get_billing_phone(),
            'orderVal.OrderTitle.FldTotalFaktor' => $order->get_total() - $order->get_shipping_total(),
            'orderVal.OrderTitle.FldTakhfifVizhe' => 0,
            'orderVal.OrderTitle.FldTozihFaktor' => $FldTozihat,
            'orderVal.OrderTitle.FldAddress' => $address,
            'orderVal.OrderTitle.FldPayId' => get_transaction_id($order),
        ];


        // اضافه کردن آیتم‌های سفارش
        foreach ($order->get_items() as $itemRow => $item) {
            if ($item->get_type() === 'line_item') {
                $product = $item->get_product();
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldC_Kala'] = $product->get_meta('fldC_Kala');
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldN_Kala'] = $product->get_name();
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldFee'] = $item->get_total();
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldFeeBadAzTakhfif'] = $item->get_total();
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldN_Vahed'] = $product->get_meta('vahed') ?: 'وجود ندارد';
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldN_Vahed_Kol'] = $product->get_meta('vahed_kol');
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldTedad'] = $item->get_quantity();
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldTedadKol'] = $item->get_quantity();
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldTedadDarKarton'] = 0;
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldTozihat'] = $order->get_meta('description');
                $formParams['orderVal.OrderDetails[' . $itemRow . '].FldACode_C'] = $product->get_meta('_A_Code');
                $formParams['orderVal.OrderDetails[' . $itemRow . '].A_Code'] = $product->get_meta('_A_Code');
            }
        }


        $url = BASE_URL . 'orders/save';
        $response = send_post_request($url, $formParams);

        // به‌روزرسانی وضعیت ارسال به حسابداری
        $order->update_meta_data('_send_to_accounting', 1);
        $order->save();
    }
}

// استفاده از توابع در هر لحظه
add_action('woocommerce_order_status_completed', function($order_id) {
    send_customer_to_accounting($order_id);
    send_order_to_accounting($order_id);
});