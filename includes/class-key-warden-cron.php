<?php
if (!defined('ABSPATH')) exit;

/**
 * Daily cleanup: revoke expired and idle passwords, drop data for passwords that no longer exist.
 */
class Key_Warden_Cron {
    const HOOK = 'key_warden_daily';

    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        // Also covers first activation and anything that cleared the schedule.
        add_action('init', array(__CLASS__, 'schedule'));
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * @param bool $only_editable For manual runs: skip users the current user can't edit.
     * @return int How many passwords were revoked.
     */
    public static function run($only_editable = false) {
        $idle_days = (int) Key_Warden_Settings::get('idle_revoke_days');
        // Core only updates "last used" once a day, so allow an extra day before calling a password idle.
        $idle_cutoff = $idle_days > 0 ? time() - ($idle_days + 1) * DAY_IN_SECONDS : 0;
        $revoked = 0;

        foreach (Key_Warden_Store::user_ids_with_passwords() as $user_id) {
            if ($only_editable && !current_user_can('edit_user', $user_id)) continue;
            foreach (WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
                $meta = Key_Warden_Store::get($user_id, $item['uuid']);
                $reason = '';
                if (Key_Warden_Store::is_expired($meta)) {
                    $reason = __('Expired', 'key-warden');
                } elseif ($idle_cutoff) {
                    $last = !empty($item['last_used']) ? (int) $item['last_used'] : (int) $item['created'];
                    if ($last < $idle_cutoff) {
                        /* translators: %d: number of days */
                        $reason = sprintf(__('Unused for %d days', 'key-warden'), $idle_days);
                    }
                }

                if ($reason === '') continue;
                self::revoke($user_id, $item, $reason);
                $revoked++;
            }

            // Re-read right before cleaning up, so a password created a moment ago keeps its data.
            $live = wp_list_pluck(WP_Application_Passwords::get_user_application_passwords($user_id), 'uuid');
            foreach (array_keys(Key_Warden_Store::all_for_user($user_id)) as $uuid) {
                if (!in_array($uuid, $live, true)) Key_Warden_Store::forget($user_id, $uuid);
            }
        }
        return $revoked;
    }

    private static function revoke($user_id, $item, $reason) {
        Key_Warden_Enforcer::$quiet = true; // Skip the generic "revoked" log entry.
        WP_Application_Passwords::delete_application_password($user_id, $item['uuid']);
        Key_Warden_Enforcer::$quiet = false;
        Key_Warden_Log::add('auto_revoked', $user_id, $item['name'], $reason);
    }
}
