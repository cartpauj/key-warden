<?php
if (!defined('ABSPATH')) exit;

/**
 * Extra data we keep per application password (expiry, suspension, route profile),
 * plus helpers for reading core's own password records across all users.
 */
class Key_Warden_Store {
    const META_KEY = '_key_warden_passwords';

    public static function defaults() {
        return array(
            'expires'   => 0,     // Unix timestamp, 0 = never.
            'suspended' => false,
            'profile'   => Key_Warden_Settings::PROFILE_INHERIT,
        );
    }

    public static function get($user_id, $uuid) {
        $all = self::all_for_user($user_id);
        $row = isset($all[$uuid]) ? $all[$uuid] : array();
        return array_merge(self::defaults(), $row);
    }

    public static function set($user_id, $uuid, array $values) {
        $all = self::all_for_user($user_id);
        $current = isset($all[$uuid]) ? $all[$uuid] : array();
        $all[$uuid] = array_merge(self::defaults(), $current, $values);
        update_user_meta($user_id, self::META_KEY, $all);
    }

    /**
     * Change a password's expiry, suspension, or profile, and log it. Missing keys are left alone.
     *
     * @param array $changes Any of: expires (timestamp, 0 = never), suspended (bool), profile (ID, '' = inherit, '__none').
     * @return true|WP_Error
     */
    public static function update_password($user_id, $uuid, array $changes) {
        $item = WP_Application_Passwords::get_user_application_password($user_id, $uuid);
        if (!$item) return new WP_Error('key_warden_not_found', __('That application password does not exist.', 'key-warden'));

        $values = array();
        if (array_key_exists('expires', $changes)) $values['expires'] = max(0, (int) $changes['expires']);
        if (array_key_exists('suspended', $changes)) $values['suspended'] = (bool) $changes['suspended'];
        if (array_key_exists('profile', $changes)) $values['profile'] = Key_Warden_Settings::clean_profile_id($changes['profile'], true);
        if (!$values) return true;

        // Say what changed, e.g. "Suspended, expiry. By jane".
        $before = self::get($user_id, $uuid);
        $parts = array();
        if (isset($values['suspended']) && $values['suspended'] !== (bool) $before['suspended']) {
            $parts[] = $values['suspended'] ? __('Suspended', 'key-warden') : __('Resumed', 'key-warden');
        }
        if (isset($values['expires']) && $values['expires'] !== (int) $before['expires']) {
            $parts[] = $values['expires']
                /* translators: %s: date */
                ? sprintf(__('Expires %s', 'key-warden'), wp_date(get_option('date_format'), $values['expires']))
                : __('Never expires', 'key-warden');
        }
        if (isset($values['profile']) && $values['profile'] !== $before['profile']) {
            $parts[] = $values['profile'] === Key_Warden_Settings::PROFILE_INHERIT
                ? __('Profile: role or default', 'key-warden')
                /* translators: %s: profile name */
                : sprintf(__('Profile: %s', 'key-warden'), Key_Warden_Policy::profile_label($values['profile']));
        }
        if (!$parts) return true; // Nothing actually changed.

        self::set($user_id, $uuid, $values);
        Key_Warden_Log::add('updated', $user_id, $item['name'], implode(', ', $parts) . '. ' . Key_Warden_Log::actor());
        return true;
    }

    public static function forget($user_id, $uuid) {
        $all = self::all_for_user($user_id);
        if (!isset($all[$uuid])) return;
        unset($all[$uuid]);
        if ($all) {
            update_user_meta($user_id, self::META_KEY, $all);
        } else {
            delete_user_meta($user_id, self::META_KEY);
        }
    }

    public static function all_for_user($user_id) {
        $all = get_user_meta($user_id, self::META_KEY, true);
        return is_array($all) ? $all : array();
    }

    public static function is_expired(array $meta) {
        return !empty($meta['expires']) && (int) $meta['expires'] <= time();
    }

    /**
     * IDs of every user that holds at least one application password.
     */
    public static function user_ids_with_passwords() {
        // Only runs on this plugin's admin screen, the daily cleanup, and admin-only abilities.
        return get_users(array(
            'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_compare' => 'EXISTS',
            'fields'       => 'ID',
            // Single site: every user, even ones with no role (a classic place to hide a backdoor).
            // Multisite: only this site's users; the rest belong to other sites' admins.
            'blog_id'      => is_multisite() ? get_current_blog_id() : 0,
        ));
    }

    /**
     * Every application password on the site, flattened, with our extra data merged in.
     */
    public static function all_passwords() {
        $rows = array();
        foreach (self::user_ids_with_passwords() as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) continue;
            foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
                unset($item['password']);
                $rows[] = array_merge($item, self::get($user_id, $item['uuid']), array(
                    'user_id'    => (int) $user_id,
                    'user_login' => $user->user_login,
                    'user'       => $user,
                ));
            }
        }
        return $rows;
    }

    /**
     * Passwords whose owner the current user is allowed to edit. On single sites that's everyone
     * for an admin; on multisite it keeps site admins away from other sites' users and super admins.
     */
    public static function manageable_passwords() {
        return array_values(array_filter(self::all_passwords(), function ($row) {
            return current_user_can('edit_user', $row['user_id']);
        }));
    }

    public static function find($user_id, $uuid) {
        $item = WP_Application_Passwords::get_user_application_password($user_id, $uuid);
        if (!$item) return null;
        unset($item['password']);
        return array_merge($item, self::get($user_id, $uuid), array('user_id' => (int) $user_id));
    }
}
