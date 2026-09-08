# CDG Core

WordPress optimizations, security hardening, and agency features for Crawford Design Group client sites.

## Version 1.9.10

### Requirements

- WordPress 6.0+
- PHP 8.0+
- Divi 4.0+
- SpinupWP hosting (recommended)

### Installation

1. Upload the `cdg-core/` folder to `/wp-content/plugins/`
2. Activate **CDG Core** from the Plugins page
3. Visit **Settings > CDG Core** to configure

### Features

- WordPress head cleanup & emoji removal
- Security hardening (XML-RPC, uploads, headers)
- **SVG upload support** with admin-only restriction
- **Font upload support** (OTF, TTF, WOFF, WOFF2) with admin-only restriction
- **Lottie/JSON upload support** with admin-only restriction
- Performance optimizations (Gutenberg, queries, images)
- Gravity Forms / Divi compatibility fixes and auto-page generation
- Documentation system for editors
- CPT Dashboard widgets
- **Disable Comments** (full system disable)
- **Hide Divi Projects**
- **Rename "Posts"** - rebrand the built-in Post type's labels, sidebar menu, and icon
- **Expired transient cleanup** - daily cron removes stale transients to reduce database bloat
- **Plugin Visibility** - hide specific plugins from the Plugins page per role (native WordPress roles included, not just Manager/Staff); Agency always sees every plugin
- **Custom Roles** - opt-in Agency / Manager / Staff roles
- **Sidebar Menu Management** - rename/hide sidebar items and submenus per role
- Admin branding & default admin CSS

### File Structure

```
plugins/
+-- cdg-core/
    +-- cdg-core.php                  <- Main plugin file
    +-- README.md
    +-- includes/
    |   +-- class-admin.php           <- Admin UI & settings
    |   +-- class-cleanup.php         <- WordPress head cleanup
    |   +-- class-cpt-dashboard.php   <- CPT dashboard widgets
    |   +-- class-defaults.php        <- Comments & Divi defaults
    |   +-- class-documentation.php   <- Documentation CPT
    |   +-- class-font-support.php    <- Font upload support
    |   +-- class-gf-auto-page.php    <- GF auto page generation
    |   +-- class-gravity-forms.php   <- GF/Divi compatibility
    |   +-- class-lottie-support.php  <- Lottie upload support
    |   +-- class-performance.php     <- Performance optimizations
    |   +-- class-plugin-visibility.php <- Plugin visibility control
    |   +-- class-security.php        <- Security hardening
    |   +-- class-svg-support.php     <- SVG upload support
    |   +-- plugin-update-checker/    <- Vendored update checker (GitHub Releases)
    +-- admin/
        +-- js/
        |   +-- admin-script.js
        |   +-- gf-auto-page.js       <- GF auto-page JS
        +-- css/
            +-- admin-style.css
            +-- gf-auto-page.css
```

### Settings Tabs

| Tab               | Description                                              |
| ----------------- | -------------------------------------------------------- |
| **Features**      | Documentation system, CPT widgets                        |
| **Defaults**      | Comments, Divi Projects, Post renaming                   |
| **WP Cleanup**    | Head cleanup, dashboard widgets, heartbeat               |
| **Security**      | XML-RPC, uploads, X-Powered-By, SVG/Font/Lottie support  |
| **Performance**   | Gutenberg, queries, images, revisions, transient cleanup |
| **Gravity Forms** | Divi/GF compatibility fixes and auto-page generation     |
| **Admin**         | Branding, theme color, custom CSS                        |
| **Roles**         | Custom Agency / Manager / Staff roles; Agency auto-assigned by email |
| **Sidebar**       | Rename/hide sidebar menu items and submenus per role, plus per-role Plugin Visibility |

### SpinupWP Compatibility

CDG Core is designed to work alongside SpinupWP hosting. The following security headers are handled by SpinupWP at the Nginx level and are **not** duplicated by this plugin:

- **Strict-Transport-Security (HSTS)**
- **X-XSS-Protection**
- **X-Frame-Options**
- **X-Content-Type-Options**

CDG Core complements SpinupWP by handling:

- **X-Powered-By removal** (not handled by SpinupWP defaults)
- **XML-RPC disabling**
- **Dangerous file upload blocking**
- **Code editor restrictions** (classic editor code view, plus the Theme/Plugin File Editor screens for every role)

### Defaults Tab

#### Disable Comments

Completely disables WordPress comments:

- Removes comment support from all post types
- Hides Comments menu from admin
- Hides Discussion settings page
- Blocks access to comment admin pages
- Disables comment REST API endpoints
- Disables comment feeds (301 redirect to home)
- Removes pingback headers

#### Hide Divi Projects

Fully disables Divi's built-in Projects post type:

- Unregisters the `project` post type
- Removes Project Categories taxonomy
- Removes Project Tags taxonomy
- Redirects any direct access to project admin pages

#### Rename "Posts"

Rebrands the built-in Post type across wp-admin — labels, sidebar menu entry, and menu icon. Purely cosmetic: the post type's slug, rewrite rules, and permalinks are untouched.

- **Singular / Plural labels** — drive "Add New," "Edit," "All …," search/trash messages, and the admin bar's "+ New" dropdown
- **Menu icon** — replaces the default icon in the sidebar, picked from the same dashicon picker used by Custom Menu Links
- Disabled by default

Note: a handful of deeply hardcoded WordPress core strings (the Dashboard "At a Glance" widget's post counts, "Recently Published") read literal `_n( 'Post', 'Posts', ... )` calls rather than the post type's label object, and may still say "Post(s)" even with this enabled.

### Security Tab

#### SVG Upload Support

When enabled, SVG and SVGZ files can be uploaded through the Media Library with preview support and automatic dimension detection.

- **Enable SVG Uploads**: Disabled by default
- **Restrict to Admins**: Enabled by default

#### Font Upload Support

When enabled, custom font files can be uploaded through the Media Library for use with Divi or custom CSS `@font-face` declarations.

Supported formats: OTF, TTF, WOFF, WOFF2

- **Enable Font Uploads**: Disabled by default
- **Restrict to Admins**: Enabled by default

#### Lottie Upload Support

When enabled, Lottie animation files can be uploaded through the Media Library for use with Divi or animation libraries.

Supported formats: .json, .lottie

- **Enable Lottie Uploads**: Disabled by default
- **Restrict to Admins**: Enabled by default

### Gravity Forms Tab

#### Divi Compatibility Fixes

Prevents Divi from deferring Gravity Forms scripts on pages with forms, fixing "gf_global is not defined" errors. Supports auto-detect and manual page slug modes.

#### Auto-Page Generation

When creating a new Gravity Forms form, an optional checkbox in the form creation flyout will automatically generate a draft `cdg_form` custom post type page pre-loaded with a Divi 5 GF Styler module pointed at the new form. A "View Form Page" button is injected next to the Save Form button in the form editor.

### Plugin Visibility (Sidebar Tab)

The Sidebar tab's Plugin Visibility card lets you hide specific plugins from the WordPress Plugins page per role — Administrator, Manager, Staff, or any other native role, not just non-administrators. Agency always sees every plugin regardless of what's checked. Plugins remain active - they are only hidden from the list view.

### Heartbeat Control

Control WordPress heartbeat API behavior:

- **Admin**: Set interval (60s recommended) or disable
- **Frontend**: Set interval or disable (disabled recommended)
- **Exception**: Divi Visual Builder (heartbeat enabled when builder is active)

### Post Revisions

Control how many revisions WordPress keeps:

- **Unlimited**: WordPress default behavior
- **Disabled**: No revisions saved
- **Limited**: Specify a number (e.g., 5 revisions per post)

Note: The CDG Core setting overrides any `WP_POST_REVISIONS` constant in `wp-config.php`.

### Database Cleanup

Daily cron removes transients whose expiration has already passed, using WordPress's own `delete_expired_transients()`. Only ever touches rows WordPress itself already considers dead, so it's safe alongside Divi's own caching. Enabled by default.

### Admin Branding

- Custom admin footer text with CDG branding
- CDG Core version and WordPress version in footer
- Default admin CSS for polished admin UI (rounded corners, consistent borders, CDG accent color)
- Custom admin CSS field for per-site overrides

### Deployment

CDG Core is deployed from GitHub using a shell script. See `CDG-Core-Deployment-Guide.md` for the full workflow.

```bash
# Deploy to all servers
GITHUB_TOKEN="your_token" ./deploy-cdg-core.sh all

# Deploy to production only
GITHUB_TOKEN="your_token" ./deploy-cdg-core.sh anchorage

# Deploy to development only
GITHUB_TOKEN="your_token" ./deploy-cdg-core.sh development
```

### Updating an Installed Site (wp-admin)

As of 1.9.3, CDG Core ships with [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (vendored at `includes/plugin-update-checker/`), pointed at this repo's GitHub Releases. This is what makes "Update available" and the native "Update Now" button show up on a client site's Plugins page — sites no longer need the manual "re-upload the zip and replace" flow, which could throw a critical error on swap.

**Cutting a release:**

1. Bump the `Version:` header in `cdg-core.php` (and `CDG_CORE_VERSION`) to the new version number.
2. On GitHub, draft a new Release from `main` and tag it (e.g. `v1.9.9`).
3. Publish it with a title and changelog. No zip needs to be built or attached.

No manual zip step is required: when a release has no matching zip asset, the vendored Plugin Update Checker (`Puc\v5p7\Vcs\GitHubApi`, see `downloadUrl` fallback in `includes/plugin-update-checker/Puc/v5p7/Vcs/GitHubApi.php`) falls back to GitHub's own auto-generated source zip for the tag (`zipball_url`) — a valid single-folder archive that WordPress's plugin upgrader installs correctly given the repo root already matches the plugin's file layout (see the 1.9.0 restructure below). Attaching a named zip asset (e.g. `cdgcore.zip`) still works if one is ever needed — a matching asset takes priority over the zipball fallback — it's just no longer a required step.

Installed sites will see the update within ~12 hours (WordPress's normal update-check cadence), or immediately if an admin clicks "Check again" on the Updates screen. From there it's a normal one-click "Update Now" — no deactivate/reactivate workaround needed.

Auto-updates are not enabled by default. If you want a given site to apply releases unattended, an admin can turn on "Enable auto-updates" for CDG Core from that site's Plugins page — this uses WordPress's own fatal-error-protected update path.

### Changelog

#### 1.9.12

- Fixed the Documentation **Categories** link, still missing from Tools after 1.9.11's fix. That fix set the taxonomy's own `show_in_menu` to `'tools.php'`, but WordPress core never actually reads that value in this configuration: the only place a taxonomy's `show_in_menu` gets turned into a submenu link is a loop in `wp-admin/menu.php` that runs solely for post types with their own top-level menu (`show_in_menu === true`, checked strictly) — and post types nested under an existing menu (like Documentation under Tools) skip that loop entirely. Unlike post types, which get a dedicated fallback (`_add_post_type_submenus()` in `wp-includes/post.php`) for exactly this nested case, taxonomies have no such fallback in core. Categories is now added the same way View Documentation already is: a manual `add_submenu_page( 'tools.php', ... )` call pointed at the real `edit-tags.php` screen.

#### 1.9.11

- Added a **Tools → Autoloaded Options** page: lists the site's autoloaded `wp_options` rows by size (mirroring Site Health's own "Autoloaded options could affect performance" check, including its 800 KB threshold), and lets an admin disable autoload per option with one click. Only the `autoload` column is ever touched — the option's value is never read back or rewritten. A hard-coded list of WordPress-core and CDG Core options (enforced server-side, not just hidden in the UI) can't be disabled from this screen, and every change is logged with a one-click Undo.
- Fixed a menu regression from 1.9.10's Tools nesting: both the Documentation taxonomy's **Categories** screen and the **View Documentation** page were still registering their submenu under the post type's own slug (`edit.php?post_type=cdg_documentation`), which only renders as a real menu entry when a post type has its own top-level menu. Once the post type moved under Tools, that slug stopped being a valid menu parent and WordPress silently dropped both links from the sidebar — the pages still existed and were reachable by direct URL, just invisible in the nav. Both now point at `tools.php` directly, same as the post type itself.

#### 1.9.10

- Documentation CPT now lives under **Tools → Documentation** instead of its own top-level sidebar menu (`register_post_type()`'s `show_in_menu` set to `'tools.php'`). Its own sub-tabs (viewer pages, categories) are unaffected — they're registered against the post type's own `edit.php?post_type=cdg_documentation` slug regardless of where that slug sits in the menu tree. `menu_position`/`menu_icon`, which only apply to top-level items, were dropped from the registration since they no longer do anything. Sites that used the Sidebar tab to rename/hide Documentation as a top-level entry will need to reconfigure that under Tools's submenu section.

#### 1.9.9

- Added a "Rename 'Posts'" feature (Defaults tab, off by default): rebrands the built-in Post type's labels, sidebar menu entry, and icon across wp-admin without touching its slug, rewrite rules, or permalinks. Uses `register_post_type_args` for the label set (all ~28 keys, since WordPress core ships every one explicitly for `post` — none are left for auto-fill to catch) and a direct `admin_menu` rewrite for the sidebar entry itself, which WordPress builds from hardcoded strings rather than the post type's labels. Note: this same feature previously shipped in 1.2.0 and was explicitly removed in 1.3.0; no reason for that removal is recorded in the changelog or commit history.
- Added scheduled cleanup of expired transients (Performance tab, on by default): a daily cron calling WordPress's own `delete_expired_transients()` to reduce database bloat, scoped so it only ever touches rows already past their own expiration.
- "Disable Code Editor" (Security tab) now also blocks the Theme Editor and Plugin Editor screens entirely, for every role — previously it only hid the classic editor's raw-HTML code view, despite its description already claiming to cover the file editor screens.

#### 1.9.7

- Sidebar tab: Sidebar Menu Items gained search, a "Customized only" filter, and expand/collapse-all — the list runs 30–50+ rows once submenus are counted and had no way to jump to one. Fixed the grid's missing responsive breakpoint (no fallback below 782px — it just squeezed) with a horizontal-scroll safety net, now also applied to Plugin Visibility. Plugin Visibility gained per-role select-all column headers and now covers the full role set (Administrator, Editor, Author, Contributor, Subscriber, Manager, Staff); its Editor/Author/Contributor/Subscriber columns hide automatically when Roles &rsaquo; "Hide Default WordPress Roles" is on, since those roles can't be newly assigned anyway — saved state for them isn't lost, the checkboxes stay in the form, just visually hidden. Custom Menu Links now collapse to a one-line summary instead of staying permanently expanded.
- Roles tab: split the single "Custom Roles" card into five — Custom Roles (with an Active/Off status pill), a new Role Capabilities comparison table (Administrator vs. Manager vs. Staff, derived from `CDG_Core_Roles::MANAGER_BLOCKLIST`), Agency Access, Role Visibility, and a visually distinct amber "Maintenance" card for Rebuild Roles.

#### 1.9.6

- Removed the custom "Agency" role (`cdg_agency`, a clone of Administrator). It caused a real-world bug: a third-party plugin (Gravity Forms) gated its own admin menu on the literal `administrator` role rather than a capability, so an Agency account — despite holding every one of Administrator's capabilities — never saw that menu item. The Agency Email setting now switches the matching account to WordPress's native Administrator role directly instead of a lookalike clone, which has no such gap. That account continues to bypass every Sidebar tab hide rule (Sidebar Menu Items, Custom Menu Links, Plugin Visibility) unconditionally, now matched by email instead of role (`CDG_Core_Roles::is_agency_user()`). Sites with users still on the old `cdg_agency` role are migrated to Administrator automatically on upgrade, and the retired role is removed. Manager and Staff are unaffected.
- The Roles tab's "Rebuild Roles" button (introduced in 1.9.5) now only re-clones Manager and Staff, since Agency is no longer a cloned role.

#### 1.9.5

- Added a "Rebuild Roles" button to the Roles tab (Custom Roles card). Agency/Manager/Staff are normally only (re)created when missing, so a role created before another plugin (e.g. Gravity Forms) added its own capabilities to Administrator won't pick those up on its own — this forces a re-clone from the site's current live Administrator/Editor capabilities.

#### 1.9.4

- Removed the "Howdy," greeting from the admin bar account menu (`CDG_Core_Cleanup::remove_howdy()`), leaving just the username. Small test change to exercise the new GitHub-release update flow end to end.

#### 1.9.3

- Added GitHub-based automatic updates: vendored [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (`includes/plugin-update-checker/`), pointed at this repo's Releases. Client sites now get a native "Update available" / "Update Now" prompt on the Plugins page instead of requiring a manual zip re-upload. See "Updating an Installed Site" below for the release process. Auto-updates are off by default.
- Hardened `cdg-core.php` against being parsed twice in a single request (unguarded class/constant/function declarations could fatal — "critical error" — if the plugin's files were swapped mid-request during a manual update, requiring a deactivate/reactivate to recover). All top-level declarations are now wrapped in `class_exists()` / `function_exists()` guards.

#### 1.9.2

- Agency is no longer manually assignable from the Add User / Edit User / Bulk Edit role dropdowns, regardless of the "Hide Default WordPress Roles" toggle. Instead, added an **Agency Email** setting (Roles tab, default `support@crawforddesigngp.com`) — the account holding that email is automatically switched to Agency, replacing whatever role it had, on login, account creation, and profile edits.
- "Hide Default WordPress Roles" now hides Editor, Author, Contributor, and Subscriber only; Administrator always stays selectable in those dropdowns.
- Sidebar Menu Items and Custom Menu Links can now also be hidden from **Administrator**, not just Manager/Staff (`CDG_Core_Roles::target_roles()` gained a third entry).
- Removed the Sidebar tab's "Menu Order" card (per-role drag-and-drop sidebar reordering) and its underlying `sidebar_menu_order` setting entirely.

#### 1.9.1

- Restored Plugin Visibility (removed during the 1.9.0 sidebar restructure, originally added in 1.6.5) as a new card in the Sidebar tab: hide specific installed plugins from the Plugins page per role. Targetable roles now include the native WordPress roles (Administrator, Editor, Author, Contributor, Subscriber) in addition to Manager/Staff, so a plugin can be hidden from a client even on sites that never enable custom roles. Agency always bypasses this and sees every plugin.

#### 1.9.0

- Added Roles tab: opt-in **Agency / Manager / Staff** custom roles (Agency = full Administrator clone for CDG staff; Manager = Administrator minus plugin/theme installs, user management, and core updates; Staff = clone of Editor). Includes an option to hide native WordPress roles from the role-assignment dropdowns.
- Sidebar tab reworked to target the new **Manager** and **Staff** roles directly instead of individual users; Agency always sees the full, unmodified sidebar
- Added submenu-level renaming and hiding to the Sidebar tab (previously top-level menu items only)
- Documentation dashboard widgets now support per-category dashboard column placement
- Added one-time migration to clear the legacy default "Custom Admin CSS" value for sites that never customized it, without touching sites that did
- New file: `includes/class-roles.php`
- Repo restructured: plugin files moved from a nested `cdg-core/` subfolder to the repo root; removed duplicate README

#### 1.7.0

- Converted from a must-use plugin to a standard plugin (install to `/wp-content/plugins/`, activate from the Plugins page)
- Merged loader file into the main plugin file (`cdg-core.php`), which now carries a standard plugin header
- Added `register_activation_hook()` / `register_deactivation_hook()` to flush rewrite rules on activation/deactivation
- Updated in-admin guide copy that referenced mu-plugin behavior

#### 1.6.5

- Fixed GravityForms auto-page creation broken by GF 2.10.x admin UI changes (flyout button class renamed from `__footer-primary-button` to `__foot-primary-button`)
- Added Plugin Visibility feature: hide specific plugins from the Plugins page for non-administrator users
- Added Plugins settings tab with alphabetically sorted plugin checklist
- Added `class-plugin-visibility.php`
- Changed settings radio group layout from vertical column to horizontal row
- Changed plugin list to use two-column grid layout

#### 1.3.1

- Added Font upload support (OTF, TTF, WOFF, WOFF2) with admin-only restriction
- Added Lottie/JSON upload support (.json, .lottie) with admin-only restriction
- Added default admin CSS for polished admin UI styling
- Added admin JS toggles for Font and Lottie admin-only options
- New classes: `CDG_Core_Font_Support`, `CDG_Core_Lottie_Support`

#### 1.3.0

- Removed post type renaming feature (Posts rename)
- Removed Divi Projects renaming feature
- Fixed duplicate DNS prefetch removal between Cleanup and Performance classes
- Extracted duplicate `gf_global` data construction into shared private method
- Fixed Documentation component creating duplicate instances during activation
- Fixed redundant type check in `add_lazy_loading()` method
- Fixed leading space in inline style concatenation for aspect-ratio
- Fixed version constant mismatch between loader and main plugin file
- Changed comment feed disable from 403 to 301 redirect for better SEO
- Added plugin activation/deactivation cache invalidation for dashboard widgets
- Added `is_array()` safety check on `get_option()` return in `load_settings()`
- Added proper `esc_html()` escaping to version constant in admin footer
- Cleaned up admin JavaScript (removed rename-related toggle handlers)
- Code cleanup and PHPDoc improvements

#### 1.2.1

- Removed X-Frame-Options header (handled by SpinupWP at Nginx level)
- Removed Gravity Forms heartbeat exception (simplified heartbeat control)
- Moved frontend heartbeat control to `init` hook for more reliable script deregistration
- Updated Security tab description to clarify SpinupWP handles security headers
- Code cleanup and documentation improvements

#### 1.2.0

- Added "Defaults" tab for WordPress/Divi default modifications
- Added Disable Comments feature (full comment system disable)
- Added Hide Divi Projects feature
- Added Rename Divi Projects feature
- Moved Rename Posts from Features tab to Defaults tab
- Consolidated post type modification functionality into new `CDG_Core_Defaults` class

#### 1.1.0

- Added SVG upload support
- Added admin-only restriction option for SVG uploads
- Added SVG preview support in Media Library

#### 1.0.0

- Initial release
