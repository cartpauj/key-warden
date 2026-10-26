<?php
if (!defined('ABSPATH')) exit;

/**
 * Abilities (WordPress 6.9+ Abilities API) so AI agents can manage this plugin through the MCP Adapter.
 * All of them require the same capability as the admin screen (manage_options by default).
 */
class Key_Warden_Abilities {
    const CATEGORY = 'application-passwords';
    const PREFIX   = 'key-warden/';

    public static function init() {
        // The Abilities API arrived in WordPress 6.9. On older sites there's nothing to register with.
        if (!function_exists('wp_register_ability')) return;
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'));
    }

    /**
     * Checked at registration time (not plugin load) so a theme's functions.php can still turn them off.
     */
    private static function enabled() {
        /**
         * Turn the MCP / Abilities API abilities off entirely.
         *
         * @param bool $enabled
         */
        return (bool) apply_filters('key_warden_register_abilities', true);
    }

    public static function register_category() {
        if (!self::enabled() || !function_exists('wp_register_ability_category')) return;
        wp_register_ability_category(self::CATEGORY, array(
            'label'       => __('Application passwords', 'key-warden'),
            'description' => __('See and manage application passwords, who can use them, route profiles, and related settings.', 'key-warden'),
        ));
    }

    public static function register() {
        if (!self::enabled()) return;
        $uuid = array('type' => 'string', 'description' => __('The application password UUID (from list-passwords).', 'key-warden'));
        $expires = array(
            'type'        => array('string', 'integer', 'null'),
            'description' => __('When it expires: a date/time like "2026-12-31 17:00" (site timezone) or ISO 8601, a Unix timestamp, or null / 0 / "" for never.', 'key-warden'),
        );
        $profile = array(
            'type'        => 'string',
            'description' => __('Route profile ID (from list-profiles), "__none" for no restrictions, or "" to use the role\'s profile or the default profile.', 'key-warden'),
        );
        $rules = array(
            'type'        => array('array', 'string'),
            'items'       => array('type' => 'string'),
            'description' => __('Route rules, one per item (or one per line). Format: "[METHODS] /route/pattern", e.g. "GET /wp/v2/*", "GET,POST /wc/v3/orders*", "/mcp/*". Routes exclude /wp-json. * matches anything. Lines starting with # are notes.', 'key-warden'),
        );
        $mode = array(
            'type'        => 'string',
            'enum'        => array('allowlist', 'blocklist'),
            'description' => __('"allowlist" = block all except the listed routes. "blocklist" = allow all except the listed routes.', 'key-warden'),
        );

        self::add('list-passwords', array(
            'label'       => __('List application passwords', 'key-warden'),
            'description' => __('Lists every application password on the site, for every user: owner, name, status (active/expired/suspended), created, last used and IP, expiry, and which route profile applies. Use it to audit for unknown or forgotten passwords.', 'key-warden'),
            'readonly'    => true,
            'input'       => array(
                'status' => array('type' => 'string', 'enum' => array('all', 'active', 'expired', 'suspended'), 'default' => 'all'),
                'user'   => array('type' => array('string', 'integer'), 'description' => __('Only this user: ID, username, or email.', 'key-warden')),
                'search' => array('type' => 'string', 'description' => __('Matches password name, username, email, or last IP.', 'key-warden')),
            ),
            'execute'     => array(__CLASS__, 'list_passwords'),
        ));

        self::add('create-password', array(
            'label'       => __('Create an application password', 'key-warden'),
            'description' => __('Creates an application password for a user and returns the plain password. It is shown only once, so pass it on to the person who needs it. Follows the plugin\'s "who can create" and per-user limit rules, and sends the new-password email alert if it is on.', 'key-warden'),
            'input'       => array(
                'user'    => array('type' => array('string', 'integer'), 'description' => __('User ID, username, or email.', 'key-warden')),
                'name'    => array('type' => 'string', 'description' => __('A name to recognize it by, e.g. "Claude on Paul\'s laptop".', 'key-warden')),
                'expires' => $expires,
                'profile' => $profile,
            ),
            'required'    => array('user', 'name'),
            'execute'     => array(__CLASS__, 'create_password'),
        ));

        self::add('update-password', array(
            'label'       => __('Update an application password', 'key-warden'),
            'description' => __('Changes an application password\'s expiry, suspension, or route profile. Only the fields you send are changed. Suspending blocks it without deleting it.', 'key-warden'),
            'idempotent'  => true,
            'input'       => array(
                'uuid'      => $uuid,
                'expires'   => $expires,
                'suspended' => array('type' => 'boolean'),
                'profile'   => $profile,
            ),
            'required'    => array('uuid'),
            'execute'     => array(__CLASS__, 'update_password'),
        ));

        self::add('revoke-password', array(
            'label'       => __('Revoke an application password', 'key-warden'),
            'description' => __('Permanently deletes an application password. Any app using it stops working right away. This cannot be undone. Consider suspending it with update-password instead.', 'key-warden'),
            'destructive' => true,
            'idempotent'  => true,
            'input'       => array('uuid' => $uuid),
            'required'    => array('uuid'),
            'execute'     => array(__CLASS__, 'revoke_password'),
        ));

        self::add('list-profiles', array(
            'label'       => __('List route profiles', 'key-warden'),
            'description' => __('Lists route profiles: their ID, name, mode, route rules, and where each is used (default profile, roles, single passwords).', 'key-warden'),
            'readonly'    => true,
            'execute'     => array(__CLASS__, 'list_profiles'),
        ));

        self::add('save-profile', array(
            'label'       => __('Create or update a route profile', 'key-warden'),
            'description' => __('Creates a route profile, or updates one when "id" is given. To start from a template, pass "template" (from list-templates) and leave out mode/rules. Returns the saved profile and any rule lines that were not understood.', 'key-warden'),
            'idempotent'  => true,
            'input'       => array(
                'id'       => array('type' => 'string', 'description' => __('Profile ID to update. Leave out to create a new profile.', 'key-warden')),
                'name'     => array('type' => 'string'),
                'mode'     => $mode,
                'rules'    => $rules,
                'read_only_abilities'   => array('type' => 'boolean', 'description' => __('Only let passwords with this profile run read-only abilities (e.g. for a read-only AI agent). Default false: any ability the user may run. Passwords with a profile can never change this plugin\'s own settings either way.', 'key-warden')),
                'allow_non_rest'        => array('type' => 'boolean', 'description' => __('Advanced, default false. Also let passwords with this profile log in outside the REST API (XML-RPC, other plugins\' endpoints such as GraphQL). Route rules are NOT enforced there, so this weakens the profile.', 'key-warden')),
                'template' => array('type' => 'string', 'description' => __('Template ID to fill in name, mode, and rules from (new profiles only).', 'key-warden')),
            ),
            'execute'     => array(__CLASS__, 'save_profile'),
        ));

        self::add('delete-profile', array(
            'label'       => __('Delete a route profile', 'key-warden'),
            'description' => __('Deletes a route profile. Passwords and roles that used it fall back to the next profile in line (role, then the default profile). If it was the default profile, the default becomes "no restrictions".', 'key-warden'),
            'destructive' => true,
            'idempotent'  => true,
            'input'       => array('id' => array('type' => 'string')),
            'required'    => array('id'),
            'execute'     => array(__CLASS__, 'delete_profile'),
        ));

        self::add('list-templates', array(
            'label'       => __('List route profile templates', 'key-warden'),
            'description' => __('Lists the starter templates (WordPress, AI agents, WooCommerce, other plugins) with their rules and whether the plugin they target is active.', 'key-warden'),
            'readonly'    => true,
            'execute'     => array(__CLASS__, 'list_templates'),
        ));

        self::add('list-routes', array(
            'label'       => __('List REST API routes', 'key-warden'),
            'description' => __('Lists every REST API route on this site, grouped by namespace, written as rule patterns (placeholders become *). Use it to write route rules.', 'key-warden'),
            'readonly'    => true,
            'input'       => array(
                'namespace' => array('type' => 'string', 'description' => __('Only this namespace, e.g. "wp/v2" or "wc/v3".', 'key-warden')),
            ),
            'execute'     => array(__CLASS__, 'list_routes'),
        ));

        self::add('test-route', array(
            'label'       => __('Test a route against a profile', 'key-warden'),
            'description' => __('Checks whether a request would be allowed. Test a saved profile with "profile_id", or unsaved rules with "mode" + "rules".', 'key-warden'),
            'readonly'    => true,
            'input'       => array(
                'method'     => array('type' => 'string', 'enum' => array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), 'default' => 'GET'),
                'route'      => array('type' => 'string', 'description' => __('e.g. /wp/v2/posts/123 (with or without /wp-json).', 'key-warden')),
                'profile_id' => array('type' => 'string'),
                'mode'       => $mode,
                'rules'      => $rules,
            ),
            'required'    => array('route'),
            'execute'     => array(__CLASS__, 'test_route'),
        ));

        self::add('get-settings', array(
            'label'       => __('Get settings', 'key-warden'),
            'description' => __('Returns all plugin settings: who can create and log in with application passwords, per-user limit, role profiles, default profile, expiry and auto-revoke days, XML-RPC, email alerts, and logging. Also says whether application passwords are available on this site.', 'key-warden'),
            'readonly'    => true,
            'execute'     => array(__CLASS__, 'get_settings'),
        ));

        self::add('update-settings', array(
            'label'       => __('Update settings', 'key-warden'),
            'description' => __('Changes plugin settings. Only the fields you send are changed. Careful: restricting who can log in takes effect immediately and can cut off existing apps and agents, including the one making this call.', 'key-warden'),
            'idempotent'  => true,
            'input'       => array(
                'create_mode'           => array('type' => 'string', 'enum' => array('all', 'restricted'), 'description' => __('Who can create: everyone, or only create_roles + create_users.', 'key-warden')),
                'create_roles'          => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => __('Role slugs, e.g. ["administrator"].', 'key-warden')),
                'create_users'          => array('type' => 'array', 'items' => array('type' => array('string', 'integer')), 'description' => __('User IDs, usernames, or emails.', 'key-warden')),
                'auth_mode'             => array('type' => 'string', 'enum' => array('all', 'restricted'), 'description' => __('Who can log in: everyone, or only auth_roles + auth_users.', 'key-warden')),
                'auth_roles'            => array('type' => 'array', 'items' => array('type' => 'string')),
                'auth_users'            => array('type' => 'array', 'items' => array('type' => array('string', 'integer'))),
                'max_per_user'          => array('type' => 'integer', 'minimum' => 0, 'description' => __('0 = no limit.', 'key-warden')),
                'default_lifetime_days' => array('type' => 'integer', 'minimum' => 0, 'description' => __('New passwords expire after this many days. 0 = never.', 'key-warden')),
                'idle_revoke_days'      => array('type' => 'integer', 'minimum' => 0, 'description' => __('Revoke passwords unused for this many days. 0 = never.', 'key-warden')),
                'allow_xmlrpc'          => array('type' => 'boolean'),
                'default_profile'       => array('type' => 'string', 'description' => __('Profile ID, or "__none" for no restrictions.', 'key-warden')),
                'role_profiles'         => array('type' => 'object', 'additionalProperties' => array('type' => 'string'), 'description' => __('Role slug => profile ID ("__none" for no restrictions). Roles left out use the default profile. Replaces the whole map.', 'key-warden')),
                'notify_on_create'      => array('type' => 'boolean'),
                'log_enabled'           => array('type' => 'boolean'),
            ),
            'execute'     => array(__CLASS__, 'update_settings'),
        ));

        self::add('get-activity-log', array(
            'label'       => __('Get the activity log', 'key-warden'),
            'description' => __('Recent events, newest first: passwords created, revoked, auto-revoked, updated, and blocked logins / routes (with the blocked route).', 'key-warden'),
            'readonly'    => true,
            'input'       => array(
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => Key_Warden_Log::MAX, 'default' => 50),
            ),
            'execute'     => array(__CLASS__, 'get_activity_log'),
        ));

        self::add('run-cleanup', array(
            'label'       => __('Run expiry cleanup now', 'key-warden'),
            'description' => __('Revokes expired passwords, and passwords unused for longer than the auto-revoke setting, right now instead of waiting for the daily run. Returns how many were revoked.', 'key-warden'),
            'destructive' => true,
            'idempotent'  => true,
            'execute'     => array(__CLASS__, 'run_cleanup'),
        ));
    }

    /**
     * Small wrapper so each ability above stays readable.
     */
    private static function add($name, array $a) {
        $args = array(
            'label'               => $a['label'],
            'description'         => $a['description'],
            'category'            => self::CATEGORY,
            'execute_callback'    => $a['execute'],
            'permission_callback' => array(__CLASS__, empty($a['readonly']) ? 'can_change' : 'can_manage'),
            'meta'                => array(
                'mcp'         => array('public' => true), // Exposed to MCP only, not the /wp-abilities REST listing.
                'annotations' => array(
                    'readonly'    => !empty($a['readonly']),
                    'destructive' => !empty($a['destructive']),
                    'idempotent'  => !empty($a['readonly']) || !empty($a['idempotent']),
                ),
            ),
        );
        if (!empty($a['input'])) {
            $args['input_schema'] = array(
                'type'                 => 'object',
                'properties'           => $a['input'],
                'additionalProperties' => false,
                'default'              => array(), // Lets callers send nothing when every field is optional.
            );
            if (!empty($a['required'])) $args['input_schema']['required'] = $a['required'];
        }
        wp_register_ability(self::PREFIX . $name, $args);
    }

    public static function can_manage() {
        return current_user_can(Key_Warden_Policy::manager_cap());
    }

    /**
     * Changing anything also requires that the caller isn't using a restricted application password.
     * Otherwise an agent limited to "/mcp/*" could use these abilities to lift its own limits.
     */
    public static function can_change() {
        if (!self::can_manage()) return false;
        if (Key_Warden_Policy::current_password_is_restricted()) {
            return new WP_Error('key_warden_restricted_password', __('This application password has a route profile, so it can only use the read-only abilities. Changes must be made from an unrestricted connection or the dashboard.', 'key-warden'));
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Passwords
     * ------------------------------------------------------------------- */

    public static function list_passwords($input = null) {
        $input = (array) $input;
        $rows = Key_Warden_Store::manageable_passwords();

        $status = isset($input['status']) ? $input['status'] : 'all';
        $user_id = 0;
        if (!empty($input['user'])) {
            $user = self::find_user($input['user']);
            if (is_wp_error($user)) return $user;
            $user_id = $user->ID;
        }
        $search = isset($input['search']) ? strtolower(trim($input['search'])) : '';

        $out = array();
        foreach ($rows as $row) {
            if ($user_id && $row['user_id'] !== $user_id) continue;
            if ($status !== 'all' && self::status_of($row) !== $status) continue;
            if ($search !== '' && strpos(strtolower($row['name'] . ' ' . $row['user_login'] . ' ' . $row['user']->user_email . ' ' . $row['last_ip']), $search) === false) continue;
            $out[] = self::format_password($row);
        }
        return array('count' => count($out), 'passwords' => $out);
    }

    public static function create_password($input = null) {
        $input = (array) $input;
        $user = self::find_user($input['user']);
        if (is_wp_error($user)) return $user;

        if (!wp_is_application_passwords_available_for_user($user)) {
            return new WP_Error('key_warden_not_available', __('Application passwords are not available for this user (not allowed to log in with them, or turned off on this site).', 'key-warden'));
        }
        if (!current_user_can('create_app_password', $user->ID)) {
            return new WP_Error('key_warden_cannot_create', Key_Warden_Policy::at_limit($user->ID)
                ? __('This user has reached the limit of application passwords.', 'key-warden')
                : __('This user is not allowed to have application passwords created, or you cannot edit this user.', 'key-warden'));
        }

        // Check every input before creating anything, so a bad value can't leave a password behind.
        $changes = array();
        if (array_key_exists('expires', $input)) {
            $ts = self::parse_time($input['expires']);
            if (is_wp_error($ts)) return $ts;
            $changes['expires'] = $ts;
        }
        if (array_key_exists('profile', $input)) {
            if (!in_array($input['profile'], array('', Key_Warden_Settings::PROFILE_NONE), true) && !Key_Warden_Settings::profile($input['profile'])) {
                return new WP_Error('key_warden_bad_profile', __('No route profile with that ID.', 'key-warden'));
            }
            $changes['profile'] = $input['profile'];
        }

        $created = WP_Application_Passwords::create_new_application_password($user->ID, array('name' => sanitize_text_field($input['name'])));
        if (is_wp_error($created)) return $created;
        list($password, $item) = $created;
        if ($changes) Key_Warden_Store::update_password($user->ID, $item['uuid'], $changes);

        $row = Key_Warden_Store::find($user->ID, $item['uuid']);
        return array(
            'password' => WP_Application_Passwords::chunk_password($password),
            'note'     => __('This is the only time the password is shown.', 'key-warden'),
            'details'  => $row ? self::format_password(array_merge($row, array('user' => $user, 'user_login' => $user->user_login))) : null,
        );
    }

    public static function update_password($input = null) {
        $input = (array) $input;
        $found = self::find_password($input['uuid']);
        if (is_wp_error($found)) return $found;

        $changes = array_intersect_key($input, array_flip(array('suspended', 'profile')));
        if (array_key_exists('expires', $input)) {
            $ts = self::parse_time($input['expires']);
            if (is_wp_error($ts)) return $ts;
            $changes['expires'] = $ts;
        }
        if (isset($changes['profile']) && !in_array($changes['profile'], array('', Key_Warden_Settings::PROFILE_NONE), true) && !Key_Warden_Settings::profile($changes['profile'])) {
            return new WP_Error('key_warden_bad_profile', __('No route profile with that ID.', 'key-warden'));
        }

        $result = Key_Warden_Store::update_password($found['user_id'], $input['uuid'], $changes);
        if (is_wp_error($result)) return $result;
        return self::format_password(self::find_password($input['uuid']));
    }

    public static function revoke_password($input = null) {
        $found = self::find_password($input['uuid']);
        if (is_wp_error($found)) return $found;
        WP_Application_Passwords::delete_application_password($found['user_id'], $input['uuid']);
        return array('revoked' => true, 'name' => $found['name'], 'user' => $found['user_login']);
    }

    /* ---------------------------------------------------------------------
     * Profiles, templates, routes
     * ------------------------------------------------------------------- */

    public static function list_profiles() {
        $usage = Key_Warden_Policy::profile_usage();
        $out = array();
        foreach (Key_Warden_Settings::profiles() as $id => $p) {
            $out[] = self::format_profile($id, $p, $usage[$id]);
        }
        return array('profiles' => $out);
    }

    public static function save_profile($input = null) {
        $input = (array) $input;
        $id = isset($input['id']) ? sanitize_key($input['id']) : '';
        $existing = $id ? Key_Warden_Settings::profile($id) : null;
        if ($id && !$existing) return new WP_Error('key_warden_bad_profile', __('No route profile with that ID.', 'key-warden'));

        $base = $existing ? $existing : array('name' => '', 'mode' => 'allowlist', 'rules' => array(), 'abilities' => 'all', 'non_rest' => false);
        if (!$existing && !empty($input['template'])) {
            $t = Key_Warden_Templates::get(sanitize_key($input['template']));
            if (!$t) return new WP_Error('key_warden_bad_template', __('No template with that ID.', 'key-warden'));
            $base = array('name' => $t['name'], 'mode' => $t['mode'], 'rules' => $t['rules'], 'abilities' => $t['abilities'], 'non_rest' => false);
        }

        $abilities = isset($input['read_only_abilities']) ? ($input['read_only_abilities'] ? 'read' : 'all') : $base['abilities'];
        $non_rest = isset($input['allow_non_rest']) ? (bool) $input['allow_non_rest'] : $base['non_rest'];
        $name = isset($input['name']) ? sanitize_text_field($input['name']) : $base['name'];
        $mode = isset($input['mode']) ? $input['mode'] : $base['mode'];
        $invalid = array();
        $rules = $base['rules'];
        if (isset($input['rules'])) {
            $text = is_array($input['rules']) ? implode("\n", $input['rules']) : (string) $input['rules'];
            list($rules, $invalid) = Key_Warden_Rules::sanitize_lines($text);
        }
        if ($name === '') return new WP_Error('key_warden_missing_name', __('A profile needs a name.', 'key-warden'));

        $id = Key_Warden_Settings::save_profile($id, $name, $mode, $rules, array('abilities' => $abilities, 'non_rest' => $non_rest));
        $usage = Key_Warden_Policy::profile_usage();
        return array(
            'profile'       => self::format_profile($id, Key_Warden_Settings::profile($id), $usage[$id]),
            'invalid_rules' => $invalid,
        );
    }

    public static function delete_profile($input = null) {
        $id = sanitize_key($input['id']);
        $profile = Key_Warden_Settings::profile($id);
        if (!$profile) return new WP_Error('key_warden_bad_profile', __('No route profile with that ID.', 'key-warden'));
        Key_Warden_Settings::delete_profile($id);
        return array('deleted' => true, 'id' => $id, 'name' => $profile['name']);
    }

    public static function list_templates() {
        $out = array();
        foreach (Key_Warden_Templates::all() as $id => $t) {
            $out[] = array(
                'id'          => $id,
                'group'       => $t['group'],
                'name'        => $t['name'],
                'description' => $t['description'],
                'mode'        => $t['mode'],
                'rules'       => $t['rules'],
                'available'   => Key_Warden_Templates::is_available($t),
            );
        }
        return array('templates' => $out);
    }

    public static function list_routes($input = null) {
        $routes = Key_Warden_Rules::site_routes();
        $ns = isset($input['namespace']) ? trim($input['namespace'], '/') : '';
        if ($ns !== '') {
            if (!isset($routes[$ns])) {
                return new WP_Error('key_warden_bad_namespace', sprintf(
                    /* translators: %s: list of namespaces */
                    __('No such namespace. Available: %s', 'key-warden'),
                    implode(', ', array_keys($routes))
                ));
            }
            $routes = array($ns => $routes[$ns]);
        }
        return array('namespaces' => $routes);
    }

    public static function test_route($input = null) {
        $input = (array) $input;
        if (!empty($input['profile_id'])) {
            $profile = Key_Warden_Settings::profile(sanitize_key($input['profile_id']));
            if (!$profile) return new WP_Error('key_warden_bad_profile', __('No route profile with that ID.', 'key-warden'));
        } elseif (isset($input['rules'])) {
            $text = is_array($input['rules']) ? implode("\n", $input['rules']) : (string) $input['rules'];
            list($rules) = Key_Warden_Rules::sanitize_lines($text);
            $profile = array('mode' => isset($input['mode']) && $input['mode'] === 'blocklist' ? 'blocklist' : 'allowlist', 'rules' => $rules);
        } else {
            return new WP_Error('key_warden_missing_profile', __('Pass either profile_id, or mode and rules.', 'key-warden'));
        }

        $route = preg_replace('#^/?' . preg_quote(rest_get_url_prefix(), '#') . '(?=/|$)#', '', strtok($input['route'], '?'));
        $method = isset($input['method']) ? $input['method'] : 'GET';
        list($allowed, $rule) = Key_Warden_Rules::check($profile, $method, $route);
        return array('allowed' => $allowed, 'matched_rule' => $rule, 'method' => $method, 'route' => $route, 'mode' => $profile['mode']);
    }

    /* ---------------------------------------------------------------------
     * Settings, log, cleanup
     * ------------------------------------------------------------------- */

    public static function get_settings() {
        $s = Key_Warden_Settings::all();
        foreach (array('create_users', 'auth_users') as $key) {
            $s[$key] = array_values(array_filter(array_map(function ($id) {
                $u = get_userdata((int) $id);
                return $u ? array('id' => (int) $u->ID, 'login' => $u->user_login) : null;
            }, (array) $s[$key])));
        }
        $s['role_profiles'] = (object) $s['role_profiles']; // Always a JSON object, even when empty.
        $s['roles'] = array_keys(wp_roles()->get_names());
        $s['application_passwords_supported'] = wp_is_application_passwords_supported();
        $s['application_passwords_available'] = wp_is_application_passwords_available();
        return $s;
    }

    public static function update_settings($input = null) {
        list($clean, $warnings) = Key_Warden_Settings::sanitize((array) $input);
        Key_Warden_Settings::update($clean);
        return array('updated' => array_keys($clean), 'warnings' => $warnings, 'settings' => self::get_settings());
    }

    public static function get_activity_log($input = null) {
        $limit = isset($input['limit']) ? (int) $input['limit'] : 50;
        $labels = Key_Warden_Log::labels();
        $out = array();
        foreach (array_slice(Key_Warden_Log::all(), 0, $limit) as $row) {
            $u = $row['user_id'] ? get_userdata($row['user_id']) : null;
            $out[] = array(
                'time'     => wp_date('c', $row['time']),
                'event'    => $row['event'],
                'label'    => isset($labels[$row['event']]) ? $labels[$row['event']] : $row['event'],
                'user'     => $u ? $u->user_login : null,
                'password' => $row['password'],
                'detail'   => $row['detail'],
                'ip'       => $row['ip'],
            );
        }
        return array('logging_enabled' => (bool) Key_Warden_Settings::get('log_enabled'), 'entries' => $out);
    }

    public static function run_cleanup() {
        return array('revoked' => Key_Warden_Cron::run(true));
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    private static function find_user($value) {
        list($ids) = Key_Warden_Settings::resolve_users(array($value));
        if (!$ids) return new WP_Error('key_warden_no_user', __('No user found.', 'key-warden'));
        return get_userdata($ids[0]);
    }

    /**
     * Find a password by UUID alone, across all users.
     */
    private static function find_password($uuid) {
        foreach (Key_Warden_Store::manageable_passwords() as $row) {
            if ($row['uuid'] === $uuid) return $row;
        }
        return new WP_Error('key_warden_not_found', __('That application password does not exist.', 'key-warden'));
    }

    private static function status_of(array $row) {
        if (!empty($row['suspended'])) return 'suspended';
        if (Key_Warden_Store::is_expired($row)) return 'expired';
        return 'active';
    }

    /**
     * @return int|WP_Error Timestamp, 0 for never.
     */
    private static function parse_time($value) {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return 0;
        if (is_int($value) || ctype_digit((string) $value)) return (int) $value;
        try {
            return (new DateTimeImmutable((string) $value, wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            return new WP_Error('key_warden_bad_date', __('Could not understand that date.', 'key-warden'));
        }
    }

    private static function format_password(array $row) {
        $effective = Key_Warden_Policy::profile_id_for($row['user_id'], $row['uuid']);
        $own = $row['profile'];
        return array(
            'uuid'              => $row['uuid'],
            'name'              => $row['name'],
            'user_id'           => (int) $row['user_id'],
            'user_login'        => $row['user_login'],
            'user_roles'        => array_values((array) $row['user']->roles),
            'status'            => self::status_of($row),
            'user_can_log_in'   => Key_Warden_Policy::user_allowed('auth', $row['user']),
            'created'           => wp_date('c', $row['created']),
            'last_used'         => $row['last_used'] ? wp_date('c', $row['last_used']) : null,
            'last_ip'           => $row['last_ip'] ? $row['last_ip'] : null,
            'expires'           => $row['expires'] ? wp_date('c', $row['expires']) : null,
            'suspended'         => (bool) $row['suspended'],
            'profile'           => $own === Key_Warden_Settings::PROFILE_INHERIT ? 'inherit' : $own,
            'effective_profile' => array('id' => $effective, 'name' => Key_Warden_Policy::profile_label($effective)),
        );
    }

    private static function format_profile($id, array $p, array $used_by) {
        return array('id' => $id, 'name' => $p['name'], 'mode' => $p['mode'], 'rules' => $p['rules'], 'read_only_abilities' => $p['abilities'] === 'read', 'allow_non_rest' => $p['non_rest'], 'used_by' => $used_by);
    }
}
