<?php
if (!defined('ABSPATH')) exit;

/**
 * Small activity log kept in one non-autoloaded option (newest first, capped).
 */
class Key_Warden_Log {
    const OPTION = 'key_warden_log';
    const MAX    = 200;

    public static function add($event, $user_id = 0, $password_name = '', $detail = '') {
        if (!Key_Warden_Settings::get('log_enabled')) return;
        $log = self::all();
        array_unshift($log, array(
            'time'     => time(),
            'event'    => $event,
            'user_id'  => (int) $user_id,
            'password' => (string) $password_name,
            'detail'   => (string) $detail,
            'ip'       => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        ));
        update_option(self::OPTION, array_slice($log, 0, self::MAX), false);
    }

    /**
     * Log a configuration change by the current user, noting the app password if one was used (e.g. an AI agent).
     */
    /**
     * "By jane", "By jane, using application password "Claude"", "By WP-CLI", or "Automatic".
     */
    public static function actor() {
        if (defined('WP_CLI') && WP_CLI) return __('By WP-CLI', 'key-warden');
        $user = wp_get_current_user();
        if (!$user->exists()) return __('Automatic', 'key-warden');
        $auth = Key_Warden_Policy::current_app_password();
        $item = $auth ? WP_Application_Passwords::get_user_application_password($auth[0], $auth[1]) : null;
        return $item
            /* translators: 1: username, 2: application password name */
            ? sprintf(__('By %1$s, using application password "%2$s"', 'key-warden'), $user->user_login, $item['name'])
            /* translators: %s: username */
            : sprintf(__('By %s', 'key-warden'), $user->user_login);
    }

    public static function config($event, $detail) {
        $auth = Key_Warden_Policy::current_app_password();
        $item = $auth ? WP_Application_Passwords::get_user_application_password($auth[0], $auth[1]) : null;
        self::add($event, get_current_user_id(), $item ? $item['name'] : '', $detail . '. ' . self::actor());
    }

    /**
     * Rate limit for noisy events. Returns true when this one may be logged. After $limit events for $key within
     * $window seconds, one "entries skipped" note is written (if $note) and the rest are dropped without any writes.
     */
    public static function allow($key, $limit, $window, $note = true) {
        $tkey = 'key_warden_rl_' . md5($key);
        $count = (int) get_transient($tkey);
        if ($count > $limit) return false;
        set_transient($tkey, $count + 1, $window);
        if ($count < $limit) return true;
        if ($note) self::add('throttled', 0, '', __('Too many similar events. More are not being logged for a few minutes.', 'key-warden'));
        return false;
    }

    public static function all() {
        $log = get_option(self::OPTION, array());
        return is_array($log) ? $log : array();
    }

    public static function clear() {
        delete_option(self::OPTION);
    }

    public static function labels() {
        return array(
            'created'        => __('Created', 'key-warden'),
            'revoked'        => __('Revoked', 'key-warden'),
            'auto_revoked'   => __('Auto-revoked', 'key-warden'),
            'create_blocked' => __('Creation blocked', 'key-warden'),
            'auth_blocked'   => __('Login blocked', 'key-warden'),
            'route_blocked'  => __('Route blocked', 'key-warden'),
            'updated'        => __('Updated', 'key-warden'),
            'settings'       => __('Settings changed', 'key-warden'),
            'profile_saved'  => __('Profile saved', 'key-warden'),
            'profile_deleted' => __('Profile deleted', 'key-warden'),
            'throttled'      => __('Some entries skipped', 'key-warden'),
        );
    }
}
