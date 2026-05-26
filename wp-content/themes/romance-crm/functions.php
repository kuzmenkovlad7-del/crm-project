<?php
/**
 * romance-crm functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package romance-crm
 */

if ( ! defined( '_S_VERSION' ) ) {
	// Replace the version number of the theme on each release.
	define( '_S_VERSION', '1.0.0' );
}

// Dating.com adapter — Phase 1 scaffold (read-only, no auth yet).
require_once get_template_directory() . '/dating-com-functions.php';

/**
 * Sets up theme defaults and registers support for various WordPress features.
 *
 * Note that this function is hooked into the after_setup_theme hook, which
 * runs before the init hook. The init hook is too late for some features, such
 * as indicating support for post thumbnails.
 */
function romance_crm_setup() {
	/*
		* Make theme available for translation.
		* Translations can be filed in the /languages/ directory.
		* If you're building a theme based on romance-crm, use a find and replace
		* to change 'romance-crm' to the name of your theme in all the template files.
		*/
	load_theme_textdomain( 'romance-crm', get_template_directory() . '/languages' );


	/*
		* Let WordPress manage the document title.
		* By adding theme support, we declare that this theme does not use a
		* hard-coded <title> tag in the document head, and expect WordPress to
		* provide it for us.
		*/
	add_theme_support( 'title-tag' );


	// Add theme support for selective refresh for widgets.
	add_theme_support( 'customize-selective-refresh-widgets' );

}
add_action( 'after_setup_theme', 'romance_crm_setup' );

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * Priority 0 to make it available to lower priority callbacks.
 *
 * @global int $content_width
 */
function romance_crm_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'romance_crm_content_width', 640 );
}
add_action( 'after_setup_theme', 'romance_crm_content_width', 0 );


/**
 * Enqueue scripts and styles.
 */
function romance_crm_scripts() {
	wp_enqueue_style( 'romance-crm-style', get_stylesheet_uri(), array(), _S_VERSION );
	wp_style_add_data( 'romance-crm-style', 'rtl', 'replace' );
}
add_action( 'wp_enqueue_scripts', 'romance_crm_scripts' );

/// REDIRECT TO LOGIN ///
add_action('template_redirect', 'redirect_non_logged_users');

function redirect_non_logged_users() {
    if (!is_user_logged_in()) {

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || is_front_page()) {
            return;
        }

        wp_redirect(home_url('/'));
        exit;
    }
}



/// LOGIN ///
add_action('wp_ajax_custom_ajax_login', 'custom_ajax_login_handler');
add_action('wp_ajax_nopriv_custom_ajax_login', 'custom_ajax_login_handler');

function custom_ajax_login_handler() {
    $email = sanitize_email($_POST['log'] ?? '');
    $password = $_POST['pwd'] ?? '';

    $user = get_user_by('email', $email);

    if (!$user) {
        wp_send_json([
            'success' => false,
            'message' => 'Такого пользователя не существует!'
        ]);
    }

    if (!wp_check_password($password, $user->user_pass, $user->ID)) {
        wp_send_json([
            'success' => false,
            'message' => 'Неверный пароль!'
        ]);
    }

    // Авторизуємо
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID);

		$log_message = 'Вошел в личный кабинет CRM';
		set_log('login', $log_message);

    wp_send_json([
        'success' => true
    ]);
}


function make_curl_request($url, $cookie_file, $headers, $post_fields = null) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, br, zstd');
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
	curl_setopt($ch, CURLOPT_HEADER, true);

	// SOCKS5 proxy
	/*
	curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
	curl_setopt($ch, CURLOPT_PROXY, 'mg-26045.sp1.ovh:11001');
	curl_setopt($ch, CURLOPT_PROXYUSERPWD, 'YDP6yP8_0:850g55nl'); */

	if ($post_fields) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
	}

	$response = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
	$error = curl_error($ch);
	curl_close($ch);
	
	// Витягуємо тіло відповіді (після заголовків)
	$body = substr($response, strpos($response, "\r\n\r\n") + 4);
	$json_response = json_decode($body, true);

	// Перевіряємо, чи сесія закінчилася (різні варіанти)
	$session_expired = (
			// 1. JSON {"result":"authorize"}
			($http_code === 200 && isset($json_response['result']) && $json_response['result'] === 'authorize') ||
			// 2. Наявність HTTP/2 302 у відповіді
			(strpos($response, 'HTTP/2 302') !== false) ||
			// 3. Редирект на сторінку логіну або logout
			($redirect_url && (strpos($redirect_url, 'https://login.romancecompass.com/') === 0 || strpos($redirect_url, '/logout/') !== false))
	);

	return [
			'http_code' => $http_code,
			'response' => $response,
			'error' => $error,
			'session_expired' => $session_expired,
			'redirect_url' => $redirect_url,
			'json_response' => $json_response
	];
}

function authenticate($id, $cookie_file) {
	$id_model = get_field('id_model', $id);
	$email = get_field('email_model', $id);
	$password = get_field('pass_model', $id);

	if (!$email || !$password) {
			return false;
	}

	$upload_dir = wp_upload_dir();
	$cookie_dir = $upload_dir['basedir'] . '/cookies/';
	if (!file_exists($cookie_dir)) {
			wp_mkdir_p($cookie_dir);
	}

	$login_url = "https://login.romancecompass.com/";
	$post_data = [
			'form_email'     => $email,
			'form_password'  => $password,
			'form_remember'  => '1',
			'try_to_log_in'  => 'Y'
	];

	$headers = [
			'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Encoding: gzip, deflate, br, zstd',
			'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
			'Connection: keep-alive',
			'Host: login.romancecompass.com',
			'Origin: https://login.romancecompass.com',
			'Referer: https://login.romancecompass.com/',
			'Sec-Fetch-Dest: document',
			'Sec-Fetch-Mode: navigate',
			'Sec-Fetch-Site: same-origin',
			'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
	];

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $login_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
	curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, br, zstd');
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

	// SOCKS5 proxy
	/*
	curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
	curl_setopt($ch, CURLOPT_PROXY, 'mg-26045.sp1.ovh:11001');
	curl_setopt($ch, CURLOPT_PROXYUSERPWD, 'YDP6yP8_0:850g55nl'); */

	$response = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$error = curl_error($ch);
	curl_close($ch);

	//var_dump($http_code);

	if ($error || $http_code != 200) {
			return false;
	}

	return strpos($response, "My account") !== false || strpos($response, "Logout") !== false;
}

function make_authenticated_request($id, $url, $headers, $post_fields = null) {
	$cookie_file = get_cookie_file($id);
	
	if (!file_exists($cookie_file)) {
			if (!authenticate($id, $cookie_file)) {
					return [
							'error' => 'Initial authentication failed',
							'http_code' => 401
					];
			}
	}
	
	$result = make_curl_request($url, $cookie_file, $headers, $post_fields);
	
	if ($result['session_expired']) {
			if (!authenticate($id, $cookie_file)) {
					return [
							'error' => 'Re-authentication failed',
							'http_code' => 401
					];
			}
			$result = make_curl_request($url, $cookie_file, $headers, $post_fields);
	}
	
	return $result;
}

function get_common_headers() {
	return [
			'Accept: application/json, text/javascript, */*; q=0.01',
			'Accept-Encoding: gzip, deflate, br, zstd',
			'Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
			'Connection: keep-alive',
			'Host: login.romancecompass.com',
			'Origin: https://login.romancecompass.com',
			'Referer: https://login.romancecompass.com/chat/',
			'Sec-Fetch-Dest: empty',
			'Sec-Fetch-Mode: cors',
			'Sec-Fetch-Site: same-origin',
			'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
			'X-Requested-With: XMLHttpRequest'
	];
}

function get_cookie_file($id) {
	$id_model = get_field('id_model', $id);
	$upload_dir = wp_upload_dir();
	$cookie_dir = $upload_dir['basedir'] . '/cookies/';
	// Dating.com sessions use a separate prefixed file to avoid ID collisions.
	// RC keeps its existing naming so active sessions are never invalidated.
	if ( get_field('source_model', $id) === 'dating_com' ) {
		return $cookie_dir . 'cookie_dating_com_' . $id_model . '.txt';
	}
	return $cookie_dir . 'cookie_' . $id_model . '.txt';
}

// Contact List JavaScript остается без изменений
add_action('wp_footer', 'script_get_contact_list');
function script_get_contact_list() {
	if (!is_singular('model')) {
			return;
	}
	?>
	<script>
			var modelId = <?php echo intval(get_the_ID()); ?>;
			jQuery(document).ready(function($) {
					const notificationSound = new Audio('<?php echo home_url(); ?>/wp-content/themes/romance-crm/assets/notify.mp3');
					let lastUnreadCount = 0;

					if (Notification.permission !== "granted") {
							Notification.requestPermission();
					}

					function showNotification(title, message) {
							if (Notification.permission === "granted") {
									new Notification(title, { body: message });
							}
							notificationSound.play().catch(e => console.log("Sound play failed:", e));
					}

					function fetchContactList() {
							$.ajax({
									url: ajaxurl,
									type: 'POST',
									dataType: 'json',
									data: { action: 'get_contact_list', id: modelId },
									success: function(response) {
											if (response.success) {
													$('.contact-list .response').html(response.data);
													let currentUnread = 0;
													$('.unread-message').each(function() {
															const match = $(this).text().match(/x(\d+)/);
															if (match) currentUnread += parseInt(match[1]);
													});

													if (currentUnread > lastUnreadCount) {
															const diff = currentUnread - lastUnreadCount;
															showNotification('Новые сообщения', `У вас ${diff} нов${diff === 1 ? 'ое' : 'ых'} сообщени${diff === 1 ? 'е' : 'я'}!`);
													}
													lastUnreadCount = currentUnread;
											} else {
													console.warn('Server error:', response.data);
											}
									},
									error: function(error) {
											console.error('AJAX Error:', error);
									}
							});
					}

					fetchContactList();
					setInterval(fetchContactList, 15000);
					
					window.checkOnlineModel(modelId);

					// Повторювати кожні 3 хвилини
					setInterval(function () {
						window.checkOnlineModel(modelId);
					}, 3 * 60 * 1000);
			});
	</script>
	<?php
}

// Handle Contact List
add_action('wp_ajax_get_contact_list', 'handle_get_contact_list');
add_action('wp_ajax_nopriv_get_contact_list', 'handle_get_contact_list');
function handle_get_contact_list() {
	$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
	if (!$id) {
			wp_send_json_error('Missing or invalid ID');
	}

	// Source routing — Dating.com shows pending state until endpoint is confirmed.
	if ( get_field('source_model', $id) === 'dating_com' ) {
		dc_handle_get_contact_list( $id );
		return;
	}

	$target_url = 'https://login.romancecompass.com/chat/';
	$headers = get_common_headers();
	$result = make_authenticated_request($id, $target_url, $headers);
	

	if ($result['http_code'] != 200) {
			wp_send_json_error('Request failed with HTTP code: ' . $result['http_code']);
	}

	if (preg_match('/var\s+contact_list_load\s*=\s*(\[[^\;]+);/', $result['response'], $matches)) {
			$json_str = $matches[1];
			$data = json_decode($json_str, true);
			if ($data === null) {
					wp_send_json_error('JSON decode error: ' . json_last_error_msg());
			}

			$html = '';
			foreach ($data as $chat) {
					$customer = $chat['customer'];
					$chat_id = $chat['chat_id'];
					$unread_cnt = $chat['unread_cnt'];
					$name = $customer['name'] ?? 'unknown';
					$customer_id = $customer['id'] ?? 'unknown';
					$is_online = !empty($customer['is_online']) ? '1' : '0';
					$is_favorite = !empty($chat['favorite']) ? '1' : '0';
					$photo_src = (empty($customer['photo_src']) || $customer['photo_src'] === 'https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png')
													? 'https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png'
													: 'https://login.romancecompass.com' . $customer['photo_src'];

					$online_indicator = $is_online === '1'
							? '<span class="online-indicator" style="color: green;">•</span>'
							: '<span class="online-indicator" style="color: red;">•</span>';
					$favorite_indicator = $is_favorite === '1'
							? '<span class="favorite-indicator" style="color: orange;">★</span>'
							: '<span class="favorite-indicator" style="color: orange;">☆</span>';
					$unread_html = $unread_cnt > 0
							? '<span class="unread-message" style="color: #007bff; font-weight: bold;">✉️ x' . intval($unread_cnt) . '</span>'
							: '';

					$html .= "
							<div class=\"contact-card d-flex align-items-center gap-2 mt-2 mb-2\">
									<img src=\"" . esc_url($photo_src) . "\" alt=\"" . esc_attr($name) . "\" style=\"width:30px;height:30px;border-radius:50%;\">
									<div class=\"d-flex align-items-center justify-content-between\" style=\"width: 100%;\">
											<p id=\"openChat\" data-user_id=\"$customer_id\" data-chat_id=\"$chat_id\" class=\"name mb-0\">
													$online_indicator <strong>" . esc_html($name) . "</strong> 
													<span class=\"id\" style=\"color: #0000005c;\">(ID: " . esc_html($customer_id) . ")</span>
											</p>
											<p id=\"openChat\" data-user_id=\"$customer_id\" data-chat_id=\"$chat_id\" class=\"messages mb-0\">$unread_html</p>
											<p class=\"actions d-flex align-items-center gap-2 mb-0\">
													<a href=\"#\" id=\"goFavorite\" data-user_id=\"$customer_id\" data-favorite=\"$is_favorite\">$favorite_indicator</a>
													<a href=\"#\" id=\"goRemove\" style=\"color: red;font-size: 30px;\" data-user_id=\"$customer_id\">×</a>
											</p>
									</div>
							</div>";
			}

			wp_send_json_success($html ?: '<p class="text-center">Список контактов пуст</p>');
	} else {
			wp_send_json_success('<p class="text-center">Список контактов пустой</p>');
	}
}

// Toggle Favorite
add_action('wp_ajax_toggle_favorite', 'handle_toggle_favorite');
add_action('wp_ajax_nopriv_toggle_favorite', 'handle_toggle_favorite');
function handle_toggle_favorite() {
	if (empty($_POST['user_id']) || !isset($_POST['favorite']) || !isset($_POST['id'])) {
			wp_send_json_error('Неверные данные');
	}

	// Dating.com: favorites endpoint not yet confirmed — not implemented in Phase 1.
	if ( get_field('source_model', intval($_POST['id'])) === 'dating_com' ) {
		wp_send_json_error('Dating.com: избранное не поддерживается в текущей версии.');
	}

	$user_id = intval($_POST['user_id']);
	$favorite = ($_POST['favorite'] === '1') ? 1 : 0;
	$id = intval($_POST['id']);

	$target_url = 'https://login.romancecompass.com/chat/?ajax=1&action=update_states';
	$post_fields = [
			"favorites[{$user_id}]" => $favorite,
			"watch_video[{$user_id}]" => 1
	];
	$headers = get_common_headers();
	$headers[] = 'Content-Type: application/x-www-form-urlencoded';

	$result = make_authenticated_request($id, $target_url, $headers, $post_fields);
	if ($result['http_code'] == 200) {

			if($favorite == 1) {
				$log_message = 'Добавил в избранные клиента (ID: '. $user_id .') для модели <a href="'. get_the_permalink($id) .'">'. get_the_title($id) . '</a>';
			} else {
				$log_message = 'Удалил с избранного клиента (ID: '. $user_id .') для модели <a href="'. get_the_permalink($id) .'">'. get_the_title($id) . '</a>';
			}
			set_log('favorite', $log_message);

			wp_send_json_success('Favorite status updated');
	} else {
			wp_send_json_error("Ошибка доступа: HTTP {$result['http_code']}");
	}
}

// Delete Contact
add_action('wp_ajax_delete_contact', 'handle_delete_contact');
add_action('wp_ajax_nopriv_delete_contact', 'handle_delete_contact');
function handle_delete_contact() {
	if (empty($_POST['user_id']) || !isset($_POST['id'])) {
			wp_send_json_error('Неверные данные');
	}

	// Dating.com: delete-contact endpoint not yet confirmed — not implemented in Phase 1.
	if ( get_field('source_model', intval($_POST['id'])) === 'dating_com' ) {
		wp_send_json_error('Dating.com: удаление контактов не поддерживается в текущей версии.');
	}

	$user_id = intval($_POST['user_id']);
	$id = intval($_POST['id']);

	$target_url = 'https://login.romancecompass.com/chat/?ajax=1&action=delete_contact';
	$post_fields = [
			"c_id" => $user_id
	];
	$headers = get_common_headers();
	$headers[] = 'Content-Type: application/x-www-form-urlencoded';

	$result = make_authenticated_request($id, $target_url, $headers, $post_fields);

	if ($result['http_code'] == 200) {

			$log_message = 'Удалил с контактов клиента (ID: '. $user_id .') для модели <a href="'. get_the_permalink($id) .'">'. get_the_title($id) . '</a>';
			set_log('remove_contact', $log_message);

			wp_send_json_success('User deleted!');
	} else {
			wp_send_json_error("Ошибка доступа: HTTP {$result['http_code']}");
	}
}

// Open Chat
add_action('wp_ajax_open_chat', 'handle_open_chat');
add_action('wp_ajax_nopriv_open_chat', 'handle_open_chat');
function handle_open_chat() {
	if (empty($_POST['user_id']) || !isset($_POST['chat_id']) || empty($_POST['id'])) {
			wp_send_json_error('Неверные данные');
	}

	$user_id = intval($_POST['user_id']);
	$chat_id = intval($_POST['chat_id']);
	$id = intval($_POST['id']);

	// Source routing — uses confirmed /dialogs/messages/ endpoint for Dating.com.
	if ( get_field('source_model', $id) === 'dating_com' ) {
		dc_handle_open_chat( $id, $user_id );
		return;
	}
	$headers = get_common_headers();

	// Fetch user info
	$url_info = 'https://login.romancecompass.com/chat/?ajax=1&action=get_contact_customer&c_id=' . $user_id;
	$result_info = make_authenticated_request($id, $url_info, $headers);

	if ($result_info['http_code'] !== 200) {
			wp_send_json_error('Ошибка запроса info');
	}

	$response = $result_info['response'];

	// Find the start of the JSON data (after headers)
	$jsonStart = strpos($response, '{');
	if ($jsonStart === false) {
			// Handle error - no JSON found
			die("No JSON data found in response");
	}

	// Extract the JSON part
	$jsonData = substr($response, $jsonStart);
	$data_info = json_decode($jsonData, true);

	if (!isset($data_info['result']) || $data_info['result'] !== 'ok') {
			wp_send_json_error('Ошибка в ответе info');
	}

	$customer = $data_info['customer'];
	$photo = (empty($customer['photo_src']) || $customer['photo_src'] === 'https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png')
			? 'https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png'
			: 'https://login.romancecompass.com' . $customer['photo_src'];

	$html = '<div class="chat-user-info d-flex gap-3 mb-3">
			<img src="' . esc_url($photo) . '" alt="' . esc_attr($customer['name']) . '">
			<div class="information">
					<h5 class="mb-2"><strong>' . esc_html($customer['name']) . '</strong></h5>
					<p class="m-0">ID: ' . esc_html($customer['id']) . '</p>
					<p class="m-0">Страна: ' . esc_html($customer['country']) . '</p>
					<p class="m-0">Возраст: ' . esc_html($customer['age']) . '</p>
			</div>
	</div>
	<div class="messages">
			<div class="chat-messages" data-chat_id="' . esc_attr($chat_id) . '" data-user_id="' . $user_id . '">';

	if ($chat_id == 0) {
			$html .= '<div class="text-center text-muted mt-4 mb-4">
					<strong>Истории нету, так как чат завершен</strong>
			</div>';
	} else {
			$url_msgs = 'https://login.romancecompass.com/chat/?ajax=1&action=get_messages&chat_id=' . $chat_id;
			$result_msgs = make_authenticated_request($id, $url_msgs, $headers);

			if ($result_msgs['http_code'] !== 200) {
					wp_send_json_error('Ошибка запроса сообщений');
			}
			$response = $result_msgs['response'];

			// Find the start of the JSON data (after headers)
			$jsonStart = strpos($response, '{');
			if ($jsonStart === false) {
					// Handle error - no JSON found
					die("No JSON data found in response");
			}

			// Extract the JSON part
			$jsonData = substr($response, $jsonStart);

			$data_msgs = json_decode($jsonData, true);

			if (!isset($data_msgs['result']) || $data_msgs['result'] !== 'ok') {
					wp_send_json_error('Ошибка в ответе сообщений');
			}

			foreach ($data_msgs['data']['text'] as $msg) {
					$align = ($msg['gender'] == 2) ? 'text-end text-success' : 'text-start text-primary';
					$sender = ($msg['gender'] == 2) ? 'Модель' : 'Клиент';
	
					$text = $msg['text'];
	
					if (strpos($text, '<img') !== false) {
							$text = preg_replace_callback(
									'/<img\s+[^>]*src=[\'"]([^\'"]+)[\'"]/i',
									function ($matches) {
											$src = $matches[1];
											if (strpos($src, 'http') !== 0) {
													$src = 'https://login.romancecompass.com' . $src;
											}
											return str_replace($matches[1], $src, $matches[0]);
									},
									$text
							);
							$msg_content = $text;
					} else {
							$msg_content = esc_html($text);
					}
	
					$html .= '<div class="chat-message ' . $align . ' mb-3">
									<p>' . $sender . ' ( <small>' . esc_html($msg['date']) . '</small> )</p>
									<p class="text-dark" style="font-size: 14px;">' . $msg_content . '</p>
					</div>';
			}
	}
	$html .= '</div></div>';

	$log_message = 'Открыл чат с пользователем (ID: ' . $user_id .') для модели <a href="'. get_the_permalink($id) .'">'. get_the_title($id) . '</a>';
	set_log('open_chat', $log_message);

	wp_send_json_success($html);
}

// Send Message
add_action('wp_ajax_send_message', 'handle_send_message');
add_action('wp_ajax_nopriv_send_message', 'handle_send_message');
function handle_send_message() {
	if (empty($_POST['user_id']) || !isset($_POST['message']) || !isset($_POST['id'])) {
			wp_send_json_error('Неверные данные');
	}

	// Dating.com: send message deferred — endpoint not yet confirmed.
	// TODO: implement dc_send_message() after confirming Section 6 of
	//       DATING_COM_BROWSER_INSPECTION.md, then route here.
	if ( get_field('source_model', intval($_POST['id'])) === 'dating_com' ) {
		wp_send_json_error(
			'Dating.com: отправка сообщений будет доступна после подтверждения endpoint. ' .
			'(See DATING_COM_BROWSER_INSPECTION.md Section 6)'
		);
	}

	$user_id = intval($_POST['user_id']);
	$message = $_POST['message'];
	$id = intval($_POST['id']);

	$target_url = 'https://login.romancecompass.com/chat/?ajax=1&action=send_message';
	$post_fields = [
			'c_id' => $user_id,
			'message' => $message
	];
	$headers = get_common_headers();
	$headers[] = 'Content-Type: application/x-www-form-urlencoded';

	$result = make_authenticated_request($id, $target_url, $headers, $post_fields);

	if ($result['http_code'] == 200) {
			$response = $result['response'];

			// Find the start of the JSON data (after headers)
			$jsonStart = strpos($response, '{');
			if ($jsonStart === false) {
					// Handle error - no JSON found
					die("No JSON data found in response");
			}

			// Extract the JSON part
			$jsonData = substr($response, $jsonStart);

			$data = json_decode($jsonData, true);
			
			if (isset($data['result']) && $data['result'] === 'ok' && isset($data['data']['chat_id'])) {

					$log_message = 'Отправил клиенту (ID: ' . $user_id .') сообщение для модели <a href="'. get_the_permalink($id) .'">'. get_the_title($id) . '</a>';
					set_log('send_message', $log_message);

					wp_send_json_success([
							'message' => 'Сообщение успешно отправлено',
							'chat_id' => $data['data']['chat_id']
					]);
			} else {
					wp_send_json_error('Некорректный ответ от сервера');
			}
	} else {
			wp_send_json_error("Ошибка доступа: HTTP {$result['http_code']}");
	}
}

// Check Message
add_action('wp_ajax_check_message', 'handle_check_message');
add_action('wp_ajax_nopriv_check_message', 'handle_check_message');
function handle_check_message() {
	if (empty($_POST['user_id']) || !isset($_POST['chat_id']) || empty($_POST['id'])) {
			wp_send_json_error('Неверные данные');
	}

	$user_id = intval($_POST['user_id']);
	$chat_id = intval($_POST['chat_id']);
	$id = intval($_POST['id']);

	// Source routing — uses confirmed /dialogs/messages/ endpoint for Dating.com.
	if ( get_field('source_model', $id) === 'dating_com' ) {
		dc_handle_check_message( $id, $user_id );
		return;
	}
	$headers = get_common_headers();
	$html = '<div class="chat-messages" data-chat_id="' . esc_attr($chat_id) . '" data-user_id="' . $user_id . '">';

	if ($chat_id == 0) {
			$html .= '<div class="text-center text-muted mt-4 mb-4">
					<strong>Истории нету, так как чат завершен</strong>
			</div>';
	} else {
			$url_msgs = 'https://login.romancecompass.com/chat/?ajax=1&action=get_messages&chat_id=' . $chat_id;
			$result_msgs = make_authenticated_request($id, $url_msgs, $headers);

			if ($result_msgs['http_code'] !== 200) {
					wp_send_json_error('Ошибка запроса сообщений');
			}

			$response = $result_msgs['response'];

			// Find the start of the JSON data (after headers)
			$jsonStart = strpos($response, '{');
			if ($jsonStart === false) {
					// Handle error - no JSON found
					die("No JSON data found in response");
			}

			// Extract the JSON part
			$jsonData = substr($response, $jsonStart);

			$data_msgs = json_decode($jsonData, true);

			if (!isset($data_msgs['result']) || $data_msgs['result'] !== 'ok') {
					wp_send_json_error('Ошибка в ответе сообщений');
			}

			foreach ($data_msgs['data']['text'] as $msg) {
					$align = ($msg['gender'] == 2) ? 'text-end text-success' : 'text-start text-primary';
					$sender = ($msg['gender'] == 2) ? 'Модель' : 'Клиент';
	
					$text = $msg['text'];
	
					if (strpos($text, '<img') !== false) {
							$text = preg_replace_callback(
									'/<img\s+[^>]*src=[\'"]([^\'"]+)[\'"]/i',
									function ($matches) {
											$src = $matches[1];
											if (strpos($src, 'http') !== 0) {
													$src = 'https://login.romancecompass.com' . $src;
											}
											return str_replace($matches[1], $src, $matches[0]);
									},
									$text
							);
							$msg_content = $text;
					} else {
							$msg_content = esc_html($text);
					}
	
					$html .= '<div class="chat-message ' . $align . ' mb-3">
									<p>' . $sender . ' ( <small>' . esc_html($msg['date']) . '</small> )</p>
									<p class="text-dark" style="font-size: 14px;">' . $msg_content . '</p>
					</div>';
			}
	}

	$html .= '</div>';
	wp_send_json_success($html);
}

add_action('wp_ajax_get_online_users', 'handle_get_online_users');
add_action('wp_ajax_nopriv_get_online_users', 'handle_get_online_users');

function handle_get_online_users() {
	if (empty($_POST['id']) || empty($_POST['page'])) {
		wp_send_json_error('Неверные данные');
	}

	$id = intval($_POST['id']);
	$page = intval($_POST['page']);

	// Dating.com: broadcast / mass outreach is explicitly not supported.
	if ( get_field('source_model', $id) === 'dating_com' ) {
		wp_send_json_error('Dating.com: рассылка недоступна.');
	}

	$headers = get_common_headers();
	$url_info = 'https://login.romancecompass.com/chat/?ajax=1&action=get_online&page_num=' . $page . '&clear_invited=0';
	$result = make_authenticated_request($id, $url_info, $headers);

	if ($result['http_code'] !== 200) {
		wp_send_json_error('Ошибка запроса info');
	}

	$response = $result['response'];

	// Найдём начало JSON
	$jsonStart = strpos($response, '{');
	if ($jsonStart === false) {
		wp_send_json_error('Не удалось извлечь JSON из ответа');
	}

	$jsonData = substr($response, $jsonStart);
	$data_info = json_decode($jsonData, true);

	if (!isset($data_info['result']) || $data_info['result'] !== 'ok') {
		wp_send_json_error('Ошибка в ответе info');
	}

	// Преобразуем online в массив (а не объект с числовыми ключами)
	$users = isset($data_info['online']) && is_array($data_info['online']) 
		? array_values($data_info['online']) 
		: [];

	wp_send_json_success([
		'users' => $users,
		'page' => $data_info['pager']['page'],
		'pages' => $data_info['pager']['pages'],
	]);
}


add_action('wp_ajax_send_message_to_user', 'handle_send_message_to_user');
add_action('wp_ajax_nopriv_send_message_to_user', 'handle_send_message_to_user');
function handle_send_message_to_user() {
	if (empty($_POST['id']) || empty($_POST['user_id']) || !isset($_POST['message'])) {
			wp_send_json_error('Неверные данные');
	}
	$id = intval($_POST['id']);
	$user_id = intval($_POST['user_id']);
	$message = trim($_POST['message']);

	// Dating.com: broadcast / mass outreach is explicitly not supported.
	if ( get_field('source_model', $id) === 'dating_com' ) {
		wp_send_json_error('Dating.com: рассылка недоступна.');
	}

	$headers = get_common_headers();
	$send_url = 'https://login.romancecompass.com/chat/?ajax=1&action=send_message&c_id=' . $user_id . '&message=' . urlencode($message);
	$result = make_authenticated_request($id, $send_url, $headers);

	if ($result['http_code'] === 200) {
			$response = $result['response'];

			// Find the start of the JSON data (after headers)
			$jsonStart = strpos($response, '{');
			if ($jsonStart === false) {
					// Handle error - no JSON found
					die("No JSON data found in response");
			}

			// Extract the JSON part
			$jsonData = substr($response, $jsonStart);

			$send_result = json_decode($jsonData, true);

			if (isset($send_result['result']) && $send_result['result'] === 'ok') {
					wp_send_json_success('Сообщение отправлено');
			} else {
					wp_send_json_error('Ошибка при отправке сообщения');
			}
	} else {
			wp_send_json_error('Ошибка запроса отправки');
	}
}

add_action('wp_ajax_load_more_models', 'load_more_models_callback');
add_action('wp_ajax_nopriv_load_more_models', 'load_more_models_callback');

function load_more_models_callback() {
    $offset = intval($_POST['offset']);
    $search = sanitize_text_field($_POST['search']);
    $current_user = wp_get_current_user();
    $current_user_id = get_current_user_id();

    $args = array(
        'post_type' => 'model',
        'posts_per_page' => 10,
        'offset' => $offset,
        'meta_query' => array()
    );

    if (!in_array('manager', (array) $current_user->roles) && !in_array('administrator', (array) $current_user->roles)) {
        $args['meta_query'][] = array(
            'key' => 'user_model',
            'value' => '"' . $current_user_id . '"',
            'compare' => 'LIKE'
        );
    }

    if (!empty($search)) {
        $args['meta_query'][] = array(
            'key' => 'id_model',
            'value' => $search,
            'compare' => '='
        );
    }

    $query = new WP_Query($args);

    if ($query->have_posts()) :
        while ($query->have_posts()) : $query->the_post(); 
				$blocked = get_post_meta(get_the_ID(), '_blocked-use', true);
				$blocked_time = get_post_meta(get_the_ID(), '_blocked-time', true);
				$blocked_by = get_post_meta(get_the_ID(), '_blocked-by-user', true);
						
				if ($blocked && $blocked_time && $blocked_time > time()) {
					$class = 'blocked';
					$btn = '<span class="a" disabled="disabled">Модель занята</span>';
				} else {
					$class = '';
					$btn = '<a target="_blank" href="' . esc_url(get_permalink()) . '">Работать с моделью</a>';
				}
				$src         = get_field('source_model') ?: 'romance_compass';
				$badge_label = $src === 'dating_com' ? 'Dating.com' : 'RomanceCompass';
				$badge_class = $src === 'dating_com' ? 'badge-dating' : 'badge-rc';
				?>
            <div class="model-item p-4 rounded <?= $class; ?>">
                <div class="d-flex gap-3">
                    <div class="image">
                        <img src="<?= esc_url(get_field('avatar_model')); ?>">
                    </div>
                    <div class="info">
                        <h6 class="title"><?= esc_html(get_field('name_model')); ?>
                            <span class="id" style="color: #0000005c;">(ID: <?= esc_html(get_field('id_model')); ?>)</span>
                            <span class="source-badge <?= esc_attr($badge_class); ?>"><?= esc_html($badge_label); ?></span>
                        </h6>
                        <p class="subtitle mt-1 mb-1">
                            Страна: <span style="font-weight: bold;color: rgba(0,0,0,0.7);"><?= esc_html(get_field('country_model')); ?></span> |
                            Возраст: <span style="font-weight: bold;color: rgba(0,0,0,0.7);"><?= esc_html(get_field('years_model')); ?></span>
                        </p>
												<?= $btn; ?>
                    </div>
                </div>
            </div>
        <?php endwhile;
    endif;

    wp_die();
}


add_action('wp_ajax_action_spam', 'handle_action_spam');
add_action('wp_ajax_nopriv_action_spam', 'handle_action_spam');

function handle_action_spam() {
	if (empty($_POST['type'])) {
		wp_send_json_error('Неверные данные');
	}
	$id = intval($_POST['id']);
	$type = $_POST['type'];

	if($type == 'start') {
		$message = 'Запустил рассылку для модели <a href="'. get_the_permalink($id) .'">'. get_the_title($id) . '</a>';
		set_log('start_spam', $message);
	} else {
		$message = 'Остановил рассылку для модели <a href="'. get_the_permalink($id) .'">'. get_the_title($id) . '</a>';
		set_log('stop_spam', $message);
	}
	

	wp_send_json_success();
}


// Функция для транслитерации кириллицы в латиницу
function transliterate_to_latin($string) {
    $cyr = [
        'а', 'б', 'в', 'г', 'д', 'е', 'ё', 'ж', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п',
        'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'ъ', 'ы', 'ь', 'э', 'ю', 'я',
        'А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ё', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П',
        'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я',
        'і', 'ї', 'ґ', 'є', 'І', 'Ї', 'Ґ', 'Є'
    ];
    
    $lat = [
        'a', 'b', 'v', 'g', 'd', 'e', 'yo', 'zh', 'z', 'i', 'y', 'k', 'l', 'm', 'n', 'o', 'p',
        'r', 's', 't', 'u', 'f', 'h', 'ts', 'ch', 'sh', 'sht', '', 'y', '', 'e', 'yu', 'ya',
        'A', 'B', 'V', 'G', 'D', 'E', 'Yo', 'Zh', 'Z', 'I', 'Y', 'K', 'L', 'M', 'N', 'O', 'P',
        'R', 'S', 'T', 'U', 'F', 'H', 'Ts', 'Ch', 'Sh', 'Sht', '', 'Y', '', 'E', 'Yu', 'Ya',
        'i', 'yi', 'g', 'ye', 'I', 'Yi', 'G', 'Ye'
    ];
    
    $string = str_replace($cyr, $lat, $string);
    
    $string = preg_replace('/[^a-zA-Z0-9\-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    $string = trim($string, '-');
    
    return strtolower($string);
}

// Устанавливаем slug при сохранении поста типа model
add_action('acf/save_post', 'set_model_slug_after_acf', 20); // priority 20, чтобы сработал после ACF

function set_model_slug_after_acf($post_id) {
    // Проверяем, что это не ревизия и нужный тип поста
    if (get_post_type($post_id) !== 'model' || wp_is_post_revision($post_id)) {
        return;
    }

    // Проверка прав
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Получаем значения из ACF
    $name_model = get_field('name_model', $post_id);
    $id_model = get_field('id_model', $post_id);

    if (empty($name_model) || empty($id_model)) {
        return;
    }

    $transliterated_name = transliterate_to_latin($name_model);
    $new_slug = $transliterated_name . '-' . $id_model;

    $post = get_post($post_id);

    if ($post->post_name == $new_slug) {
        return;
    }

    // Обновляем slug
    wp_update_post([
        'ID' => $post_id,
        'post_name' => $new_slug
    ]);
}



add_action('admin_init', 'restrict_theme_plugin_access');
function restrict_theme_plugin_access() {
    // Если текущий пользователь не ID 8 — блокируем доступ
    if (get_current_user_id() !== 8) {
        // Запрещаем доступ к страницам управления плагинами
        $plugin_pages = [
            'plugins.php',
            'plugin-install.php',
            'plugin-editor.php',
        ];

        // Запрещаем доступ к страницам управления темами
        $theme_pages = [
            'themes.php',
            'theme-install.php',
            'theme-editor.php',
            'customize.php', // Кастомайзер тоже связан с темами
        ];

        $restricted_pages = array_merge($plugin_pages, $theme_pages);

        $current_page = basename($_SERVER['PHP_SELF']);

        if (in_array($current_page, $restricted_pages)) {
            wp_die('У вас нет доступа к этой странице.', 'Доступ запрещен', ['response' => 403]);
        }
    }
}


// block model
add_action('wp_ajax_chek_online_model', 'handle_chek_online_model');

function handle_chek_online_model() {
	if (empty($_POST['id'])) {
		wp_send_json_error('Не передано ID');
	}

	$post_id = intval($_POST['id']);
	$current_user_id = get_current_user_id();

	if (!$current_user_id) {
		wp_send_json_error('Користувач не авторизований');
	}

	$block_time = time() + 5 * 60; // +5 хвилин

	update_post_meta($post_id, '_blocked-use', true);
	update_post_meta($post_id, '_blocked-time', $block_time);
	update_post_meta($post_id, '_blocked-by-user', $current_user_id);

	wp_send_json_success('Модель заблокована');
}


/**
 * Функция для отслеживания выхода пользователя из аккаунта
 * и выполнения дополнительных действий.
 */
function custom_track_user_logout() {
	// Проверяем, был ли выполнен выход (если это действие 'logout')
	if ( isset( $_GET['action'] ) && $_GET['action'] == 'logout' ) {
			// Получаем текущего пользователя (до выхода)
			$current_user = wp_get_current_user();
			
			// Проверяем, что пользователь был авторизован
			if ( $current_user->ID != 0 ) {

					set_log('logout', 'Вышел с личного кабинета CRM');
					
					// Действие 2: Перенаправление на определённую страницу после выхода
					wp_redirect( home_url('/') ); // Замените на нужный URL
					exit;
			}
	}
}
add_action('wp_logout', 'custom_track_user_logout', 10, 0);

add_action('admin_head', function () {
	global $pagenow;

	if ($pagenow === 'edit.php' && ($_GET['post_type'] ?? '') === 'model') {
			echo '<style>
					.acf_avatar_model.column-acf_avatar_model img {
							max-width: 70px;
					}
			</style>';
	}
});
