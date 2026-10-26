<?php
if (!defined('ABSPATH')) exit;

/**
 * The Tools > Application Passwords screen.
 */
class Key_Warden_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_key_warden_save', array(__CLASS__, 'handle_post'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_key_warden_user_search', array(__CLASS__, 'ajax_user_search'));
        add_action('wp_ajax_key_warden_test_route', array(__CLASS__, 'ajax_test_route'));
        add_filter('plugin_action_links_' . plugin_basename(KEY_WARDEN_PLUGIN_FILE), array(__CLASS__, 'action_links'));
    }

    public static function cap() {
        return Key_Warden_Policy::manager_cap();
    }

    /**
     * Read a display parameter from the URL (tab, filters, sorting, which item to edit).
     * These only change what's shown, never what's saved: every change goes through
     * handle_post() or an AJAX handler, which check a nonce and the capability.
     */
    public static function query($key, $default = '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter, see above.
        return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : $default;
    }

    public static function url($tab = 'passwords', $args = array()) {
        return add_query_arg(array_merge(array('page' => KEY_WARDEN_SLUG, 'tab' => $tab), $args), admin_url('tools.php'));
    }

    public static function menu() {
        add_management_page(
            KEY_WARDEN_NAME,
            __('Application Passwords', 'key-warden'),
            self::cap(),
            KEY_WARDEN_SLUG,
            array(__CLASS__, 'render')
        );
    }

    public static function action_links($links) {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Manage', 'key-warden') . '</a>');
        return $links;
    }

    public static function assets($hook) {
        if ($hook !== 'tools_page_' . KEY_WARDEN_SLUG) return;
        wp_enqueue_style('key-warden-admin', KEY_WARDEN_PLUGIN_URL . 'assets/admin.css', array(), KEY_WARDEN_VERSION);
        wp_enqueue_script('key-warden-admin', KEY_WARDEN_PLUGIN_URL . 'assets/admin.js', array(), KEY_WARDEN_VERSION, true);
        $templates = array();
        foreach (Key_Warden_Templates::all() as $id => $t) {
            $templates[$id] = array('name' => $t['name'], 'mode' => $t['mode'], 'rules' => $t['rules'], 'abilities' => isset($t['abilities']) ? $t['abilities'] : 'all');
        }
        wp_localize_script('key-warden-admin', 'keyWardenAdmin', array(
            'templates' => $templates,
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('key_warden_ajax'),
            'i18n'      => array(
                'noUsers'      => __('No matching users', 'key-warden'),
                'remove'       => __('Remove', 'key-warden'),
                'modeMismatch' => __('This template was written for a different mode than this profile. Check the mode before saving.', 'key-warden'),
                'confirm'      => __('Are you sure?', 'key-warden'),
            ),
        ));
    }

    private static function tabs() {
        return array(
            'passwords' => __('Passwords', 'key-warden'),
            'access'    => __('Who can use them', 'key-warden'),
            'profiles'  => __('Route profiles', 'key-warden'),
            'settings'  => __('Settings', 'key-warden'),
            'log'       => __('Activity log', 'key-warden'),
        );
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    public static function render() {
        if (!current_user_can(self::cap())) return;
        $tabs = self::tabs();
        $tab = sanitize_key(self::query('tab', 'passwords'));
        if (!isset($tabs[$tab])) $tab = 'passwords';
        ?>
        <div class="wrap key-warden-wrap">
            <h1><?php echo esc_html(KEY_WARDEN_NAME); ?></h1>
            <?php self::render_notices(); ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="<?php echo esc_url(self::url($key)); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="key-warden-tab">
                <?php call_user_func(array(__CLASS__, 'render_' . $tab)); ?>
            </div>
        </div>
        <?php
    }

    private static function render_notices() {
        if (!wp_is_application_passwords_supported()) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Application passwords need HTTPS (or a "local" environment type). WordPress has them turned off on this site until then.', 'key-warden') . '</p></div>';
        } elseif (!wp_is_application_passwords_available()) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Another plugin or setting has turned application passwords off for the whole site.', 'key-warden') . '</p></div>';
        }

        $messages = array(
            'saved'    => __('Saved.', 'key-warden'),
            'revoked'  => __('Revoked.', 'key-warden'),
            'deleted'  => __('Profile deleted.', 'key-warden'),
            'cleared'  => __('Log cleared.', 'key-warden'),
            'cleanup'  => __('Cleanup ran.', 'key-warden'),
        );
        $msg = self::query('key_warden_msg');
        if (isset($messages[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
        }
        $count = (int) self::query('key_warden_count', 0);
        if ($count) {
            /* translators: %d: number of passwords */
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d password updated.', '%d passwords updated.', $count, 'key-warden'), $count)) . '</p></div>';
        }
        $warning = get_transient('key_warden_warning_' . get_current_user_id());
        if ($warning) {
            delete_transient('key_warden_warning_' . get_current_user_id());
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($warning) . '</p></div>';
        }
    }

    private static function render_passwords() {
        if (self::query('edit') !== '' && self::query('user') !== '') {
            self::render_edit_password((int) self::query('user'), self::query('edit'));
            return;
        }

        require_once KEY_WARDEN_PLUGIN_DIR . 'includes/admin/class-key-warden-passwords-table.php';
        $table = new Key_Warden_Passwords_Table();
        $table->prepare_items();
        ?>
        <p class="description"><?php esc_html_e('Every application password on this site, for every user.', 'key-warden'); ?></p>
        <?php $table->views(); ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(KEY_WARDEN_SLUG); ?>">
            <input type="hidden" name="tab" value="passwords">
            <?php if (self::query('status') !== '') : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr(sanitize_key(self::query('status'))); ?>">
            <?php endif; ?>
            <?php $table->search_box(__('Search passwords', 'key-warden'), 'key-warden-search'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php self::hidden('bulk_passwords', false); // The list table adds its own "bulk-passwords" nonce. ?>
            <?php $table->display(); ?>
        </form>
        <?php
    }

    private static function render_edit_password($user_id, $uuid) {
        $item = current_user_can('edit_user', $user_id) ? Key_Warden_Store::find($user_id, $uuid) : null;
        $user = get_userdata($user_id);
        if (!$item || !$user) {
            echo '<p>' . esc_html__('That application password no longer exists.', 'key-warden') . '</p>';
            return;
        }
        $effective = Key_Warden_Policy::profile_id_for($user_id, $uuid);
        ?>
        <p><a href="<?php echo esc_url(self::url()); ?>">&larr; <?php esc_html_e('All passwords', 'key-warden'); ?></a></p>
        <h2><?php echo esc_html($item['name']); ?> <span class="key-warden-muted">&middot; <?php echo esc_html($user->user_login); ?></span></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php self::hidden('edit_password'); ?>
            <input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>">
            <input type="hidden" name="uuid" value="<?php echo esc_attr($uuid); ?>">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Created', 'key-warden'); ?></th>
                    <td><?php echo esc_html(self::date($item['created'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last used', 'key-warden'); ?></th>
                    <td><?php echo esc_html($item['last_used'] ? self::date($item['last_used']) . ' (' . $item['last_ip'] . ')' : __('Never', 'key-warden')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="key-warden-expires"><?php esc_html_e('Expires', 'key-warden'); ?></label></th>
                    <td>
                        <input type="datetime-local" id="key-warden-expires" name="expires" value="<?php echo esc_attr($item['expires'] ? wp_date('Y-m-d\TH:i', $item['expires']) : ''); ?>">
                        <p class="description"><?php esc_html_e('Leave empty to never expire. Uses the site timezone.', 'key-warden'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="key-warden-profile"><?php esc_html_e('Route profile', 'key-warden'); ?></label></th>
                    <td>
                        <?php self::profile_select('profile', $item['profile'], true, 'key-warden-profile'); ?>
                        <p class="description">
                            <?php
                            /* translators: %s: profile name */
                            echo esc_html(sprintf(__('Currently in effect: %s', 'key-warden'), Key_Warden_Policy::profile_label($effective)));
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Suspended', 'key-warden'); ?></th>
                    <td><label><input type="checkbox" name="suspended" value="1" <?php checked($item['suspended']); ?>> <?php esc_html_e('Block this password without deleting it', 'key-warden'); ?></label></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private static function render_access() {
        $s = Key_Warden_Settings::all();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php self::hidden('access'); ?>

            <h2><?php esc_html_e('Who can create application passwords', 'key-warden'); ?></h2>
            <?php self::who_fields('create', $s); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="key-warden-max"><?php esc_html_e('Max per user', 'key-warden'); ?></label></th>
                    <td>
                        <input type="number" min="0" id="key-warden-max" name="max_per_user" value="<?php echo (int) $s['max_per_user']; ?>" class="small-text">
                        <p class="description"><?php esc_html_e('0 means no limit.', 'key-warden'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Who can log in with application passwords', 'key-warden'); ?></h2>
            <p class="description"><?php esc_html_e('People not allowed here can\'t use any of their application passwords, and won\'t see the section on their profile.', 'key-warden'); ?></p>
            <?php self::who_fields('auth', $s); ?>

            <h2><?php esc_html_e('Route profile by role', 'key-warden'); ?></h2>
            <p class="description"><?php esc_html_e('Which route profile applies to a password, unless the password has its own. Roles set to "Default profile" use the one chosen on the Settings tab. If a user has several roles, the first one with a profile wins.', 'key-warden'); ?></p>
            <table class="form-table" role="presentation">
                <?php foreach (wp_roles()->get_names() as $role => $name) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html(translate_user_role($name)); ?></th>
                        <td><?php self::profile_select('role_profiles[' . $role . ']', isset($s['role_profiles'][$role]) ? $s['role_profiles'][$role] : '', 'default'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    private static function who_fields($type, $s) {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Allowed', 'key-warden'); ?></th>
                <td>
                    <label><input type="radio" name="<?php echo esc_attr($type); ?>_mode" value="all" <?php checked($s[$type . '_mode'], 'all'); ?> data-key-warden-toggle="<?php echo esc_attr($type); ?>"> <?php esc_html_e('Everyone (WordPress default)', 'key-warden'); ?></label><br>
                    <label><input type="radio" name="<?php echo esc_attr($type); ?>_mode" value="restricted" <?php checked($s[$type . '_mode'], 'restricted'); ?> data-key-warden-toggle="<?php echo esc_attr($type); ?>"> <?php esc_html_e('Only the roles and people below', 'key-warden'); ?></label>
                </td>
            </tr>
            <tr class="key-warden-restricted-<?php echo esc_attr($type); ?>">
                <th scope="row"><?php esc_html_e('Roles', 'key-warden'); ?></th>
                <td>
                    <?php foreach (wp_roles()->get_names() as $role => $name) : ?>
                        <label class="key-warden-inline"><input type="checkbox" name="<?php echo esc_attr($type); ?>_roles[]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, (array) $s[$type . '_roles'], true)); ?>> <?php echo esc_html(translate_user_role($name)); ?></label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr class="key-warden-restricted-<?php echo esc_attr($type); ?>">
                <th scope="row"><label for="key-warden-<?php echo esc_attr($type); ?>-users"><?php esc_html_e('People', 'key-warden'); ?></label></th>
                <td>
                    <div class="key-warden-chips" data-name="<?php echo esc_attr($type); ?>_users[]">
                        <?php foreach ((array) $s[$type . '_users'] as $id) : $u = get_userdata((int) $id); if (!$u) continue; ?>
                            <?php self::chip($type . '_users[]', $u); ?>
                        <?php endforeach; ?>
                        <input type="text" id="key-warden-<?php echo esc_attr($type); ?>-users" class="key-warden-chips-input" autocomplete="off" role="combobox" aria-expanded="false" placeholder="<?php esc_attr_e('Search by name, username, or email…', 'key-warden'); ?>">
                        <ul class="key-warden-chips-results" role="listbox" hidden></ul>
                    </div>
                    <p class="description"><?php esc_html_e('These people are allowed no matter their role.', 'key-warden'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_profiles() {
        if (self::query('edit') !== '' || self::query('new') !== '') {
            self::render_profile_editor();
            return;
        }

        $profiles = Key_Warden_Settings::profiles();
        $usage = Key_Warden_Policy::profile_usage();
        ?>
        <div class="key-warden-heading">
            <h2><?php esc_html_e('Route profiles', 'key-warden'); ?></h2>
            <a href="<?php echo esc_url(self::url('profiles', array('new' => 1))); ?>" class="page-title-action"><?php esc_html_e('Add New Profile', 'key-warden'); ?></a>
        </div>
        <p class="description"><?php esc_html_e('A route profile limits which REST API routes a password can call. Passwords with no profile can reach everything their user can.', 'key-warden'); ?></p>

        <table class="wp-list-table widefat fixed striped key-warden-profiles-table">
            <thead>
                <tr>
                    <th class="column-primary"><?php esc_html_e('Name', 'key-warden'); ?></th>
                    <th><?php esc_html_e('Mode', 'key-warden'); ?></th>
                    <th><?php esc_html_e('Rules', 'key-warden'); ?></th>
                    <th><?php esc_html_e('Used by', 'key-warden'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$profiles) : ?>
                    <tr><td colspan="4"><?php esc_html_e('No profiles yet. Add one, or start from a template below.', 'key-warden'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($profiles as $id => $p) : $rules = self::rule_lines($p['rules']); ?>
                    <tr>
                        <td class="column-primary">
                            <strong><a href="<?php echo esc_url(self::url('profiles', array('edit' => $id))); ?>"><?php echo esc_html($p['name']); ?></a></strong>
                            <div class="row-actions">
                                <span class="edit"><a href="<?php echo esc_url(self::url('profiles', array('edit' => $id))); ?>"><?php esc_html_e('Edit', 'key-warden'); ?></a> | </span>
                                <span class="delete"><a href="<?php echo esc_url(self::action_url('delete_profile', array('profile_id' => $id))); ?>" class="key-warden-confirm"><?php esc_html_e('Delete', 'key-warden'); ?></a></span>
                            </div>
                        </td>
                        <td><span class="key-warden-mode-pill key-warden-mode-<?php echo esc_attr($p['mode']); ?>"><?php echo esc_html(self::mode_short($p['mode'])); ?></span></td>
                        <td>
                            <?php foreach (array_slice($rules, 0, 3) as $line) : ?>
                                <code class="key-warden-rule"><?php echo esc_html($line); ?></code>
                            <?php endforeach; ?>
                            <?php if (count($rules) > 3) : ?>
                                <span class="key-warden-muted">
                                    <?php
                                    /* translators: %d: number of rules */
                                    echo esc_html(sprintf(__('+%d more', 'key-warden'), count($rules) - 3));
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($usage[$id] ? implode(', ', $usage[$id]) : '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e('Start from a template', 'key-warden'); ?></h2>
        <p class="description"><?php esc_html_e('Opens a new profile filled in with the template\'s rules, ready to tweak and save.', 'key-warden'); ?></p>
        <?php
        $groups = array();
        foreach (Key_Warden_Templates::all() as $tid => $t) $groups[$t['group']][$tid] = $t;
        foreach ($groups as $group => $templates) :
            ?>
            <h3 class="key-warden-group-title"><?php echo esc_html($group); ?></h3>
            <div class="key-warden-template-grid">
                <?php foreach ($templates as $tid => $t) : $available = Key_Warden_Templates::is_available($t); ?>
                    <a class="key-warden-template-card<?php echo $available ? '' : ' key-warden-unavailable'; ?>" href="<?php echo esc_url(self::url('profiles', array('new' => 1, 'template' => $tid))); ?>">
                        <span class="key-warden-template-name"><?php echo esc_html($t['name']); ?></span>
                        <span class="key-warden-template-desc"><?php echo esc_html($t['description']); ?></span>
                        <span class="key-warden-template-meta">
                            <span class="key-warden-mode-pill key-warden-mode-<?php echo esc_attr($t['mode']); ?>"><?php echo esc_html(self::mode_short($t['mode'])); ?></span>
                            <?php if (!$available) : ?><span class="key-warden-badge"><?php esc_html_e('Plugin not active', 'key-warden'); ?></span><?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php
    }

    private static function render_profile_editor() {
        $id = sanitize_key(self::query('edit'));
        $profile = $id ? Key_Warden_Settings::profile($id) : null;
        if ($id && !$profile) {
            echo '<p>' . esc_html__('That profile no longer exists.', 'key-warden') . '</p>';
            return;
        }
        if (!$profile) {
            $profile = array('name' => '', 'mode' => 'allowlist', 'rules' => array(), 'abilities' => 'all', 'non_rest' => false);
            $t = self::query('template') !== '' ? Key_Warden_Templates::get(sanitize_key(self::query('template'))) : null;
            if ($t) $profile = array('name' => $t['name'], 'mode' => $t['mode'], 'rules' => $t['rules'], 'abilities' => $t['abilities'], 'non_rest' => false);
        }
        ?>
        <p><a href="<?php echo esc_url(self::url('profiles')); ?>">&larr; <?php esc_html_e('All profiles', 'key-warden'); ?></a></p>
        <h2><?php echo $id ? esc_html__('Edit profile', 'key-warden') : esc_html__('Add new profile', 'key-warden'); ?></h2>

        <div class="key-warden-editor">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="key-warden-profile-form key-warden-editor-main">
                <?php self::hidden('save_profile'); ?>
                <input type="hidden" name="profile_id" value="<?php echo esc_attr($id); ?>">

                <div class="key-warden-field">
                    <label for="key-warden-profile-name" class="key-warden-label"><?php esc_html_e('Name', 'key-warden'); ?></label>
                    <input type="text" id="key-warden-profile-name" name="name" value="<?php echo esc_attr($profile['name']); ?>" class="regular-text" required>
                </div>

                <div class="key-warden-field">
                    <span class="key-warden-label"><?php esc_html_e('Mode', 'key-warden'); ?></span>
                    <div class="key-warden-mode-choices">
                        <label class="key-warden-mode-choice">
                            <input type="radio" name="mode" value="allowlist" class="key-warden-mode" <?php checked($profile['mode'], 'allowlist'); ?>>
                            <strong><?php echo esc_html(self::mode_label('allowlist')); ?></strong>
                            <span><?php esc_html_e('Only the routes listed below work. Safest choice.', 'key-warden'); ?></span>
                        </label>
                        <label class="key-warden-mode-choice">
                            <input type="radio" name="mode" value="blocklist" class="key-warden-mode" <?php checked($profile['mode'], 'blocklist'); ?>>
                            <strong><?php echo esc_html(self::mode_label('blocklist')); ?></strong>
                            <span><?php esc_html_e('Everything works except the routes listed below.', 'key-warden'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="key-warden-field">
                    <label for="key-warden-profile-rules" class="key-warden-label"><?php esc_html_e('Routes', 'key-warden'); ?></label>
                    <textarea id="key-warden-profile-rules" name="rules" rows="<?php echo (int) max(8, count($profile['rules']) + 2); ?>" class="large-text code key-warden-rules-input" spellcheck="false"><?php echo esc_textarea(implode("\n", $profile['rules'])); ?></textarea>
                    <p class="description"><?php esc_html_e('One per line, without /wp-json. * matches anything. Put methods in front to limit a line to them, like GET or GET,POST. Lines starting with # are notes.', 'key-warden'); ?></p>
                    <div class="key-warden-template-picker">
                        <select class="key-warden-template-select" aria-label="<?php esc_attr_e('Template', 'key-warden'); ?>">
                            <option value=""><?php esc_html_e('Add routes from a template…', 'key-warden'); ?></option>
                            <?php self::template_options(); ?>
                        </select>
                        <button type="button" class="button key-warden-template-add"><?php esc_html_e('Add', 'key-warden'); ?></button>
                    </div>
                </div>

                <div class="key-warden-field">
                    <span class="key-warden-label"><?php esc_html_e('AI abilities', 'key-warden'); ?></span>
                    <label><input type="checkbox" name="abilities_read_only" value="1" <?php checked($profile['abilities'], 'read'); ?>> <?php esc_html_e('Only allow read-only abilities', 'key-warden'); ?></label>
                    <p class="description"><?php esc_html_e('AI agents run abilities through one route (like /mcp/*), so route rules can\'t tell them apart. By default an agent can use any ability its user is allowed to. Tick this for an agent that should only look, not change anything (needs WordPress 7.1 or newer). Either way, a password with a profile can never change this plugin\'s own settings.', 'key-warden'); ?></p>
                </div>

                <details class="key-warden-advanced"<?php echo $profile['non_rest'] ? ' open' : ''; ?>>
                    <summary><?php esc_html_e('Advanced', 'key-warden'); ?></summary>
                    <label><input type="checkbox" name="non_rest" value="1" <?php checked($profile['non_rest']); ?>> <?php esc_html_e('Also allow logins outside the REST API', 'key-warden'); ?></label>
                    <p class="description"><?php esc_html_e('Lets passwords with this profile log in over XML-RPC, and on other plugins\' endpoints that accept application passwords (like GraphQL). The routes above can\'t be checked there, so these passwords could do anything their user can on those endpoints. Only turn this on if an app needs it. XML-RPC also has to be allowed under Settings.', 'key-warden'); ?></p>
                </details>

                <div class="key-warden-editor-actions">
                    <?php submit_button($id ? __('Save Profile', 'key-warden') : __('Create Profile', 'key-warden'), 'primary', 'submit', false); ?>
                    <?php if ($id) : ?>
                        <a href="<?php echo esc_url(self::action_url('delete_profile', array('profile_id' => $id))); ?>" class="button-link button-link-delete key-warden-confirm"><?php esc_html_e('Delete profile', 'key-warden'); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <aside class="key-warden-editor-side">
                <div class="key-warden-panel">
                    <h3><?php esc_html_e('Test a route', 'key-warden'); ?></h3>
                    <p class="description"><?php esc_html_e('Checks against the routes in the box as you type, before saving.', 'key-warden'); ?></p>
                    <div class="key-warden-tester">
                        <select class="key-warden-test-method" aria-label="<?php esc_attr_e('Method', 'key-warden'); ?>">
                            <?php foreach (Key_Warden_Rules::METHODS as $m) : ?>
                                <option><?php echo esc_html($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="key-warden-test-route code" placeholder="/wp/v2/posts/123" aria-label="<?php esc_attr_e('Route', 'key-warden'); ?>">
                    </div>
                    <p class="key-warden-result" hidden></p>
                </div>

                <div class="key-warden-panel">
                    <h3><?php esc_html_e('Routes on this site', 'key-warden'); ?></h3>
                    <p class="description"><?php esc_html_e('Click + to add a route to the box.', 'key-warden'); ?></p>
                    <input type="search" class="key-warden-route-filter" placeholder="<?php esc_attr_e('Filter routes…', 'key-warden'); ?>">
                    <div class="key-warden-route-list">
                        <?php self::render_site_routes(); ?>
                    </div>
                </div>
            </aside>
        </div>
        <?php
    }

    private static function render_site_routes() {
        foreach (Key_Warden_Rules::site_routes() as $ns => $routes) {
            echo '<details class="key-warden-ns"><summary><code>' . esc_html($ns) . '</code> <span class="key-warden-muted">' . (int) count($routes) . '</span>';
            echo '<button type="button" class="key-warden-route-add" data-route="' . esc_attr('/' . $ns . '/*') . '" title="' . esc_attr__('Add the whole namespace', 'key-warden') . '">+</button></summary><ul>';
            foreach ($routes as $route) {
                echo '<li><code>' . esc_html($route) . '</code><button type="button" class="key-warden-route-add" data-route="' . esc_attr($route) . '" aria-label="' . esc_attr__('Add route', 'key-warden') . '">+</button></li>';
            }
            echo '</ul></details>';
        }
    }

    private static function rule_lines(array $rules) {
        return array_values(array_filter($rules, function ($r) {
            return $r !== '' && $r[0] !== '#';
        }));
    }

    public static function mode_short($mode) {
        return $mode === 'allowlist'
            ? __('Block all except', 'key-warden')
            : __('Allow all except', 'key-warden');
    }

    private static function template_options() {
        $groups = array();
        foreach (Key_Warden_Templates::all() as $tid => $t) $groups[$t['group']][$tid] = $t;
        foreach ($groups as $group => $templates) {
            echo '<optgroup label="' . esc_attr($group) . '">';
            foreach ($templates as $tid => $t) {
                echo '<option value="' . esc_attr($tid) . '">' . esc_html($t['name']) . '</option>';
            }
            echo '</optgroup>';
        }
    }

    private static function render_settings() {
        $s = Key_Warden_Settings::all();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php self::hidden('settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="key-warden-default-profile"><?php esc_html_e('Default route profile', 'key-warden'); ?></label></th>
                    <td>
                        <?php self::profile_select('default_profile', $s['default_profile'], false, 'key-warden-default-profile'); ?>
                        <p class="description"><?php esc_html_e('Used for any password that doesn\'t have its own profile, when the user\'s role doesn\'t have one either.', 'key-warden'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="key-warden-lifetime"><?php esc_html_e('New passwords expire after', 'key-warden'); ?></label></th>
                    <td>
                        <input type="number" min="0" id="key-warden-lifetime" name="default_lifetime_days" value="<?php echo (int) $s['default_lifetime_days']; ?>" class="small-text"> <?php esc_html_e('days', 'key-warden'); ?>
                        <p class="description"><?php esc_html_e('0 means never. Applies to passwords created from now on. You can change any single password\'s expiry on the Passwords tab.', 'key-warden'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="key-warden-idle"><?php esc_html_e('Revoke unused passwords after', 'key-warden'); ?></label></th>
                    <td>
                        <input type="number" min="0" id="key-warden-idle" name="idle_revoke_days" value="<?php echo (int) $s['idle_revoke_days']; ?>" class="small-text"> <?php esc_html_e('days', 'key-warden'); ?>
                        <p class="description"><?php esc_html_e('0 means never. Checked once a day. A password that was never used counts from the day it was created.', 'key-warden'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('XML-RPC', 'key-warden'); ?></th>
                    <td>
                        <label><input type="checkbox" name="allow_xmlrpc" value="1" <?php checked($s['allow_xmlrpc']); ?>> <?php esc_html_e('Allow application passwords to log in over XML-RPC', 'key-warden'); ?></label>
                        <p class="description"><?php esc_html_e('Passwords with a route profile can never use XML-RPC, because its actions can\'t be matched to routes.', 'key-warden'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Email alerts', 'key-warden'); ?></th>
                    <td>
                        <label><input type="checkbox" name="notify_on_create" value="1" <?php checked($s['notify_on_create']); ?>> <?php esc_html_e('Email the site admin whenever anyone creates an application password', 'key-warden'); ?></label>
                        <p class="description">
                            <?php
                            /* translators: %s: email address */
                            echo esc_html(sprintf(__('Sent to %s. Catches passwords left behind as a backdoor.', 'key-warden'), get_option('admin_email')));
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Activity log', 'key-warden'); ?></th>
                    <td><label><input type="checkbox" name="log_enabled" value="1" <?php checked($s['log_enabled']); ?>> <?php esc_html_e('Keep a log of created, revoked, and blocked passwords', 'key-warden'); ?></label></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php self::hidden('run_cleanup'); ?>
            <p><button class="button"><?php esc_html_e('Run expiry cleanup now', 'key-warden'); ?></button></p>
        </form>
        <?php
    }

    private static function render_log() {
        $log = Key_Warden_Log::all();
        $labels = Key_Warden_Log::labels();
        if (!Key_Warden_Settings::get('log_enabled')) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Logging is turned off in Settings.', 'key-warden') . '</p></div>';
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('When', 'key-warden'); ?></th>
                    <th><?php esc_html_e('What', 'key-warden'); ?></th>
                    <th><?php esc_html_e('User', 'key-warden'); ?></th>
                    <th><?php esc_html_e('Password', 'key-warden'); ?></th>
                    <th><?php esc_html_e('Details', 'key-warden'); ?></th>
                    <th><?php esc_html_e('IP', 'key-warden'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$log) : ?>
                    <tr><td colspan="6"><?php esc_html_e('Nothing logged yet.', 'key-warden'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($log as $row) : $u = $row['user_id'] ? get_userdata($row['user_id']) : null; ?>
                    <tr>
                        <td><?php echo esc_html(self::date($row['time'])); ?></td>
                        <td><?php echo esc_html(isset($labels[$row['event']]) ? $labels[$row['event']] : $row['event']); ?></td>
                        <td><?php echo esc_html($u ? $u->user_login : '—'); ?></td>
                        <td><?php echo esc_html($row['password'] !== '' ? $row['password'] : '—'); ?></td>
                        <td><?php echo $row['detail'] !== '' ? esc_html($row['detail']) : '—'; ?></td>
                        <td><?php echo esc_html($row['ip']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($log) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php self::hidden('clear_log'); ?>
                <p><button class="button key-warden-confirm"><?php esc_html_e('Clear log', 'key-warden'); ?></button></p>
            </form>
        <?php endif; ?>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Small view helpers
     * ------------------------------------------------------------------- */

    private static function chip($name, WP_User $user) {
        printf(
            '<span class="key-warden-chip" title="%1$s"><input type="hidden" name="%2$s" value="%3$d">%4$s<button type="button" class="key-warden-chip-remove" aria-label="%5$s">&times;</button></span>',
            esc_attr($user->user_email),
            esc_attr($name),
            (int) $user->ID,
            esc_html($user->display_name !== $user->user_login ? $user->display_name . ' (' . $user->user_login . ')' : $user->user_login),
            esc_attr(sprintf(/* translators: %s: username */ __('Remove %s', 'key-warden'), $user->user_login))
        );
    }

    public static function ajax_user_search() {
        check_ajax_referer('key_warden_ajax');
        if (!current_user_can(self::cap())) wp_send_json_error(null, 403);
        $term = isset($_GET['term']) ? trim(sanitize_text_field(wp_unslash($_GET['term']))) : '';
        if ($term === '') wp_send_json_success(array());

        $users = get_users(array(
            'search'         => '*' . $term . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name', 'user_nicename'),
            'number'         => 10,
            'orderby'        => 'display_name',
            'exclude'        => array_map('intval', (array) wp_unslash($_GET['exclude'] ?? array())),
        ));
        wp_send_json_success(array_map(function ($u) {
            return array(
                'id'    => (int) $u->ID,
                'label' => $u->display_name !== $u->user_login ? $u->display_name . ' (' . $u->user_login . ')' : $u->user_login,
                'email' => $u->user_email,
            );
        }, $users));
    }

    /**
     * Live route tester on the profile editor: checks unsaved rules with the same code that enforces them.
     */
    public static function ajax_test_route() {
        check_ajax_referer('key_warden_ajax');
        if (!current_user_can(self::cap())) wp_send_json_error(null, 403);
        $post = wp_unslash($_POST);
        list($rules) = Key_Warden_Rules::sanitize_lines($post['rules'] ?? '');
        $profile = array('mode' => ($post['mode'] ?? '') === 'blocklist' ? 'blocklist' : 'allowlist', 'rules' => $rules);
        $route = preg_replace('#^/?' . preg_quote(rest_get_url_prefix(), '#') . '(?=/|$)#', '', strtok(sanitize_text_field($post['route'] ?? ''), '?'));
        list($allowed, $rule) = Key_Warden_Rules::check($profile, sanitize_key($post['method'] ?? 'get'), $route);
        wp_send_json_success(array(
            'allowed' => $allowed,
            'message' => ($allowed ? __('Allowed.', 'key-warden') : __('Blocked.', 'key-warden')) . ' '
                /* translators: %s: rule */
                . ($rule ? sprintf(__('Matched: %s', 'key-warden'), $rule) : __('No route matched.', 'key-warden')),
        ));
    }

    public static function action_url($do, $args = array()) {
        return wp_nonce_url(add_query_arg(array_merge(array('action' => 'key_warden_save', 'key_warden_do' => $do), $args), admin_url('admin-post.php')), 'key_warden_' . $do);
    }

    private static function hidden($do, $nonce = true) {
        echo '<input type="hidden" name="action" value="key_warden_save">';
        echo '<input type="hidden" name="key_warden_do" value="' . esc_attr($do) . '">';
        if ($nonce) wp_nonce_field('key_warden_' . $do);
    }

    /**
     * @param string|bool $inherit false, true ("use the role's profile or the default profile"), or 'default' ("use the default profile").
     */
    private static function profile_select($name, $current, $inherit, $id = '') {
        echo '<select name="' . esc_attr($name) . '"' . ($id ? ' id="' . esc_attr($id) . '"' : '') . '>';
        if ($inherit) {
            // Name the default profile so nobody has to go look it up on the Settings tab.
            $default = Key_Warden_Policy::profile_label(Key_Warden_Settings::get('default_profile'));
            $label = $inherit === 'default'
                /* translators: %s: name of the default route profile */
                ? sprintf(__('Default profile (%s)', 'key-warden'), $default)
                /* translators: %s: name of the default route profile */
                : sprintf(__('Role\'s profile, or the default profile (%s)', 'key-warden'), $default);
            echo '<option value="">' . esc_html($label) . '</option>';
        }
        echo '<option value="' . esc_attr(Key_Warden_Settings::PROFILE_NONE) . '" ' . selected($current, Key_Warden_Settings::PROFILE_NONE, false) . '>' . esc_html__('No restrictions', 'key-warden') . '</option>';
        foreach (Key_Warden_Settings::profiles() as $pid => $p) {
            echo '<option value="' . esc_attr($pid) . '" ' . selected($current, $pid, false) . '>' . esc_html($p['name']) . '</option>';
        }
        echo '</select>';
    }

    public static function mode_label($mode) {
        return $mode === 'allowlist'
            ? __('Block all except these routes', 'key-warden')
            : __('Allow all except these routes', 'key-warden');
    }

    public static function date($ts) {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $ts);
    }

    /* ---------------------------------------------------------------------
     * Saving
     * ------------------------------------------------------------------- */

    public static function handle_post() {
        if (!current_user_can(self::cap())) wp_die(esc_html__('Sorry, you are not allowed to do that.', 'key-warden'), 403);
        // Row actions arrive as nonce'd GET links, forms as POST.
        $do = isset($_REQUEST['key_warden_do']) ? sanitize_key(wp_unslash($_REQUEST['key_warden_do'])) : '';
        check_admin_referer($do === 'bulk_passwords' ? 'bulk-passwords' : 'key_warden_' . $do);
        $post = wp_unslash($_REQUEST);

        switch ($do) {
            case 'access':
                // Unchecked boxes and empty chip lists aren't posted at all, so default them to empty.
                self::save_settings(array(
                    'create_mode'   => $post['create_mode'] ?? 'all',
                    'create_roles'  => $post['create_roles'] ?? array(),
                    'create_users'  => $post['create_users'] ?? array(),
                    'auth_mode'     => $post['auth_mode'] ?? 'all',
                    'auth_roles'    => $post['auth_roles'] ?? array(),
                    'auth_users'    => $post['auth_users'] ?? array(),
                    'max_per_user'  => $post['max_per_user'] ?? 0,
                    'role_profiles' => $post['role_profiles'] ?? array(),
                ));
                self::back('access', 'saved');
                break;
            case 'settings':
                self::save_settings(array(
                    'default_profile'       => $post['default_profile'] ?? '',
                    'default_lifetime_days' => $post['default_lifetime_days'] ?? 0,
                    'idle_revoke_days'      => $post['idle_revoke_days'] ?? 0,
                    'allow_xmlrpc'          => !empty($post['allow_xmlrpc']),
                    'log_enabled'           => !empty($post['log_enabled']),
                    'notify_on_create'      => !empty($post['notify_on_create']),
                ));
                self::back('settings', 'saved');
                break;
            case 'save_profile':
                $id = sanitize_key($post['profile_id'] ?? '');
                if ($id && !Key_Warden_Settings::profile($id)) $id = '';
                list($rules, $invalid) = Key_Warden_Rules::sanitize_lines($post['rules'] ?? '');
                if ($invalid) {
                    /* translators: %s: list of rules */
                    self::warn(sprintf(__('These lines were not understood and were left out: %s', 'key-warden'), implode(' | ', $invalid)));
                }
                $id = Key_Warden_Settings::save_profile($id, sanitize_text_field($post['name'] ?? ''), sanitize_key($post['mode'] ?? ''), $rules, array(
                    'abilities' => empty($post['abilities_read_only']) ? 'all' : 'read',
                    'non_rest'  => !empty($post['non_rest']),
                ));
                self::back('profiles', 'saved', array('edit' => $id));
                break;
            case 'delete_profile':
                $id = sanitize_key($post['profile_id'] ?? '');
                if (Key_Warden_Settings::profile($id)) Key_Warden_Settings::delete_profile($id);
                self::back('profiles', 'deleted');
                break;
            case 'edit_password':
                self::save_password($post);
                break;
            case 'bulk_passwords':
                self::bulk_passwords($post);
                break;
            case 'revoke':
                $user_id = (int) ($post['user_id'] ?? 0);
                if (current_user_can('edit_user', $user_id)) WP_Application_Passwords::delete_application_password($user_id, sanitize_text_field($post['uuid'] ?? ''));
                self::back('passwords', 'revoked');
                break;
            case 'toggle_suspend':
                $user_id = (int) ($post['user_id'] ?? 0);
                $uuid = sanitize_text_field($post['uuid'] ?? '');
                if (current_user_can('edit_user', $user_id)) Key_Warden_Store::update_password($user_id, $uuid, array('suspended' => empty(Key_Warden_Store::get($user_id, $uuid)['suspended'])));
                self::back('passwords', 'saved');
                break;
            case 'clear_log':
                Key_Warden_Log::clear();
                self::back('log', 'cleared');
                break;
            case 'run_cleanup':
                Key_Warden_Cron::run(true);
                self::back('settings', 'cleanup');
                break;
        }
        self::back('passwords');
    }

    private static function save_settings(array $input) {
        list($clean, $warnings) = Key_Warden_Settings::sanitize($input);
        foreach ($warnings as $w) self::warn($w);
        Key_Warden_Settings::update($clean);
    }

    private static function save_password($post) {
        $user_id = (int) ($post['user_id'] ?? 0);
        $uuid = sanitize_text_field($post['uuid'] ?? '');
        if (!current_user_can('edit_user', $user_id)) self::back('passwords');
        $expires = 0;
        if (!empty($post['expires'])) {
            $dt = date_create_immutable_from_format('Y-m-d\TH:i', sanitize_text_field($post['expires']), wp_timezone());
            if ($dt) $expires = $dt->getTimestamp();
        }
        $result = Key_Warden_Store::update_password($user_id, $uuid, array(
            'expires'   => $expires,
            'suspended' => !empty($post['suspended']),
            'profile'   => $post['profile'] ?? '',
        ));
        if (is_wp_error($result)) self::back('passwords');
        self::back('passwords', 'saved', array('edit' => $uuid, 'user' => $user_id));
    }

    private static function bulk_passwords($post) {
        $action = $post['bulk_action'] ?? '';
        if ($action === '' || $action === '-1') $action = $post['bulk_action2'] ?? '';
        $count = 0;
        foreach ((array) ($post['passwords'] ?? array()) as $value) {
            list($user_id, $uuid) = array_pad(explode(':', (string) $value, 2), 2, '');
            $user_id = (int) $user_id;
            if (!current_user_can('edit_user', $user_id)) continue;
            $item = WP_Application_Passwords::get_user_application_password($user_id, $uuid);
            if (!$item) continue;
            if ($action === 'revoke') {
                WP_Application_Passwords::delete_application_password($user_id, $uuid);
            } elseif ($action === 'suspend' || $action === 'resume') {
                Key_Warden_Store::update_password($user_id, $uuid, array('suspended' => $action === 'suspend'));
            } else {
                continue;
            }
            $count++;
        }
        self::back('passwords', '', array('key_warden_count' => $count));
    }

    private static function warn($message) {
        $key = 'key_warden_warning_' . get_current_user_id();
        $existing = get_transient($key);
        set_transient($key, $existing ? $existing . ' ' . $message : $message, 60);
    }

    private static function back($tab, $msg = '', $args = array()) {
        if ($msg) $args['key_warden_msg'] = $msg;
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }
}
