<?php

/*
Plugin Name: Stachethemes Orders SKU Search
Description: A WooCommerce extension that allows you to Search Orders by Product SKU in the Admin Panel.
Version: 1.0
Author: Stachethemes
Author URI: https://stachethemes.com
Text Domain: stachethemes-orders-sku-search
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.7
Requires PHP: 8.0
WC requires at least: 9.6
WC tested up to: 9.6.2
*/

if (! defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

class Stachethemes_Orders_SKU_Search {

    public function __construct() {

        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });

        add_action('init', [$this, 'init']);
    }

    public function init() {

        if (!class_exists('WooCommerce') || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }

        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            add_filter('woocommerce_hpos_admin_search_filters', [$this, 'add_hpos_sku_search_filter'], 10);
            add_filter('woocommerce_hpos_generate_where_for_search_filter', [$this, 'generate_hpos_where_for_sku_search'], 10, 4);
        } else {
            add_filter('woocommerce_shop_order_search_results', [$this, 'add_legacy_search_filter'], 10, 3);
        }
    }

    public function add_hpos_sku_search_filter($options) {

        if (!is_array($options)) {
            $options = [];
        }

        $product_sku_option = ['product_sku' => esc_html__('Product SKU', 'stachethemes-orders-sku-search')];

        $product_key_position = array_search('products', array_keys($options));

        if ($product_key_position !== false) {
            $options = array_merge(
                array_slice($options, 0, $product_key_position + 1, true),
                $product_sku_option,
                array_slice($options, $product_key_position + 1, null, true)
            );
        } else {
            $options = array_merge($options, $product_sku_option);
        }

        return $options;
    }

    public function generate_hpos_where_for_sku_search($where, $search_term, $search_filter, $query) {

        global $wpdb;

        $enable_condition = ['product_sku'];

        if (empty($search_term) || !in_array($search_filter, $enable_condition)) {
            return $where;
        }

        $product_ids = wc_get_products(array(
            'sku'    => $search_term,
            'limit'  => -1,
            'return' => 'ids'
        ));

        if (empty($product_ids)) {
            return $where . " 1=0";
        }

        $product_ids     = array_map('intval', $product_ids);
        $placeholders    = implode(',', array_fill(0, count($product_ids), '%d')); // Placeholders for the ids

        $where .= " EXISTS (
                SELECT 1
                FROM %i AS oi
                INNER JOIN %i AS oim
                    ON oi.order_item_id = oim.order_item_id
                WHERE oi.order_id = %i.id
                    AND oim.meta_key = '_product_id'
                    AND oim.meta_value IN (" . $placeholders . ")
            )";

        $where = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
            $where,
            [
                $wpdb->prefix . 'woocommerce_order_items',
                $wpdb->prefix . 'woocommerce_order_itemmeta',
                $wpdb->prefix . 'wc_orders',
                ...$product_ids
            ]
        );

        return $where;
    }

    public function add_legacy_search_filter($order_ids, $term, $search_fields) {

        if (empty($term)) {
            return $order_ids;
        }

        $product_ids = wc_get_products(array(
            'sku'    => $term,
            'limit'  => -1,
            'return' => 'ids'
        ));

        if (!empty($product_ids)) {


            global $wpdb;

            $placeholders = implode(',', array_fill(0, count($product_ids), '%d')); // Placeholders for the ids

            $cache_key = 'orders_with_sku_' . md5(implode('_', $product_ids));

            $orders_with_sku = wp_cache_get($cache_key, 'woocommerce');

            if ($orders_with_sku === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $orders_with_sku = $wpdb->get_col(
                    // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The number of placeholders is correct
                    $wpdb->prepare(
                        "
                        SELECT DISTINCT o.ID
                        FROM %i o
                        INNER JOIN %i oi ON o.ID = oi.order_id
                        INNER JOIN %i oim ON oi.order_item_id = oim.order_item_id
                        WHERE o.post_type = 'shop_order'
                        AND o.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-refunded', 'wc-failed', 'wc-cancelled')
                        AND oim.meta_key = '_product_id'
                        AND oim.meta_value IN ("
                            .
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The placeholders are prepared above
                            $placeholders
                            .
                            ") ",
                        [
                            $wpdb->prefix . 'posts',
                            $wpdb->prefix . 'woocommerce_order_items',
                            $wpdb->prefix . 'woocommerce_order_itemmeta',
                            ...$product_ids
                        ]
                    )
                );

                wp_cache_set($cache_key, $orders_with_sku, 'woocommerce', 3600);
            }

            if ($orders_with_sku) {
                $order_ids = array_merge($order_ids, $orders_with_sku);
                $order_ids = array_unique($order_ids);
            }
        }

        return $order_ids;
    }
}

new Stachethemes_Orders_SKU_Search();
