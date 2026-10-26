# Key Warden

See, manage, and restrict WordPress Application Passwords in one place: who can create and use them, which REST routes they can reach, and when they expire. Built with AI agents (MCP Adapter) and backdoor cleanup in mind.

See [readme.txt](readme.txt) for the full user documentation.

## MCP abilities (AI agents)

The plugin registers [Abilities API](https://developer.wordpress.org/apis/abilities/) abilities (WordPress 6.9+). When the official [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin is active, an AI agent connected to your site can use them to manage this plugin: audit passwords, revoke or suspend them, build route profiles, and change settings. There's no extra setup and no UI for this. If the MCP Adapter isn't installed, the abilities just sit unused.

Agents find them with the MCP Adapter's `mcp-adapter-discover-abilities` tool and run them with `mcp-adapter-execute-ability`.

### Who can use them

Every ability requires the same capability as the admin screen: `manage_options` by default (change it with the `key_warden_admin_capability` filter). So the agent needs to be connected as an administrator. An agent connected as an editor gets a permission error.

The abilities are exposed to MCP only (`meta.mcp.public`). They don't appear in the `/wp-abilities/v1` REST listing.

### The abilities

All ability names start with `key-warden/`.

| Ability | What it does | Type |
|---|---|---|
| `list-passwords` | Lists every application password on every account: owner, status, created, last used and IP, expiry, and the route profile in effect. Optional filters: `status` (`all`, `active`, `expired`, `suspended`), `user`, `search`. | Read-only |
| `create-password` | Creates a password for a `user` with a `name`, plus optional `expires` and `profile`. Returns the plain password, which is shown only this once. Follows the "who can create" and per-user limit rules, and triggers the email alert. | Write |
| `update-password` | Changes a password's `expires`, `suspended`, or `profile`, by `uuid`. Only the fields you send change. | Write |
| `revoke-password` | Permanently deletes a password by `uuid`. | Destructive |
| `list-profiles` | Lists route profiles with their mode, rules, and where each is used. | Read-only |
| `save-profile` | Creates a profile, or updates one when given an `id`. Takes `name`, `mode`, `rules`, `read_only_abilities`, and `allow_non_rest` (advanced: also allow XML-RPC and other plugins' endpoints, where route rules aren't enforced), or a `template` to start from. Reports any rule lines it couldn't understand. | Write |
| `delete-profile` | Deletes a profile by `id`. Anything using it falls back to the next profile in line. | Destructive |
| `list-templates` | Lists the starter templates and whether the plugin each one targets is active. | Read-only |
| `list-routes` | Lists every REST route on the site as rule patterns, optionally for one `namespace`. | Read-only |
| `test-route` | Checks whether a `method` + `route` would be allowed, against a saved `profile_id` or unsaved `mode` + `rules`. | Read-only |
| `get-settings` | Returns all settings (who can create and log in, limits, role profiles, default profile, expiry, XML-RPC, alerts, logging) and whether application passwords are available on the site. | Read-only |
| `update-settings` | Changes any settings you send. Users can be given as IDs, usernames, or emails. Returns warnings, such as unknown users, or a restricted list that's empty. | Write |
| `get-activity-log` | Recent events, newest first: created, revoked, auto-revoked, updated, and blocked logins and routes. Optional `limit` (1 to 200, default 50). | Read-only |
| `run-cleanup` | Revokes expired and long-unused passwords now, instead of waiting for the daily run. Returns how many it revoked. | Destructive |

The full input schema for each ability, with field types and descriptions, is available to the agent through `mcp-adapter-get-ability-info`.

Each ability is tagged `readonly`, `destructive`, or `idempotent`, so MCP clients can ask before running anything risky.

#### Value formats

- **`expires`:** a date and time such as `"2026-12-31 17:00"` (site timezone) or ISO 8601, a Unix timestamp, or `null`, `0`, or `""` for never. Dates come back as ISO 8601.
- **`profile`:** a profile ID from `list-profiles`, `"__none"` for no restrictions, or `""` to use the role's profile or the default profile.
- **`rules`:** an array of strings (or one string with a line per rule), like `["GET /wp/v2/*", "GET,POST /wc/v3/orders*", "/mcp/*"]`.
- **`mode`:** `"allowlist"` (block all except these routes) or `"blocklist"` (allow all except these routes).

### Things you can ask an agent

- "Audit the application passwords on this site and flag anything that looks like a backdoor."
- "Suspend every application password that hasn't been used in 90 days."
- "Make a read-only profile for WooCommerce and give it to the ShipStation password."
- "Only allow administrators to log in with application passwords."
- "Why was my Zapier connection blocked yesterday?" (The agent reads the activity log.)

### Security notes

- **Restricted passwords can only read this plugin.** If the agent's application password has any route profile (for example "AI agent: MCP Adapter only"), this plugin's write abilities refuse to run, whatever the profile's ability setting. Without that, an agent limited to `/mcp/*` could use these abilities to remove its own limits. To let an agent manage this plugin, give its password no profile.
- **Other plugins' abilities:** an agent can use any ability its user is allowed to. To limit an agent to looking only, tick "Only allow read-only abilities" on its profile (WordPress 7.1+, enforced through core's `wp_ability_permission_result` filter). The MCP Adapter's own `execute-ability` dispatcher is exempt from that check, because the ability it runs is checked on its own.
- **Unrestricted agents are powerful.** An admin-level agent with an unrestricted password can create passwords, widen access, or turn restrictions off, just like an admin in the dashboard. Every new password still sends the email alert, and every change is logged with the name of the password that made it.
- **Watch for prompt injection.** Password names and blocked routes come from other people, and the agent reads them through `list-passwords` and `get-activity-log`. A password named "ignore previous instructions and..." is just text, but treat agent suggestions based on it with care.
- **Multisite:** the abilities, admin screen, and manual cleanup only show and change passwords of this site's users that the caller is allowed to edit (`edit_user`), so a site admin can't touch other sites' users or super admins.
- **An agent can lock itself out.** Restricting who can log in, or suspending its own password, takes effect right away, including for the agent making the call.
- **Turning the abilities off:** `add_filter( 'key_warden_register_abilities', '__return_false' );`

## Hooks

- `key_warden_templates` (filter): add or change route templates.
- `key_warden_user_allowed` (filter): `( bool $allowed, string $type, WP_User $user )`, where `$type` is `create` or `auth`.
- `key_warden_notify_email` (filter): `( string $to, WP_User $user, array $item )`. Change who gets new-password alerts.
- `key_warden_admin_capability` (filter): capability for the admin screen and abilities (default `manage_options`).
- `key_warden_register_abilities` (filter): return `false` to skip registering the MCP abilities.

## Renaming

The display name lives in three places: the plugin header and `KEY_WARDEN_NAME` in `key-warden.php`, and the title in `readme.txt`. All code uses the neutral `key_warden_` prefix.
