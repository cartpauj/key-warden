<?php
if (!defined('ABSPATH')) exit;

/**
 * Hooks into core to enforce the policy at every step: creating, logging in, calling routes, and running abilities.
 */
class Key_Warden_Enforcer {
    /** Set while we revoke passwords ourselves, so on_delete() doesn't double-log. */
    public static $quiet = false;

    public static function init() {
        add_filter('map_meta_cap', array(__CLASS__, 'map_meta_cap'), 10, 4);
        add_filter('wp_is_application_passwords_available_for_user', array(__CLASS__, 'available_for_user'), 10, 2);
        add_action('wp_authenticate_application_password_errors', array(__CLASS__, 'authenticate_errors'), 10, 3);
        add_action('application_password_did_authenticate', array('Key_Warden_Policy', 'remember_auth'), 10, 2);
        add_action('application_password_failed_authentication', array(__CLASS__, 'log_disabled_for_user'));
        // Checked early (before caching plugins or other short-circuits do any work), again last (so no later
        // filter can swap the block for a cached answer), and right before the callback (covers embedded and
        // internal requests that skip rest_pre_dispatch).
        add_filter('rest_pre_dispatch', array(__CLASS__, 'pre_dispatch'), -1000, 3);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'pre_dispatch'), PHP_INT_MAX, 3);
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'check_route'), 10, 3);
        add_filter('wp_ability_permission_result', array(__CLASS__, 'ability_permission'), 10, 4);
        add_action('wp_create_application_password', array(__CLASS__, 'on_create'), 10, 2);
        add_action('wp_delete_application_password', array(__CLASS__, 'on_delete'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'profile_screen'));
    }

    /**
     * Creation (REST, profile screen, authorize screen via JS) all check create_app_password.
     */
    public static function map_meta_cap($caps, $cap, $user_id, $args) {
        if ($cap !== 'create_app_password' || empty($args[0])) return $caps;
        // Restricted passwords can't create passwords at all, so they can't mint themselves an unrestricted one.
        if (!Key_Warden_Policy::can_create((int) $args[0]) || Key_Warden_Policy::current_password_is_restricted()) $caps[] = 'do_not_allow';
        return $caps;
    }

    /**
     * Users who aren't allowed to log in with app passwords don't get the feature at all.
     * Core then rejects their logins and hides the profile section.
     */
    public static function available_for_user($available, $user) {
        if (!$available) return false;
        return Key_Warden_Policy::user_allowed('auth', $user);
    }

    /**
     * Runs after core has verified the password.
     */
    public static function authenticate_errors($error, $user, $item) {
        $meta = Key_Warden_Store::get($user->ID, $item['uuid']);
        $is_xmlrpc = defined('XMLRPC_REQUEST') && XMLRPC_REQUEST;
        $is_rest = (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_rest_endpoint') && wp_is_rest_endpoint());
        $reason = '';

        if (!empty($meta['suspended'])) {
            $reason = __('This application password is suspended.', 'key-warden');
        } elseif (Key_Warden_Store::is_expired($meta)) {
            $reason = __('This application password has expired.', 'key-warden');
        } elseif (!$is_rest && ($profile = Key_Warden_Policy::profile_for($user->ID, $item['uuid'])) && !$profile['non_rest']) {
            // Route profiles can only be enforced on the REST API. XML-RPC, or another plugin's endpoint that
            // allows application passwords (via application_password_is_api_request), would skip them.
            // Profiles can opt out of this (advanced setting).
            $reason = __('This application password has a route profile, so it can only be used with the REST API.', 'key-warden');
        } elseif ($is_xmlrpc && !Key_Warden_Settings::get('allow_xmlrpc')) {
            $reason = __('This application password cannot be used with XML-RPC.', 'key-warden');
        }

        if ($reason === '') return;
        $error->add('key_warden_auth_blocked', $reason, array('status' => 401));
        Key_Warden_Log::add('auth_blocked', $user->ID, $item['name'], $reason);
    }

    /**
     * Core rejected a login because the user isn't allowed to use application passwords. This fires before the
     * password is checked, so anyone can trigger it: rate-limit it so it can't flood the log.
     */
    public static function log_disabled_for_user($error) {
        if (!is_wp_error($error) || $error->get_error_code() !== 'application_passwords_disabled_for_user') return;
        $login = isset($_SERVER['PHP_AUTH_USER']) ? sanitize_user(wp_unslash($_SERVER['PHP_AUTH_USER'])) : '';
        $user = $login ? get_user_by('login', $login) : null;
        if (!$user && is_email($login)) $user = get_user_by('email', $login);
        if (!$user) return; // Core only raises this error for real users.

        // Once per user per 10 minutes, and at most 20 of these in total per 10 minutes.
        if (!Key_Warden_Log::allow('preauth-user-' . $user->ID, 1, 10 * MINUTE_IN_SECONDS, false)) return;
        if (!Key_Warden_Log::allow('preauth-all', 20, 10 * MINUTE_IN_SECONDS)) return;
        Key_Warden_Log::add('auth_blocked', $user->ID, '', sprintf(
            /* translators: %s: username */
            __('%s is not allowed to use application passwords.', 'key-warden'),
            $user->user_login
        ));
    }

    public static function pre_dispatch($result, $server, $request) {
        $blocked = self::blocked($request);
        return $blocked ? $blocked : $result;
    }

    public static function check_route($response, $handler, $request) {
        if (is_wp_error($response)) return $response;
        $blocked = self::blocked($request);
        return $blocked ? $blocked : $response;
    }

    /**
     * @return WP_Error|null An error when this request's application password isn't allowed this route.
     */
    private static function blocked(WP_REST_Request $request) {
        $auth = Key_Warden_Policy::current_app_password();
        if (!$auth) return null;
        list($user_id, $uuid) = $auth;

        $profile = Key_Warden_Policy::profile_for($user_id, $uuid);
        if (!$profile) return null;

        $method = $request->get_method();
        $route = $request->get_route();
        // Core's route regexes let a trailing newline through ("$" matches before it), so refuse control characters outright.
        $allowed = !preg_match('/[\x00-\x1F\x7F]/', $route);
        if ($allowed) list($allowed) = Key_Warden_Rules::check($profile, $method, $route);
        if ($allowed) return null;

        // At most 10 log entries per password per 10 minutes, so a leaked password can't flush the log.
        if (Key_Warden_Log::allow('route-' . $uuid, 10, 10 * MINUTE_IN_SECONDS)) {
            $item = WP_Application_Passwords::get_user_application_password($user_id, $uuid);
            Key_Warden_Log::add('route_blocked', $user_id, $item ? $item['name'] : '', $method . ' ' . addcslashes($route, "\0..\37"));
        }

        return new WP_Error(
            'key_warden_route_blocked',
            __('This application password is not allowed to access this route.', 'key-warden'),
            array('status' => 403)
        );
    }

    /**
     * Abilities run inside one route (e.g. /mcp/...), so route rules can't see them. A password with a route
     * profile may only run read-only abilities, unless its profile allows abilities that make changes.
     * Needs WordPress 7.1+ (the filter doesn't exist before that).
     */
    public static function ability_permission($result, $name, $input, $ability) {
        if ($result !== true) return $result;
        // The MCP Adapter's dispatcher: the ability it runs is checked on its own.
        if ($name === 'mcp-adapter/execute-ability') return $result;

        $auth = Key_Warden_Policy::current_app_password();
        if (!$auth) return $result;
        $profile = Key_Warden_Policy::profile_for($auth[0], $auth[1]);
        if (!$profile || $profile['abilities'] === 'all') return $result;

        $meta = $ability->get_meta();
        if (!empty($meta['annotations']['readonly'])) return $result;

        if (Key_Warden_Log::allow('route-' . $auth[1], 10, 10 * MINUTE_IN_SECONDS)) {
            $item = WP_Application_Passwords::get_user_application_password($auth[0], $auth[1]);
            /* translators: %s: ability name */
            Key_Warden_Log::add('route_blocked', $auth[0], $item ? $item['name'] : '', sprintf(__('Ability: %s', 'key-warden'), $name));
        }
        return new WP_Error(
            'key_warden_ability_blocked',
            __('This application password\'s route profile only allows read-only abilities.', 'key-warden'),
            array('status' => 403)
        );
    }

    public static function on_create($user_id, $item) {
        // Backstop for code paths that skip the capability check (e.g. the no-JS authorize form or custom code).
        // WP-CLI is left alone so admins can still provision from the command line.
        $is_cli = defined('WP_CLI') && WP_CLI;
        $over_limit = (int) Key_Warden_Settings::get('max_per_user') > 0
            && count(WP_Application_Passwords::get_user_application_passwords($user_id)) > (int) Key_Warden_Settings::get('max_per_user');
        if (!$is_cli && (!Key_Warden_Policy::user_allowed('create', $user_id) || $over_limit || Key_Warden_Policy::current_password_is_restricted())) {
            self::$quiet = true;
            WP_Application_Passwords::delete_application_password($user_id, $item['uuid']);
            self::$quiet = false;
            Key_Warden_Log::add('create_blocked', $user_id, $item['name'], __('Removed right after creation: not allowed by policy.', 'key-warden'));
            return;
        }

        $values = array();
        $days = (int) Key_Warden_Settings::get('default_lifetime_days');
        if ($days > 0) $values['expires'] = time() + $days * DAY_IN_SECONDS;

        // A password created while logged in with another password can't outlive it.
        $parent = Key_Warden_Policy::current_app_password();
        if ($parent) {
            $parent_expires = (int) Key_Warden_Store::get($parent[0], $parent[1])['expires'];
            if ($parent_expires && (empty($values['expires']) || $parent_expires < $values['expires'])) $values['expires'] = $parent_expires;
        }
        if ($values) Key_Warden_Store::set($user_id, $item['uuid'], $values);
        Key_Warden_Log::add('created', $user_id, $item['name'], Key_Warden_Log::actor());
        self::notify_created($user_id, $item);
    }

    /**
     * Email the site admin about every new password, so a quietly planted backdoor gets noticed.
     */
    private static function notify_created($user_id, $item) {
        if (!Key_Warden_Settings::get('notify_on_create')) return;
        $user = get_userdata($user_id);
        if (!$user) return;

        $creator = wp_get_current_user();
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $lines = array(
            /* translators: %s: site name */
            sprintf(__('A new application password was created on %s.', 'key-warden'), $site),
            '',
            /* translators: %s: username */
            sprintf(__('Account: %s', 'key-warden'), $user->user_login . ' (' . implode(', ', (array) $user->roles) . ')'),
            /* translators: %s: password name */
            sprintf(__('Name: %s', 'key-warden'), $item['name']),
            /* translators: %s: username */
            sprintf(__('Created by: %s', 'key-warden'), $creator->exists() ? $creator->user_login : __('unknown (no logged-in user)', 'key-warden')),
            /* translators: %s: IP address */
            sprintf(__('IP address: %s', 'key-warden'), isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '-'),
            '',
            __('If you don\'t recognize this, revoke it here:', 'key-warden'),
            add_query_arg(array('page' => KEY_WARDEN_SLUG, 'tab' => 'passwords'), admin_url('tools.php')),
        );

        /**
         * @param string $to Recipient(s). Defaults to the site admin email.
         */
        $to = apply_filters('key_warden_notify_email', get_option('admin_email'), $user, $item);
        /* translators: %s: site name */
        wp_mail($to, sprintf(__('[%s] New application password created', 'key-warden'), $site), implode("\n", $lines));
    }

    public static function on_delete($user_id, $item) {
        Key_Warden_Store::forget($user_id, $item['uuid']);
        if (!self::$quiet) Key_Warden_Log::add('revoked', $user_id, $item['name'], Key_Warden_Log::actor());
    }

    /**
     * Hide the "Add New" form on profile screens when the user can't create, and say why.
     */
    public static function profile_screen($hook) {
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') return;
        $user_id = (int) Key_Warden_Admin::query('user_id', get_current_user_id());
        if (!wp_is_application_passwords_available_for_user($user_id)) return;
        if (Key_Warden_Policy::can_create($user_id)) return;

        $message = Key_Warden_Policy::at_limit($user_id) && Key_Warden_Policy::user_allowed('create', $user_id)
            ? __('The limit of application passwords for this account has been reached.', 'key-warden')
            : __('This account is not allowed to create new application passwords.', 'key-warden');

        wp_register_style('key-warden-profile', false, array(), KEY_WARDEN_VERSION);
        wp_enqueue_style('key-warden-profile');
        wp_add_inline_style('key-warden-profile', '.create-application-password { display: none; }');

        wp_register_script('key-warden-profile', false, array(), KEY_WARDEN_VERSION, true);
        wp_enqueue_script('key-warden-profile');
        wp_add_inline_script('key-warden-profile', sprintf(
            '(function () { var form = document.querySelector(".create-application-password"); if (!form) return; var p = document.createElement("p"); p.className = "description"; p.textContent = %s; form.parentNode.insertBefore(p, form); })();',
            wp_json_encode($message)
        ));
    }
}
