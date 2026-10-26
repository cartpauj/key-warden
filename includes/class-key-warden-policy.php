<?php
if (!defined('ABSPATH')) exit;

/**
 * Answers the policy questions: who may create, who may log in, and which profile applies.
 */
class Key_Warden_Policy {
    /**
     * @param string $type 'create' or 'auth'
     */
    public static function user_allowed($type, $user) {
        $user = $user instanceof WP_User ? $user : get_userdata((int) $user);
        if (!$user) return false;

        $settings = Key_Warden_Settings::all();
        $allowed = true;
        if ($settings[$type . '_mode'] === 'restricted') {
            $allowed = in_array((int) $user->ID, array_map('intval', $settings[$type . '_users']), true)
                || (bool) array_intersect((array) $user->roles, (array) $settings[$type . '_roles']);
        }

        /**
         * @param bool    $allowed
         * @param string  $type 'create' or 'auth'
         * @param WP_User $user
         */
        return (bool) apply_filters('key_warden_user_allowed', $allowed, $type, $user);
    }

    public static function can_create($user_id) {
        return self::user_allowed('create', $user_id) && !self::at_limit($user_id);
    }

    public static function at_limit($user_id) {
        $max = (int) Key_Warden_Settings::get('max_per_user');
        if ($max <= 0) return false;
        return count(WP_Application_Passwords::get_user_application_passwords($user_id)) >= $max;
    }

    /**
     * Profile ID that applies to a password: its own override, then the user's role, then the default profile.
     */
    public static function profile_id_for($user_id, $uuid) {
        $profiles = Key_Warden_Settings::profiles();
        $settings = Key_Warden_Settings::all();

        $meta = Key_Warden_Store::get($user_id, $uuid);
        $own = $meta['profile'];
        if ($own === Key_Warden_Settings::PROFILE_NONE || isset($profiles[$own])) return $own;

        $user = get_userdata($user_id);
        if ($user) {
            foreach ((array) $user->roles as $role) {
                if (empty($settings['role_profiles'][$role])) continue;
                $p = $settings['role_profiles'][$role];
                if ($p === Key_Warden_Settings::PROFILE_NONE || isset($profiles[$p])) return $p;
            }
        }

        $default = $settings['default_profile'];
        return isset($profiles[$default]) ? $default : Key_Warden_Settings::PROFILE_NONE;
    }

    /**
     * @return array|null The profile array, or null when there are no route restrictions.
     */
    public static function profile_for($user_id, $uuid) {
        $id = self::profile_id_for($user_id, $uuid);
        return $id === Key_Warden_Settings::PROFILE_NONE ? null : Key_Warden_Settings::profile($id);
    }

    /** array(user_id, uuid) captured when an application password logs in. */
    private static $auth = null;

    public static function remember_auth($user, $item) {
        self::$auth = array((int) $user->ID, $item['uuid']);
    }

    /**
     * The application password used for this request, as array(user_id, uuid), or null.
     * Uses who actually logged in, so code that switches the current user mid-request can't change the answer.
     */
    public static function current_app_password() {
        if (self::$auth) return self::$auth;
        $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;
        return $uuid && get_current_user_id() ? array(get_current_user_id(), $uuid) : null;
    }

    /**
     * True when this request was made with an application password that has route restrictions.
     */
    public static function current_password_is_restricted() {
        $auth = self::current_app_password();
        return $auth && self::profile_for($auth[0], $auth[1]) !== null;
    }

    /**
     * Capability needed to manage this plugin (admin screen and MCP abilities).
     */
    public static function manager_cap() {
        return apply_filters('key_warden_admin_capability', 'manage_options');
    }

    /**
     * Where each profile is used: default profile, roles, single passwords.
     */
    public static function profile_usage() {
        $settings = Key_Warden_Settings::all();
        $usage = array_fill_keys(array_keys(Key_Warden_Settings::profiles()), array());
        $role_names = wp_roles()->get_names();

        if (isset($usage[$settings['default_profile']])) {
            $usage[$settings['default_profile']][] = __('Default profile', 'key-warden');
        }
        foreach ($settings['role_profiles'] as $role => $pid) {
            if (isset($usage[$pid], $role_names[$role])) $usage[$pid][] = translate_user_role($role_names[$role]);
        }
        $counts = array();
        foreach (Key_Warden_Store::manageable_passwords() as $row) {
            if (isset($usage[$row['profile']])) $counts[$row['profile']] = ($counts[$row['profile']] ?? 0) + 1;
        }
        foreach ($counts as $pid => $n) {
            /* translators: %d: number of passwords */
            $usage[$pid][] = sprintf(_n('%d password', '%d passwords', $n, 'key-warden'), $n);
        }
        return $usage;
    }

    public static function profile_label($id) {
        if ($id === Key_Warden_Settings::PROFILE_NONE) return __('No restrictions', 'key-warden');
        $p = Key_Warden_Settings::profile($id);
        return $p ? $p['name'] : __('No restrictions', 'key-warden');
    }
}
