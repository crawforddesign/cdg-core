# CDG Core

WordPress optimizations, security hardening, and agency features for Crawford Design Group client sites.

## Version 1.7.0

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
- **Plugin Visibility** - hide plugins from non-administrator users
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
| **Defaults**      | Comments, Divi Projects                                  |
| **WP Cleanup**    | Head cleanup, dashboard widgets, heartbeat               |
| **Security**      | XML-RPC, uploads, X-Powered-By, SVG/Font/Lottie support  |
| **Performance**   | Gutenberg, queries, images, revisions                    |
| **Gravity Forms** | Divi/GF compatibility fixes and auto-page generation     |
| **Admin**         | Branding, theme color, custom CSS                        |
| **Plugins**       | Hide plugins from non-administrator users                |

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
- **Code editor restrictions**

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

### Plugins Tab

The Plugins tab lets you select specific plugins to hide from the WordPress Plugins page for all non-administrator users. Administrators always see every plugin. Plugins remain active - they are only hidden from the list view.

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

### Changelog

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
