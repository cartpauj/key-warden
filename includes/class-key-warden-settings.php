<?php
if (!defined('ABSPATH')) exit;

/**
 * Site-wide settings and route profiles, stored as two options.
 */
class Key_Warden_Settings {
    const OPTION   = 'key_warden_settings';
    const PROFILES = 'key_warden_profiles';

    // Special profile values.
    const PROFILE_INHERIT = '';        // Per-password: fall back to the role's profile, then the default profile.
    const PROFILE_NONE    = '__none';  // No route restrictions.

    public static function defaults() {
        return array(
            'create_mode'           => 'all',  // all | restricted
            'create_roles'          => array(),
            'create_users'          => array(),
            'auth_mode'             => 'all',  // all | restricted
            'auth_roles'            => array(),
            'auth_users'            => array(),
            'max_per_user'          => 0,
            'default_lifetime_days' => 0,
            'idle_revoke_days'      => 0,
            'allow_xmlrpc'          => true,
            'default_profile'       => self::PROFILE_NONE,
            'role_profiles'         => array(),
            'log_enabled'           => true,
            'notify_on_create'      => true,
        );
    }

    /**
     * Plain names for settings, used in the activity log.
     */
    public static function labels() {
        return array(
            'create_mode'           => __('who can create', 'key-warden'),
            'create_roles'          => __('who can create', 'key-warden'),
            'create_users'          => __('who can create', 'key-warden'),
            'auth_mode'             => __('who can log in', 'key-warden'),
            'auth_roles'            => __('who can log in', 'key-warden'),
            'auth_users'            => __('who can log in', 'key-warden'),
            'max_per_user'          => __('max per user', 'key-warden'),
            'default_lifetime_days' => __('expiry for new passwords', 'key-warden'),
            'idle_revoke_days'      => __('revoke unused passwords', 'key-warden'),
            'allow_xmlrpc'          => __('XML-RPC', 'key-warden'),
            'default_profile'       => __('default profile', 'key-warden'),
            'role_profiles'         => __('route profile by role', 'key-warden'),
            'log_enabled'           => __('activity log', 'key-warden'),
            'notify_on_create'      => __('email alerts', 'key-warden'),
        );
    }

    public static function all() {
        $saved = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    public static function get($key) {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function update(array $values) {
        $current = self::all();
        $merged = array_merge($current, $values);
        $changed = array_keys(array_filter($values, function ($v, $k) use ($current) {
            return $current[$k] != $v;
        }, ARRAY_FILTER_USE_BOTH));
        // Logged before saving, so switching logging off still leaves a record.
        if ($changed) {
            $labels = self::labels();
            $names = array_map(function ($k) use ($labels) {
                return isset($labels[$k]) ? $labels[$k] : $k;
            }, $changed);
            /* translators: %s: list of setting names */
            Key_Warden_Log::config('settings', sprintf(__('Changed: %s', 'key-warden'), implode(', ', array_unique($names))));
        }
        update_option(self::OPTION, $merged, true);
    }

    /**
     * Clean a partial settings array. Only keys that are present are returned.
     * Used by both the admin screen and the MCP abilities, so the rules live in one place.
     *
     * @return array array(array $clean, string[] $warnings)
     */
    public static function sanitize(array $input) {
        $clean = array();
        $warnings = array();
        $roles = array_keys(wp_roles()->get_names());

        foreach (array('create', 'auth') as $type) {
            if (array_key_exists($type . '_mode', $input)) {
                $clean[$type . '_mode'] = $input[$type . '_mode'] === 'restricted' ? 'restricted' : 'all';
            }
            if (array_key_exists($type . '_roles', $input)) {
                $clean[$type . '_roles'] = array_values(array_intersect((array) $input[$type . '_roles'], $roles));
            }
            if (array_key_exists($type . '_users', $input)) {
                list($ids, $missing) = self::resolve_users($input[$type . '_users']);
                $clean[$type . '_users'] = $ids;
                if ($missing) {
                    /* translators: %s: list of names */
                    $warnings[] = sprintf(__('No user found for: %s', 'key-warden'), implode(', ', $missing));
                }
            }
        }
        foreach (array('max_per_user', 'default_lifetime_days', 'idle_revoke_days') as $key) {
            if (array_key_exists($key, $input)) $clean[$key] = max(0, (int) $input[$key]);
        }
        foreach (array('allow_xmlrpc', 'log_enabled', 'notify_on_create') as $key) {
            if (array_key_exists($key, $input)) $clean[$key] = (bool) $input[$key];
        }
        if (array_key_exists('default_profile', $input)) {
            $clean['default_profile'] = self::clean_profile_id($input['default_profile'], false);
        }
        if (array_key_exists('role_profiles', $input)) {
            $clean['role_profiles'] = array();
            foreach ((array) $input['role_profiles'] as $role => $pid) {
                $pid = self::clean_profile_id($pid, true);
                if (in_array($role, $roles, true) && $pid !== self::PROFILE_INHERIT) $clean['role_profiles'][$role] = $pid;
            }
        }

        // Don't let anyone lock everybody out without noticing.
        $merged = array_merge(self::all(), $clean);
        foreach (array('create', 'auth') as $type) {
            if ($merged[$type . '_mode'] === 'restricted' && !$merged[$type . '_roles'] && !$merged[$type . '_users']) {
                $warnings[] = __('A "restricted" list is empty, so nobody is allowed for it.', 'key-warden');
            }
        }
        return array($clean, $warnings);
    }

    /**
     * Turn user IDs, usernames, or emails (array or comma-separated) into user IDs.
     *
     * @return array array(int[] $ids, string[] $not_found)
     */
    public static function resolve_users($value) {
        $tokens = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $ids = array();
        $missing = array();
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') continue;
            $user = ctype_digit($token) ? get_userdata((int) $token) : null;
            if (!$user) $user = get_user_by('login', $token);
            if (!$user && is_email($token)) $user = get_user_by('email', $token);
            if ($user) {
                $ids[] = (int) $user->ID;
            } else {
                $missing[] = $token;
            }
        }
        return array(array_values(array_unique($ids)), $missing);
    }

    /**
     * A valid profile ID, "no restrictions", or (when allowed) "inherit".
     */
    public static function clean_profile_id($value, $allow_inherit) {
        $value = sanitize_text_field((string) $value);
        if ($value === self::PROFILE_NONE || self::profile($value)) return $value;
        return $allow_inherit ? self::PROFILE_INHERIT : self::PROFILE_NONE;
    }

    /**
     * @return array id => array('name' => string, 'mode' => 'allowlist'|'blocklist', 'rules' => string[])
     */
    public static function profiles() {
        $profiles = get_option(self::PROFILES, array());
        if (!is_array($profiles)) return array();
        foreach ($profiles as &$p) {
            // "all" = any ability the user may run (default), "read" = read-only abilities only.
            $p['abilities'] = isset($p['abilities']) && $p['abilities'] === 'read' ? 'read' : 'all';
            // Advanced: also allow logins outside the REST API (XML-RPC, other plugins' endpoints), where routes can't be checked.
            $p['non_rest'] = !empty($p['non_rest']);
        }
        return $profiles;
    }

    public static function profile($id) {
        $profiles = self::profiles();
        return isset($profiles[$id]) ? $profiles[$id] : null;
    }

    /**
     * @param array $options Optional: 'abilities' ('read'|'all'), 'non_rest' (bool).
     */
    public static function save_profile($id, $name, $mode, array $rules, array $options = array()) {
        $profiles = self::profiles();
        if (!$id) $id = 'p_' . strtolower(wp_generate_password(8, false));
        $profiles[$id] = array(
            'name'  => $name !== '' ? $name : __('Untitled profile', 'key-warden'),
            'mode'  => $mode === 'allowlist' ? 'allowlist' : 'blocklist',
            'rules' => array_values($rules),
            'abilities' => isset($options['abilities']) && $options['abilities'] === 'read' ? 'read' : 'all',
            'non_rest'  => !empty($options['non_rest']),
        );
        update_option(self::PROFILES, $profiles, true);
        Key_Warden_Log::config('profile_saved', $profiles[$id]['name']);
        return $id;
    }

    /**
     * First run: create a ready-to-use "Read-only" profile. It is NOT assigned to anything, so activating
     * the plugin never blocks existing connections. Runs once; deleting the profile later won't bring it back.
     */
    public static function seed() {
        if (get_option(self::PROFILES) !== false) return;

        $t = Key_Warden_Templates::get('core_read_only');
        $profiles = array('read_only' => array('name' => __('Read-only', 'key-warden'), 'mode' => $t['mode'], 'rules' => $t['rules'], 'abilities' => 'read'));
        update_option(self::PROFILES, $profiles, true);
    }

    public static function delete_profile($id) {
        $profiles = self::profiles();
        if (isset($profiles[$id])) Key_Warden_Log::config('profile_deleted', $profiles[$id]['name']);
        unset($profiles[$id]);
        update_option(self::PROFILES, $profiles, true);

        // Anything pointing at the deleted profile falls back to "inherit".
        $settings = self::all();
        if ($settings['default_profile'] === $id) $settings['default_profile'] = self::PROFILE_NONE;
        $settings['role_profiles'] = array_filter($settings['role_profiles'], function ($p) use ($id) {
            return $p !== $id;
        });
        update_option(self::OPTION, $settings, true);
    }
}
