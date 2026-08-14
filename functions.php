<?php
defined( 'ABSPATH' ) || exit;

// ── Styles ────────────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function (): void {
	wp_enqueue_style(
		'twentytwentyfive-style',
		get_template_directory_uri() . '/style.css',
		[],
		wp_get_theme( 'twentytwentyfive' )->get( 'Version' )
	);
	wp_enqueue_style(
		'ozupay-demo-style',
		get_stylesheet_uri(),
		[ 'twentytwentyfive-style' ],
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_style(
		'ozupay-demo-fonts',
		'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
		[],
		null
	);
} );

// ── WooCommerce support ───────────────────────────────────────────────────────

add_action( 'after_setup_theme', function (): void {
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-slider' );
} );

add_action( 'before_woocommerce_init', function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// ── UX tweaks ─────────────────────────────────────────────────────────────────

// Skip cart — go straight to checkout after adding a product.
add_filter( 'woocommerce_add_to_cart_redirect', function (): string {
	return wc_get_checkout_url();
} );

// Default country: Kenya.
add_filter( 'default_checkout_billing_country', function (): string { return 'KE'; } );

// Fake-address demo shortcut — ONLY for a virtual cart (no real delivery
// involved). A cart that needs_shipping() (e.g. a physical test product with
// a flat-rate charge, or anything going through M-Pesa on Delivery) must
// collect a real address, since that address is where the order actually
// ships. Faking/hiding it there previously meant a real delivery order
// silently got "OzuPay demo checkout, Nairobi, 00100" instead of the
// customer's real address — see ozpd_cart_needs_real_address() below, which
// every hook in this section is gated on.
function ozpd_cart_needs_real_address(): bool {
	return function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_shipping();
}

add_filter( 'default_checkout_billing_address_1', function ( $value ) {
	return ozpd_cart_needs_real_address() ? $value : ozpd_demo_address_defaults()['address_1'];
} );
add_filter( 'default_checkout_billing_city', function ( $value ) {
	return ozpd_cart_needs_real_address() ? $value : ozpd_demo_address_defaults()['city'];
} );
add_filter( 'default_checkout_billing_postcode', function ( $value ) {
	return ozpd_cart_needs_real_address() ? $value : ozpd_demo_address_defaults()['postcode'];
} );
add_filter( 'default_checkout_shipping_country', function ( $value ) {
	return ozpd_cart_needs_real_address() ? $value : ozpd_demo_address_defaults()['country'];
} );
add_filter( 'default_checkout_shipping_address_1', function ( $value ) {
	return ozpd_cart_needs_real_address() ? $value : ozpd_demo_address_defaults()['address_1'];
} );
add_filter( 'default_checkout_shipping_city', function ( $value ) {
	return ozpd_cart_needs_real_address() ? $value : ozpd_demo_address_defaults()['city'];
} );
add_filter( 'default_checkout_shipping_postcode', function ( $value ) {
	return ozpd_cart_needs_real_address() ? $value : ozpd_demo_address_defaults()['postcode'];
} );

function ozpd_demo_address_defaults(): array {
	return [
		'country'   => 'KE',
		'address_1' => 'OzuPay demo checkout',
		'city'      => 'Nairobi',
		'state'     => '',
		'postcode'  => '00100',
	];
}

function ozpd_set_demo_customer_address(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->customer || ozpd_cart_needs_real_address() ) {
		return;
	}

	$address = ozpd_demo_address_defaults();

	WC()->customer->set_billing_country( $address['country'] );
	WC()->customer->set_billing_address_1( $address['address_1'] );
	WC()->customer->set_billing_city( $address['city'] );
	WC()->customer->set_billing_state( $address['state'] );
	WC()->customer->set_billing_postcode( $address['postcode'] );
	WC()->customer->set_shipping_country( $address['country'] );
	WC()->customer->set_shipping_address_1( $address['address_1'] );
	WC()->customer->set_shipping_city( $address['city'] );
	WC()->customer->set_shipping_state( $address['state'] );
	WC()->customer->set_shipping_postcode( $address['postcode'] );
	WC()->customer->save();
}

add_action( 'wp', function (): void {
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		ozpd_set_demo_customer_address();
	}
} );

add_action( 'woocommerce_checkout_update_order_review', 'ozpd_set_demo_customer_address', 1 );
add_action( 'woocommerce_checkout_process', 'ozpd_set_demo_customer_address', 1 );

add_filter( 'woocommerce_checkout_posted_data', function ( array $data ): array {
	if ( ozpd_cart_needs_real_address() ) {
		return $data;
	}

	$address = ozpd_demo_address_defaults();

	foreach ( [ 'billing', 'shipping' ] as $type ) {
		$data[ "{$type}_country" ]   = $address['country'];
		$data[ "{$type}_address_1" ] = $address['address_1'];
		$data[ "{$type}_city" ]      = $address['city'];
		$data[ "{$type}_state" ]     = $address['state'];
		$data[ "{$type}_postcode" ]  = $address['postcode'];
	}

	return $data;
}, 5 );

// Minimal checkout: email + phone (required for M-Pesa) only.
// Name and country aren't needed for a sandbox demo purchase; country is
// fixed to Kenya below since OzuPay only supports KES. Skipped entirely for a
// cart that needs_shipping() — a real delivery order needs the customer's
// real name and address, not the faked/hidden demo shortcut.
add_filter( 'woocommerce_checkout_fields', function ( array $fields ): array {
	if ( ozpd_cart_needs_real_address() ) {
		return $fields;
	}

	unset(
		$fields['billing']['billing_first_name'],
		$fields['billing']['billing_last_name'],
		$fields['billing']['billing_company'],
		$fields['billing']['billing_address_2']
	);

	$address = ozpd_demo_address_defaults();

	$hidden_address_fields = [
		'billing_country'   => $address['country'],
		'billing_address_1' => $address['address_1'],
		'billing_city'      => $address['city'],
		'billing_state'     => $address['state'],
		'billing_postcode'  => $address['postcode'],
		'shipping_country'  => $address['country'],
		'shipping_address_1' => $address['address_1'],
		'shipping_city'     => $address['city'],
		'shipping_state'    => $address['state'],
		'shipping_postcode' => $address['postcode'],
	];

	foreach ( $hidden_address_fields as $key => $value ) {
		$type = 0 === strpos( $key, 'shipping_' ) ? 'shipping' : 'billing';

		if ( ! isset( $fields[ $type ][ $key ] ) ) {
			continue;
		}

		$fields[ $type ][ $key ]['type']     = 'hidden';
		$fields[ $type ][ $key ]['default']  = $value;
		$fields[ $type ][ $key ]['required'] = false;
		$fields[ $type ][ $key ]['class']    = [ 'ozpd-hidden-address-field' ];
	}

	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['label']       = 'Phone';
		$fields['billing']['billing_phone']['description'] = 'For M-Pesa prompt.';
		$fields['billing']['billing_phone']['placeholder'] = '07XXXXXXXX';
		$fields['billing']['billing_phone']['required']    = true;
		$fields['billing']['billing_phone']['class']       = [ 'form-row-wide' ];
		$fields['billing']['billing_phone']['priority']    = 25;
	}

	if ( isset( $fields['billing']['billing_email'] ) ) {
		$fields['billing']['billing_email']['label']       = 'Email';
		$fields['billing']['billing_email']['description'] = 'Use your real email to see the email template the plugin uses.';
		$fields['billing']['billing_email']['priority']    = 20;
	}

	unset( $fields['order']['order_comments'] );

	return $fields;
} );

// Country field is hidden above; orders are always Kenyan (KES-only gateway),
// and the customer's name isn't collected — fall back to the email handle so
// admin order lists and emails still show something readable. Skipped for a
// cart that needs_shipping(), where the fields above were never hidden and
// the order must keep the real address the customer entered.
add_action( 'woocommerce_checkout_create_order', function ( $order ): void {
	if ( ozpd_cart_needs_real_address() ) {
		return;
	}

	$address = ozpd_demo_address_defaults();

	$order->set_billing_country( $address['country'] );
	$order->set_billing_address_1( $address['address_1'] );
	$order->set_billing_city( $address['city'] );
	$order->set_billing_state( $address['state'] );
	$order->set_billing_postcode( $address['postcode'] );
	$order->set_shipping_country( $address['country'] );
	$order->set_shipping_address_1( $address['address_1'] );
	$order->set_shipping_city( $address['city'] );
	$order->set_shipping_state( $address['state'] );
	$order->set_shipping_postcode( $address['postcode'] );

	if ( ! $order->get_billing_first_name() && $order->get_billing_email() ) {
		$handle = strtok( $order->get_billing_email(), '@' );
		$order->set_billing_first_name( $handle ?: 'Customer' );
	}
}, 20 );

// Disable image zoom on product pages.
add_filter( 'woocommerce_single_product_zoom_enabled', '__return_false' );

// Product Collection blocks use a multi-request AJAX add-to-cart flow. For
// this purpose-built demo, one click should move straight to checkout. Capture
// the product ID already rendered by WooCommerce and let its normal server-side
// add-to-cart handler populate the cart during the checkout navigation.
add_action( 'wp_footer', function (): void {
	if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
		return;
	}
	?>
<script>
(function () {
	function labelButtons() {
		document.querySelectorAll('.ozpd-products-section [data-product_id]').forEach(function (button) {
			var label = button.querySelector('span');
			if (label && label.textContent !== 'Try checkout') label.textContent = 'Try checkout';
			button.setAttribute('aria-label', 'Try this product in checkout');
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.ozpd-products-section [data-product_id]');
		if (!button) return;
		var productId = button.getAttribute('data-product_id');
		if (!productId) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		button.classList.add('is-loading');
		button.textContent = 'Opening checkout\u2026';
		window.location.assign('/checkout/?add-to-cart=' + encodeURIComponent(productId));
	}, true);

	labelButtons();
	new MutationObserver(labelButtons).observe(document.body, { childList: true, subtree: true });
}());
</script>
	<?php
}, 5 );

// ── Cart count badge — updated via WC Store API (works with Cart Block) ───────

add_action( 'wp_footer', function (): void {
	?>
<script>
(function () {
	function updateCount(n) {
		document.querySelectorAll('.ozpd-cart-count').forEach(function (el) {
			el.textContent = n > 0 ? n : '';
		});
	}

	// Initial load: fetch from Store API.
	fetch('/wp-json/wc/store/v1/cart', { credentials: 'include' })
		.then(function (r) { return r.ok ? r.json() : null; })
		.then(function (d) { if (d) updateCount(d.items_count || 0); })
		.catch(function () {});

	// Subscribe to the WC Blocks Redux store so the badge updates instantly on
	// cart/checkout pages (add, remove, quantity up/down, cart emptied).
	// wp.data is not present on shop pages (Interactivity API is used there
	// instead), so cap retries and fall back to the polling interval below.
	function subscribeWcStore(retries) {
		if (window.wp && window.wp.data) {
			var lastCount = -1;
			window.wp.data.subscribe(function () {
				var store = window.wp.data.select('wc/store/cart');
				if (!store || typeof store.getCartData !== 'function') return;
				var data = store.getCartData();
				if (data && typeof data.itemsCount === 'number' && data.itemsCount !== lastCount) {
					lastCount = data.itemsCount;
					updateCount(lastCount);
				}
			});
		} else if (retries > 0) {
			setTimeout(function () { subscribeWcStore(retries - 1); }, 300);
		}
	}
	subscribeWcStore(10);

	// Fallback poll: keeps the badge in sync on shop pages where wp.data is
	// not loaded (Product Collection uses Interactivity API, not Redux).
	setInterval(function () {
		fetch('/wp-json/wc/store/v1/cart', { credentials: 'include' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (d) { if (d) updateCount(d.items_count || 0); })
			.catch(function () {});
	}, 3000);
})();

// ── Reset countdown ticker — time remaining until the next 00:00 UTC reset ──
(function () {
	var els = document.querySelectorAll('.ozpd-reset-ticker');
	if (!els.length) return;

	function pad(n) { return n < 10 ? '0' + n : String(n); }

	function render() {
		var now = new Date();
		var nextMidnightUtc = Date.UTC(
			now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() + 1, 0, 0, 0
		);
		var remainingMs = nextMidnightUtc - now.getTime();
		if (remainingMs < 0) remainingMs = 0;

		var totalSeconds = Math.floor(remainingMs / 1000);
		var hours   = Math.floor(totalSeconds / 3600);
		var minutes = Math.floor((totalSeconds % 3600) / 60);
		var seconds = totalSeconds % 60;
		var text = 'Resets in ' + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);

		els.forEach(function (el) { el.textContent = text; });
	}

	render();
	setInterval(render, 1000);
})();
</script>
	<?php
} );

// ── "Buy the plugin" block on order received ────────────────────────────────
// A shortcode (not a woocommerce_thankyou hook) so the order-confirmation.html
// template can place it directly after the "Order received" heading — every
// woocommerce_thankyou-hooked callback renders inside the WC Blocks
// "Additional Information" block, which is always the last block on the page,
// so the promo would otherwise always require scrolling to see.
add_shortcode( 'ozpd_order_promo', function (): string {
	return '<div class="ozpd-order-promo">'
		. '<div class="ozpd-order-promo__content">'
		. '<h2>Ready to use this on your own store?</h2>'
		. '<a href="https://ozupay.com/shop/" class="ozpd-order-btn" target="_blank" rel="noopener">Get OzuPay M-Pesa Plugin Pro &rarr;</a>'
		. '<p class="ozpd-note">From KES 5,000 / year</p>'
		. '</div>'
		. '</div>';
} );

// ── Merchant view: what this one order looks like on the merchant's side ─────
// Deliberately NOT a wp-admin page — no login, no role, no capability system
// to worry about. Access is keyed by order ID + the WC order key (the same
// per-order secret WooCommerce already uses for guest "view order" links), so
// a visitor can only ever open the order they themselves just placed. There
// is no list view and no way to enumerate or browse any other order — this
// intentionally can't become the PII-exposure problem a real Orders/REST API
// view would (see the admin-access security review earlier in this project).

function ozpd_merchant_view_url( WC_Order $order ): string {
	return add_query_arg(
		[
			'ozpd_merchant_view' => 1,
			'order_id'           => $order->get_id(),
			'key'                => $order->get_order_key(),
		],
		home_url( '/' )
	);
}

add_action( 'template_redirect', function (): void {
	if ( empty( $_GET['ozpd_merchant_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view gated by the order key compare below, not a state-changing action.
		return;
	}

	$order_id = absint( $_GET['order_id'] ?? 0 );
	$key      = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
	$order    = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
		wp_safe_redirect( home_url( '/shop/' ) );
		exit;
	}

	ozpd_render_merchant_view( $order );
	exit;
}, 5 ); // Same early priority as the demo-login handler — must run before the
        // homepage/empty-checkout redirect hook that also fires on template_redirect.

/**
 * A shortcode (not a hook) so order-confirmation.html can place it right next
 * to the order summary. Only renders on the customer's own just-placed order
 * — pulled from the current request's own query var/key, never a lookup.
 */
add_shortcode( 'ozpd_merchant_view_link', function (): string {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
		return '';
	}

	$order_id = absint( get_query_var( 'order-received' ) );
	$key      = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only link build, no state change; key itself is the auth check.
	$order    = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
		return '';
	}

	return '<div class="ozpd-merchant-view-cta">'
		. '<a href="' . esc_url( ozpd_merchant_view_url( $order ) ) . '" target="_blank" rel="noopener">'
		. 'See how this order looks on the merchant&#8217;s side &rarr;'
		. '</a></div>';
} );

/**
 * Friendly labels for the OzuPay transaction-log events relevant to a single
 * STK Push order — mirrors the event_type strings OzuPay_Order_Meta::log_event()
 * writes in includes/Handlers/class-stk-callback.php and friends.
 */
function ozpd_merchant_view_event_label( string $event_type ): string {
	$labels = [
		'stk_confirmed'     => 'Payment confirmed via M-Pesa',
		'stk_failed'        => 'M-Pesa payment failed or was cancelled by the customer',
		'stk_underpayment'  => 'M-Pesa payment received but for less than the order total',
		'stk_abandoned'     => 'Customer left the STK Push prompt unanswered',
		'stk_reconciled_failed' => 'Marked failed by the reconciliation sweep (no callback received)',
	];
	return $labels[ $event_type ] ?? ucwords( str_replace( '_', ' ', $event_type ) );
}

function ozpd_render_merchant_view( WC_Order $order ): void {
	global $wpdb;

	$status_meta = [
		'processing' => [ 'Processing', '#2563eb', '#eff6ff' ],
		'completed'  => [ 'Completed', '#16a34a', '#f0fdf4' ],
		'on-hold'    => [ 'On hold', '#d97706', '#fffbeb' ],
		'pending'    => [ 'Pending payment', '#64748b', '#f8fafc' ],
		'failed'     => [ 'Failed', '#dc2626', '#fef2f2' ],
		'cancelled'  => [ 'Cancelled', '#dc2626', '#fef2f2' ],
	];
	[ $status_label, $status_color, $status_bg ] = $status_meta[ $order->get_status() ] ?? [ ucfirst( $order->get_status() ), '#64748b', '#f8fafc' ];

	$receipt      = (string) $order->get_meta( '_ozupay_receipt' );
	$amount_paid  = (float) $order->get_meta( '_ozupay_amount_paid' );
	$phone        = (string) $order->get_meta( '_ozupay_phone' );
	$stk_sent_at  = (string) $order->get_meta( '_ozupay_stk_sent_at' );

	$events = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT event_type, receipt, amount, result_code, created_at FROM {$wpdb->prefix}ozupay_transactions WHERE order_id = %d ORDER BY created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no variables.
			$order->get_id()
		)
	);

	$items_html = '';
	foreach ( $order->get_items() as $item ) {
		$items_html .= '<tr>'
			. '<td>' . esc_html( $item->get_name() ) . '</td>'
			. '<td style="text-align:center">' . esc_html( $item->get_quantity() ) . '</td>'
			. '<td style="text-align:right">' . wp_kses_post( wc_price( $order->get_line_total( $item ) ) ) . '</td>'
			. '</tr>';
	}

	$timeline_html = '';
	if ( $stk_sent_at ) {
		$timeline_html .= '<li><span class="ozpd-mv-time">' . esc_html( mysql2date( 'M j, g:i:s A', $stk_sent_at ) ) . '</span><span>STK Push sent to ' . esc_html( $phone ?: 'customer' ) . '</span></li>';
	}
	foreach ( $events as $event ) {
		$timeline_html .= '<li><span class="ozpd-mv-time">' . esc_html( mysql2date( 'M j, g:i:s A', $event->created_at ) ) . '</span><span>' . esc_html( ozpd_merchant_view_event_label( $event->event_type ) ) . '</span></li>';
	}
	if ( '' === $timeline_html ) {
		$timeline_html = '<li><span class="ozpd-mv-time">&mdash;</span><span>No payment events recorded yet.</span></li>';
	}

	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>Order #<?php echo esc_html( $order->get_order_number() ); ?> &mdash; Merchant view</title>
<style>
	body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:32px 16px}
	.ozpd-mv-wrap{max-width:720px;margin:0 auto}
	.ozpd-mv-banner{background:#0f172a;color:#fff;border-radius:10px;padding:14px 18px;font-size:13px;margin-bottom:20px;line-height:1.5}
	.ozpd-mv-banner strong{color:#4ade80}
	.ozpd-mv-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:20px}
	.ozpd-mv-card h2{margin:0 0 16px;font-size:15px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
	.ozpd-mv-head{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
	.ozpd-mv-head h1{margin:0;font-size:22px}
	.ozpd-mv-badge{display:inline-block;padding:5px 12px;border-radius:999px;font-size:12px;font-weight:700;background:<?php echo esc_attr( $status_bg ); ?>;color:<?php echo esc_attr( $status_color ); ?>}
	table{width:100%;border-collapse:collapse;font-size:14px}
	th{text-align:left;font-size:11px;text-transform:uppercase;color:#94a3b8;padding-bottom:8px;border-bottom:1px solid #e2e8f0}
	td{padding:8px 0;border-bottom:1px solid #f1f5f9}
	.ozpd-mv-kv{display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:14px}
	.ozpd-mv-kv div span{display:block}
	.ozpd-mv-kv .ozpd-mv-label{color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px}
	ul.ozpd-mv-timeline{list-style:none;margin:0;padding:0}
	ul.ozpd-mv-timeline li{display:flex;gap:14px;font-size:13.5px;padding:8px 0;border-bottom:1px solid #f1f5f9}
	ul.ozpd-mv-timeline li:last-child{border-bottom:none}
	.ozpd-mv-time{color:#94a3b8;white-space:nowrap;font-family:monospace;font-size:12px}
</style>
</head>
<body>
<div class="ozpd-mv-wrap">
	<div class="ozpd-mv-banner">
		This is a read-only preview of what <strong>you, the merchant</strong>, would see for this order in your WooCommerce admin &mdash; including the M-Pesa reconciliation happening automatically in the background.
	</div>

	<div class="ozpd-mv-card">
		<div class="ozpd-mv-head">
			<h1>Order #<?php echo esc_html( $order->get_order_number() ); ?></h1>
			<span class="ozpd-mv-badge"><?php echo esc_html( $status_label ); ?></span>
		</div>
		<p style="color:#64748b;font-size:13px;margin:8px 0 0"><?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'F j, Y \a\t g:i A' ) ); ?></p>
	</div>

	<div class="ozpd-mv-card">
		<h2>Items</h2>
		<table>
			<tr><th>Item</th><th style="text-align:center">Qty</th><th style="text-align:right">Total</th></tr>
			<?php echo $items_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each cell escaped/kses'd above. ?>
		</table>
		<p style="text-align:right;font-weight:700;margin-top:12px"><?php echo wp_kses_post( 'Order total: ' . $order->get_formatted_order_total() ); ?></p>
	</div>

	<div class="ozpd-mv-card">
		<h2>Payment</h2>
		<div class="ozpd-mv-kv">
			<div><span class="ozpd-mv-label">Method</span><span>OzuPay &mdash; M-Pesa STK Push</span></div>
			<div><span class="ozpd-mv-label">Amount paid</span><span><?php echo $amount_paid ? wp_kses_post( wc_price( $amount_paid ) ) : '&mdash;'; ?></span></div>
			<div><span class="ozpd-mv-label">M-Pesa receipt</span><span><?php echo esc_html( $receipt ?: '—' ); ?></span></div>
			<div><span class="ozpd-mv-label">Phone charged</span><span><?php echo esc_html( $phone ?: '—' ); ?></span></div>
		</div>
	</div>

	<div class="ozpd-mv-card">
		<h2>Reconciliation timeline</h2>
		<ul class="ozpd-mv-timeline">
			<?php echo $timeline_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each field escaped above. ?>
		</ul>
	</div>
</div>
</body>
</html>
	<?php
}

// ── Demo simulation notice in WooCommerce emails ──────────────────────────────

// woocommerce_email_header fires inside email-header.php after the H1 heading.
// Signature: do_action( 'woocommerce_email_header', $email_heading, $email )
add_action( 'woocommerce_email_header', function ( string $email_heading, \WC_Email $email ): void {
	echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:16px">'
		. '<tr><td style="background:#fff8e1;border:1px solid #f59e0b;border-radius:4px;padding:12px 16px;font-family:sans-serif;font-size:13px;color:#92400e">'
		. '<strong>Demo email</strong> — This is a simulated purchase from '
		. '<a href="https://demo.ozupay.com" style="color:#92400e">demo.ozupay.com</a>. '
		. 'Daraja will reverse any M-Pesa deduction from your balance automatically. Order data is deleted every hour.'
		. '</td></tr></table>';
}, 20, 2 );

// ── Pro upsell block in WooCommerce emails ────────────────────────────────────

// woocommerce_email_footer fires inside email-footer.php before the footer HTML.
// Signature: do_action( 'woocommerce_email_footer', $email )
add_action( 'woocommerce_email_footer', function ( \WC_Email $email ): void {
	echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:24px">'
		. '<tr><td style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;padding:16px 20px;font-family:sans-serif;font-size:13px;color:#166534;text-align:center">'
		. '<strong style="display:block;font-size:15px;margin-bottom:6px">Ready to use this on your own store?</strong>'
		. 'OzuPay Pro gives you analytics, B2C refunds, payment links, POS API, and more.<br>'
		. '<a href="https://ozupay.com/shop/" style="display:inline-block;margin-top:10px;padding:8px 20px;background:#16a34a;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;font-size:13px" target="_blank" rel="noopener">Get OzuPay Pro &rarr;</a>'
		. '<span style="display:block;margin-top:6px;font-size:12px;opacity:.8">From KES 5,000 / year &nbsp;&middot;&nbsp; ozupay.com</span>'
		. '</td></tr></table>';
}, 5 );

// ── STK expiry banner on the payment-waiting page ─────────────────────────────
// Daraja cancels an unanswered STK Push after ~60 s. This banner appears at
// 52 s so the customer knows to tap Retry before the request expires.

add_action( 'wp_footer', function (): void {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}
	?>
<style>
#ozpd-stk-expiry-banner{display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#dc2626;color:#fff;font-family:sans-serif;font-size:14px;text-align:center;padding:14px 16px;box-shadow:0 -2px 8px rgba(0,0,0,.25)}
#ozpd-stk-expiry-banner strong{display:block;margin-bottom:2px}
</style>
<div id="ozpd-stk-expiry-banner">
	<strong>M-Pesa request expired</strong>
	Daraja cancelled the STK Push because there was no response within 60 seconds. Use the <em>Retry</em> button below to resend the prompt.
</div>
<script>
(function () {
	var banner = document.getElementById('ozpd-stk-expiry-banner');
	if (!banner) return;

	// Only show while the payment-waiting modal is still active AND still waiting
	// (not yet confirmed, failed, timed out, or dismissed by the customer).
	var shown = false;
	var timer = setTimeout(function () {
		// Real modal markup lives in ozupay/templates/checkout/payment-waiting.php —
		// #ozupay-waiting-backdrop is the overlay, #ozupay-status-pill carries the
		// current state as an "is-*" class (is-waiting/is-confirmed/is-failed/is-timedout).
		var overlay = document.getElementById('ozupay-waiting-backdrop');
		if (!overlay || overlay.hidden) return;
		var pill = document.getElementById('ozupay-status-pill');
		if (pill && !pill.classList.contains('is-waiting')) return;
		if (!shown) {
			shown = true;
			banner.style.display = 'block';
			// Auto-hide after 30 s — by then the modal has usually updated.
			setTimeout(function () { banner.style.display = 'none'; }, 30000);
		}
	}, 52000);
})();
</script>
	<?php
} );

// ── Demo data cleanup (hourly cron) ───────────────────────────────────────────

add_action( 'init', function (): void {
	if ( ! wp_next_scheduled( 'ozpd_cleanup' ) ) {
		wp_schedule_event( time(), 'hourly', 'ozpd_cleanup' );
	}
} );

add_action( 'ozpd_cleanup', function (): void {
	try {
		$cutoff = time() - 2 * HOUR_IN_SECONDS;

		$order_ids = wc_get_orders( [
			'date_created' => '<' . $cutoff,
			'limit'        => -1,
			'return'       => 'ids',
		] );

		foreach ( $order_ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order ) {
				$order->delete( true );
			}
		}

		global $wpdb;
		// Remove OzuPay transaction log rows older than 2 hours.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}ozupay_transactions WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', $cutoff )
			)
		);
	} catch ( \Throwable $e ) {
		error_log( '[ozpd_cleanup] ' . gmdate( 'Y-m-d H:i:s' ) . ' — ' . $e->getMessage() );
	}
} );

// ── Full daily reset at 00:00 UTC ──────────────────────────────────────────
// Wipes every order and transaction log row regardless of age, plus the
// checkout rate-limit transients, so each UTC day starts from a clean slate
// for anyone testing the demo. wp_schedule_event() timestamps are always UTC,
// so anchoring the first run to the next UTC midnight and recurring 'daily'
// keeps firing at 00:00 UTC indefinitely (subject to WP-Cron's usual
// page-load-triggered timing — it fires on the first visit at or after the
// scheduled time, not necessarily at the exact second).

function ozpd_next_utc_midnight(): int {
	// strtotime()/DateTime "tomorrow" resolves against the default timezone,
	// which is not guaranteed to be UTC — anchor explicitly to avoid drifting
	// the reset off 00:00 UTC on servers configured with a different zone.
	$utc_now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
	$midnight = ( clone $utc_now )->modify( 'tomorrow' )->setTime( 0, 0, 0 );
	return $midnight->getTimestamp();
}

add_action( 'init', function (): void {
	if ( ! wp_next_scheduled( 'ozpd_daily_reset' ) ) {
		wp_schedule_event( ozpd_next_utc_midnight(), 'daily', 'ozpd_daily_reset' );
	}
} );

add_action( 'ozpd_daily_reset', function (): void {
	try {
		$order_ids = wc_get_orders( [
			'limit'  => -1,
			'return' => 'ids',
		] );

		foreach ( $order_ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order ) {
				$order->delete( true );
			}
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, no variables.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}ozupay_transactions" );

		// Clear checkout rate-limit transients so testers aren't blocked into the next day.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ozpd_rl_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ozpd_rl_' ) . '%'
			)
		);
	} catch ( \Throwable $e ) {
		error_log( '[ozpd_daily_reset] ' . gmdate( 'Y-m-d H:i:s' ) . ' — ' . $e->getMessage() );
	}
} );

// ── Homepage + empty-checkout redirect → shop ─────────────────────────────────

add_action( 'template_redirect', function (): void {
	if ( is_front_page() ) {
		wp_redirect( home_url( '/shop/' ), 302 );
		exit;
	}

	// Redirect /checkout/ to /shop/ when cart is empty (avoids blank page).
	if ( function_exists( 'is_checkout' ) && is_checkout() && WC()->cart && WC()->cart->is_empty() && ! is_wc_endpoint_url() ) {
		wp_redirect( home_url( '/shop/' ), 302 );
		exit;
	}
} );

// ── Demo tester notification (admin new-order email) ─────────────────────────
// The generic "You have received an order" subject and heading are useless on a
// demo site. Replace them with a summary that shows immediately who to follow up
// with, and add a contact-details block so the email is actionable at a glance.

add_filter( 'woocommerce_email_subject_new_order', function ( string $subject, \WC_Order $order ): string {
	$email = $order->get_billing_email();
	$phone = $order->get_billing_phone();
	// Single-quoted — positional specifiers must not be inside double quotes.
	/* translators: 1: customer email address 2: customer phone number */
	return sprintf( __( 'Demo test: %1$s (%2$s) just tried OzuPay', 'ozupay-demo' ), $email, $phone );
}, 10, 2 );

add_filter( 'woocommerce_email_heading_new_order', function ( string $heading, \WC_Order $order ): string {
	return __( 'Someone just tested your plugin', 'ozupay-demo' );
}, 10, 2 );

// The line-items/totals table is meaningless here — it lists sandbox products
// and fake prices, not anything the developer needs to follow up with a tester.
// Suppress it for this specific admin notification only.
add_action( 'woocommerce_email_order_details', function ( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
	if ( $sent_to_admin && 'new_order' === $email->id ) {
		remove_action( 'woocommerce_email_order_details', array( WC_Emails::instance(), 'order_details' ), 10 );
	}
}, 5, 4 );

add_action( 'woocommerce_email_after_order_table', function ( \WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email ): void {
	if ( ! $sent_to_admin || 'new_order' !== $email->id ) {
		return;
	}

	$customer_email = $order->get_billing_email();
	$customer_phone = $order->get_billing_phone();

	if ( $plain_text ) {
		/* translators: %s: customer email address */
		echo esc_html( sprintf( __( 'Email: %s', 'ozupay-demo' ), $customer_email ) ) . "\n";
		/* translators: %s: customer phone number */
		echo esc_html( sprintf( __( 'Phone: %s', 'ozupay-demo' ), $customer_phone ) ) . "\n\n";
		return;
	}

	echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-top:16px;margin-bottom:16px">'
		. '<tr><td style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:4px;padding:16px 20px;font-family:sans-serif;font-size:14px;color:#0c4a6e">'
		. '<strong style="display:block;margin-bottom:8px;font-size:15px">Customer contact details</strong>'
		. '<table role="presentation" border="0" cellpadding="0" cellspacing="0">'
		. '<tr>'
		. '<td style="padding:3px 0;font-weight:600;padding-right:12px">Email</td>'
		. '<td><a href="mailto:' . esc_attr( $customer_email ) . '" style="color:#0369a1">' . esc_html( $customer_email ) . '</a></td>'
		. '</tr>'
		. '<tr>'
		. '<td style="padding:3px 0;font-weight:600;padding-right:12px">Phone</td>'
		. '<td>' . esc_html( $customer_phone ) . '</td>'
		. '</tr>'
		. '</table>'
		. '</td></tr></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all values individually escaped above.
}, 10, 4 );

// ── Restricted admin access for demo testers ──────────────────────────────────
// Lets visitors see the OzuPay Dashboard and Transaction Log — read-only,
// no WooCommerce orders/products, Users, Plugins, or any other part of
// wp-admin — via a one-click auto-login link (no shared password to leak) on
// a single capability-locked account.
//
// Deliberately does NOT grant 'ozupay_manage_credentials' (Settings/Sandbox
// Testing): that panel renders decrypted Consumer Secret/Passkey in plain
// HTML (class-settings-page.php) and its Sandbox Testing panel can fire real
// STK Push prompts to arbitrary phone numbers with no rate limit of its own.
// Access to this page is unauthenticated in effect (the login link needs no
// secret), so granting that capability would hand both to anyone on the
// internet, not just intended testers.
//
// Only 'ozupay' (Dashboard) and 'ozupay-transactions' (Transaction Log) are
// allow-listed below — NOT every 'ozupay-*' page — because 'manage_woocommerce'
// alone (which OzuPay's Dashboard/Transactions require, and which the role
// must have to see them) also unlocks other OzuPay pages that leak secrets or
// allow persistent exfiltration:
//   - System Info prints the live Daraja callback URL with '?token=...'
//     embedded. That token is the ONLY effective auth gate on the callback
//     endpoints in sandbox mode (source-IP validation is skipped there — see
//     SECURITY.md / class-callback-security.php) — leaking it lets anyone
//     forge a synthetic "payment succeeded" callback for any order.
//   - Webhooks Setup lets a tester register a new WooCommerce webhook to any
//     URL they choose, silently siphoning future orders' PII (email/phone)
//     going forward.
// An allowlist survives future OzuPay admin pages being added; a blocklist
// would not.

define( 'OZPD_DEMO_ROLE', 'ozupay_demo_tester' );

/** The only admin.php?page= values the demo role may ever reach. */
function ozpd_demo_allowed_pages(): array {
	return [ 'ozupay', 'ozupay-transactions' ];
}

add_action( 'init', function (): void {
	if ( ! get_role( OZPD_DEMO_ROLE ) ) {
		add_role( OZPD_DEMO_ROLE, 'OzuPay Demo Tester', [] );
	}

	// Self-healing: re-grant if WooCommerce/OzuPay ever strip a role's caps.
	// Deliberately excludes 'ozupay_manage_credentials' — see block comment above.
	$role = get_role( OZPD_DEMO_ROLE );
	if ( $role ) {
		foreach ( [ 'read', 'manage_woocommerce' ] as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
		if ( $role->has_cap( 'ozupay_manage_credentials' ) ) {
			$role->remove_cap( 'ozupay_manage_credentials' );
		}
	}

	if ( ! username_exists( 'ozupay-demo-tester' ) ) {
		$user_id = wp_insert_user( [
			'user_login' => 'ozupay-demo-tester',
			'user_pass'  => wp_generate_password( 64, true, true ),
			'user_email' => 'demo-tester@demo.ozupay.com',
			'role'       => OZPD_DEMO_ROLE,
			'display_name' => 'Demo Tester',
		] );
		if ( ! is_wp_error( $user_id ) ) {
			update_option( 'ozpd_demo_tester_user_id', $user_id );
		}
	}
} );

/**
 * One-click login: /?ozpd_demo_login=1 signs the visitor into the shared,
 * capability-locked demo account and drops them on the OzuPay Dashboard.
 * A real admin (has manage_options) is left alone — just redirected — so
 * clicking the link never demotes an actual logged-in developer session.
 */
add_action( 'template_redirect', function (): void {
	if ( empty( $_GET['ozpd_demo_login'] ) ) {
		return;
	}

	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=ozupay' ) );
		exit;
	}

	$user_id = (int) get_option( 'ozpd_demo_tester_user_id' );
	$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

	if ( ! $user || ! in_array( OZPD_DEMO_ROLE, (array) $user->roles, true ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	wp_clear_auth_cookie();
	wp_set_current_user( $user->ID );
	wp_set_auth_cookie( $user->ID );
	do_action( 'wp_login', $user->user_login, $user );

	wp_safe_redirect( admin_url( 'admin.php?page=ozupay' ) );
	exit;
}, 5 ); // Must run before the homepage-redirect hook below — that one fires
        // unconditionally on is_front_page(), which "/?ozpd_demo_login=1" still is.

// Land the demo role straight on the OzuPay Dashboard after any login.
add_filter( 'login_redirect', function ( string $redirect_to, string $requested_redirect_to, $user ) {
	if ( $user instanceof WP_User && in_array( OZPD_DEMO_ROLE, (array) $user->roles, true ) ) {
		return admin_url( 'admin.php?page=ozupay' );
	}
	return $redirect_to;
}, 10, 3 );

/**
 * Strip every admin menu item outside the allow-listed OzuPay pages for the
 * demo role. Removes every other top-level menu (WooCommerce, Users,
 * Plugins, ...) plus OzuPay's own System Info / Webhooks / Settings /
 * About / Go-Live submenus — 'manage_woocommerce' unlocks all of those too,
 * not just Dashboard and Transaction Log.
 */
add_action( 'admin_menu', function (): void {
	if ( ! is_user_logged_in() || ! in_array( OZPD_DEMO_ROLE, (array) wp_get_current_user()->roles, true ) ) {
		return;
	}

	$allowed = ozpd_demo_allowed_pages();

	global $menu, $submenu;
	if ( is_array( $menu ) ) {
		foreach ( $menu as $item ) {
			$slug = $item[2] ?? '';
			if ( '' !== $slug && 'ozupay' !== $slug ) {
				remove_menu_page( $slug );
			}
		}
	}
	if ( isset( $submenu['ozupay'] ) && is_array( $submenu['ozupay'] ) ) {
		foreach ( $submenu['ozupay'] as $item ) {
			$slug = $item[2] ?? '';
			if ( '' !== $slug && ! in_array( $slug, $allowed, true ) ) {
				remove_submenu_page( 'ozupay', $slug );
			}
		}
	}
}, PHP_INT_MAX );

/**
 * Belt-and-braces: menu hiding above is UI only. Block direct navigation to
 * any admin screen outside the allow-listed OzuPay pages for the demo role.
 */
add_action( 'admin_init', function (): void {
	if ( wp_doing_ajax() || ! is_user_logged_in() ) {
		return;
	}
	if ( ! in_array( OZPD_DEMO_ROLE, (array) wp_get_current_user()->roles, true ) ) {
		return;
	}

	global $pagenow;
	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

	$is_allowed = 'admin.php' === $pagenow && in_array( $page, ozpd_demo_allowed_pages(), true );
	if ( ! $is_allowed ) {
		wp_safe_redirect( admin_url( 'admin.php?page=ozupay' ) );
		exit;
	}
} );

/**
 * Trim the admin bar down to just the essentials for the shared account —
 * no site-name/customizer link, comments, "+New" menu, or My Sites switcher.
 */
add_action( 'admin_bar_menu', function ( WP_Admin_Bar $admin_bar ): void {
	if ( ! is_user_logged_in() || ! in_array( OZPD_DEMO_ROLE, (array) wp_get_current_user()->roles, true ) ) {
		return;
	}
	foreach ( [ 'wp-logo', 'site-name', 'comments', 'new-content', 'my-sites' ] as $node_id ) {
		$admin_bar->remove_node( $node_id );
	}
}, 999 );

// ── IP rate limiting: max 3 checkouts per IP per hour ────────────────────────

/**
 * Returns the current request IP as a sanitised string.
 */
function ozpd_get_ip(): string {
	return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
}

/**
 * Returns the transient key for the current IP's checkout count.
 */
function ozpd_rate_key(): string {
	return 'ozpd_rl_' . md5( ozpd_get_ip() );
}

// Classic checkout: block on process.
add_action( 'woocommerce_checkout_process', function (): void {
	if ( (int) get_transient( ozpd_rate_key() ) >= 3 ) {
		wc_add_notice(
			__( 'Too many checkout attempts from your connection. Please wait an hour and try again.', 'ozupay-demo' ),
			'error'
		);
	}
} );

// Classic checkout: increment counter after order is created.
add_action( 'woocommerce_checkout_order_created', function (): void {
	$key   = ozpd_rate_key();
	$count = (int) get_transient( $key );
	set_transient( $key, $count + 1, HOUR_IN_SECONDS );
} );
