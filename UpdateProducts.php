<?php

require_once 'constant.php';
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $api_url = BASE_URL . 'updated/products';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Content-Type: application/json\r\n"
        ]
    ]);

    $response = file_get_contents($api_url, false, $context);

    if ($response === FALSE) {
        throw new Exception('Error fetching data from API');
    }

    $response = json_decode($response, true);

    foreach ($response as $article) {
        $sku = $article['A_Code'];
        $stmt = $pdo->prepare("SELECT ID FROM wp_posts WHERE post_type = 'product' AND post_status IN ('publish', 'draft') AND ID IN (SELECT post_id FROM wp_postmeta WHERE meta_key = '_sku' AND meta_value = :sku)");
        $stmt->execute([':sku' => $sku]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $product_id = $product['ID'];

            $price = $article['Sel_Price'];
            $offPrice = $article['PriceTakhfif'] > 0 ? $article['PriceTakhfif'] : $article['Sel_Price'];
            $stock = $article['Exist'];
            $status = $article['IsActive'] == "true" ? 'publish' : 'draft';

            update_product_meta($pdo, $product_id, '_regular_price', $price);
            update_product_meta($pdo, $product_id, '_sale_price', $offPrice);
            update_product_meta($pdo, $product_id, '_stock', $stock);
            update_product_meta($pdo, $product_id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');

            $stmt = $pdo->prepare("UPDATE wp_posts SET post_status = :status WHERE ID = :id");
            $stmt->execute([':status' => $status, ':id' => $product_id]);

            $main_category_id = get_or_create_term($pdo, $article['Main_Category']['M_groupcode'], 'Main Category');
            set_product_categories($pdo, $product_id, [$main_category_id]);

            if (!empty($article['Sub_Category'])) {
                $sub_category_id = get_or_create_term($pdo, $article['Sub_Category']['S_groupcode'], 'Sub Category');
                set_product_categories($pdo, $product_id, [$sub_category_id], true);
            }
        } 
    }
} catch (\PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
} catch (\Exception $e) {
    error_log('API or general error: ' . $e->getMessage());
}

function update_product_meta($pdo, $product_id, $meta_key, $meta_value) {
    $stmt = $pdo->prepare("SELECT meta_id FROM wp_postmeta WHERE post_id = :post_id AND meta_key = :meta_key");
    $stmt->execute([':post_id' => $product_id, ':meta_key' => $meta_key]);
    $meta = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($meta) {
        $stmt = $pdo->prepare("UPDATE wp_postmeta SET meta_value = :meta_value WHERE meta_id = :meta_id");
        $stmt->execute([':meta_value' => $meta_value, ':meta_id' => $meta['meta_id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (:post_id, :meta_key, :meta_value)");
        $stmt->execute([':post_id' => $product_id, ':meta_key' => $meta_key, ':meta_value' => $meta_value]);
    }
}

function slug_generate($title)
{
    if ($title === null) {
        return '';
    }
    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace(
        '/[^a-z0-9\x{0600}-\x{06FF}\x{06F0}-\x{06F9}\s-]/u',
        '',
        $title
    );
    $title = trim(preg_replace('/[\s-]+/u', '-', $title), '-');
    return $title ?: '';
}


function get_or_create_term($pdo, $slug, $name) {
    $stmt = $pdo->prepare("SELECT term_id FROM wp_terms WHERE slug = :slug");
    $stmt->execute([':slug' => slug_generate($slug)]);
    $term = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($term) {
        return $term['term_id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO wp_terms (name, slug) VALUES (:name, :slug)");
        $stmt->execute([':name' => $name, ':slug' => slug_generate($slug)]);
        $term_id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO wp_term_taxonomy (term_id, taxonomy) VALUES (:term_id, 'product_cat')");
        $stmt->execute([':term_id' => $term_id]);

        return $term_id;
    }
}


function set_product_categories($pdo, $product_id, $category_ids, $append = false) {
    if (!$append) {
        $stmt = $pdo->prepare("DELETE FROM wp_term_relationships WHERE object_id = :object_id");
        $stmt->execute([':object_id' => $product_id]);
    }

    foreach ($category_ids as $category_id) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (:object_id, :term_taxonomy_id)");
        $stmt->execute([':object_id' => $product_id, ':term_taxonomy_id' => $category_id]);
    }
}

