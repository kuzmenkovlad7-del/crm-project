<?php
/**
 * Dating.com adapter — Phase 1: browser-assisted read-only sync
 *
 * Approach:
 *   The operator opens dating.com chats in their own browser session, then runs
 *   a bookmarklet. The bookmarklet reads already-called chat URLs from the
 *   browser performance API, re-fetches the messages using the browser's own
 *   session cookies (credentials:include to api.dating.com only), and POSTs
 *   sanitized message data to our WordPress import endpoint. No cookies,
 *   tokens, or auth headers are ever sent to our server.
 *
 * Confirmed endpoints (browser inspection 2026-05-25):
 *   GET https://api.dating.com/dialogs/messages/{op_id}:{contact_id}?omit=0&select=50
 *   GET https://api.dating.com/users/private/{op_id}  (self-profile only)
 *
 * NOT implemented (Phase 2, pending confirmation):
 *   - Server-side login / auth
 *   - Contact list / inbox endpoint
 *   - Send message
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

if ( ! defined( 'DC_API_BASE' ) ) {
	define( 'DC_API_BASE', 'https://api.dating.com' );
}

// ─────────────────────────────────────────────
// Cookie / session helpers (Phase 2 — server-side only)
// ─────────────────────────────────────────────

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
// Authentication — PENDING (Phase 2)
// ─────────────────────────────────────────────

/**
 * Authentication stub — always returns false.
 * NOT IMPLEMENTED — login endpoint and form fields not yet confirmed.
 * TODO: implement after DATING_COM_BROWSER_INSPECTION.md Section 1 is confirmed.
 */
function dc_authenticate( $id, $cookie_file ) {
	return false;
}

// ─────────────────────────────────────────────
// HTTP helpers (Phase 2 server-side use)
// ─────────────────────────────────────────────

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
// Per-model import token (token-based auth for bookmarklet)
// ─────────────────────────────────────────────

/**
 * Returns (or creates) a random 40-char import token for a model.
 * Stored in post meta. Token is shown in the bookmarklet; it proves the
 * operator viewed the CRM model page. No WordPress session needed at import time.
 */
function dc_get_model_import_token( $model_id ) {
	$token = get_post_meta( $model_id, '_dc_import_token', true );
	if ( ! $token ) {
		$token = wp_generate_password( 40, false );
		update_post_meta( $model_id, '_dc_import_token', $token );
	}
	return $token;
}

function dc_verify_import_token( $model_id, $token ) {
	if ( ! $model_id || ! $token ) {
		return false;
	}
	$stored = get_post_meta( $model_id, '_dc_import_token', true );
	return $stored && hash_equals( $stored, $token );
}

// ─────────────────────────────────────────────
// Local storage helpers
// ─────────────────────────────────────────────

function dc_get_stored_contacts( $model_id ) {
	$v = get_post_meta( $model_id, '_dc_contacts', true );
	return is_array( $v ) ? $v : [];
}

function dc_get_stored_messages( $model_id, $contact_id ) {
	$key = '_dc_messages_' . sanitize_key( $contact_id );
	$v   = get_post_meta( $model_id, $key, true );
	return is_array( $v ) ? $v : [];
}

// ─────────────────────────────────────────────
// AJAX — Import messages (called by bookmarklet)
// ─────────────────────────────────────────────

add_action( 'wp_ajax_dc_import_messages',        'dc_handle_import_messages_ajax' );
add_action( 'wp_ajax_nopriv_dc_import_messages', 'dc_handle_import_messages_ajax' );

function dc_handle_import_messages_ajax() {
	// CORS — bookmarklet runs on https://dating.com and POSTs here.
	// FormData POST is a "simple" CORS request (no preflight needed).
	$allowed_origins = [ 'https://dating.com', 'https://www.dating.com' ];
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
	if ( in_array( $origin, $allowed_origins, true ) ) {
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Vary: Origin' );
	}

	$model_id = intval( $_POST['model_id'] ?? 0 );
	$token    = sanitize_text_field( $_POST['token'] ?? '' );

	if ( ! $model_id || ! dc_verify_import_token( $model_id, $token ) ) {
		wp_send_json_error( 'Недействительный токен. Обновите страницу модели в CRM.' );
	}

	if ( get_field( 'source_model', $model_id ) !== 'dating_com' ) {
		wp_send_json_error( 'Модель не является Dating.com.' );
	}

	$operator_id  = sanitize_text_field( $_POST['operator_id'] ?? '' );
	$contact_id   = sanitize_text_field( $_POST['contact_id'] ?? '' );
	$messages_raw = isset( $_POST['messages'] ) ? wp_unslash( $_POST['messages'] ) : '[]';

	if ( $operator_id === '' || $contact_id === '' ) {
		wp_send_json_error( 'Отсутствуют operator_id или contact_id.' );
	}

	if ( ! ctype_digit( $operator_id ) || ! ctype_digit( $contact_id ) ) {
		wp_send_json_error( 'Некорректный формат ID (ожидаются только цифры).' );
	}

	$raw_messages = json_decode( $messages_raw, true );
	if ( ! is_array( $raw_messages ) ) {
		wp_send_json_error( 'Неверный формат данных сообщений.' );
	}

	// Sanitize — keep only expected scalar fields, drop everything else
	$sanitized = [];
	foreach ( $raw_messages as $msg ) {
		if ( ! is_array( $msg ) ) {
			continue;
		}
		$sanitized[] = [
			'id'        => intval( $msg['id'] ?? 0 ),
			'sender'    => sanitize_text_field( (string) ( $msg['sender'] ?? '' ) ),
			'recipient' => sanitize_text_field( (string) ( $msg['recipient'] ?? '' ) ),
			'timestamp' => intval( $msg['timestamp'] ?? 0 ),
			'read'      => ! empty( $msg['read'] ) ? 1 : 0,
			'text'      => sanitize_textarea_field( mb_substr( (string) ( $msg['text'] ?? '' ), 0, 2000 ) ),
			'tag'       => sanitize_text_field( (string) ( $msg['tag'] ?? '' ) ),
			'status'    => sanitize_text_field( (string) ( $msg['status'] ?? '' ) ),
		];
	}

	// Merge with existing messages, de-duplicated by id
	$existing = dc_get_stored_messages( $model_id, $contact_id );
	$indexed  = [];
	foreach ( $existing as $m ) {
		if ( ! empty( $m['id'] ) ) {
			$indexed[ $m['id'] ] = $m;
		}
	}
	foreach ( $sanitized as $m ) {
		if ( ! empty( $m['id'] ) ) {
			$indexed[ $m['id'] ] = $m;
		}
	}
	usort( $indexed, function ( $a, $b ) {
		return (int) $a['timestamp'] - (int) $b['timestamp'];
	} );
	$merged = array_values( $indexed );

	update_post_meta( $model_id, '_dc_messages_' . sanitize_key( $contact_id ), $merged );

	// Update contact index
	$contacts  = dc_get_stored_contacts( $model_id );
	$last      = ! empty( $merged ) ? end( $merged ) : null;
	$last_text = $last ? (string) ( $last['text'] ?? '' ) : '';
	$last_ts   = $last ? (int) ( $last['timestamp'] ?? 0 ) : 0;

	$unread = 0;
	foreach ( $merged as $m ) {
		if ( (string) $m['sender'] !== (string) $operator_id && empty( $m['read'] ) ) {
			$unread++;
		}
	}

	$found = false;
	foreach ( $contacts as &$c ) {
		if ( (string) $c['contact_id'] === (string) $contact_id ) {
			$c['operator_id']    = $operator_id;
			$c['last_message']   = mb_substr( $last_text, 0, 120 );
			$c['last_timestamp'] = $last_ts;
			$c['unread_count']   = $unread;
			$c['import_time']    = time();
			$found = true;
			break;
		}
	}
	unset( $c );

	if ( ! $found ) {
		$contacts[] = [
			'contact_id'     => $contact_id,
			'operator_id'    => $operator_id,
			'last_message'   => mb_substr( $last_text, 0, 120 ),
			'last_timestamp' => $last_ts,
			'unread_count'   => $unread,
			'import_time'    => time(),
		];
	}

	usort( $contacts, function ( $a, $b ) {
		return (int) $b['last_timestamp'] - (int) $a['last_timestamp'];
	} );

	update_post_meta( $model_id, '_dc_contacts', array_values( $contacts ) );

	wp_send_json_success( [
		'imported'       => count( $sanitized ),
		'total_messages' => count( $merged ),
		'contact_id'     => $contact_id,
	] );
}

// ─────────────────────────────────────────────
// AJAX handler — Contact list (reads local storage)
// ─────────────────────────────────────────────

function dc_handle_get_contact_list( $id ) {
	$contacts = dc_get_stored_contacts( $id );

	if ( empty( $contacts ) ) {
		wp_send_json_success( dc_render_no_contacts_hint() );
		return;
	}

	$placeholder = 'https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png';
	$html = '';

	foreach ( $contacts as $c ) {
		$contact_id  = esc_attr( $c['contact_id'] );
		$last_text   = esc_html( mb_substr( (string) ( $c['last_message'] ?? '' ), 0, 60 ) );
		$last_time   = ! empty( $c['last_timestamp'] )
		             ? esc_html( date( 'd.m H:i', (int) $c['last_timestamp'] ) )
		             : '';
		$unread      = (int) ( $c['unread_count'] ?? 0 );
		$unread_html = $unread > 0
		             ? '<span class="unread-message" style="color:#007bff;font-weight:bold;">&#9993; x' . $unread . '</span>'
		             : '';

		$html .= '
		<div class="contact-card d-flex align-items-center gap-2 mt-2 mb-2">
			<img src="' . esc_url( $placeholder ) . '" alt="" style="width:30px;height:30px;border-radius:50%;">
			<div class="d-flex align-items-center justify-content-between" style="width:100%;">
				<p id="openChat" data-user_id="' . $contact_id . '" data-chat_id="0" class="name mb-0" style="cursor:pointer;">
					<strong>ID: ' . $contact_id . '</strong>
					<br><small class="text-muted">' . $last_text . '</small>
				</p>
				<p id="openChat" data-user_id="' . $contact_id . '" data-chat_id="0" class="messages mb-0" style="cursor:pointer;text-align:right;">
					' . $unread_html . '
					<br><small class="text-muted">' . $last_time . '</small>
				</p>
			</div>
		</div>';
	}

	wp_send_json_success( $html );
}

// ─────────────────────────────────────────────
// AJAX handler — Open chat (reads local storage)
// ─────────────────────────────────────────────

function dc_handle_open_chat( $id, $contact_id ) {
	$messages = dc_get_stored_messages( $id, $contact_id );
	$op_id    = get_field( 'id_model', $id );

	$html  = '<div class="chat-user-info d-flex gap-3 mb-3">';
	$html .= '<div class="information">';
	$html .= '<h5 class="mb-2"><strong>Dating.com</strong></h5>';
	$html .= '<p class="m-0">&#x1F4AC; Контакт ID: ' . esc_html( $contact_id ) . '</p>';
	$html .= '</div></div>';

	$html .= '<div class="messages">';
	$html .= '<div class="chat-messages" data-chat_id="0"'
	       . ' data-user_id="' . esc_attr( $contact_id ) . '"'
	       . ' data-source="dating_com">';

	if ( empty( $messages ) ) {
		$html .= '<div class="text-center text-muted p-4">'
		       . '<p><strong>Нет импортированных сообщений</strong></p>'
		       . '<p>Откройте этот чат на <a href="https://dating.com" target="_blank" rel="noopener">Dating.com</a>'
		       . ' и запустите буклет синхронизации со страницы модели.</p>'
		       . '</div>';
	} else {
		foreach ( $messages as $msg ) {
			$is_outbound = ( (string) ( $msg['sender'] ?? '' ) === (string) $op_id );
			$align       = $is_outbound ? 'text-end text-success' : 'text-start text-primary';
			$sender      = $is_outbound ? 'Модель' : 'Клиент';
			$text        = isset( $msg['text'] ) ? esc_html( $msg['text'] ) : '';
			$date        = ! empty( $msg['timestamp'] )
			             ? esc_html( date( 'd.m.Y H:i', (int) $msg['timestamp'] ) )
			             : esc_html( (string) ( $msg['timestamp'] ?? '' ) );

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
// AJAX handler — Check / poll messages (reads local storage)
// ─────────────────────────────────────────────

function dc_handle_check_message( $id, $contact_id ) {
	$messages = dc_get_stored_messages( $id, $contact_id );
	$op_id    = get_field( 'id_model', $id );

	$html = '<div class="chat-messages" data-chat_id="0"'
	      . ' data-user_id="' . esc_attr( $contact_id ) . '"'
	      . ' data-source="dating_com">';

	if ( empty( $messages ) ) {
		$html .= '<div class="text-center text-muted p-4"><p>Нет импортированных сообщений.</p></div>';
	} else {
		foreach ( $messages as $msg ) {
			$is_outbound = ( (string) ( $msg['sender'] ?? '' ) === (string) $op_id );
			$align       = $is_outbound ? 'text-end text-success' : 'text-start text-primary';
			$sender      = $is_outbound ? 'Модель' : 'Клиент';
			$text        = isset( $msg['text'] ) ? esc_html( $msg['text'] ) : '';
			$date        = ! empty( $msg['timestamp'] )
			             ? esc_html( date( 'd.m.Y H:i', (int) $msg['timestamp'] ) )
			             : esc_html( (string) ( $msg['timestamp'] ?? '' ) );

			$html .= '<div class="chat-message ' . $align . ' mb-3">'
			       . '<p>' . $sender . ' ( <small>' . $date . '</small> )</p>'
			       . '<p class="text-dark" style="font-size:14px;">' . $text . '</p>'
			       . '</div>';
		}
	}

	$html .= '</div>';

	wp_send_json_success( $html );
}

// ─────────────────────────────────────────────
// Sync panel & bookmarklet
// ─────────────────────────────────────────────

function dc_render_no_contacts_hint() {
	return '<div class="text-center text-muted mt-3 mb-3 p-3" style="border:1px dashed #e91e8c;border-radius:6px;">'
	     . '<strong style="color:#e91e8c;">Dating.com</strong><br>'
	     . '<small>Контакты не синхронизированы.<br>'
	     . 'Используйте буклет синхронизации на этой странице.</small>'
	     . '</div>';
}

/**
 * Renders the Dating.com sync helper panel for the model detail page.
 * Outputs Russian instructions, a draggable bookmarklet button,
 * a readonly textarea with the full console snippet, a copy-to-clipboard
 * button, and a contacts-refresh button.
 *
 * The bookmarklet code is built entirely in page JavaScript so that no PHP
 * string concatenation can generate malformed JS. Config values are passed
 * via wp_json_encode() and interpolated with JSON.stringify() in JS.
 */
function dc_render_sync_panel( $id ) {
	$token    = dc_get_model_import_token( $id );
	$ajax_url = admin_url( 'admin-ajax.php' );
	$cfg_json = wp_json_encode( [
		'ajax_url' => $ajax_url,
		'model_id' => (string) $id,
		'token'    => $token,
	] );

	ob_start();
	?>
	<div class="dc-sync-panel mt-4 p-3 rounded border" id="dc-sync-panel">
		<h6 class="fw-bold mb-3">
			<span style="color:#c2185b;">&#9670;</span>
			<span style="color:#c2185b;">Dating.com</span> — Синхронизация сообщений
		</h6>

		<ol class="dc-sync-steps mb-3">
			<li>Откройте <a href="https://dating.com" target="_blank" rel="noopener">Dating.com</a> в <strong>этом же браузере</strong> и войдите в аккаунт модели.</li>
			<li>Откройте <strong>1–10 нужных диалогов</strong> — просто кликните по каждому, чтобы браузер загрузил историю сообщений.</li>
			<li><strong>Перетащите кнопку ниже</strong> в панель закладок браузера. Или нажмите «Скопировать код» и вставьте в консоль (F12 → Console) на сайте Dating.com.</li>
			<li>Находясь на сайте Dating.com — нажмите закладку или выполните код в консоли.</li>
			<li>После сообщения «синхронизировано» — нажмите <strong>«Обновить контакты»</strong> ниже.</li>
		</ol>

		<div class="d-flex gap-2 flex-wrap mb-2 align-items-center">
			<a class="btn btn-sm btn-outline-secondary dc-bookmarklet"
			   id="dc-bookmarklet-link"
			   href="#"
			   title="Перетащите в панель закладок браузера">
				&#128278;&nbsp;DC&nbsp;Sync — перетащить в закладки
			</a>
			<button type="button"
			        class="btn btn-sm btn-outline-dark"
			        id="dc-copy-bookmarklet">
				&#128203;&nbsp;Скопировать код
			</button>
			<button type="button"
			        class="btn btn-sm btn-outline-primary"
			        id="dc-refresh-contacts">
				&#8635;&nbsp;Обновить контакты
			</button>
		</div>

		<textarea id="dc-bm-code"
		          class="form-control mt-2 mb-2"
		          readonly
		          rows="3"
		          style="font-size:11px;font-family:monospace;resize:vertical;"
		          placeholder="Загрузка кода…"></textarea>

		<div id="dc-sync-status" class="text-muted" style="font-size:12px;min-height:18px;"></div>
	</div>

	<script>
	jQuery(function($){
		// Config values injected by PHP via wp_json_encode — never concatenated as raw strings.
		var cfg = <?= $cfg_json; ?>;
		// JSON.stringify produces safely quoted JS string literals for W, M, T.
		var W = JSON.stringify(cfg.ajax_url);
		var M = JSON.stringify(cfg.model_id);
		var T = JSON.stringify(cfg.token);

		// Build the entire bookmarklet in JavaScript.
		// Single-quotes inside these double-quoted JS strings need no escaping.
		// \\n inside a JS double-quoted string becomes \n in the string value,
		// which is the JS newline escape inside the bookmarklet's alert() calls.
		var code = "(function(){"
			+ "var W="+W+",M="+M+",T="+T+";"
			+ "var e=performance.getEntriesByType?performance.getEntriesByType('resource'):[];"
			+ "var s={},p=[];"
			+ "for(var i=0;i<e.length;i++){"
			+   "var x=e[i].name.match(/api\\.dating\\.com\\/dialogs\\/messages\\/(\\d+):(\\d+)/);"
			+   "if(x&&!s[x[1]+':'+x[2]]){s[x[1]+':'+x[2]]=1;p.push({o:x[1],c:x[2]});}"
			+ "}"
			+ "if(!p.length){"
			+   "alert('DC Sync: Не найдено диалогов с api.dating.com.\\nОткройте 1–10 чатов на Dating.com и повторите.');"
			+   "return;"
			+ "}"
			+ "var tot=p.length,done=0,errs=0;"
			+ "p.forEach(function(pr){"
			+   "fetch('https://api.dating.com/dialogs/messages/'+pr.o+':'+pr.c+'?omit=0&select=50',"
			+         "{credentials:'include',headers:{'Accept':'application/json'}})"
			+   ".then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})"
			+   ".then(function(msgs){"
			+     "if(!Array.isArray(msgs))throw new Error('bad_resp');"
			+     "var safe=msgs.map(function(m){"
			+       "return{id:+(m.id||0),sender:''+(m.sender||''),recipient:''+(m.recipient||''),"
			+             "timestamp:+(m.timestamp||0),read:m.read?1:0,"
			+             "text:(''+(m.text||'')).substring(0,2000),"
			+             "tag:''+(m.tag||''),status:''+(m.status||'')};"
			+     "});"
			+     "var fd=new FormData();"
			+     "fd.append('action','dc_import_messages');"
			+     "fd.append('token',T);"
			+     "fd.append('model_id',M);"
			+     "fd.append('operator_id',pr.o);"
			+     "fd.append('contact_id',pr.c);"
			+     "fd.append('messages',JSON.stringify(safe));"
			+     "return fetch(W,{method:'POST',body:fd});"
			+   "})"
			+   ".then(function(r){return r.json();})"
			+   ".then(function(){"
			+     "done++;"
			+     "if(done+errs===tot){"
			+       "alert('DC Sync: синхронизировано '+done+' из '+tot"
			+             "+(errs?' (ошибок: '+errs+')':'')"
			+             "+'.\\nВернитесь в CRM и нажмите «Обновить контакты».');"
			+     "}"
			+   "})"
			+   ".catch(function(err){"
			+     "errs++;console.error('DC Sync ('+pr.c+'):',err);"
			+     "if(done+errs===tot){"
			+       "alert('DC Sync: '+done+'/'+tot+' ок. Ошибок: '+errs+'. F12 → Console.');"
			+     "}"
			+   "});"
			+ "});"
			+ "})();";

		$('#dc-bookmarklet-link').attr('href', 'javascript:' + encodeURIComponent(code));
		$('#dc-bm-code').val(code);

		$('#dc-copy-bookmarklet').on('click', function(){
			var bm = $('#dc-bm-code').val();
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(bm).then(function(){
					$('#dc-sync-status').text('Код скопирован. Вставьте в консоль Dating.com (F12 > Console > Enter).');
				}).catch(function(){
					$('#dc-bm-code').select();
					try {
						document.execCommand('copy');
						$('#dc-sync-status').text('Код скопирован (запасной метод).');
					} catch(e) {
						$('#dc-sync-status').text('Не удалось скопировать автоматически. Выделите код вручную (Ctrl+A в поле).');
					}
				});
			} else {
				$('#dc-bm-code').select();
				try {
					document.execCommand('copy');
					$('#dc-sync-status').text('Код скопирован (запасной метод).');
				} catch(e) {
					$('#dc-sync-status').text('Не удалось скопировать автоматически. Выделите код вручную (Ctrl+A в поле).');
				}
			}
		});

		$('#dc-refresh-contacts').on('click', function(){
			$('#dc-sync-status').text('Обновление...');
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				dataType: 'json',
				data: { action: 'get_contact_list', id: modelId },
				success: function(r){
					if (r.success) {
						$('.contact-list .response').html(r.data);
						$('#dc-sync-status').text('Контакты обновлены.');
					} else {
						$('#dc-sync-status').text('Ошибка: ' + r.data);
					}
				},
				error: function(){
					$('#dc-sync-status').text('AJAX-ошибка при обновлении контактов.');
				}
			});
		});
	});
	</script>
	<?php
	return ob_get_clean();
}

// ─────────────────────────────────────────────
// ACF: register source_model field
// ─────────────────────────────────────────────

add_action( 'acf/init', 'dc_register_source_model_field' );
function dc_register_source_model_field() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}
	acf_add_local_field_group( [
		'key'    => 'group_crm_source_model',
		'title'  => 'Источник / Source',
		'fields' => [
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
		'position'        => 'side',
		'style'           => 'default',
		'label_placement' => 'top',
		'active'          => true,
	] );
}
