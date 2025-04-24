<?php
/**
 * Plugin Name: WooCommerce Bulk Tickets Discount
 * Plugin URI: https://solutions.techscape.pk
 * Description: Adds bulk discount for tickets (product ID 5460) and dynamic ticket assignment for products based on price. Offers Entry Selection 2 for free on any purchase.
 * Version: 1.1.0
 * Author: Sajid Sdk
 * Author URI: https://solutions.techscape.pk
 * License: GPL-2.0+
 * Text Domain: wc-bulk-tickets
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('WCBTD_VERSION', '1.1.0');

// Initialize plugin
class WCBTD_Plugin {
    private static $wcbtd_instance = null;
    private $wcbtd_ticket_product_id = 5460; // Entry Selection 2 product ID

    public static function wcbtd_get_instance() {
        if (null === self::$wcbtd_instance) {
            self::$wcbtd_instance = new self();
        }
        return self::$wcbtd_instance;
    }

    private function __construct() {
        // Register hooks
        add_action('wp_enqueue_scripts', [$this, 'wcbtd_enqueue_assets']);
        add_action('woocommerce_cart_calculate_fees', [$this, 'wcbtd_apply_bulk_ticket_discount'], 10, 1);
        add_filter('woocommerce_cart_item_name', [$this, 'wcbtd_add_free_tickets_info_to_name'], 10, 3);
        add_filter('woocommerce_cart_item_name', [$this, 'wcbtd_add_tickets_button_to_cart_item'], 20, 3);
        add_action('woocommerce_after_cart_item_name', [$this, 'wcbtd_add_ticket_source_info'], 10, 2);
        add_action('woocommerce_add_to_cart', [$this, 'wcbtd_bulk_tickets_added_notice'], 20, 3);
        add_action('woocommerce_before_single_product', [$this, 'wcbtd_display_bulk_tickets_offer']);
        add_action('wp_ajax_wcbtd_add_tickets_to_cart', [$this, 'wcbtd_ajax_add_tickets_to_cart']);
        add_action('wp_ajax_nopriv_wcbtd_add_tickets_to_cart', [$this, 'wcbtd_ajax_add_tickets_to_cart']);
        // New hooks for free Entry Selection 2
        add_action('woocommerce_cart_calculate_fees', [$this, 'wcbtd_apply_free_entry_selection_discount'], 20, 1);
        add_action('woocommerce_cart_updated', [$this, 'wcbtd_ensure_free_entry_selection']);
    }

    public function wcbtd_enqueue_assets() {
        if (is_cart() || is_product()) {
            // Enqueue a dummy style handle for inline CSS
            wp_enqueue_style('wcbtd-bulk-tickets', false);
            $wcbtd_css = '
                .wcbtd-bulk-offer-info {
                    margin-bottom: 20px;
                    background-color: #f8f8f8;
                    border-left: 4px solid #7ad03a;
                    padding: 1em;
                }
                .wcbtd-bulk-offer-info h4 {
                    margin: 0 0 10px 0;
                }
                .wcbtd-bulk-offer-info ul {
                    list-style: none;
                    margin: 0;
                    padding: 0;
                }
                .wcbtd-free-ticket-info {
                    display: block;
                    font-size: 0.9em;
                    color: #4CAF50;
                    margin-top: 5px;
                }
                .wcbtd-add-tickets-button {
                    margin-top: 10px;
                }
                .wcbtd-ticket-source-info {
                    font-size: 0.9em;
                    color: #555;
                    margin-top: 5px;
                }
            ';
            wp_add_inline_style('wcbtd-bulk-tickets', $wcbtd_css);

            // Enqueue jQuery and inline script
            wp_enqueue_script('jquery');
            wp_enqueue_script('wcbtd-bulk-tickets', false, ['jquery'], WCBTD_VERSION, true);
            $wcbtd_js = '
                (function($) {
                    $(document).ready(function() {
                        $(".wcbtd-add-tickets-button a").on("click", function(e) {
                            e.preventDefault();
                            var $button = $(this);
                            var quantity = $button.data("wcbtd-quantity");

                            $.ajax({
                                url: WCBTDTicketsAjax.ajaxurl,
                                type: "POST",
                                data: {
                                    action: "wcbtd_add_tickets_to_cart",
                                    nonce: WCBTDTicketsAjax.nonce,
                                    quantity: quantity
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert(response.data.message);
                                        $.ajax({
                                            url: wc_cart_fragments_params.wc_ajax_url.toString().replace("%%endpoint%%", "get_refreshed_fragments"),
                                            type: "POST",
                                            success: function(data) {
                                                $(document.body).trigger("wc_fragment_refresh");
                                            }
                                        });
                                    } else {
                                        alert(response.data.message);
                                    }
                                },
                                error: function() {
                                    alert("Error adding tickets. Please try again.");
                                }
                            });
                        });
                    });
                })(jQuery);
            ';
            wp_add_inline_script('wcbtd-bulk-tickets', $wcbtd_js);
            wp_localize_script(
                'wcbtd-bulk-tickets',
                'WCBTDTicketsAjax',
                [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wcbtd_add_tickets_nonce')
                ]
            );
        }
    }

    /**
     * Calculate how many tickets to make free based on the tier
     */
    public function wcbtd_get_bulk_free_tickets($wcbtd_quantity) {
        if ($wcbtd_quantity <= 10) {
            return 0;
        } elseif ($wcbtd_quantity <= 49) {
            return min($wcbtd_quantity - 10, 10);
        } elseif ($wcbtd_quantity <= 200) {
            return min($wcbtd_quantity - 50, 150);
        }
        return 150;
    }

    /**
     * Get the total quantity of the target product in cart
     */
    public function wcbtd_get_tickets_quantity_in_cart() {
        static $wcbtd_quantity = null;
        if (null !== $wcbtd_quantity) {
            return $wcbtd_quantity;
        }

        $wcbtd_quantity = 0;
        if (!WC()->cart || WC()->cart->is_empty()) {
            return $wcbtd_quantity;
        }

        foreach (WC()->cart->get_cart() as $wcbtd_cart_item) {
            if ($wcbtd_cart_item['product_id'] == $this->wcbtd_ticket_product_id) {
                $wcbtd_quantity += $wcbtd_cart_item['quantity'];
            }
        }

        return $wcbtd_quantity;
    }

    /**
     * Calculate number of tickets for a product based on its price
     */
    public function wcbtd_get_tickets_for_product($wcbtd_product) {
        if (!$wcbtd_product || $wcbtd_product->get_id() == $this->wcbtd_ticket_product_id) {
            return 0;
        }

        $wcbtd_price = $wcbtd_product->get_price();
        return max(1, floor($wcbtd_price / 10));
    }

    /**
     * Apply bulk discount for tickets
     */
    public function wcbtd_apply_bulk_ticket_discount($wcbtd_cart) {
        if (is_admin() && !defined('DOING_AJAX') || !WC()->cart) {
            return;
        }

        $wcbtd_total_quantity = $this->wcbtd_get_tickets_quantity_in_cart();
        $wcbtd_free_tickets = $this->wcbtd_get_bulk_free_tickets($wcbtd_total_quantity);

        if ($wcbtd_free_tickets <= 0) {
            return;
        }

        // Get the ticket price
        $wcbtd_ticket_price = 0;
        foreach ($wcbtd_cart->get_cart() as $wcbtd_cart_item) {
            if ($wcbtd_cart_item['product_id'] == $this->wcbtd_ticket_product_id) {
                $wcbtd_ticket_price = $wcbtd_cart_item['data']->get_price();
                break;
            }
        }

        if ($wcbtd_ticket_price <= 0) {
            return;
        }

        // Calculate discount
        $wcbtd_discount_amount = $wcbtd_free_tickets * $wcbtd_ticket_price;

        $wcbtd_cart->add_fee(
            esc_html(sprintf(
                __('%d Free Tickets Discount', 'wc-bulk-tickets'),
                $wcbtd_free_tickets
            )),
            -$wcbtd_discount_amount,
            true
        );
    }

    /**
     * Ensure one Entry Selection 2 is added to the cart if any other product is present
     */
    public function wcbtd_ensure_free_entry_selection() {
        if (is_admin() && !defined('DOING_AJAX') || !WC()->cart) {
            return;
        }

        $wcbtd_cart = WC()->cart;
        $wcbtd_has_non_ticket_product = false;
        $wcbtd_entry_selection_quantity = 0;
        $wcbtd_entry_selection_cart_key = null;

        // Check for non-ticket products and count Entry Selection 2
        foreach ($wcbtd_cart->get_cart() as $wcbtd_cart_key => $wcbtd_cart_item) {
            if ($wcbtd_cart_item['product_id'] != $this->wcbtd_ticket_product_id) {
                $wcbtd_has_non_ticket_product = true;
            } elseif ($wcbtd_cart_item['product_id'] == $this->wcbtd_ticket_product_id) {
                $wcbtd_entry_selection_quantity += $wcbtd_cart_item['quantity'];
                $wcbtd_entry_selection_cart_key = $wcbtd_cart_key;
            }
        }

        // If there's a non-ticket product and no Entry Selection 2, add one
        if ($wcbtd_has_non_ticket_product && $wcbtd_entry_selection_quantity == 0) {
            $wcbtd_cart->add_to_cart($this->wcbtd_ticket_product_id, 1);
            wc_add_notice(__('Entry Selection 2 has been added to your cart for free!', 'wc-bulk-tickets'), 'success');
        }

        // If there's a non-ticket product and more than one Entry Selection 2, reduce to one
        if ($wcbtd_has_non_ticket_product && $wcbtd_entry_selection_quantity > 1 && $wcbtd_entry_selection_cart_key) {
            $wcbtd_cart->set_quantity($wcbtd_entry_selection_cart_key, 1);
        }

        // If there are no non-ticket products but Entry Selection 2 exists, remove it
        if (!$wcbtd_has_non_ticket_product && $wcbtd_entry_selection_quantity > 0 && $wcbtd_entry_selection_cart_key) {
            $wcbtd_cart->remove_cart_item($wcbtd_entry_selection_cart_key);
            wc_add_notice(__('Entry Selection 2 has been removed as no qualifying products are in your cart.', 'wc-bulk-tickets'), 'notice');
        }
    }

    /**
     * Apply $2 discount to make Entry Selection 2 free
     */
    public function wcbtd_apply_free_entry_selection_discount($wcbtd_cart) {
        if (is_admin() && !defined('DOING_AJAX') || !WC()->cart) {
            return;
        }

        $wcbtd_has_non_ticket_product = false;
        $wcbtd_entry_selection_quantity = 0;

        // Check for non-ticket products and Entry Selection 2
        foreach ($wcbtd_cart->get_cart() as $wcbtd_cart_item) {
            if ($wcbtd_cart_item['product_id'] != $this->wcbtd_ticket_product_id) {
                $wcbtd_has_non_ticket_product = true;
            } elseif ($wcbtd_cart_item['product_id'] == $this->wcbtd_ticket_product_id) {
                $wcbtd_entry_selection_quantity += $wcbtd_cart_item['quantity'];
            }
        }

        // Apply $2 discount if conditions are met
        if ($wcbtd_has_non_ticket_product && $wcbtd_entry_selection_quantity > 0) {
            $wcbtd_discount_amount = 2.00; // $2 discount for Entry Selection 2
            $wcbtd_cart->add_fee(
                esc_html__('Free Entry Selection 2 Discount', 'wc-bulk-tickets'),
                -$wcbtd_discount_amount,
                true
            );
        }
    }

    /**
     * Add info about free tickets to cart item name
     */
    public function wcbtd_add_free_tickets_info_to_name($wcbtd_name, $wcbtd_cart_item, $wcbtd_cart_item_key) {
        if ($wcbtd_cart_item['product_id'] != $this->wcbtd_ticket_product_id) {
            return $wcbtd_name;
        }

        $wcbtd_total_quantity = $this->wcbtd_get_tickets_quantity_in_cart();
        $wcbtd_free_tickets = $this->wcbtd_get_bulk_free_tickets($wcbtd_total_quantity);

        $wcbtd_has_non_ticket_product = false;
        foreach (WC()->cart->get_cart() as $wcbtd_item) {
            if ($wcbtd_item['product_id'] != $this->wcbtd_ticket_product_id) {
                $wcbtd_has_non_ticket_product = true;
                break;
            }
        }

        $wcbtd_message = '';
        if ($wcbtd_has_non_ticket_product && $wcbtd_cart_item['quantity'] == 1) {
            $wcbtd_message .= esc_html__('(Free with purchase)', 'wc-bulk-tickets');
        }
        if ($wcbtd_free_tickets > 0) {
            $wcbtd_message .= $wcbtd_message ? ' ' : '';
            $wcbtd_message .= sprintf(
                __('(%d tickets free with bulk discount)', 'wc-bulk-tickets'),
                $wcbtd_free_tickets
            );
        }

        if ($wcbtd_message) {
            return $wcbtd_name . sprintf(' <span class="wcbtd-free-ticket-info">%s</span>', esc_html($wcbtd_message));
        }

        return $wcbtd_name;
    }

    /**
     * Add button in cart to add tickets for a product
     */
    public function wcbtd_add_tickets_button_to_cart_item($wcbtd_cart_item_name, $wcbtd_cart_item, $wcbtd_cart_item_key) {
        if ($wcbtd_cart_item['product_id'] == $this->wcbtd_ticket_product_id) {
            return $wcbtd_cart_item_name;
        }

        $wcbtd_product = wc_get_product($wcbtd_cart_item['product_id']);
        if (!$wcbtd_product) {
            return $wcbtd_cart_item_name;
        }

        $wcbtd_ticket_quantity = $this->wcbtd_get_tickets_for_product($wcbtd_product) * $wcbtd_cart_item['quantity'];
        if ($wcbtd_ticket_quantity <= 0) {
            return $wcbtd_cart_item_name;
        }

        $wcbtd_button = sprintf(
            '<div class="wcbtd-add-tickets-button"><a href="#" class="button" data-wcbtd-quantity="%d" data-wcbtd-cart-item-key="%s">%s</a></div>',
            esc_attr($wcbtd_ticket_quantity),
            esc_attr($wcbtd_cart_item_key),
            esc_html(sprintf(__('Add %d Ticket(s) for this item', 'wc-bulk-tickets'), $wcbtd_ticket_quantity))
        );

        return $wcbtd_cart_item_name . $wcbtd_button;
    }

    /**
     * Add ticket source info to ticket cart item
     */
    public function wcbtd_add_ticket_source_info($wcbtd_cart_item, $wcbtd_cart_item_key) {
        if ($wcbtd_cart_item['product_id'] != $this->wcbtd_ticket_product_id) {
            return;
        }

        $wcbtd_total_quantity = $this->wcbtd_get_tickets_quantity_in_cart();
        $wcbtd_free_tickets = $this->wcbtd_get_bulk_free_tickets($wcbtd_total_quantity);

        if ($wcbtd_free_tickets > 0) {
            echo '<div class="wcbtd-ticket-source-info">';
            echo esc_html(sprintf(
                __('Includes %d free ticket(s) due to bulk discount.', 'wc-bulk-tickets'),
                $wcbtd_free_tickets
            ));
            echo '</div>';
        }
    }

    /**
     * Display bulk ticket offers on product page
     */
    public function wcbtd_display_bulk_tickets_offer() {
        global $wcbtd_product;

        if (!$wcbtd_product || $wcbtd_product->get_id() != $this->wcbtd_ticket_product_id) {
            return;
        }

        $wcbtd_current_quantity = $this->wcbtd_get_tickets_quantity_in_cart();
        $wcbtd_free_tickets = $this->wcbtd_get_bulk_free_tickets($wcbtd_current_quantity);

        echo '<div class="wcbtd-bulk-offer-info">';
        echo '<h4>' . esc_html__('Special Bulk Purchase Offers:', 'wc-bulk-tickets') . '</h4>';
        echo '<ul>';
        echo '<li>' . esc_html__('✓ Buy 11–49 tickets, get up to 10 additional tickets free!', 'wc-bulk-tickets') . '</li>';
        echo '<li>' . esc_html__('✓ Buy at least 50 tickets, get up to 150 additional tickets free!', 'wc-bulk-tickets') . '</li>';
        echo '<li>' . esc_html__('✓ Any tickets beyond 200 are at regular price.', 'wc-bulk-tickets') . '</li>';
        echo '</ul>';
        echo '</div>';

        if ($wcbtd_free_tickets > 0) {
            echo '<div class="woocommerce-info">';
            echo esc_html(sprintf(
                __('Based on your current cart (%d tickets), you\'ll get %d FREE ticket(s)!', 'wc-bulk-tickets'),
                $wcbtd_current_quantity,
                $wcbtd_free_tickets
            ));
            echo '</div>';
        }
    }

    /**
     * Add notice when adding tickets to cart
     */
    public function wcbtd_bulk_tickets_added_notice($wcbtd_cart_item_key, $wcbtd_product_id, $wcbtd_quantity) {
        if ($wcbtd_product_id != $this->wcbtd_ticket_product_id) {
            return;
        }

        $wcbtd_total_quantity = $this->wcbtd_get_tickets_quantity_in_cart();
        $wcbtd_free_tickets = $this->wcbtd_get_bulk_free_tickets($wcbtd_total_quantity);

        if ($wcbtd_free_tickets > 0) {
            $wcbtd_message = sprintf(
                __('You now have %d tickets in your cart and get %d free! Buy 50 and get up to 150 more free. Additional tickets beyond 200 are regular price.', 'wc-bulk-tickets'),
                $wcbtd_total_quantity,
                $wcbtd_free_tickets
            );
            wc_add_notice($wcbtd_message, 'success');
        }
    }

    /**
     * AJAX handler to add tickets to cart
     */
    public function wcbtd_ajax_add_tickets_to_cart() {
        check_ajax_referer('wcbtd_add_tickets_nonce', 'nonce');

        $wcbtd_product_id = $this->wcbtd_ticket_product_id;
        $wcbtd_quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 0;

        if ($wcbtd_quantity > 0) {
            WC()->cart->add_to_cart($wcbtd_product_id, $wcbtd_quantity);
            wp_send_json_success(['message' => __('Tickets successfully added to cart!', 'wc-bulk-tickets')]);
        } else {
            wp_send_json_error(['message' => __('Invalid quantity.', 'wc-bulk-tickets')]);
        }
    }
}

// Initialize plugin
WCBTD_Plugin::wcbtd_get_instance();
?>