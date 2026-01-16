<?php
/**
 * Plugin Name: PBB Gift Certificates (WooCommerce + Redemption)
 * Description: Redeem Paw B&B gift certificates at WooCommerce checkout using the certificate number (PBB-00001 style). Uses Flamingo as the source-of-truth for initial amount on first redemption, then tracks remaining balance in a custom table.
 * Version: 1.0.0
 * Author: GloTree Solutions
 *
 * INSTALL:
 * 1) Create folder: /wp-content/plugins/pbb-gift-certificates/
 * 2) Put THIS file in that folder as: /wp-content/plugins/pbb-gift-certificates/pbb-gift-certificates.php
 * 3) Activate in WP Admin -> Plugins
 *
 * REQUIREMENTS:
 * - WooCommerce
 *  * - Flamingo
 *
 * WHAT THIS PLUGIN DOES:
 * Redeem at checkout:
 at checkout:
 *    - Adds a "Gift Certificate Number" field at checkout + an "Apply" button.
 *    - Applies a discount up to the available remaining balance.
 *    - On first use of a certificate, it looks up the original amount from Flamingo (serial_number meta)
 *      and creates a record in the DB.
 *    - Tracks remaining balance in a custom DB table (NOT in Flamingo).
 *
 * IMPORTANT:
 * - This does NOT use Woo coupons.
 * - This does NOT require paid gift-card plugins.
 */

/** =========================
 *  0) SETTINGS (EDIT THESE)
 *  ========================= */
define('PBB_GC_PREFIX', 'PBB-');    // Certificate code prefix
define('PBB_GC_PAD', 5);            // PBB-00001 padding length
define('PBB_GC_ENABLE_ISSUE', false);
if (PBB_GC_ENABLE_ISSUE) {
  add_action('woocommerce_order_status_processing', 'pbb_gc_issue_after_payment', 30, 1);
  add_action('woocommerce_order_status_completed',  'pbb_gc_issue_after_payment', 30, 1);
}



// Your gift cert product IDs + variation IDs (based on what you gave)
function pbb_gc_allowed_product_ids(): array {
	return [4666]; // parent product
}
function pbb_gc_allowed_variation_ids(): array {
	// Variations you listed + the new one you added (4867)
	return [4668, 4669, 4670, 4671, 4867];
}

/** =========================
 *  1) ACTIVATION: CREATE DB TABLE
 *  ========================= */
register_activation_hook(__FILE__, function () {
	global $wpdb;
	$table = $wpdb->prefix . 'pbb_gc_balances';
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		cert_code VARCHAR(32) NOT NULL,
		serial_raw BIGINT UNSIGNED NOT NULL DEFAULT 0,
		original_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		currency VARCHAR(8) NOT NULL DEFAULT 'USD',
		flamingo_post_id BIGINT UNSIGNED NULL,
		last_order_id BIGINT UNSIGNED NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'active',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY cert_code (cert_code),
		KEY serial_raw (serial_raw)
	) {$charset};";

	dbDelta($sql);
});

/** =========================
 *  2) SMALL UTILITIES
 *  ========================= */
function pbb_gc_money_to_decimal($value): float {
	// Accept: "$50", "50", "50.00"
	$v = trim((string)$value);
	$v = preg_replace('/[^0-9.]/', '', $v);
	return (float)$v;
}

function pbb_gc_decimal_to_money(float $amount): string {
	return '$' . number_format(max(0, $amount), 2);
}

function pbb_gc_normalize_code(string $code): string {
	$code = strtoupper(trim($code));
	$code = preg_replace('/\s+/', '', $code);
	return $code;
}

function pbb_gc_code_to_serial_raw(string $code): int {
	// Accepts: PBB-00055 -> 55, or just "55"
	$code = pbb_gc_normalize_code($code);

	// remove prefix if present
	if (strpos($code, strtoupper(PBB_GC_PREFIX)) === 0) {
		$code = substr($code, strlen(PBB_GC_PREFIX));
	}
	$code = preg_replace('/[^0-9]/', '', $code);
	return (int)$code;
}

function pbb_gc_serial_to_code(int $serial_raw): string {
	return strtoupper(PBB_GC_PREFIX) . str_pad((string)$serial_raw, PBB_GC_PAD, '0', STR_PAD_LEFT);
}

function pbb_gc_cart_has_gift_cert(): bool {
	if (!function_exists('WC') || !WC()->cart) return false;

	$allowed_products   = pbb_gc_allowed_product_ids();
	$allowed_variations = pbb_gc_allowed_variation_ids();

	foreach (WC()->cart->get_cart() as $cart_item) {
		$product = $cart_item['data'] ?? null;
		if (!$product) continue;

		$pid = (int)$product->get_id();
		$vid = (int)($cart_item['variation_id'] ?? 0);

		if (in_array($pid, $allowed_products, true) || in_array($vid, $allowed_variations, true)) {
			return true;
		}
	}
	return false;
}

function pbb_gc_order_has_gift_cert(WC_Order $order): bool {
	$allowed_products   = pbb_gc_allowed_product_ids();
	$allowed_variations = pbb_gc_allowed_variation_ids();

	foreach ($order->get_items() as $item) {
		$pid = (int)$item->get_product_id();
		$vid = (int)$item->get_variation_id();
		if (in_array($pid, $allowed_products, true) || in_array($vid, $allowed_variations, true)) {
			return true;
		}
	}
	return false;
}

/**
 * Determine certificate value from the order line item:
 * - Uses the gift certificate item subtotal divided by qty (so it works for ANY new variation).
 */
function pbb_gc_get_order_gift_amount(WC_Order $order): ?float {
	$allowed_products   = pbb_gc_allowed_product_ids();
	$allowed_variations = pbb_gc_allowed_variation_ids();

	foreach ($order->get_items() as $item) {
		$pid = (int)$item->get_product_id();
		$vid = (int)$item->get_variation_id();
		if (!in_array($pid, $allowed_products, true) && !in_array($vid, $allowed_variations, true)) {
			continue;
		}

		$qty = max(1, (int)$item->get_quantity());
		$subtotal = (float)$item->get_subtotal(); // before tax
		$per = $subtotal / $qty;

		if ($per > 0) return $per;
	}
	return null;
}


/** =========================
 *  4) REDEEM: Checkout field + apply + deduct balance
 *  =========================
 *
 * IMPORTANT:
 * - The Apply/Remove buttons MUST NOT submit the whole checkout form.
 * - We use AJAX to store the applied certificate in WC session, then we trigger an update_checkout().
 */

/**
 * Add a checkout field + Apply/Remove buttons.
 * Shows on checkout ONLY (not with PayPal "express" buttons on product page).
 */
add_action('woocommerce_review_order_before_payment', function () {

    if (!function_exists('WC') || !WC()->cart) return;

    // Optional: hide redeem box while customer is BUYING a gift certificate
    if (function_exists('pbb_gc_cart_has_gift_cert') && pbb_gc_cart_has_gift_cert()) return;

    $nonce = wp_create_nonce('pbb_gc_apply');

    echo '<div class="pbb-gc-redeem" style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:8px;">';
    echo '<h3 style="margin:0 0 8px;">Have a Paw B&amp;B Gift Certificate?</h3>';

    $applied = WC()->session ? WC()->session->get('pbb_gc_applied_code') : '';
    $applied = $applied ? esc_html($applied) : '';

    echo '<p style="margin:0 0 8px;">Enter your certificate number (example: ' . esc_html(pbb_gc_serial_to_code(1)) . ')</p>';

    echo '<div style="display:flex;gap:8px;align-items:center;max-width:560px;">';
    echo '<input type="text" id="pbb_gc_code" name="pbb_gc_code" placeholder="PBB-00001" value="' . $applied . '" style="flex:1;min-width:220px;" />';
    echo '<button type="button" id="pbb_gc_apply_btn" class="button" style="white-space:nowrap;" data-nonce="' . esc_attr($nonce) . '">Apply</button>';
    echo '<button type="button" id="pbb_gc_remove_btn" class="button" style="white-space:nowrap;" data-nonce="' . esc_attr($nonce) . '">Remove</button>';
    echo '</div>';

    echo '<div id="pbb_gc_msg" style="margin-top:8px;"></div>';

    echo '</div>';
}, 20);

/**
 * AJAX: Apply / Remove certificate
 */
add_action('wp_ajax_pbb_gc_apply', 'pbb_gc_ajax_apply');
add_action('wp_ajax_nopriv_pbb_gc_apply', 'pbb_gc_ajax_apply');

function pbb_gc_ajax_apply() {

    if (!function_exists('WC') || !WC()->session || !WC()->cart) {
        wp_send_json_error(['message' => 'WooCommerce session not available.']);
    }

    $nonce = (string)($_POST['security'] ?? '');
    if (!wp_verify_nonce($nonce, 'pbb_gc_apply')) {
        wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.']);
    }

    $action = (string)($_POST['gc_action'] ?? '');
    $code   = trim((string)($_POST['code'] ?? ''));

    // Remove
    if ($action === 'remove') {
        WC()->session->__unset('pbb_gc_applied_code');
        WC()->session->__unset('pbb_gc_apply_amount');

        // Recalculate totals immediately
        WC()->cart->calculate_totals();

        wp_send_json_success(['message' => 'Gift certificate removed.']);
    }

    // Apply
    $code = pbb_gc_normalize_code($code);

    if ($code === '') {
        wp_send_json_error(['message' => 'Please enter a gift certificate number.']);
    }

    // Validate existence + remaining balance (creates record if first time)
    $lookup = pbb_gc_get_or_create_balance_from_flamingo($code);
    $balance = $lookup['balance'] ?? null;
    if (!$balance || ((float)($balance['remaining_amount'] ?? 0)) <= 0) {
        $searched = $lookup['searched_serials'] ?? [];
        $searched = array_map('esc_html', $searched);
        $searched_text = $searched ? 'Searched serials: ' . implode(', ', $searched) . '.' : '';
        $message = 'That gift certificate is not valid or has no remaining balance.';
        if ($searched_text) {
            $message .= ' ' . $searched_text;
        }
        wp_send_json_error(['message' => $message]);
    }

    WC()->session->set('pbb_gc_applied_code', $balance['cert_code']);

    // Recalculate totals immediately
    WC()->cart->calculate_totals();

    wp_send_json_success(['message' => 'Gift certificate applied: ' . $balance['cert_code']]);
}

/**
 * Front-end JS (checkout only)
 * - Calls AJAX apply/remove
 * - Triggers checkout refresh so the negative fee appears
 */
add_action('wp_footer', function () {

    if (!function_exists('is_checkout') || !is_checkout()) return;
    if (is_order_received_page()) return;

    ?>
    <script>
    (function(){
        function $(sel){ return document.querySelector(sel); }

        function setMsg(html, isError){
            var el = $('#pbb_gc_msg');
            if(!el) return;
            el.innerHTML = '<div style="padding:8px;border-radius:6px;border:1px solid ' + (isError ? '#cc0000' : '#2e7d32') + ';color:' + (isError ? '#cc0000' : '#2e7d32') + ';">' + html + '</div>';
        }

        function ajaxApply(action){
            var applyBtn  = $('#pbb_gc_apply_btn');
            var removeBtn = $('#pbb_gc_remove_btn');
            var codeEl    = $('#pbb_gc_code');

            if(!applyBtn || !removeBtn || !codeEl) return;

            var nonce = (action === 'remove') ? removeBtn.getAttribute('data-nonce') : applyBtn.getAttribute('data-nonce');
            var code  = codeEl.value || '';

            // Use WC's AJAX endpoint when available
            var url = (window.wc_checkout_params && window.wc_checkout_params.ajax_url) ? window.wc_checkout_params.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');

            var form = new FormData();
            form.append('action', 'pbb_gc_apply');
            form.append('security', nonce);
            form.append('gc_action', action);
            form.append('code', code);

            fetch(url, { method:'POST', credentials:'same-origin', body: form })
              .then(r => r.json())
              .then(function(res){
                  if(res && res.success){
                      setMsg(res.data && res.data.message ? res.data.message : 'Done.', false);

                      // Trigger Woo checkout refresh so totals/fees update
                      if (window.jQuery) {
                          window.jQuery('body').trigger('update_checkout');
                      }
                  } else {
                      setMsg((res && res.data && res.data.message) ? res.data.message : 'Could not apply certificate.', true);
                  }
              })
              .catch(function(){
                  setMsg('Request failed. Please refresh and try again.', true);
              });
        }

        document.addEventListener('click', function(e){
            var t = e.target;

            if(t && t.id === 'pbb_gc_apply_btn'){
                e.preventDefault();
                ajaxApply('apply');
            }

            if(t && t.id === 'pbb_gc_remove_btn'){
                e.preventDefault();
                ajaxApply('remove');
            }
        });
    })();
    </script>
    <?php
}, 99);

/**
 * Apply the discount as a negative fee.
 * This runs whenever totals are recalculated (including after update_checkout).
 */
add_action('woocommerce_cart_calculate_fees', function () {

    if (!function_exists('WC') || !WC()->cart || !WC()->session) return;

    $code = WC()->session->get('pbb_gc_applied_code');
    if (!$code) return;

    $balance = pbb_gc_get_balance_row($code);
    if (!$balance) return;

    $remaining = (float)$balance['remaining_amount'];
    if ($remaining <= 0) return;

    $cart_total = (float)WC()->cart->get_cart_contents_total();
    $cart_total += (float)WC()->cart->get_shipping_total();
    $cart_total += (float)WC()->cart->get_taxes_total();
    if ($cart_total <= 0) return;

    $apply = min($remaining, $cart_total);

    // Store apply amount in session (used later when deducting)
    WC()->session->set('pbb_gc_apply_amount', $apply);

    WC()->cart->add_fee('Gift Certificate (' . $balance['cert_code'] . ')', -1 * $apply, false);
}, 20);

/**
 * Save applied info to the order.
 */
add_action('woocommerce_checkout_create_order', function ($order) {

    if (!function_exists('WC') || !WC()->session) return;

    $code  = WC()->session->get('pbb_gc_applied_code');
    $apply = WC()->session->get('pbb_gc_apply_amount');

    if ($code && $apply) {
        $order->update_meta_data('_pbb_gc_redeem_code', $code);
        $order->update_meta_data('_pbb_gc_redeem_amount', (float)$apply);
    }
}, 20, 1);

/**
 * Deduct on Processing/Completed.
 */
add_action('woocommerce_order_status_processing', 'pbb_gc_deduct_on_paid', 40, 1);
add_action('woocommerce_order_status_completed',  'pbb_gc_deduct_on_paid', 40, 1);

function pbb_gc_deduct_on_paid($order_id) {

    $order = wc_get_order($order_id);
    if (!$order) return;

    // Prevent double-deduct
    if ($order->get_meta('_pbb_gc_redeem_deducted') === 'yes') return;

    $code  = (string)$order->get_meta('_pbb_gc_redeem_code');
    $apply = (float)$order->get_meta('_pbb_gc_redeem_amount');

    if (!$code || $apply <= 0) return;

    $ok = pbb_gc_deduct_balance($code, $apply, (int)$order_id);

    if ($ok) {
        $order->update_meta_data('_pbb_gc_redeem_deducted', 'yes');
        $order->add_order_note('PBB GC: Deducted ' . pbb_gc_decimal_to_money($apply) . ' from ' . $code);
        $order->save();

        // Clear session so it doesn't stick to the next checkout
        if (function_exists('WC') && WC()->session) {
            WC()->session->__unset('pbb_gc_applied_code');
            WC()->session->__unset('pbb_gc_apply_amount');
        }
    } else {
        $order->add_order_note('PBB GC: Deduction FAILED for ' . $code . ' amount ' . pbb_gc_decimal_to_money($apply));
    }
}

/**
 * Show remaining balance in customer emails when a certificate is used.
 */
add_action('woocommerce_email_after_order_table', function ($order, $sent_to_admin, $plain_text, $email) {
	if ($sent_to_admin) return;
	if (!$order instanceof WC_Order) return;

	$code = (string)$order->get_meta('_pbb_gc_redeem_code');
	if (!$code) return;

	$balance = pbb_gc_get_balance_row($code);
	if (!$balance) return;

	$remaining = (float)$balance['remaining_amount'];
	$label = 'Gift Certificate Remaining Balance';
	$value = pbb_gc_decimal_to_money($remaining);

	if ($plain_text) {
		echo "\n{$label}: {$value}\n";
		return;
	}

	echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</p>';
}, 20, 4);


/** =========================
 *  5) DB + Flamingo lookup
 *  ========================= */

function pbb_gc_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'pbb_gc_balances';
}

function pbb_gc_get_balance_row(string $cert_code): ?array {
	global $wpdb;
	$table = pbb_gc_table();

	$cert_code = pbb_gc_normalize_code($cert_code);

	$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE cert_code = %s LIMIT 1", $cert_code), ARRAY_A);
	return $row ?: null;
}

function pbb_gc_insert_balance(array $data): bool {
	global $wpdb;
	$table = pbb_gc_table();

	$now = current_time('mysql');

	$defaults = [
		'cert_code' => '',
		'serial_raw' => 0,
		'original_amount' => 0.00,
		'remaining_amount' => 0.00,
		'currency' => 'USD',
		'flamingo_post_id' => null,
		'status' => 'active',
		'created_at' => $now,
		'updated_at' => $now,
	];

	$data = array_merge($defaults, $data);

	$ok = (bool)$wpdb->insert($table, $data, [
		'%s','%d','%f','%f','%s','%d','%s','%s','%s'
	]);

	return $ok;
}

function pbb_gc_update_remaining(string $cert_code, float $remaining, int $order_id = 0): bool {
	global $wpdb;
	$table = pbb_gc_table();

	$cert_code = pbb_gc_normalize_code($cert_code);

	$data = [
		'remaining_amount' => max(0, $remaining),
		'updated_at' => current_time('mysql'),
	];
	$formats = ['%f', '%s'];

	if ($order_id > 0) {
		$data['last_order_id'] = $order_id;
		$formats[] = '%d';
	}

	return (bool)$wpdb->update(
		$table,
		$data,
		['cert_code' => $cert_code],
		$formats,
		['%s']
	);
}

function pbb_gc_deduct_balance(string $cert_code, float $amount, int $order_id = 0): bool {
	$row = pbb_gc_get_balance_row($cert_code);
	if (!$row) return false;

	$remaining = (float)$row['remaining_amount'];
	$new_remaining = $remaining - $amount;

	return pbb_gc_update_remaining($row['cert_code'], $new_remaining, $order_id);
}

/**
 * First-time redemption:
 * - if cert_code exists in DB -> return it
 * - else -> look up Flamingo by serial_number meta and read gift_amount
 * - create DB row and return it
 */
function pbb_gc_get_or_create_balance_from_flamingo(string $entered_code): array {
	$entered_code = pbb_gc_normalize_code($entered_code);

	// If already known in DB, use it
	$existing = pbb_gc_get_balance_row($entered_code);
	if ($existing) return [
		'balance' => $existing,
		'searched_serials' => [],
	];

	// Convert code to raw serial
	$serial = pbb_gc_code_to_serial_raw($entered_code);
	if ($serial <= 0) return [
		'balance' => null,
		'searched_serials' => [],
	];

	// Use canonical code format
	$canonical_code = pbb_gc_serial_to_code($serial);

	// Maybe the canonical exists already
	$existing2 = pbb_gc_get_balance_row($canonical_code);
	if ($existing2) return [
		'balance' => $existing2,
		'searched_serials' => [],
	];

	// Query Flamingo inbound posts by meta serial_number (supports multiple formats)
	$fl = pbb_gc_find_flamingo_by_serial($serial, $entered_code);
	if (!$fl) return [
		'balance' => null,
		'searched_serials' => pbb_gc_serial_search_candidates($serial, $entered_code),
	];

	$gift_amount = pbb_gc_extract_gift_amount_from_flamingo_post($fl['post_id']);
	if ($gift_amount <= 0) return [
		'balance' => null,
		'searched_serials' => pbb_gc_serial_search_candidates($serial, $entered_code),
	];

	// Create DB row
	$ok = pbb_gc_insert_balance([
		'cert_code' => $canonical_code,
		'serial_raw' => $serial,
		'original_amount' => $gift_amount,
		'remaining_amount' => $gift_amount,
		'flamingo_post_id' => (int)$fl['post_id'],
	]);

	if (!$ok) return [
		'balance' => null,
		'searched_serials' => pbb_gc_serial_search_candidates($serial, $entered_code),
	];

	return [
		'balance' => pbb_gc_get_balance_row($canonical_code),
		'searched_serials' => [],
	];
}

function pbb_gc_serial_search_candidates(int $serial, string $entered_code = ''): array {
	if ($serial <= 0) return [];

	$entered_code = pbb_gc_normalize_code($entered_code);
	$canonical_code = pbb_gc_serial_to_code($serial);
	$serial_padded = str_pad((string)$serial, PBB_GC_PAD, '0', STR_PAD_LEFT);

	return array_values(array_filter(array_unique([
		(string)$serial,
		$serial_padded,
		$canonical_code,
		$entered_code,
	])));
}

function pbb_gc_find_flamingo_by_serial(int $serial, string $entered_code = ''): ?array {
	if ($serial <= 0) return null;

	$candidates = pbb_gc_serial_search_candidates($serial, $entered_code);

	$serial_keys = [
		'serial_number',
		'_field_serial_number',
		'field_serial_number',
		'serial-number',
		'serialnumber',
	];
	$serial_queries = [];
	foreach ($serial_keys as $serial_key) {
		$serial_queries[] = [
			'key'     => $serial_key,
			'value'   => $candidates,
			'compare' => 'IN',
		];
	}

	// Flamingo uses a CPT 'flamingo_inbound'
	$meta_query = array_merge(['relation' => 'OR'], $serial_queries);

	$q = new WP_Query([
		'post_type'      => 'flamingo_inbound',
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'meta_query'     => $meta_query,
		'orderby' => 'date',
		'order'   => 'DESC',
	]);

	if (!$q->have_posts()) {
		$fallback_post_id = pbb_gc_find_flamingo_by_serial_fallback($candidates);
		if (!$fallback_post_id) return null;

		return ['post_id' => $fallback_post_id];
	}

	$post_id = (int)$q->posts[0]->ID;

	return ['post_id' => $post_id];
}

function pbb_gc_find_flamingo_by_serial_fallback(array $candidates): ?int {
	global $wpdb;

	$candidates = array_values(array_filter(array_unique(array_map('strval', $candidates))));
	if (!$candidates) return null;

	$like_serial = $wpdb->prepare(
		"pm.meta_value LIKE %s",
		'%' . $wpdb->esc_like('serial_number') . '%'
	);

	$like_candidate_clauses = [];
	foreach ($candidates as $candidate) {
		$like_candidate_clauses[] = $wpdb->prepare(
			"pm.meta_value LIKE %s",
			'%' . $wpdb->esc_like($candidate) . '%'
		);
	}

	if (!$like_candidate_clauses) return null;

	$like_candidates_sql = implode(' OR ', $like_candidate_clauses);
	$post_type = 'flamingo_inbound';
	$post_status = 'publish';

	$sql = $wpdb->prepare(
		"SELECT p.ID
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		WHERE p.post_type = %s
		  AND p.post_status = %s
		  AND {$like_serial}
		  AND ({$like_candidates_sql})
		ORDER BY p.post_date DESC
		LIMIT 1",
		$post_type,
		$post_status
	);

	$post_id = (int)$wpdb->get_var($sql);
	return $post_id > 0 ? $post_id : null;
}

/**
 * Flamingo storage varies depending on versions, so we search multiple meta keys.
 * Your screenshot shows:
 * - Meta key: serial_number
 * And your Fields show:
 * - gift_amount = "$50"
 *
 * Flamingo often stores fields in postmeta directly as the field name (gift_amount),
 * so we try that first. Then we fall back to scanning meta values for a $amount.
 */
function pbb_gc_extract_money_from_text($text): float {
	if (!is_string($text) || $text === '') return 0;
	if (preg_match('/\$\s*([0-9]+(?:\.[0-9]{1,2})?)/', $text, $m)) {
		return (float)$m[1];
	}
	return 0;
}

function pbb_gc_find_gift_amount_in_fields($fields): float {
	if (!is_array($fields)) return 0;

	foreach ($fields as $key => $value) {
		$normalized_key = strtolower((string)$key);
		$normalized_key = preg_replace('/[^a-z0-9]+/', '_', $normalized_key);
		$normalized_key = trim($normalized_key, '_');

		if (in_array($normalized_key, ['gift_amount', 'giftamount', 'gift_certificate_amount'], true)) {
			if (is_array($value) && isset($value['value'])) {
				$value = $value['value'];
			}
			$val = pbb_gc_money_to_decimal($value);
			if ($val > 0) return $val;
		}

		if (is_array($value)) {
			$nested = pbb_gc_find_gift_amount_in_fields($value);
			if ($nested > 0) return $nested;
		}
	}

	return 0;
}

function pbb_gc_extract_gift_amount_from_flamingo_post(int $post_id): float {
	if ($post_id <= 0) return 0;

	// Best-case: direct meta key
	$direct = get_post_meta($post_id, 'gift_amount', true);
	$val = pbb_gc_money_to_decimal($direct);
	if ($val > 0) return $val;

	// Other common Flamingo keys (just in case)
	$alts = ['_field_gift_amount', '_field_gift-amount', '_fields', 'fields', '_flamingo_fields', 'message'];
	foreach ($alts as $k) {
		$v = get_post_meta($post_id, $k, true);
		$nested = pbb_gc_find_gift_amount_in_fields($v);
		if ($nested > 0) return $nested;
		if (is_array($v) && isset($v['gift_amount'])) {
			$val = pbb_gc_money_to_decimal($v['gift_amount']);
			if ($val > 0) return $val;
		}
		if (is_string($v)) {
			$val = pbb_gc_extract_money_from_text($v);
			if ($val > 0) return $val;
		}
	}

	// Try post content/fields for any $amount in message bodies
	$post_text_sources = [
		get_post_field('post_content', $post_id),
		get_post_field('post_excerpt', $post_id),
		get_post_field('post_title', $post_id),
	];
	foreach ($post_text_sources as $text) {
		$val = pbb_gc_extract_money_from_text($text);
		if ($val > 0) return $val;
	}

	// Last resort: scan all meta values for a $amount
	$all = get_post_meta($post_id);
	foreach ($all as $key => $vals) {
		foreach ((array)$vals as $maybe) {
			if (!is_string($maybe)) continue;
			$val = pbb_gc_extract_money_from_text($maybe);
			if ($val > 0) return $val;
		}
	}

	return 0;
}

function pbb_gc_normalize_field_key(string $field_key): string {
	$field_key = strtolower($field_key);
	$field_key = preg_replace('/[^a-z0-9]+/', '_', $field_key);
	return trim($field_key, '_');
}

function pbb_gc_find_field_value_in_array($fields, string $field_key): string {
	if (!is_array($fields)) return '';

	foreach ($fields as $name => $value) {
		$normalized = pbb_gc_normalize_field_key((string)$name);
		if ($normalized === $field_key) {
			if (is_array($value) && isset($value['value'])) {
				return (string)$value['value'];
			}
			if (is_string($value)) {
				return $value;
			}
		}

		if (is_array($value)) {
			$nested = pbb_gc_find_field_value_in_array($value, $field_key);
			if ($nested !== '') return $nested;
		}
	}

	return '';
}

function pbb_gc_get_flamingo_field_value(int $post_id, string $field_key): string {
	if ($post_id <= 0 || $field_key === '') return '';

	$field_key = pbb_gc_normalize_field_key($field_key);
	$direct = get_post_meta($post_id, $field_key, true);
	if (is_string($direct) && $direct !== '') {
		return $direct;
	}

	$alts = ['_fields', 'fields', '_flamingo_fields'];
	foreach ($alts as $key) {
		$fields = get_post_meta($post_id, $key, true);
		$found = pbb_gc_find_field_value_in_array($fields, $field_key);
		if ($found !== '') return $found;
	}

	return '';
}

function pbb_gc_get_flamingo_serial_raw(int $post_id): int {
	$serial_keys = [
		'_serial_number',
		'_meta',
		'serial_number',
		'_field_serial_number',
		'field_serial_number',
		'serial-number',
		'serialnumber',
	];

	foreach ($serial_keys as $key) {
		$value = get_post_meta($post_id, $key, true);
		if (is_string($value) && $value !== '') {
			if ($key === '_meta') {
				$meta_array = maybe_unserialize($value);
				if (is_array($meta_array) && isset($meta_array['serial_number'])) {
					$value = $meta_array['serial_number'];
				} else {
					continue;
				}
			}
			$serial = (int)preg_replace('/[^0-9]/', '', (string)$value);
			if ($serial > 0) return $serial;
		}
	}

	$alts = ['_fields', 'fields', '_flamingo_fields'];
	foreach ($alts as $key) {
		$fields = get_post_meta($post_id, $key, true);
		$found = pbb_gc_find_field_value_in_array($fields, 'serial_number');
		if ($found !== '') {
			$serial = (int)preg_replace('/[^0-9]/', '', $found);
			if ($serial > 0) return $serial;
		}
	}

	$all_meta = get_post_meta($post_id);
	foreach ($all_meta as $key => $vals) {
		if (pbb_gc_normalize_field_key((string)$key) !== 'serial_number') {
			continue;
		}
		foreach ((array)$vals as $maybe) {
			if (!is_string($maybe) && !is_numeric($maybe)) {
				continue;
			}
			$serial = (int)preg_replace('/[^0-9]/', '', (string)$maybe);
			if ($serial > 0) return $serial;
		}
	}

	return 0;
}

function pbb_gc_get_order_items_summary(int $order_id): string {
	if ($order_id <= 0) return '';
	$order = wc_get_order($order_id);
	if (!$order) return '';

	$lines = [];
	foreach ($order->get_items() as $item) {
		$name = $item->get_name();
		$qty = (int)$item->get_quantity();
		$total = (float)$order->get_line_total($item, true);
		$lines[] = sprintf(
			'%s × %d (%s)',
			$name,
			$qty,
			wc_price($total)
		);
	}

	return implode('; ', $lines);
}

function pbb_gc_get_balance_by_serial(int $serial_raw): ?array {
	if ($serial_raw <= 0) return null;

	global $wpdb;
	$table = pbb_gc_table();

	$row = $wpdb->get_row(
		$wpdb->prepare("SELECT * FROM {$table} WHERE serial_raw = %d LIMIT 1", $serial_raw),
		ARRAY_A
	);

	return $row ?: null;
}

function pbb_gc_render_frontend_ledger(): string {
	global $wpdb;
	$table = pbb_gc_table();
	$rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A);
	$rows_by_code = [];
	foreach ($rows as $row) {
		if (!isset($row['cert_code'])) continue;
		$rows_by_code[$row['cert_code']] = $row;
	}

	$q = new WP_Query([
		'post_type'      => 'flamingo_inbound',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
	]);

	if ($q->have_posts()) {
		foreach ($q->posts as $post_id) {
			$serial_raw = pbb_gc_get_flamingo_serial_raw((int)$post_id);
			$cert_code = $serial_raw > 0 ? pbb_gc_serial_to_code($serial_raw) : '';
			if ($cert_code && isset($rows_by_code[$cert_code])) {
				continue;
			}
			$gift_amount = pbb_gc_extract_gift_amount_from_flamingo_post((int)$post_id);

			$rows[] = [
				'cert_code' => $cert_code !== '' ? $cert_code : '(missing)',
				'serial_raw' => $serial_raw > 0 ? $serial_raw : 0,
				'original_amount' => $gift_amount,
				'remaining_amount' => $gift_amount,
				'flamingo_post_id' => (int)$post_id,
				'updated_at' => get_the_date('Y-m-d H:i:s', (int)$post_id),
			];
		}
	}

	ob_start();
	?>
	<div class="pbb-gc-ledger">
		<?php if (!$rows) : ?>
			<p>No certificates recorded yet (they appear after first redemption).</p>
		<?php else : ?>
			<div class="pbb-gc-accordion" style="display:flex;flex-direction:column;gap:12px;">
				<?php foreach ($rows as $row) : ?>
					<details class="pbb-gc-accordion__item" style="border:1px solid #e0e0e0;border-radius:8px;padding:8px 12px;">
						<summary style="cursor:pointer;font-weight:600;display:flex;justify-content:space-between;gap:12px;align-items:center;">
							<span><?php echo esc_html($row['cert_code']); ?></span>
							<span><?php echo esc_html(pbb_gc_decimal_to_money((float)$row['remaining_amount'])); ?></span>
						</summary>
						<div class="pbb-gc-accordion__body" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 16px;">
							<div><strong>Cert Code:</strong> <?php echo esc_html($row['cert_code']); ?></div>
							<div><strong>Serial Raw:</strong> <?php echo esc_html($row['serial_raw']); ?></div>
							<div><strong>Original:</strong> <?php echo esc_html(pbb_gc_decimal_to_money((float)$row['original_amount'])); ?></div>
							<div><strong>Remaining:</strong> <?php echo esc_html(pbb_gc_decimal_to_money((float)$row['remaining_amount'])); ?></div>
							<div><strong>Flamingo Post:</strong> <?php echo esc_html((string)$row['flamingo_post_id']); ?></div>
							<div><strong>Updated:</strong> <?php echo esc_html((string)$row['updated_at']); ?></div>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php

	return (string)ob_get_clean();
}

add_shortcode('pbb_gc_frontend_ledger', 'pbb_gc_render_frontend_ledger');

function pbb_gc_render_flamingo_serials(): string {
	global $wpdb;
	$serials = [];
	$serial_keys = [
		'_serial_number',
		'_meta',
		'serial_number',
		'_field_serial_number',
		'field_serial_number',
		'serial-number',
		'serialnumber',
	];
	$placeholders = implode(',', array_fill(0, count($serial_keys), '%s'));

	$sql = $wpdb->prepare(
		"SELECT p.ID, pm.meta_key, pm.meta_value
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		WHERE p.post_type = %s
		  AND pm.meta_key IN ({$placeholders})
		ORDER BY p.post_date DESC",
		array_merge(['flamingo_inbound'], $serial_keys)
	);

	$rows = $wpdb->get_results($sql, ARRAY_A);
	foreach ($rows as $row) {
		$post_id = (int)($row['ID'] ?? 0);
		$meta_key = (string)($row['meta_key'] ?? '');
		$meta_value = $row['meta_value'] ?? '';

		if ($meta_key === '_meta') {
			$meta_array = maybe_unserialize($meta_value);
			if (is_array($meta_array) && isset($meta_array['serial_number'])) {
				$meta_value = $meta_array['serial_number'];
			} else {
				continue;
			}
		}

		$serial_raw = (int)preg_replace('/[^0-9]/', '', (string)$meta_value);
		if ($serial_raw <= 0) continue;
		$serials[] = [
			'serial' => pbb_gc_serial_to_code($serial_raw),
			'amount' => $post_id > 0 ? pbb_gc_extract_gift_amount_from_flamingo_post($post_id) : null,
			'remaining' => null,
		];
	}

	if (!$serials) {
		$q = new WP_Query([
			'post_type'      => 'flamingo_inbound',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		]);

		if ($q->have_posts()) {
			foreach ($q->posts as $post_id) {
				$serial_raw = pbb_gc_get_flamingo_serial_raw((int)$post_id);
				if ($serial_raw <= 0) continue;
					$serials[] = [
						'serial' => pbb_gc_serial_to_code($serial_raw),
						'amount' => pbb_gc_extract_gift_amount_from_flamingo_post((int)$post_id),
						'remaining' => null,
					];
				}
			}
		}

	$serial_map = [];
	foreach ($serials as $serial) {
		$key = $serial['serial'] ?? '';
		if ($key === '') continue;
		if (!isset($serial_map[$key])) {
			$serial_map[$key] = $serial;
		}
	}
	foreach ($serial_map as $key => $serial) {
		$serial_raw = pbb_gc_code_to_serial_raw($key);
		if ($serial_raw <= 0) continue;
		$balance = pbb_gc_get_balance_by_serial($serial_raw);
		if (!$balance) continue;
		$serial_map[$key]['remaining'] = $balance['remaining_amount'] ?? null;
	}
	$serials = array_values($serial_map);

	ob_start();
	?>
	<div class="pbb-gc-flamingo-serials">
		<?php if (!$serials) : ?>
			<p>No Flamingo serial numbers found.</p>
		<?php else : ?>
			<table class="shop_table shop_table_responsive">
				<thead>
					<tr>
						<th>Certificate</th>
						<th>Gift Amount</th>
						<th>Remaining Funds</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($serials as $serial) : ?>
						<tr>
							<td><?php echo esc_html($serial['serial']); ?></td>
							<td>
								<?php
								$amount = $serial['amount'];
								if (is_numeric($amount)) {
									echo esc_html(pbb_gc_decimal_to_money((float)$amount));
								} else {
									echo '&mdash;';
									}
									?>
							</td>
							<td>
								<?php
								$remaining = $serial['remaining'];
								if (!is_numeric($remaining)) {
									$remaining = $serial['amount'];
								}
								if (is_numeric($remaining)) {
									echo esc_html(pbb_gc_decimal_to_money((float)$remaining));
								} else {
									echo '&mdash;';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php

	return (string)ob_get_clean();
}

add_shortcode('pbb_gc_flamingo_serials', 'pbb_gc_render_flamingo_serials');

/** =========================
 *  6) (OPTIONAL) ADMIN: simple balances list
 *  ========================= */
add_action('admin_menu', function () {
	add_submenu_page(
		'woocommerce',
		'PBB Gift Certificates',
		'PBB Gift Certificates',
		'manage_woocommerce',
		'pbb-gift-certificates',
		'pbb_gc_admin_page'
	);
});

function pbb_gc_admin_page() {
	if (!current_user_can('manage_woocommerce')) return;

	global $wpdb;
	$table = pbb_gc_table();

	$rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A);

	echo '<div class="wrap"><h1>PBB Gift Certificates</h1>';
	echo '<p>This table tracks remaining balance after first redemption. First redemption pulls original amount from Flamingo by serial_number.</p>';

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>Cert Code</th><th>Serial Raw</th><th>Original</th><th>Remaining</th><th>Last Order</th><th>Flamingo Post</th><th>Updated</th>';
	echo '</tr></thead><tbody>';

	if (!$rows) {
		echo '<tr><td colspan="7">No certificates recorded yet (they appear after first redemption).</td></tr>';
	} else {
		foreach ($rows as $r) {
			$order_id = isset($r['last_order_id']) ? (int)$r['last_order_id'] : 0;
			$order_link = $order_id ? admin_url('post.php?post=' . $order_id . '&action=edit') : '';
			echo '<tr>';
			echo '<td>' . esc_html($r['cert_code']) . '</td>';
			echo '<td>' . esc_html($r['serial_raw']) . '</td>';
			echo '<td>' . esc_html(pbb_gc_decimal_to_money((float)$r['original_amount'])) . '</td>';
			echo '<td>' . esc_html(pbb_gc_decimal_to_money((float)$r['remaining_amount'])) . '</td>';
			echo '<td>' . ($order_link ? '<a href="' . esc_url($order_link) . '">#' . esc_html((string)$order_id) . '</a>' : '&mdash;') . '</td>';
			echo '<td>' . esc_html((string)$r['flamingo_post_id']) . '</td>';
			echo '<td>' . esc_html((string)$r['updated_at']) . '</td>';
			echo '</tr>';
		}
	}

	echo '</tbody></table></div>';
}
