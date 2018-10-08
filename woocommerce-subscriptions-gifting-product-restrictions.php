<?php
/**
 * Plugin Name: WooCommerce Subscriptions Gifting - Product Restrictions
 * Plugin URI: https://github.com/Prospress/woocommerce-subscriptions-gifting-product-restrictions
 * Description: A mini-extension to enable gifting functionality only on certain products
 * Author: Prospress Inc.
 * Author URI: https://prospress.com/
 * Version: 1.0
 * License: GPLv3
 *
 * GitHub Plugin URI: Prospress/woocommerce-subscriptions-gifting-product-restrictions
 * GitHub Branch: master
 *
 * Copyright 2018 Prospress, Inc.  (email : freedoms@prospress.com)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Main plugin class.
 */
final class WCS_Gifting_Product_Restrictions {

    /** @var WCS_Gifting_Product_Restrictions The single instance of this class */
    private static $instance = null;

    /** @var bool Flag used to determine whether metadata has been saved after an admin-side save */
    private $meta_saved = false;


    /**
     * Returns the single instance of the main plugin class.
     *
     * @return WCS_Gifting_Product_Restrictions
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'init' ) );
    }

    /**
     * Initializes plugin functionality when WordPress initializes.
     */
    public function init() {
        if ( ! class_exists( 'WCSG_Checkout' ) ) {
            return;
        }

        add_filter( 'woocommerce_subscription_settings', array( $this, 'add_settings' ), 11 );
        add_filter( 'wcsg_is_giftable_product', array( $this, 'is_product_giftable' ), 10, 2 );
        add_action( 'woocommerce_subscriptions_product_options_advanced', array( $this, 'add_product_level_override_setting' ), 11 );
        add_action( 'save_post', array( $this, 'maybe_save_giftable_meta' ), 12 );
    }

    /**
     * Adds plugin settings to the "Gifting Subscriptions" section inside the "Subscriptions" settings tab.
     *
     * Hooked to the `woocommerce_subscription_settings` action hook.
     *
     * @param  array $settings Current Subscriptions settings.
     * @return array
     */
    public function add_settings( $settings ) {
        WC_Subscriptions_Admin::insert_setting_after(
            $settings,
            WCSG_Admin::$option_prefix . '_gifting_checkbox_text',
            array(
                'id'       => 'wcsgr_giftable_by_default',
                'name'     => __( 'Giftable Products', 'woocommerce-subscriptions-gifting-product-restrictions' ),
                'desc'     => __( 'Allow customers to gift all subscription products by default.', 'woocommerce-subscriptions-gifting-product-restrictions' ),
                'desc_tip' => sprintf( __( 'You can make specific subscription products giftable from the product edit screen. %sLearn more%s.', 'woocommerce-subscription-gifting-product-restrictions' ), '<a href="https://github.com/Prospress/woocommerce-subscriptions-gifting-product-restrictions/blob/master/README.md">', '</a>' ),
                'type'     => 'checkbox',
                'default'  => 'yes'
            )
        );

        return $settings;
    }

    /**
     * Determines whether a given product is giftable or not.
     *
     * Hooked to the `wcsg_is_giftable_product` filter.
     *
     * @param  bool $is_giftable
     * @param  WC_Product $product The product to check.
     * @return bool
     */
    public function is_product_giftable( $is_giftable, $product ) {
        // This is already non-giftable for a reason.
        if ( ! $is_giftable ) {
            return false;
        }

        $product_setting = get_post_meta( $product->get_id(), '_wcsgr_is_giftable', true );

        if ( ! in_array( $product_setting, array( 'yes', 'no' ) ) ) {
            $is_giftable = ( 'yes' === get_option( 'wcsgr_giftable_by_default', 'yes' ) );
        } else {
            $is_giftable = ( 'yes' === $product_setting );
        }

        return $is_giftable;
    }

    /**
     * Adds a setting to the "Advanced" tab on a product's edit screen to alter the gifting behavior for the product.
     *
     * Hooked to the `woocommerce_subscriptions_product_options_advanced` action hook.
     */
    public function add_product_level_override_setting() {
        global $post;

        echo '<div class="options_group is_product_giftable show_if_subscription show_if_variable-subscription">';
        woocommerce_wp_select(
            array(
                'id'      => '_wcsgr_is_giftable',
                'label'   => __( 'Gifting policy', 'woocommerce-subscriptions-gifting-product-restrictions' ),
                'options' => array(
                    ''    => __( 'Follow global setting', 'woocommerce-subscriptions-gifting-product-restrictions' ),
                    'yes' => __( 'Giftable', 'woocommerce-subscriptions-gifting-product-restrictions' ),
                    'no'  => __( 'Not giftable', 'woocommerce-subscriptions-gifting-product-restrictions' ),
                ),
                'value'   => get_post_meta( $post->ID, '_wcsgr_is_giftable', true )
            )
        );
        echo '</div>';
    }

    /**
     * Updates the giftable metadata when a subscription product is saved admin-side.
     *
     * Hooked to the `save_post` action hook.
     *
     * @param int $post_id The ID for the post being saved.
     */
    public function maybe_save_giftable_meta( $post_id ) {
        if ( empty( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( $_POST['_wcsnonce'], 'wcs_subscription_meta' ) || ! WC_Subscriptions_Product::is_subscription( $post_id ) || $this->meta_saved ) {
            return;
        }

        $posted_value = ! empty( $_REQUEST['_wcsgr_is_giftable'] ) ? stripslashes( $_REQUEST['_wcsgr_is_giftable'] ) : '';
        if ( in_array( $posted_value, array( 'yes', 'no' ) ) ) {
            update_post_meta( $post_id, '_wcsgr_is_giftable', $posted_value );
        } else {
            delete_post_meta( $post_id, '_wcsgr_is_giftable' );
        }

        $this->meta_saved = true;
    }

}

add_action( 'plugins_loaded', array( 'WCS_Gifting_Product_Restrictions', 'instance' ) );
