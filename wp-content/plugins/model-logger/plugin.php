<?php
/*
Plugin Name: WP Action Logger
Description: A plugin to log user actions in WordPress
Version: 1.3
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WPActionLogger {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (null == self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'action_logs';
        
        register_activation_hook(__FILE__, array($this, 'create_log_table'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'handle_log_deletion'));
    }
    
    public function create_log_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            action varchar(255) NOT NULL,
            log_info text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public static function set_log($action, $log_info) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'action_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'action' => sanitize_text_field($action),
                'log_info' => wp_kses_post($log_info),
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Логи действий',
            'Логи действий',
            'manage_options',
            'action-logs',
            array($this, 'render_logs_page'),
            'dashicons-list-view',
            30
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_action-logs' !== $hook) {
            return;
        }
        
        // Проверка роли пользователя
        if (!current_user_can('administrator')) {
            wp_die('Доступ запрещен. Только администраторы могут просматривать эту страницу.');
        }
        
        wp_enqueue_style('action-logs-css', plugins_url('css/admin.css', __FILE__));
        wp_enqueue_script('action-logs-js', plugins_url('js/admin.js', __FILE__), array('jquery'), '1.0', true);
        
        // Datepicker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        
        // Add inline styles and scripts
        wp_add_inline_style('action-logs-css', '
            .log-preview {
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 300px;
            }
            .log-full {
                white-space: normal;
                word-wrap: break-word;
                max-height: 200px;
                overflow-y: auto;
            }
            .show-more {
                font-size: 12px;
                color: #0073aa;
                cursor: pointer;
                display: block;
                margin-top: 5px;
            }
        ');
        
        wp_add_inline_script('action-logs-js', '
            jQuery(document).ready(function($) {
                $(".datepicker").datepicker({
                    dateFormat: "yy-mm-dd"
                });
                
                $(".delete-log").click(function(e) {
                    if (!confirm("Вы точно хотите удалить этот лог?")) {
                        e.preventDefault();
                    }
                });
                
                $(".log-details").on("click", ".show-more", function(e) {
                    e.preventDefault();
                    var $container = $(this).closest(".log-details");
                    $container.find(".log-preview, .log-full").toggle();
                    $(this).text(function(i, text) {
                        return text === "Показать полностью" ? "Свернуть" : "Показать полностью";
                    });
                });
            });
        ');
    }
    
    public function handle_log_deletion() {
        if (isset($_GET['action']) && $_GET['action'] === 'delete_log' && isset($_GET['log_id']) && isset($_GET['_wpnonce'])) {
            if (!current_user_can('administrator')) {
                wp_die('Доступ запрещен. Только администраторы могут выполнять это действие.');
            }
            
            if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_log_' . $_GET['log_id'])) {
                wp_die('Ошибка безопасности.');
            }
            
            global $wpdb;
            $log_id = intval($_GET['log_id']);
            $wpdb->delete($this->table_name, array('id' => $log_id), array('%d'));
            
            wp_redirect(admin_url('admin.php?page=action-logs&deleted=1'));
            exit;
        }
    }
    
    private function truncate_text($text, $length = 250) {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }
    
    public function render_logs_page() {
        // Проверка роли пользователя
        if (!current_user_can('administrator')) {
            wp_die('Доступ запрещен. Только администраторы могут просматривать эту страницу.');
        }
        
        global $wpdb;
        
        // Show deletion notice
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Лог успешно удален.</p></div>';
        }
        
        // Handle filters
        $user_filter = isset($_GET['user_filter']) ? intval($_GET['user_filter']) : '';
        $action_filter = isset($_GET['action_filter']) ? sanitize_text_field($_GET['action_filter']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Build query
        $query = "SELECT l.*, u.user_login, u.display_name 
                  FROM {$this->table_name} l
                  LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
                  WHERE 1=1";
        
        $query_count = "SELECT COUNT(*) FROM {$this->table_name} l WHERE 1=1";
        
        $params = array();
        
        if (!empty($user_filter)) {
            $query .= " AND l.user_id = %d";
            $query_count .= " AND l.user_id = %d";
            $params[] = $user_filter;
        }
        
        if (!empty($action_filter)) {
            $query .= " AND l.action LIKE %s";
            $query_count .= " AND l.action LIKE %s";
            $params[] = '%' . $wpdb->esc_like($action_filter) . '%';
        }
        
        if (!empty($date_from)) {
            $query .= " AND l.created_at >= %s";
            $query_count .= " AND l.created_at >= %s";
            $params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $query .= " AND l.created_at <= %s";
            $query_count .= " AND l.created_at <= %s";
            $params[] = $date_to . ' 23:59:59';
        }
        
        $query .= " ORDER BY l.created_at DESC LIMIT %d, %d";
        $params[] = $offset;
        $params[] = $per_page;
        
        if (!empty($params)) {
            $prepared_query = $wpdb->prepare($query, $params);
            $prepared_count = $wpdb->prepare($query_count, array_slice($params, 0, count($params) - 2));
        } else {
            $prepared_query = $query;
            $prepared_count = $query_count;
        }
        
        $logs = $wpdb->get_results($prepared_query);
        $total_items = $wpdb->get_var($prepared_count);
        
        // Get unique actions for filter dropdown
        $actions = $wpdb->get_col("SELECT DISTINCT action FROM {$this->table_name} ORDER BY action");
        
        ?>
        <div class="wrap">
            <h1>Логи действий</h1>
            
            <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                <input type="hidden" name="page" value="action-logs">
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label for="user_filter">Пользователь:</label>
                        <?php wp_dropdown_users(array(
                            'show_option_all' => 'Все пользователи',
                            'name' => 'user_filter',
                            'selected' => $user_filter,
                            'include_selected' => true
                        )); ?>
                        
                        <label for="action_filter" style="margin-left: 10px;">Действие:</label>
                        <select name="action_filter" id="action_filter">
                            <option value="">Все действия</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?php echo esc_attr($action); ?>" <?php selected($action_filter, $action); ?>>
                                    <?php echo esc_html($action); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <label for="date_from" style="margin-left: 10px;">Дата от:</label>
                        <input type="text" name="date_from" id="date_from" class="datepicker" value="<?php echo esc_attr($date_from); ?>" placeholder="YYYY-MM-DD">
                        
                        <label for="date_to" style="margin-left: 10px;">Дата до:</label>
                        <input type="text" name="date_to" id="date_to" class="datepicker" value="<?php echo esc_attr($date_to); ?>" placeholder="YYYY-MM-DD">
                        
                        <input type="submit" class="button" value="Фильтровать">
                        
                        <?php if (!empty($user_filter) || !empty($action_filter) || !empty($date_from) || !empty($date_to)): ?>
                            <a href="<?php echo admin_url('admin.php?page=action-logs'); ?>" class="button" style="margin-left: 10px;">Очистить фильтр</a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="tablenav-pages">
                        <?php
                        $total_pages = ceil($total_items / $per_page);
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
            </form>
            
            <table style="margin-top: 20px;" class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Пользователь</th>
                        <th>Действие</th>
                        <th>Детали</th>
                        <th>Дата/Время</th>
                        <th>Удалить</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5">Логи не найдены</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php if ($log->user_id): ?>
                                        <a href="<?php echo get_edit_user_link($log->user_id); ?>">
                                            <?php echo esc_html($log->display_name ?: $log->user_login); ?>
                                        </a>
                                    <?php else: ?>
                                        Guest
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->action); ?></td>
                                <td class="log-details">
                                    <div class="log-preview">
                                        <?php echo $this->truncate_text(wp_kses_post($log->log_info), 250); ?>
                                    </div>
                                    <?php if (strlen($log->log_info) > 250): ?>
                                        <div class="log-full" style="display:none;">
                                            <?php echo wp_kses_post($log->log_info); ?>
                                        </div>
                                        <a href="#" class="show-more">Показать полностью</a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date_i18n('Y-m-d H:i:s', strtotime($log->created_at)); ?></td>
                                <td>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=action-logs&action=delete_log&log_id=' . $log->id), 'delete_log_' . $log->id, '_wpnonce'); ?>" 
                                       class="button button-small delete-log" 
                                       title="Удалить этот лог">
                                        Удалить
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $current_page
                    ));
                    ?>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize the plugin
WPActionLogger::get_instance();

// Helper function for easy logging
if (!function_exists('set_log')) {
    function set_log($action, $log_info) {
        return WPActionLogger::set_log($action, $log_info);
    }
}