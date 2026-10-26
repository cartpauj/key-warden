=== Key Warden ===
Contributors: cartpauj
Tags: application passwords, security, rest api, mcp, ai
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control and audit application passwords: see every one, decide who can use them, limit what they reach, and make them expire.

== Description ==

Application passwords let apps, scripts, and now AI agents log in to your site's API as a real user. They're useful, and they're also a quiet way in that most site owners never look at.

**Key Warden** puts all of them on one screen (Tools > Application Passwords) and gives you the controls WordPress doesn't.

= Why this matters now: AI agents and backdoors =

WordPress's MCP Adapter lets AI assistants like Claude and Cursor work on your site, and they connect with an application password. That means more of these passwords on more sites, often with full admin access.

That's also exactly what an attacker wants. Someone who gets in once (a stolen login, a vulnerable plugin) can quietly add an application password to any account, even a forgotten subscriber, and keep a permanent back door into your site. It survives a password reset, it doesn't show up on the login screen, and WordPress gives you no single place to spot it.

This plugin helps you shut that down:

* **See them all at once.** Every application password, on every account, in one list: who owns it, when it was made, and when and from what IP it was last used.
* **Get an email when one is created.** Any new application password sends an alert to the site admin, with a link to revoke it.
* **Decide who's allowed.** Only the roles or people you pick can create them, or log in with them.
* **Limit the damage.** Give an AI agent the "MCP Adapter only" profile and it can't touch users, plugins, or settings through the rest of the API, even if it runs as an admin.
* **Make them expire.** New passwords can expire automatically, and unused ones can be revoked after a set number of days.

**Nothing changes when you activate it.** No restrictions apply until you set them up, so existing connections keep working.

= Features =

* One list of every application password across all users, with search, status filters, and bulk revoke / suspend / resume.
* Suspend a password without deleting it.
* Expiry dates per password, plus a default lifetime for new ones.
* Auto-revoke passwords that haven't been used in a set number of days.
* Choose who can create passwords, and who can log in with them: by role, by person, or both.
* Limit how many passwords each person can have.
* Route profiles that limit which REST API routes a password can reach.
* Assign a profile to one password, to a role, or as the default profile for everything else.
* Ready-made templates for WordPress, AI agents, WooCommerce, MemberPress, Gravity Forms, LearnDash, The Events Calendar, and Contact Form 7.
* A live route tester and a list of every route your site exposes, so writing rules isn't guesswork.
* Email alert when any password is created.
* Turn off XML-RPC logins for application passwords.
* Activity log of created, revoked, and blocked passwords.
* MCP abilities, so an AI agent connected through the MCP Adapter can manage all of the above for you.

= The screens =

Everything lives under **Tools > Application Passwords**:

* **Passwords:** every application password on the site. Click one to set its expiry date, give it its own route profile, or suspend it.
* **Who can use them:** who can create passwords, who can log in with them, the per-person limit, and which route profile each role gets.
* **Route profiles:** your profiles, plus templates to start new ones from.
* **Settings:** the default profile, expiry and auto-revoke, XML-RPC, email alerts, and the log.
* **Activity log:** the last 200 events: passwords created, revoked, auto-revoked, and blocked.

= Route profiles =

A route profile limits which parts of the REST API a password can call. There are two kinds:

* **Block all except these routes:** only the listed routes work. This is the safest choice.
* **Allow all except these routes:** everything works except the listed routes.

A password with no profile can reach everything its user can. A profile never gives a password more access than its user already has. It can only take access away.

= Writing route rules =

Put one rule per line. Routes are written without the `/wp-json` part (it's removed for you if you paste it in).

* `/wp/v2/posts*` matches posts and anything under it, with any method.
* `GET /wp/v2/*` matches only reads (GET) under `/wp/v2/`.
* `GET,POST /wc/v3/orders*` matches reading and creating orders.
* `# a note` is a comment and is ignored.

`*` matches anything, including slashes. A rule without `*` matches only that exact route, so `/wp/v2/media` doesn't cover `/wp/v2/media/5`. Matching isn't case-sensitive, and HEAD requests count as GET. The methods you can use are GET, POST, PUT, PATCH, and DELETE.

To see what a rule does before saving, type a route into the **Test a route** box on the profile editor. The **Routes on this site** list shows every route your site exposes. Click + to add one.

= Which profile applies =

For each password, the first match wins:

1. The profile set on that password (Passwords tab > Edit).
2. The profile for the user's role (Who can use them tab). If a user has several roles, the first one with a profile wins.
3. The **Default route profile** (Settings tab).
4. If none of those is set, there are no restrictions.

Each profile also has an **AI abilities** setting. See "Good to know" below.

= What a blocked app sees =

* **Not allowed to log in, suspended, or expired:** the app gets a `401` error and can't log in at all. Users who aren't allowed to log in with application passwords also won't see the Application Passwords section on their profile.
* **Route not allowed:** the app gets a `403` error with the code `key_warden_route_blocked`.
* **Not allowed to create a password:** the "Add New Application Password" form is hidden on their profile, and the API returns a `403` error.

Every block is recorded in the Activity log, with the route that was blocked.

= Good to know =

* A password with a route profile only works with the REST API. It can't log in over XML-RPC, or on another plugin's endpoint that accepts application passwords (like GraphQL), because those can't be checked against routes. If an app really needs one of those, tick **Advanced > Also allow logins outside the REST API** on its profile. Its route rules won't apply there, so use this sparingly.
* AI agents run abilities through one route (like `/mcp/*`), so route rules can't tell abilities apart. By default an agent can use any ability its user is allowed to. For an agent that should only look, tick **Only allow read-only abilities** on its profile (the read-only templates come with it ticked). This needs WordPress 7.1 or newer.
* A password with a profile can never change this plugin's own settings through its abilities, so an agent can't lift its own limits.
* Requests sent through `/batch/v1` are checked one by one, so batching can't get around a profile.
* A password with a route profile can't create new application passwords, so a limited app can't make itself an unlimited one. A password created while logged in with another password can't outlive it.
* Blocked-route log entries are limited to 10 per password every 10 minutes, so a leaked password can't flush the log.
* Changes to settings and profiles are recorded in the Activity log, including which application password made them (for example, an AI agent).
* Expired passwords stop working right away. The daily cleanup then deletes them. You can also run the cleanup by hand from the Settings tab.
* WooCommerce's own REST API keys (the ones starting with `ck_`) aren't application passwords, so this plugin doesn't affect them.
* Creating passwords with WP-CLI isn't blocked, so admins can still set them up from the command line.

== Installation ==

1. Install and activate the plugin.
2. Go to **Tools > Application Passwords**.
3. Look through the **Passwords** tab for anything you don't recognize.

That's it. Nothing is restricted until you choose to restrict it. See the FAQ for common setups.

== Frequently Asked Questions ==

= Will activating this break my existing connections? =

No. Activating it blocks nothing. A ready-made "Read-only" route profile is created for you, but it isn't assigned to anything until you choose to.

= How do I check my site for a hidden backdoor? =

Open **Tools > Application Passwords**. Look for passwords on accounts that shouldn't have one (subscribers, customers, old staff), names you don't recognize, or "Last used" IPs you don't know. Revoke anything suspicious. To stop it happening again:

1. Under **Who can use them**, set "Who can log in" to "Only the roles and people below", and pick only the roles or people who really need application passwords. Passwords on every other account stop working right away.
2. Do the same for "Who can create".
3. Keep **Email alerts** on (Settings), so you hear about every new password.

= How do I connect an AI agent safely? =

1. Create a user for the agent with the lowest role that can do the job, or use your own account.
2. Create an application password for it.
3. On the **Route profiles** tab, click the **AI agent: MCP Adapter only** template and save it.
4. On the **Passwords** tab, edit the agent's password and pick that profile.
5. Optional: give it an expiry date, so access ends on its own.

The agent can now use MCP, but can't use the rest of the REST API.

= How do I give a store or shipping integration only what it needs? =

Start a profile from a WooCommerce template, for example **WooCommerce: order fulfillment**. Adjust the routes if your integration needs more, and test them with the route tester. Then assign the profile to the integration's password.

= How do I make every password read-only? =

On the **Settings** tab, set **Default route profile** to "Read-only". Apps that only read keep working. Anything that writes (publishing tools, AI agents over MCP, store syncs) will need its own profile, set per password or per role.

= Why can't my AI agent use MCP with the Read-only profile? =

MCP sends every request as a POST, even when it only reads, so a GET-only profile blocks it. Use the **AI agent: MCP Adapter only** template for agents instead.

= An app suddenly stopped working. How do I find out why? =

Check the **Activity log**. It shows whether the password was blocked for a route (and which one), expired, suspended, or belongs to someone who isn't allowed to log in. To check a route against a profile, paste it into **Test a route** on the profile editor.

= A template says "Plugin not active". =

The plugin that template is for isn't active on your site, or its API isn't turned on. For example, the MemberPress templates need the MemberPress Developer Tools add-on. You can still use the template. It just won't match anything until that plugin's API exists.

= I don't see application passwords on my site at all. =

WordPress only turns application passwords on for sites served over HTTPS (or sites set to the "local" environment type). Some security plugins also turn them off.

= Does it work with multisite? =

Each site has its own settings, and each site's screen only shows and manages that site's users, and only users the admin is allowed to edit. In practice, on multisite that usually means super admins. Application passwords belong to users, and users are shared across the network, so a password blocked on one site may still work on another site that doesn't run this plugin, or has different settings.

= What does uninstalling remove? =

This plugin's settings, profiles, log, and its extra data about each password (expiry, suspension, profile). Your application passwords themselves are left alone.

= Can an AI agent manage this plugin for me? =

Yes. If WordPress's MCP Adapter plugin is active (WordPress 6.9 or newer), an AI agent connected as an administrator can use this plugin's abilities to:

* list passwords, then create, update, suspend, or revoke them
* build and test route profiles
* change settings
* read the activity log

There's nothing to set up. You can ask things like "Audit the application passwords on this site and flag anything that looks like a backdoor" or "Suspend every password that hasn't been used in 90 days."

The abilities need the `manage_options` capability, so an agent connected as an editor can't use them. If the agent's own password has a route profile (like "AI agent: MCP Adapter only"), it can only use this plugin's read-only abilities, so it can't lift its own limits. Every password an agent creates still sends the email alert, and every change is logged. To turn the abilities off, add `add_filter( 'key_warden_register_abilities', '__return_false' );` to your site.

The full list of abilities and their inputs is in the plugin's README.md on GitHub.

= Hooks for developers =

* `key_warden_templates` (filter): add or change route templates. The array is keyed by ID, and each template has `group`, `name`, `description`, `mode` (`allowlist` or `blocklist`), `namespace`, and `rules`.
* `key_warden_user_allowed` (filter): `( bool $allowed, string $type, WP_User $user )`, where `$type` is `create` or `auth`. Override who is allowed.
* `key_warden_notify_email` (filter): `( string $to, WP_User $user, array $item )`. Change who gets new-password alerts.
* `key_warden_admin_capability` (filter): the capability needed to use the admin screen and the MCP abilities. Defaults to `manage_options`.
* `key_warden_register_abilities` (filter): return `false` to skip registering the MCP abilities.

== Screenshots ==

1. Every application password on the site, in one list.
2. Choose who can create and log in with application passwords.
3. Route profiles and starter templates.
4. The profile editor, with the live route tester and site routes.

== Changelog ==

= 1.0.0 =
* First release.
