<?php
/*
Plugin Name: Key Warden
Plugin URI: https://github.com/cartpauj/key-warden
Description: See, manage, and restrict WordPress Application Passwords in one place: who can create and use them, which API routes they can reach, and when they expire.
Version: 1.0.0
Requires at least: 5.9
Requires PHP: 7.4
Author: cartpauj
Author URI: https://github.com/cartpauj
Text Domain: key-warden
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
*/

if (!defined('ABSPATH')) exit;

// Display name lives here (and in the header above + readme.txt). Code uses the "key_warden" / "Key_Warden" / "key-warden" prefixes.
define('KEY_WARDEN_NAME', 'Key Warden');
define('KEY_WARDEN_SLUG', 'key-warden');
define('KEY_WARDEN_VERSION', '1.0.0');
define('KEY_WARDEN_PLUGIN_FILE', __FILE__);
define('KEY_WARDEN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KEY_WARDEN_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-settings.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-store.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-rules.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-templates.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-log.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-policy.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-enforcer.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-cron.php';
require_once KEY_WARDEN_PLUGIN_DIR . 'includes/class-key-warden-abilities.php';

Key_Warden_Enforcer::init();
Key_Warden_Cron::init();
Key_Warden_Abilities::init();

if (is_admin()) {
    require_once KEY_WARDEN_PLUGIN_DIR . 'includes/admin/class-key-warden-admin.php';
    Key_Warden_Admin::init();
}

register_activation_hook(__FILE__, array('Key_Warden_Settings', 'seed'));
register_deactivation_hook(__FILE__, array('Key_Warden_Cron', 'unschedule'));
