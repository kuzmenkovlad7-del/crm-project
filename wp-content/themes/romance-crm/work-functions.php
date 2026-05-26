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

    wp_send_json([
        'success' => true
    ]);
}


// Centralized cURL request handler
function make_curl_request($url, $cookie_file, $headers, $post_fields = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, br, zstd');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

    if ($post_fields) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$http_code, $response, $error];
}

// Centralized authentication function
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

    [$http_code, $response, $error] = make_curl_request($login_url, $cookie_file, $headers, $post_data);

    if ($error || $http_code != 200) {
        return false;
    }

    return strpos($response, "My account") !== false || strpos($response, "Logout") !== false;
}

// Common headers for AJAX requests
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

// Validate and get cookie file
function get_cookie_file($id) {
    $id_model = get_field('id_model', $id);
    $upload_dir = wp_upload_dir();
    $cookie_dir = $upload_dir['basedir'] . '/cookies/';
    return $cookie_dir . 'cookie_' . $id_model . '.txt';
}

// Contact List JavaScript
add_action('wp_footer', 'script_get_contact_list');
function script_get_contact_list() {
    if (!is_singular('model')) {
        return;
    }
    ?>
    <script>
        var modelId = <?php echo intval(get_the_ID()); ?>;
        jQuery(document).ready(function($) {
            const notificationSound = new Audio('https://crmrc.app/wp-content/themes/romance-crm/assets/notify.mp3');
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

    $cookie_file = get_cookie_file($id);
    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $target_url = 'https://login.romancecompass.com/chat/';
    $headers = get_common_headers();
    [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers);

    if ($http_code != 200 || strpos($response, "window.location.href='/';") !== false) {
        if (!authenticate($id, $cookie_file)) {
            wp_send_json_error('Re-auth failed');
        }
        [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers);
    }

    if ($http_code == 200) {
        if (preg_match('/var\s+contact_list_load\s*=\s*(\[[^\;]+);/', $response, $matches)) {
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
    } else {
        wp_send_json_error("Ошибка доступа: HTTP {$http_code}");
    }
}

// Toggle Favorite
add_action('wp_ajax_toggle_favorite', 'handle_toggle_favorite');
add_action('wp_ajax_nopriv_toggle_favorite', 'handle_toggle_favorite');
function handle_toggle_favorite() {
    if (empty($_POST['user_id']) || !isset($_POST['favorite']) || !isset($_POST['id'])) {
        wp_send_json_error('Неверные данные');
    }

    $user_id = intval($_POST['user_id']);
    $favorite = ($_POST['favorite'] === '1') ? 1 : 0;
    $id = intval($_POST['id']);
    $cookie_file = get_cookie_file($id);

    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $target_url = 'https://login.romancecompass.com/chat/?ajax=1&action=update_states';
    $post_fields = [
        "favorites[{$user_id}]" => $favorite,
        "watch_video[{$user_id}]" => 1
    ];
    $headers = get_common_headers();
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';

    [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers, $post_fields);

    if ($http_code != 200 || strpos($response, "window.location.href='/';") !== false) {
        if (!authenticate($id, $cookie_file)) {
            wp_send_json_error('Re-auth failed');
        }
        [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers, $post_fields);
    }

    if ($http_code == 200) {
        wp_send_json_success('Favorite status updated');
    } else {
        wp_send_json_error("Ошибка доступа: HTTP {$http_code}");
    }
}

// Delete Contact
add_action('wp_ajax_delete_contact', 'handle_delete_contact');
add_action('wp_ajax_nopriv_delete_contact', 'handle_delete_contact');
function handle_delete_contact() {
    if (empty($_POST['user_id']) || !isset($_POST['id'])) {
        wp_send_json_error('Неверные данные');
    }

    $user_id = intval($_POST['user_id']);
    $id = intval($_POST['id']);
    $cookie_file = get_cookie_file($id);

    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $target_url = 'https://login.romancecompass.com/chat/?ajax=1&action=delete_contact';
    $post_fields = [
        "c_id" => $user_id
    ];
    $headers = get_common_headers();
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';

    [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers, $post_fields);

    if ($http_code != 200 || strpos($response, "window.location.href='/';") !== false) {
        if (!authenticate($id, $cookie_file)) {
            wp_send_json_error('Re-auth failed');
        }
        [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers, $post_fields);
    }

    if ($http_code == 200) {
        wp_send_json_success('User delete!');
    } else {
        wp_send_json_error("Ошибка доступа: HTTP {$http_code}");
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
    $cookie_file = get_cookie_file($id);

    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $headers = get_common_headers();

    // Fetch user info
    $url_info = 'https://login.romancecompass.com/chat/?ajax=1&action=get_contact_customer&c_id=' . $user_id;
    [$code_info, $response_info, $error] = make_curl_request($url_info, $cookie_file, $headers);

    if ($code_info !== 200) {
        wp_send_json_error('Ошибка запроса info');
    }

    $data_info = json_decode($response_info, true);
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
        [$code_msgs, $response_msgs, $error] = make_curl_request($url_msgs, $cookie_file, $headers);

        if ($code_msgs !== 200) {
            wp_send_json_error('Ошибка запроса сообщений');
        }

        $data_msgs = json_decode($response_msgs, true);
        if (!isset($data_msgs['result']) || $data_msgs['result'] !== 'ok') {
            wp_send_json_error('Ошибка в ответе сообщений');
        }

        foreach ($data_msgs['data']['text'] as $msg) {
					$align = ($msg['gender'] == 2) ? 'text-end text-success' : 'text-start text-primary';
					$sender = ($msg['gender'] == 2) ? 'Модель' : 'Клиент';
			
					$text = $msg['text'];
			
					// Проверка на наличие <img src="">
					if (strpos($text, '<img') !== false) {
							// Добавляем домен к src, если он не начинается с http
							$text = preg_replace_callback(
									'/<img\s+[^>]*src=[\'"]([^\'"]+)[\'"]/i',
									function ($matches) {
											$src = $matches[1];
											// Добавляем префикс только если src начинается с /
											if (strpos($src, 'http') !== 0) {
													$src = 'https://login.romancecompass.com' . $src;
											}
											return str_replace($matches[1], $src, $matches[0]);
									},
									$text
							);
							$msg_content = $text; // Не экранируем, оставляем как есть
					} else {
							$msg_content = esc_html($text); // Экранируем обычный текст
					}
			
					$html .= '<div class="chat-message ' . $align . ' mb-3">
							<p>' . $sender . ' ( <small>' . esc_html($msg['date']) . '</small> )</p>
							<p class="text-dark" style="font-size: 14px;">' . $msg_content . '</p>
					</div>';
				}
    }

    $html .= '</div></div>';
    wp_send_json_success($html);
}

// Send Message
add_action('wp_ajax_send_message', 'handle_send_message');
add_action('wp_ajax_nopriv_send_message', 'handle_send_message');
function handle_send_message() {
    if (empty($_POST['user_id']) || !isset($_POST['message']) || !isset($_POST['id'])) {
        wp_send_json_error('Неверные данные');
    }

    $user_id = intval($_POST['user_id']);
    $message = sanitize_text_field($_POST['message']);
    $id = intval($_POST['id']);
    $cookie_file = get_cookie_file($id);

    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $target_url = 'https://login.romancecompass.com/chat/?ajax=1&action=send_message';
    $post_fields = [
        'c_id' => $user_id,
        'message' => $message
    ];
    $headers = get_common_headers();
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';

    [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers, $post_fields);

    if ($http_code != 200 || strpos($response, "window.location.href='/';") !== false) {
        if (!authenticate($id, $cookie_file)) {
            wp_send_json_error('Re-auth failed');
        }
        [$http_code, $response, $error] = make_curl_request($target_url, $cookie_file, $headers, $post_fields);
    }

    if ($http_code == 200) {
        $data = json_decode($response, true);
        if (isset($data['result']) && $data['result'] === 'ok' && isset($data['data']['chat_id'])) {
            wp_send_json_success([
                'message' => 'Сообщение успешно отправлено',
                'chat_id' => $data['data']['chat_id']
            ]);
        } else {
            wp_send_json_error('Некорректный ответ от сервера');
        }
    } else {
        wp_send_json_error("Ошибка доступа: HTTP {$http_code}");
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
    $cookie_file = get_cookie_file($id);

    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $headers = get_common_headers();
    $html = '<div class="chat-messages" data-chat_id="' . esc_attr($chat_id) . '" data-user_id="' . $user_id . '">';

    if ($chat_id == 0) {
        $html .= '<div class="text-center text-muted mt-4 mb-4">
            <strong>Истории нету, так как чат завершен</strong>
        </div>';
    } else {
        $url_msgs = 'https://login.romancecompass.com/chat/?ajax=1&action=get_messages&chat_id=' . $chat_id;
        [$code_msgs, $response_msgs, $error] = make_curl_request($url_msgs, $cookie_file, $headers);

        if ($code_msgs !== 200) {
            wp_send_json_error('Ошибка запроса сообщений');
        }

        $data_msgs = json_decode($response_msgs, true);
        if (!isset($data_msgs['result']) || $data_msgs['result'] !== 'ok') {
            wp_send_json_error('Ошибка в ответе сообщений');
        }

        foreach ($data_msgs['data']['text'] as $msg) {
					$align = ($msg['gender'] == 2) ? 'text-end text-success' : 'text-start text-primary';
					$sender = ($msg['gender'] == 2) ? 'Модель' : 'Клиент';
			
					$text = $msg['text'];
			
					// Проверка на наличие <img src="">
					if (strpos($text, '<img') !== false) {
							// Добавляем домен к src, если он не начинается с http
							$text = preg_replace_callback(
									'/<img\s+[^>]*src=[\'"]([^\'"]+)[\'"]/i',
									function ($matches) {
											$src = $matches[1];
											// Добавляем префикс только если src начинается с /
											if (strpos($src, 'http') !== 0) {
													$src = 'https://login.romancecompass.com' . $src;
											}
											return str_replace($matches[1], $src, $matches[0]);
									},
									$text
							);
							$msg_content = $text; // Не экранируем, оставляем как есть
					} else {
							$msg_content = esc_html($text); // Экранируем обычный текст
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

    $cookie_file = get_cookie_file($id);
    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $headers = get_common_headers();

    $url_info = 'https://login.romancecompass.com/chat/?ajax=1&action=get_online&page_num=' . $page . '&clear_invited=0';
    list($code_info, $response_info, $error) = make_curl_request($url_info, $cookie_file, $headers);

    if ($code_info !== 200) {
        wp_send_json_error('Ошибка запроса info');
    }

    $data_info = json_decode($response_info, true);
    if (!isset($data_info['result']) || $data_info['result'] !== 'ok') {
        wp_send_json_error('Ошибка в ответе info');
    }

    wp_send_json_success([
        'users' => $data_info['online'],
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

    $cookie_file = get_cookie_file($id);
    if (!file_exists($cookie_file) && !authenticate($id, $cookie_file)) {
        wp_send_json_error('Auth failed');
    }

    $headers = get_common_headers();

    $send_url = 'https://login.romancecompass.com/chat/?ajax=1&action=send_message&c_id=' . $user_id . '&message=' . urlencode($message);
    list($code_send, $response_send, $error_send) = make_curl_request($send_url, $cookie_file, $headers);

    if ($code_send === 200) {
        $send_result = json_decode($response_send, true);
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

    if (!in_array('manager', (array) $current_user->roles)) {
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
        while ($query->have_posts()) : $query->the_post(); ?>
            <div class="model-item p-4 rounded">
                <div class="d-flex gap-3">
                    <div class="image">
                        <img src="<?= esc_url(get_field('avatar_model')); ?>">
                    </div>
                    <div class="info">
                        <h6 class="title"><?= esc_html(get_field('name_model')); ?>
                            <span class="id" style="color: #0000005c;">(ID: <?= esc_html(get_field('id_model')); ?>)</span>
                        </h6>
                        <p class="subtitle mt-1 mb-1">
                            Страна: <span style="font-weight: bold;color: rgba(0,0,0,0.7);"><?= esc_html(get_field('country_model')); ?></span> |
                            Возраст: <span style="font-weight: bold;color: rgba(0,0,0,0.7);"><?= esc_html(get_field('years_model')); ?></span>
                        </p>
                        <a target="_blank" href="<?= esc_url(get_permalink()); ?>">Работать с моделью</a>
                    </div>
                </div>
            </div>
        <?php endwhile;
    endif;

    wp_die();
}
