<?php
if (!defined('ABSPATH')) exit;

/**
 * Starter rule sets. Each one can seed a new profile or be appended to an existing one.
 * "namespace" is used to show whether the plugin is active on this site.
 */
class Key_Warden_Templates {
    public static function all() {
        $templates = array(
            // WordPress core.
            'core_read_only' => array(
                'group'       => 'WordPress',
                'name'        => __('Read-only (whole API)', 'key-warden'),
                'description' => __('Can read anything the user can see, but cannot create, change, or delete.', 'key-warden'),
                'mode'        => 'allowlist',
                'abilities'   => 'read',
                'namespace'   => '',
                'rules'       => array('GET /*'),
            ),
            'core_content_editor' => array(
                'group'       => 'WordPress',
                'name'        => __('Content publishing (posts, pages, media)', 'key-warden'),
                'description' => __('For writing tools and publishing apps. Manage posts, pages, media and terms.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'wp/v2',
                'rules'       => array(
                    'GET /',
                    '/wp/v2/posts*',
                    '/wp/v2/pages*',
                    '/wp/v2/media*',
                    '/wp/v2/categories*',
                    '/wp/v2/tags*',
                    '/wp/v2/blocks*',
                    'GET /wp/v2/types*',
                    'GET /wp/v2/taxonomies*',
                    'GET /wp/v2/statuses*',
                    'GET /wp/v2/users/me',
                ),
            ),
            'core_media' => array(
                'group'       => 'WordPress',
                'name'        => __('Media uploads only', 'key-warden'),
                'description' => __('Upload and manage files in the Media Library. Nothing else.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'wp/v2',
                'rules'       => array('/wp/v2/media*', 'GET /wp/v2/users/me'),
            ),
            'core_comments' => array(
                'group'       => 'WordPress',
                'name'        => __('Comment moderation', 'key-warden'),
                'description' => __('Read and moderate comments, and read posts for context.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'wp/v2',
                'rules'       => array('/wp/v2/comments*', 'GET /wp/v2/posts*', 'GET /wp/v2/pages*', 'GET /wp/v2/users/me'),
            ),
            'core_block_admin' => array(
                'group'       => 'WordPress',
                'name'        => __('Block site admin areas', 'key-warden'),
                'description' => __('Allow everything except plugins, themes, settings, site health, changing users, and managing application passwords.', 'key-warden'),
                'mode'        => 'blocklist',
                'namespace'   => '',
                'rules'       => array(
                    '/wp/v2/plugins*',
                    '/wp/v2/themes*',
                    '/wp/v2/settings*',
                    '/wp-site-health/*',
                    '/wp/v2/users/*/application-passwords*',
                    'POST,PUT,PATCH,DELETE /wp/v2/users*',
                ),
            ),
            'core_block_users' => array(
                'group'       => 'WordPress',
                'name'        => __('Block all user data', 'key-warden'),
                'description' => __('Allow everything except the users endpoints.', 'key-warden'),
                'mode'        => 'blocklist',
                'namespace'   => '',
                'rules'       => array('/wp/v2/users*'),
            ),

            // AI agents.
            'ai_mcp_only' => array(
                'group'       => __('AI agents', 'key-warden'),
                'name'        => __('AI agent: MCP Adapter only', 'key-warden'),
                'description' => __('For Claude, Cursor and other MCP clients. Only the MCP endpoint, nothing else in the API. The agent can use any ability its user is allowed to.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'mcp',
                'rules'       => array('/mcp/*'),
            ),
            'ai_abilities_read' => array(
                'group'       => __('AI agents', 'key-warden'),
                'name'        => __('AI agent: read-only abilities', 'key-warden'),
                'description' => __('List abilities and run only the read-only ones through the Abilities API.', 'key-warden'),
                'mode'        => 'allowlist',
                'abilities'   => 'read',
                'namespace'   => 'wp-abilities/v1',
                'rules'       => array('GET /wp-abilities/v1/*'),
            ),
            'ai_block' => array(
                'group'       => __('AI agents', 'key-warden'),
                'name'        => __('Block AI agents (MCP and Abilities API)', 'key-warden'),
                'description' => __('Allow everything except the MCP endpoint and the Abilities API.', 'key-warden'),
                'mode'        => 'blocklist',
                'namespace'   => '',
                'rules'       => array('/mcp/*', '/wp-abilities/*'),
            ),

            // WooCommerce.
            'wc_read_only' => array(
                'group'       => 'WooCommerce',
                'name'        => __('WooCommerce: read-only', 'key-warden'),
                'description' => __('Reporting and dashboards. Read store data, change nothing.', 'key-warden'),
                'mode'        => 'allowlist',
                'abilities'   => 'read',
                'namespace'   => 'wc/v3',
                'rules'       => array('GET /wc/v3/*', 'GET /wc-analytics/*'),
            ),
            'wc_orders' => array(
                'group'       => 'WooCommerce',
                'name'        => __('WooCommerce: order fulfillment', 'key-warden'),
                'description' => __('Shipping and fulfillment tools. Manage orders, read customers and products.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'wc/v3',
                'rules'       => array('/wc/v3/orders*', 'GET /wc/v3/customers*', 'GET /wc/v3/products*', 'GET /wc/v3/data*'),
            ),
            'wc_products' => array(
                'group'       => 'WooCommerce',
                'name'        => __('WooCommerce: products and inventory', 'key-warden'),
                'description' => __('Inventory sync and catalog tools. Manage products, variations, categories and attributes.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'wc/v3',
                'rules'       => array('/wc/v3/products*', 'GET /wc/v3/data*'),
            ),
            'wc_customers' => array(
                'group'       => 'WooCommerce',
                'name'        => __('WooCommerce: customers (CRM sync)', 'key-warden'),
                'description' => __('Manage customers and read their orders.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'wc/v3',
                'rules'       => array('/wc/v3/customers*', 'GET /wc/v3/orders*'),
            ),
            'wc_full' => array(
                'group'       => 'WooCommerce',
                'name'        => __('WooCommerce: full store API only', 'key-warden'),
                'description' => __('Everything in the WooCommerce API, nothing from WordPress core.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'wc/v3',
                'rules'       => array('/wc/v3/*', '/wc-analytics/*'),
            ),
            'wc_block_settings' => array(
                'group'       => 'WooCommerce',
                'name'        => __('WooCommerce: block store settings', 'key-warden'),
                'description' => __('Allow everything except store settings, payment gateways, webhooks, and system status.', 'key-warden'),
                'mode'        => 'blocklist',
                'namespace'   => 'wc/v3',
                'rules'       => array('/wc/v3/settings*', '/wc/v3/payment_gateways*', '/wc/v3/webhooks*', '/wc/v3/system_status*'),
            ),

            // Other plugins.
            'mp_read_only' => array(
                'group'       => __('Other plugins', 'key-warden'),
                'name'        => __('MemberPress: read-only', 'key-warden'),
                'description' => __('Read members, memberships, and transactions (Developer Tools API).', 'key-warden'),
                'mode'        => 'allowlist',
                'abilities'   => 'read',
                'namespace'   => 'mp/v1',
                'rules'       => array('GET /mp/v1/*'),
            ),
            'mp_full' => array(
                'group'       => __('Other plugins', 'key-warden'),
                'name'        => __('MemberPress: full API only', 'key-warden'),
                'description' => __('Everything in the MemberPress Developer Tools API, nothing else.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'mp/v1',
                'rules'       => array('/mp/v1/*'),
            ),
            'gf_read_only' => array(
                'group'       => __('Other plugins', 'key-warden'),
                'name'        => __('Gravity Forms: read entries', 'key-warden'),
                'description' => __('Read forms and entries, for exports and reporting.', 'key-warden'),
                'mode'        => 'allowlist',
                'abilities'   => 'read',
                'namespace'   => 'gf/v2',
                'rules'       => array('GET /gf/v2/*'),
            ),
            'gf_full' => array(
                'group'       => __('Other plugins', 'key-warden'),
                'name'        => __('Gravity Forms: full API only', 'key-warden'),
                'description' => __('Everything in the Gravity Forms API, nothing else.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'gf/v2',
                'rules'       => array('/gf/v2/*'),
            ),
            'ld_read_only' => array(
                'group'       => __('Other plugins', 'key-warden'),
                'name'        => __('LearnDash: read-only', 'key-warden'),
                'description' => __('Read courses, lessons, groups and progress.', 'key-warden'),
                'mode'        => 'allowlist',
                'abilities'   => 'read',
                'namespace'   => 'ldlms/v2',
                'rules'       => array('GET /ldlms/v2/*'),
            ),
            'tec_full' => array(
                'group'       => __('Other plugins', 'key-warden'),
                'name'        => __('The Events Calendar: events API only', 'key-warden'),
                'description' => __('Manage events, venues and organizers.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'tribe/events/v1',
                'rules'       => array('/tribe/events/v1/*'),
            ),
            'cf7_full' => array(
                'group'       => __('Other plugins', 'key-warden'),
                'name'        => __('Contact Form 7: forms API only', 'key-warden'),
                'description' => __('Manage Contact Form 7 forms.', 'key-warden'),
                'mode'        => 'allowlist',
                'namespace'   => 'contact-form-7/v1',
                'rules'       => array('/contact-form-7/v1/*'),
            ),
        );

        /**
         * Add or change starter templates.
         *
         * @param array $templates id => array(group, name, description, mode, namespace, rules, optional abilities 'read'|'all')
         */
        return apply_filters('key_warden_templates', $templates);
    }

    public static function get($id) {
        $all = self::all();
        if (!isset($all[$id])) return null;
        return array_merge(array('abilities' => 'all'), $all[$id]);
    }

    /**
     * Is the plugin behind a template active? Templates with no namespace always are.
     */
    public static function is_available(array $template) {
        if (empty($template['namespace'])) return true;
        return in_array($template['namespace'], rest_get_server()->get_namespaces(), true);
    }
}
