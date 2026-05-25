<?php
/**
 * Plugin Name: Bulk User Model Editor
 * Version: 1.0
 */

class BulkUserModelEditorPlain {
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer-edit.php', [$this, 'render_modal']);
        add_action('wp_ajax_get_bulk_user_model_modal', [$this, 'ajax_get_modal']);
        add_action('wp_ajax_save_bulk_user_model', [$this, 'ajax_save_data']);
        add_action('wp_ajax_user_autocomplete_search', [$this, 'ajax_user_search']);
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'edit.php' || ($_GET['post_type'] ?? '') !== 'model') return;

        wp_enqueue_script('bulk-user-editor-js', plugins_url('/bulk-user-editor.js', __FILE__), ['jquery'], null, true);
        wp_localize_script('bulk-user-editor-js', 'BULK_USER_MODEL', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bulk_user_model')
        ]);
    }

    public function render_modal() {
        if (($_GET['post_type'] ?? '') !== 'model') return;
        ?>
        <style>
            #bulk-user-model-modal {
                position: fixed;
                top: 50px;
                left: 50%;
                transform: translateX(-50%);
                background: #fff;
                border: 1px solid #ccc;
                padding: 20px;
                z-index: 9999;
                width: 90%;
                max-width: 800px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.3);
                display: none;
            }
            .model-block {
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 1px solid #eee;
            }
            .selected-users {
                margin-top: 10px;
            }
            .selected-users span {
                display: inline-block;
                background: #f0f0f0;
                padding: 5px 10px;
                margin: 2px;
                border-radius: 4px;
            }
            .selected-users span .remove-user {
                cursor: pointer;
                margin-left: 5px;
                color: red;
            }
            #bulk-user-model-modal .modal-close {
                position: absolute;
                top: 10px;
                right: 10px;
                background: #eee;
                border: none;
                padding: 5px 10px;
                cursor: pointer;
            }
        </style>
        <div id="bulk-user-model-modal"></div>
        <?php
    }

    public function ajax_get_modal() {
        check_ajax_referer('bulk_user_model', 'nonce');

        $post_ids = array_map('intval', $_POST['post_ids'] ?? []);
        if (empty($post_ids)) wp_send_json_error('Не выбрано ни одной модели');

        ob_start(); ?>
        <button type="button" class="modal-close">✕</button>
        <form id="bulk-user-model-form">
            <input type="hidden" name="action" value="save_bulk_user_model">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('bulk_user_model'); ?>">
            <div class="models-list" style="height: auto;max-height: 550px;overflow: scroll;margin-bottom: 20px;">
                <?php foreach ($post_ids as $post_id):
                    $user_ids = get_field('user_model', $post_id, false) ?: [];
                    if (!is_array($user_ids)) $user_ids = [$user_ids];
                ?>
                <div class="model-block" data-post-id="<?php echo $post_id; ?>">
                    <h3><?php echo get_the_title($post_id); ?> (ID: <?php echo $post_id; ?>)</h3>
                    <input type="hidden" name="post_ids[]" value="<?php echo $post_id; ?>">
                    <input type="text" class="user-autocomplete" data-post-id="<?php echo $post_id; ?>" placeholder="Введите имя или email">
                    <div class="selected-users" data-post-id="<?php echo $post_id; ?>">
                        <?php foreach ($user_ids as $uid): 
                            if ($user = get_userdata($uid)):
                        ?>
                        <span data-id="<?php echo $uid; ?>">
                            <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                            <span class="remove-user">×</span>
                            <input type="hidden" name="users[<?php echo $post_id; ?>][]" value="<?php echo $uid; ?>">
                        </span>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="button button-primary">Сохранить</button>
        </form>
        <?php
        wp_send_json_success(ob_get_clean());
    }

    public function ajax_save_data() {
        check_ajax_referer('bulk_user_model', 'nonce');

        $post_ids = array_map('intval', $_POST['post_ids'] ?? []);
        if (empty($post_ids)) wp_send_json_error('Нет данных для сохранения');

        foreach ($post_ids as $pid) {
            $user_ids = array_map('intval', $_POST['users'][$pid] ?? []);
            update_field('user_model', $user_ids, $pid);
        }

        wp_send_json_success('Сохранено');
    }

    public function ajax_user_search() {
        $search = sanitize_text_field($_GET['term'] ?? '');
        $results = [];

        if ($search) {
            $users = get_users([
                'search' => "*$search*",
                'search_columns' => ['user_login', 'user_email', 'display_name'],
                'number' => 10,
            ]);

            foreach ($users as $user) {
                $results[] = [
                    'id' => $user->ID,
                    'label' => $user->display_name . ' (' . $user->user_email . ')',
                    'value' => $user->display_name . ' (' . $user->user_email . ')',
                    'user_id' => $user->ID
                ];
            }
        }

        wp_send_json($results);
    }
}

new BulkUserModelEditorPlain();
