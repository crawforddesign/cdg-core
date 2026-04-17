# CDG Core - Must-Use Plugin

WordPress optimizations, security hardening, and agency features for Crawford Design Group client sites.

## Version 1.2.1

### Installation

1. Upload `cdg-core/` folder to `/wp-content/mu-plugins/`
2. Upload `cdg-core.php` to `/wp-content/mu-plugins/`
3. Visit **Settings → CDG Core** to configure

### Features

- WordPress head cleanup & emoji removal
- Security hardening (XML-RPC, uploads, headers)
- **SVG upload support**
- Performance optimizations (Gutenberg, queries, images)
- Gravity Forms / Divi compatibility fixes
- Documentation system for editors
- CPT Dashboard widgets
- **Disable Comments** (v1.2.0)
- **Hide Divi Projects** (v1.2.0)
- **Rename Divi Projects** (v1.2.0)
- Post type renaming
- Admin branding & custom CSS

### Settings Tabs

| Tab | Description |
|-----|-------------|
| **Features** | Documentation system, CPT widgets |
| **Defaults** | Comments, Divi Projects, Post renaming |
| **WordPress Cleanup** | Head cleanup, dashboard widgets, heartbeat |
| **Security** | XML-RPC, uploads, X-Powered-By, SVG support |
| **Performance** | Gutenberg, queries, images, revisions |
| **Gravity Forms** | Divi/GF compatibility fixes |
| **Admin** | Branding, custom CSS |

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

The **Defaults** tab contains settings for modifying WordPress and Divi default behavior:

#### Disable Comments
Completely disables WordPress comments:
- Removes comment support from all post types
- Hides Comments menu from admin
- Hides Discussion settings page
- Blocks access to comment admin pages
- Disables comment REST API endpoints
- Disables comment feeds
- Removes pingback headers

#### Hide Divi Projects
Fully disables Divi's built-in Projects post type:
- Unregisters the `project` post type
- Removes Project Categories taxonomy
- Removes Project Tags taxonomy
- Redirects any direct access to project admin pages

#### Rename Divi Projects
Customize the Projects post type labels (only when not hidden):
- Plural name (e.g., "Portfolio")
- Singular name (e.g., "Portfolio Item")
- Menu name
- Menu icon (dashicon)

#### Rename Posts
Customize the default Posts post type labels:
- Plural name (e.g., "Slides")
- Singular name (e.g., "Slide")
- Menu name
- Menu icon (dashicon)

### SVG Upload Support

CDG Core includes SVG upload support. When enabled:

- SVG and SVGZ files can be uploaded through the Media Library
- SVG previews display correctly in the Media Library
- Dimensions are automatically detected from SVG viewBox/width/height

**Settings:**
- **Enable SVG Uploads**: Allow SVG file uploads (disabled by default)
- **Restrict to Admins**: Only allow administrators to upload SVGs (enabled by default)

Find these settings under **Settings → CDG Core → Security**.

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

Alternatively, add to `wp-config.php`:

```php
define('WP_POST_REVISIONS', 5);
```

Note: The CDG Core setting overrides the wp-config.php constant.

### Changelog

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
