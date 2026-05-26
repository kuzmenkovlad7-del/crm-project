<?php
/**
 * Dating.com adapter — Phase 1 scaffold
 * Read-only: contact list pending state + confirmed message loading.
 *
 * CONFIRMED endpoints (browser inspection 2026-05-25):
 *   GET https://api.dating.com/dialogs/messages/{op_id}:{contact_id}?omit={n}&select={n}
 *   GET https://api.dating.com/users/private/{op_id}  (operator self-profile only)
 *
 * PENDING endpoints (do not implement until confirmed):
 *   Contact list / inbox  — see DATING_COM_BROWSER_INSPECTION.md Section 3
 *   Contact profile       — see DATING_COM_BROWSER_INSPECTION.md Section 4
 *   Send message          — see DATING_COM_BROWSER_INSPECTION.md Section 6
 *
 * NEVER in this file:
 *   - Captcha bypass / headless browser
 *   - Akamai / Cloudflare bypass
 *   - Hardcoded cookies, tokens, or credentials
 *   - Broadcast / mass outreach
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Confirmed API base from browser inspection.
if ( ! defined( 'DC_API_BASE' ) ) {
	define( 'DC_API_BASE', 'https://api.dating.com' );
}

// ─────────────────────────────────────────────
// Cookie / session helpers
// ─────────────────────────────────────────────

/**
 * Returns the Dating.com cookie file path for a model.
 * Isolated from RC cookies by the "dating_com_" prefix.
 */
function dc_get_cookie_file( $id ) {
	$id_model   = get_field( 'id_model', $id );
	$upload_dir = wp_upload_dir();
	$cookie_dir = $upload_dir['basedir'] . '/cookies/';
	if ( ! file_exists( $cookie_dir ) ) {
		wp_mkdir_p( $cookie_dir );
	}
	return $cookie_dir . 'cookie_dating_com_' . $id_model . '.txt';
}

// ─────────────────────────────────────────────
// Authentication — PENDING
// ─────────────────────────────────────────────

/**
 * Authentication stub.
 *
 * NOT IMPLEMENTED — login endpoint and form fields not yet confirmed.
 *
 * TODO (after completing DATING_COM_BROWSER_INSPECTION.md Section 1):
 *   1. Set DC_LOGIN_URL to the confirmed POST target.
 *   2. Set $post_data field names to match the login form.
 *   3. Confirm whether captcha is present. If captcha is present,
 *      server-side auth is NOT viable — fall back to Path C instead.
 *   4. Confirm session mechanism (cookie names to persist).
 *
 * @return false Always returns false until login flow is confirmed.
 */
function dc_authenticate( $id, $cookie_file ) {
	// DC_LOGIN_URL is intentionally undefined until Section 1 is confirmed.
	// Do not guess or hardcode a URL here.
	return false;
}

// ─────────────────────────────────────────────
// HTTP helpers
// ─────────────────────────────────────────────

/**
 * Returns HTTP headers for requests to api.dating.com.
 * Mirrors get_common_headers() structure used for RomanceCompass.
 */
function dc_get_common_headers() {
	return [
		'Accept: application/json, text/javascript, */*; q=0.01',
		'Accept-Encoding: gzip, deflate, br',
		'Accept-Language: en-US,en;q=0.9',
		'Connection: keep-alive',
		'Host: api.dating.com',
		'Origin: https://dating.com',
		'Referer: https://dating.com/',
		'Sec-Fetch-Dest: empty',
		'Sec-Fetch-Mode: cors',
		'Sec-Fetch-Site: same-site',
		'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		'X-Requested-With: XMLHttpRequest',
	];
}

/**
 * Makes a cURL request to api.dating.com using the stored session cookie.
 * Read-only GET only in Phase 1.
 *
 * @return array [$http_code, $response_body, $curl_error]
 */
function dc_make_request( $url, $cookie_file, $headers = null ) {
	if ( $headers === null ) {
		$headers = dc_get_common_headers();
	}

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookie_file );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	curl_setopt( $ch, CURLOPT_ENCODING, 'gzip, deflate, br' );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' );

	$response  = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$error     = curl_error( $ch );
	curl_close( $ch );

	return [ $http_code, $response, $error ];
}

// ─────────────────────────────────────────────
// AJAX handler — Contact list
// ─────────────────────────────────────────────

/**
 * Contact list handler — PENDING endpoint confirmation.
 *
 * Returns a clear pending state in the CRM UI.
 *
 * TODO: Replace stub once Section 3 of DATING_COM_BROWSER_INSPECTION.md is filled in.
 *   Expected pattern: GET https://api.dating.com/dialogs/... (or similar inbox endpoint)
 *   Plug the confirmed URL in here and parse the JSON response to build contact cards.
 */
function dc_handle_get_contact_list( $id ) {
	// ── PLUG CONTACT LIST ENDPOINT HERE ─────────────────────────────────
	// $op_id      = get_field( 'id_model', $id );
	// $cookie_file = dc_get_cookie_file( $id );
	// $url        = DC_API_BASE . '/dialogs/...'; // <── confirmed URL goes here
	// ─────────────────────────────────────────────────────────────────────

	wp_send_json_success(
		'<div class="text-center text-muted mt-3 mb-3 p-3" '
		. 'style="border:1px dashed #ccc;border-radius:6px;">'
		. '<strong>Dating.com</strong><br>'
		. '<small>Список контактов: ожидание подтверждения endpoint.<br>'
		. 'Contact list endpoint pending — see '
		. '<em>DATING_COM_BROWSER_INSPECTION.md</em> Section&nbsp;3.</small>'
		. '</div>'
	);
}

// ─────────────────────────────────────────────
// AJAX handler — Open chat (message history)
// ─────────────────────────────────────────────

/**
 * Open-chat handler — uses the CONFIRMED messages endpoint.
 *
 * Confirmed endpoint:
 *   GET https://api.dating.com/dialogs/messages/{op_id}:{contact_id}?omit=0&select=50
 *
 * Contact profile data (name, photo, country, age) is NOT included in Phase 1
 * because the profile endpoint path has not been confirmed yet
 * (see DATING_COM_BROWSER_INSPECTION.md Section 4).
 *
 * @param int $id         WordPress post ID of the model.
 * @param int $contact_id Dating.com user ID of the contact.
 */
function dc_handle_open_chat( $id, $contact_id ) {
	$cookie_file = dc_get_cookie_file( $id );

	if ( ! file_exists( $cookie_file ) ) {
		wp_send_json_error(
			'Dating.com: сессия не активна. '
			. 'Авторизация ожидает подтверждения метода входа — '
			. 'см. DATING_COM_BROWSER_INSPECTION.md Section 1.'
		);
	}

	$op_id = get_field( 'id_model', $id );
	$url   = DC_API_BASE . '/dialogs/messages/'
	       . intval( $op_id ) . ':' . intval( $contact_id )
	       . '?omit=0&select=50';

	[ $http_code, $response, $error ] = dc_make_request( $url, $cookie_file );

	if ( $http_code !== 200 ) {
		wp_send_json_error(
			'Dating.com: ошибка запроса сообщений (HTTP ' . $http_code . ')'
		);
	}

	$messages = json_decode( $response, true );
	if ( ! is_array( $messages ) ) {
		wp_send_json_error( 'Dating.com: некорректный ответ от API.' );
	}

	// ── Contact info header ──────────────────────────────────────────────
	// TODO: Replace static ID display with full profile data once Section 4
	//       of DATING_COM_BROWSER_INSPECTION.md is confirmed.
	//       Expected profile endpoint: GET https://api.dating.com/users/{contact_id}
	// ─────────────────────────────────────────────────────────────────────
	$html  = '<div class="chat-user-info d-flex gap-3 mb-3">';
	$html .= '<div class="information">';
	$html .= '<h5 class="mb-2"><strong>Dating.com</strong></h5>';
	$html .= '<p class="m-0">ID: ' . esc_html( $contact_id ) . '</p>';
	$html .= '<p class="m-0 text-muted"><small>Профиль: ожидание подтверждения endpoint</small></p>';
	$html .= '</div></div>';

	// ── Message thread ───────────────────────────────────────────────────
	$html .= '<div class="messages">';
	$html .= '<div class="chat-messages"'
	       . ' data-chat_id="0"'
	       . ' data-user_id="' . esc_attr( $contact_id ) . '"'
	       . ' data-source="dating_com">';

	if ( empty( $messages ) ) {
		$html .= '<div class="text-center text-muted mt-4 mb-4">'
		       . '<strong>Нет сообщений</strong></div>';
	} else {
		foreach ( $messages as $msg ) {
			$is_outbound = ( (string) ( $msg['sender'] ?? '' ) === (string) $op_id );
			$align       = $is_outbound ? 'text-end text-success' : 'text-start text-primary';
			$sender      = $is_outbound ? 'Модель' : 'Клиент';
			$text        = isset( $msg['text'] ) ? esc_html( $msg['text'] ) : '';
			$date        = isset( $msg['timestamp'] ) ? esc_html( $msg['timestamp'] ) : '';

			$html .= '<div class="chat-message ' . $align . ' mb-3">'
			       . '<p>' . $sender . ' ( <small>' . $date . '</small> )</p>'
			       . '<p class="text-dark" style="font-size:14px;">' . $text . '</p>'
			       . '</div>';
		}
	}

	$html .= '</div></div>';

	wp_send_json_success( $html );
}

// ─────────────────────────────────────────────
// AJAX handler — Check / poll messages
// ─────────────────────────────────────────────

/**
 * Check-message handler — same confirmed endpoint as open_chat.
 * Called every 15 s by main.js to refresh the open chat modal.
 *
 * @param int $id         WordPress post ID of the model.
 * @param int $contact_id Dating.com user ID of the contact.
 */
function dc_handle_check_message( $id, $contact_id ) {
	$cookie_file = dc_get_cookie_file( $id );

	if ( ! file_exists( $cookie_file ) ) {
		wp_send_json_error( 'Dating.com: сессия не активна.' );
	}

	$op_id = get_field( 'id_model', $id );
	$url   = DC_API_BASE . '/dialogs/messages/'
	       . intval( $op_id ) . ':' . intval( $contact_id )
	       . '?omit=0&select=50';

	[ $http_code, $response, $error ] = dc_make_request( $url, $cookie_file );

	if ( $http_code !== 200 ) {
		wp_send_json_error(
			'Dating.com: ошибка запроса сообщений (HTTP ' . $http_code . ')'
		);
	}

	$messages = json_decode( $response, true );
	if ( ! is_array( $messages ) ) {
		wp_send_json_error( 'Dating.com: некорректный ответ от API.' );
	}

	$html  = '<div class="chat-messages"'
	       . ' data-chat_id="0"'
	       . ' data-user_id="' . esc_attr( $contact_id ) . '"'
	       . ' data-source="dating_com">';

	foreach ( $messages as $msg ) {
		$is_outbound = ( (string) ( $msg['sender'] ?? '' ) === (string) $op_id );
		$align       = $is_outbound ? 'text-end text-success' : 'text-start text-primary';
		$sender      = $is_outbound ? 'Модель' : 'Клиент';
		$text        = isset( $msg['text'] ) ? esc_html( $msg['text'] ) : '';
		$date        = isset( $msg['timestamp'] ) ? esc_html( $msg['timestamp'] ) : '';

		$html .= '<div class="chat-message ' . $align . ' mb-3">'
		       . '<p>' . $sender . ' ( <small>' . $date . '</small> )</p>'
		       . '<p class="text-dark" style="font-size:14px;">' . $text . '</p>'
		       . '</div>';
	}

	$html .= '</div>';

	wp_send_json_success( $html );
}

// ─────────────────────────────────────────────
// ACF: register source_model field
// ─────────────────────────────────────────────

/**
 * Programmatically registers the source_model Select field on the model post type.
 * Defaults to romance_compass so all existing models are unaffected.
 */
add_action( 'acf/init', 'dc_register_source_model_field' );
function dc_register_source_model_field() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}
	acf_add_local_field_group( [
		'key'      => 'group_crm_source_model',
		'title'    => 'Источник / Source',
		'fields'   => [
			[
				'key'           => 'field_crm_source_model',
				'label'         => 'Источник',
				'name'          => 'source_model',
				'type'          => 'select',
				'choices'       => [
					'romance_compass' => 'RomanceCompass',
					'dating_com'      => 'Dating.com',
				],
				'default_value' => 'romance_compass',
				'required'      => 0,
				'return_format' => 'value',
				'instructions'  => 'Выберите платформу. По умолчанию: RomanceCompass.',
			],
		],
		'location' => [
			[
				[
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'model',
				],
			],
		],
		'position'       => 'side',
		'style'          => 'default',
		'label_placement'=> 'top',
		'active'         => true,
	] );
}
