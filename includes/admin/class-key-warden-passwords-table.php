<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Every application password on the site, across all users.
 */
class Key_Warden_Passwords_Table extends WP_List_Table {
    private $counts = array();

    public function __construct() {
        parent::__construct(array('singular' => 'password', 'plural' => 'passwords', 'ajax' => false));
    }

    public static function status_of(array $row) {
        if (!empty($row['suspended'])) return 'suspended';
        if (Key_Warden_Store::is_expired($row)) return 'expired';
        return 'active';
    }

    public function get_columns() {
        return array(
            'cb'        => '<input type="checkbox">',
            'name'      => __('Name', 'key-warden'),
            'user'      => __('User', 'key-warden'),
            'status'    => __('Status', 'key-warden'),
            'profile'   => __('Route profile', 'key-warden'),
            'created'   => __('Created', 'key-warden'),
            'last_used' => __('Last used', 'key-warden'),
            'expires'   => __('Expires', 'key-warden'),
        );
    }

    protected function get_sortable_columns() {
        return array(
            'name'      => array('name', false),
            'user'      => array('user', false),
            'created'   => array('created', true),
            'last_used' => array('last_used', true),
            'expires'   => array('expires', false),
        );
    }

    public function prepare_items() {
        $rows = Key_Warden_Store::manageable_passwords();

        $this->counts = array('all' => count($rows), 'active' => 0, 'expired' => 0, 'suspended' => 0);
        foreach ($rows as $row) $this->counts[self::status_of($row)]++;

        $status = sanitize_key(Key_Warden_Admin::query('status'));
        if (isset($this->counts[$status]) && $status !== 'all') {
            $rows = array_filter($rows, function ($row) use ($status) {
                return Key_Warden_Passwords_Table::status_of($row) === $status;
            });
        }

        $search = strtolower(trim(Key_Warden_Admin::query('s')));
        if ($search !== '') {
            $rows = array_filter($rows, function ($row) use ($search) {
                $haystack = strtolower($row['name'] . ' ' . $row['user_login'] . ' ' . $row['user']->user_email . ' ' . $row['last_ip']);
                return strpos($haystack, $search) !== false;
            });
        }

        $orderby = sanitize_key(Key_Warden_Admin::query('orderby', 'created'));
        $order = strtolower(Key_Warden_Admin::query('order')) === 'asc' ? 1 : -1;
        usort($rows, function ($a, $b) use ($orderby, $order) {
            switch ($orderby) {
                case 'name':
                    return $order * strcasecmp($a['name'], $b['name']);
                case 'user':
                    return $order * strcasecmp($a['user_login'], $b['user_login']);
                case 'last_used':
                    return $order * ((int) $a['last_used'] <=> (int) $b['last_used']);
                case 'expires':
                    // "Never" sorts last when ascending.
                    $ea = $a['expires'] ? (int) $a['expires'] : PHP_INT_MAX;
                    $eb = $b['expires'] ? (int) $b['expires'] : PHP_INT_MAX;
                    return $order * ($ea <=> $eb);
                default:
                    return $order * ((int) $a['created'] <=> (int) $b['created']);
            }
        });

        $per_page = $this->get_items_per_page('key_warden_per_page', 50);
        $total = count($rows);
        $this->items = array_slice(array_values($rows), ($this->get_pagenum() - 1) * $per_page, $per_page);
        $this->set_pagination_args(array('total_items' => $total, 'per_page' => $per_page));
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), 'name');
    }

    protected function get_views() {
        $current = sanitize_key(Key_Warden_Admin::query('status', 'all'));
        $labels = array(
            'all'       => __('All', 'key-warden'),
            'active'    => __('Active', 'key-warden'),
            'expired'   => __('Expired', 'key-warden'),
            'suspended' => __('Suspended', 'key-warden'),
        );
        $views = array();
        foreach ($labels as $key => $label) {
            $url = Key_Warden_Admin::url('passwords', $key === 'all' ? array() : array('status' => $key));
            $views[$key] = sprintf('<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url($url), $current === $key ? ' class="current"' : '', esc_html($label), (int) $this->counts[$key]);
        }
        return $views;
    }

    protected function get_bulk_actions() {
        return array(
            'suspend' => __('Suspend', 'key-warden'),
            'resume'  => __('Resume', 'key-warden'),
            'revoke'  => __('Revoke', 'key-warden'),
        );
    }

    /**
     * Core names the select "action", which clashes with admin-post.php's own "action" field.
     */
    protected function bulk_actions($which = '') {
        $name = $which === 'top' ? 'bulk_action' : 'bulk_action2';
        echo '<label for="' . esc_attr($name) . '" class="screen-reader-text">' . esc_html__('Select bulk action', 'key-warden') . '</label>';
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '">';
        echo '<option value="-1">' . esc_html__('Bulk actions', 'key-warden') . '</option>';
        foreach ($this->get_bulk_actions() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Apply', 'key-warden'), 'action key-warden-confirm', '', false, array('id' => 'doaction' . ($which === 'top' ? '' : '2')));
    }

    public function no_items() {
        esc_html_e('No application passwords found.', 'key-warden');
    }

    protected function column_cb($row) {
        return sprintf('<input type="checkbox" name="passwords[]" value="%s">', esc_attr($row['user_id'] . ':' . $row['uuid']));
    }

    protected function column_name($row) {
        $args = array('user_id' => $row['user_id'], 'uuid' => $row['uuid']);
        $actions = array(
            'edit'    => sprintf('<a href="%s">%s</a>', esc_url(Key_Warden_Admin::url('passwords', array('edit' => $row['uuid'], 'user' => $row['user_id']))), esc_html__('Edit', 'key-warden')),
            'suspend' => sprintf('<a href="%s">%s</a>', esc_url(Key_Warden_Admin::action_url('toggle_suspend', $args)), empty($row['suspended']) ? esc_html__('Suspend', 'key-warden') : esc_html__('Resume', 'key-warden')),
            'delete'  => sprintf('<a href="%s" class="key-warden-confirm">%s</a>', esc_url(Key_Warden_Admin::action_url('revoke', $args)), esc_html__('Revoke', 'key-warden')),
        );
        $edit = Key_Warden_Admin::url('passwords', array('edit' => $row['uuid'], 'user' => $row['user_id']));
        return sprintf('<strong><a href="%s">%s</a></strong>%s', esc_url($edit), esc_html($row['name']), $this->row_actions($actions));
    }

    protected function column_user($row) {
        return sprintf('<a href="%s">%s</a><br><span class="key-warden-muted">%s</span>', esc_url(get_edit_user_link($row['user_id'])), esc_html($row['user_login']), esc_html(implode(', ', array_map(function ($r) {
            $names = wp_roles()->get_names();
            return isset($names[$r]) ? translate_user_role($names[$r]) : $r;
        }, (array) $row['user']->roles))));
    }

    protected function column_status($row) {
        $status = self::status_of($row);
        $labels = array(
            'active'    => __('Active', 'key-warden'),
            'expired'   => __('Expired', 'key-warden'),
            'suspended' => __('Suspended', 'key-warden'),
        );
        $html = sprintf('<span class="key-warden-status key-warden-status-%s">%s</span>', esc_attr($status), esc_html($labels[$status]));
        if (!Key_Warden_Policy::user_allowed('auth', $row['user'])) {
            $html .= '<br><span class="key-warden-muted">' . esc_html__('User not allowed to log in with app passwords', 'key-warden') . '</span>';
        }
        return $html;
    }

    protected function column_profile($row) {
        $id = Key_Warden_Policy::profile_id_for($row['user_id'], $row['uuid']);
        $label = esc_html(Key_Warden_Policy::profile_label($id));
        if ($row['profile'] === Key_Warden_Settings::PROFILE_INHERIT) {
            $label .= ' <span class="key-warden-muted">' . esc_html__('(inherited)', 'key-warden') . '</span>';
        }
        return $label;
    }

    protected function column_created($row) {
        return esc_html(Key_Warden_Admin::date($row['created']));
    }

    protected function column_last_used($row) {
        if (empty($row['last_used'])) return '<span class="key-warden-muted">' . esc_html__('Never', 'key-warden') . '</span>';
        return esc_html(Key_Warden_Admin::date($row['last_used'])) . '<br><span class="key-warden-muted">' . esc_html($row['last_ip']) . '</span>';
    }

    protected function column_expires($row) {
        if (empty($row['expires'])) return '<span class="key-warden-muted">' . esc_html__('Never', 'key-warden') . '</span>';
        return esc_html(Key_Warden_Admin::date($row['expires']));
    }
}
