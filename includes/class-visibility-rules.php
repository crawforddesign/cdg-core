<?php
/**
 * Visibility Rules
 *
 * Rule-based indirection for the Sidebar tab's hide controls.
 *
 * Prior to 1.10.0, every hide field on the Sidebar tab (sidebar entry,
 * submenu item, custom menu link, plugin visibility) stored a raw list of
 * role slugs like `['administrator', 'cdg_client_manager']`. That meant a
 * hide could only ever target roles, and reusing "hide these five things
 * from Managers" required repeating the role slug in five places.
 *
 * Now those fields store rule IDs instead, and rules are named groups of
 * targets — a target is either a role slug OR a specific user ID. The
 * runtime resolver in CDG_Core_Plugin_Visibility asks each rule "does this
 * describe the current user?" via matches_current_user() below.
 *
 * The CDG staff ("Agency") account still bypasses every rule
 * unconditionally — that check lives in the runtime callers, above this
 * class, since Agency status is a plugin-wide policy, not a rule condition.
 *
 * @package CDG_Core
 * @since 1.10.0
 */

declare(strict_types=1);

class CDG_Core_Visibility_Rules
{
    /**
     * Rule ID length. IDs are lowercase hex minted with random_bytes(), same
     * pattern used by custom_menu_links.
     */
    private const ID_LENGTH = 8;

    /**
     * Maximum number of rules stored per site. Not a hard limit anyone will
     * hit in practice, but keeps a malformed submission from ballooning the
     * option row.
     */
    private const MAX_RULES = 50;

    /**
     * Return every configured rule, indexed by ID. Rules without an ID are
     * dropped defensively — the sanitizer mints one for anything that's
     * missing on save, so this only matters for out-of-band writes.
     *
     * @return array<string, array{id:string,name:string,roles:string[],users:int[]}>
     */
    public static function get_rules(CDG_Core $plugin): array
    {
        $raw = (array) $plugin->get_setting('visibility_rules');

        $rules = [];
        foreach ($raw as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $id = (string) ($rule['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $rules[$id] = [
                'id'    => $id,
                'name'  => (string) ($rule['name']  ?? ''),
                'roles' => array_values(array_filter(array_map('strval', (array) ($rule['roles'] ?? [])))),
                'users' => array_values(array_filter(array_map('intval', (array) ($rule['users'] ?? [])))),
            ];
        }
        return $rules;
    }

    /**
     * IDs of every currently configured rule. Used to validate the "Hidden
     * For Rules" selections on other Sidebar-tab fields.
     *
     * @return string[]
     */
    public static function get_rule_ids(CDG_Core $plugin): array
    {
        return array_keys(self::get_rules($plugin));
    }

    /**
     * True if any of the given rule IDs matches the currently signed-in
     * user. A rule matches when the user has one of the rule's target roles
     * OR their user ID is explicitly listed. Missing rule IDs (e.g. a rule
     * was deleted after being referenced somewhere) are silently ignored.
     *
     * The Agency bypass is NOT applied here — callers apply it separately
     * before invoking this, because Agency status is a plugin-wide policy
     * that shouldn't be conflated with per-rule matching. See
     * CDG_Core_Plugin_Visibility for the actual bypass call sites.
     *
     * @param string[] $rule_ids
     */
    public static function matches_current_user(array $rule_ids, CDG_Core $plugin): bool
    {
        if (empty($rule_ids)) {
            return false;
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        $rules      = self::get_rules($plugin);
        $user_id    = (int) $user->ID;
        $user_roles = (array) $user->roles;

        foreach ($rule_ids as $rid) {
            $rule = $rules[$rid] ?? null;
            if (!$rule) {
                continue;
            }
            if (in_array($user_id, $rule['users'], true)) {
                return true;
            }
            if (!empty($rule['roles']) && array_intersect($user_roles, $rule['roles'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize a submitted visibility_rules payload from the Sidebar tab.
     * Rules with an empty name are dropped. Every kept rule has a stable
     * 8-hex ID (preserved if valid, minted otherwise). Roles are intersected
     * with the site's currently registered role slugs; users are intersected
     * with the IDs of accounts that actually exist. A rule with neither a
     * role nor a user selected is kept — it just never matches anyone until
     * a target is added.
     *
     * @param mixed $input Raw $_POST['visibility_rules']
     * @return array<int, array{id:string,name:string,roles:string[],users:int[]}>
     */
    public static function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $valid_roles = array_keys(wp_roles()->get_names());
        $out = [];
        $seen_ids = [];

        foreach ($input as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $name = sanitize_text_field(wp_unslash((string) ($rule['name'] ?? '')));
            if ($name === '') {
                continue;
            }

            $raw_id = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($rule['id'] ?? '')));
            if (strlen($raw_id) !== self::ID_LENGTH || isset($seen_ids[$raw_id])) {
                $raw_id = self::mint_id($seen_ids);
            }
            $seen_ids[$raw_id] = true;

            $roles = array_values(array_intersect(
                array_map('sanitize_key', (array) ($rule['roles'] ?? [])),
                $valid_roles
            ));

            $user_ids = array_filter(array_map('intval', (array) ($rule['users'] ?? [])), fn($id) => $id > 0);
            $users    = !empty($user_ids) ? self::filter_existing_users($user_ids) : [];

            $out[] = [
                'id'    => $raw_id,
                'name'  => $name,
                'roles' => $roles,
                'users' => $users,
            ];

            if (count($out) >= self::MAX_RULES) {
                break;
            }
        }

        return $out;
    }

    /**
     * Given a submitted list of rule IDs (from a "Hidden For Rules" field),
     * return only those that reference an existing rule. Used everywhere the
     * Sidebar tab used to intersect against role slugs.
     *
     * @param mixed    $submitted   Raw value from $_POST for one Hidden For Rules field.
     * @param string[] $valid_rule_ids  Output of get_rule_ids(); passed in so
     *                                  callers avoid loading the settings
     *                                  multiple times inside a single tab save.
     * @return string[]
     */
    public static function filter_rule_ids(mixed $submitted, array $valid_rule_ids): array
    {
        if (!is_array($submitted)) {
            return [];
        }
        $ids = array_map(
            fn($v) => preg_replace('/[^a-f0-9]/', '', strtolower((string) $v)),
            $submitted
        );
        return array_values(array_intersect($ids, $valid_rule_ids));
    }

    /**
     * Return the 8-hex IDs of users that actually exist. Filters the
     * submitted list without loading full WP_User objects (the users table
     * lookup is single-column and small either way).
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function filter_existing_users(array $ids): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $found = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE ID IN ({$placeholders})",
                ...$ids
            )
        );
        return array_map('intval', (array) $found);
    }

    /**
     * Generate a fresh 8-hex ID that doesn't collide with anything already
     * emitted in the current sanitize() pass.
     *
     * @param array<string,bool> $seen
     */
    private static function mint_id(array $seen): string
    {
        do {
            $id = substr(bin2hex(random_bytes(4)), 0, self::ID_LENGTH);
        } while (isset($seen[$id]));
        return $id;
    }
}
