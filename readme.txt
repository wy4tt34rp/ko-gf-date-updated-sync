=== KO – GF Date Updated Sync (Gravity Flow) ===
Contributors: ko
Tags: gravityforms, gravityflow, gravityview, workflow, exports
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
This plugin keeps Gravity Forms' entry "Date Updated" (the `date_updated` column) in sync with Gravity Flow workflow activity.

Gravity Flow can advance workflow steps and record activity without updating Gravity Forms' `date_updated`. GravityView typically displays the Gravity Forms `date_updated`, and export/automation tools often rely on "updated since last run". If `date_updated` doesn't change, exports can miss entries that were approved after they were created.

This plugin ensures `date_updated` changes when the workflow changes.

== How it works ==
1. **Live Sync (automatic)**
   * Hooks into `gravityflow_step_complete`.
   * Updates the Gravity Forms entry's `date_updated` to the current UTC time.
   * Only writes when the value would actually change.

2. **Backfill Tool (manual)**
   * Adds a Tools page: **Tools → GF Date Updated Backfill**.
   * Lets you sync older entries by setting `date_updated` to the latest Gravity Flow activity timestamp:
     * Reads the Gravity Flow activity table: `{wp_prefix}gravityflow_activity_log`
     * Uses: `MAX(date_created)` where `lead_id = entry_id`
   * Run in batches to avoid timeouts.

3. **Debug logging (wp-content/debug.log)**
   * If `WP_DEBUG_LOG` is enabled, the plugin logs each successful change to `wp-content/debug.log`.
   * Log prefix: `[KO-GFDU]`
   * Logged fields: source (live_step/backfill/single_test), entry_id, old/new values, step_id (if available), and user_id.
   * Only logs when a value actually changes (no spam on no-op updates).

== Installation ==
1. Upload the plugin ZIP: Plugins → Add New → Upload Plugin.
2. Activate **KO – GF Date Updated Sync (Gravity Flow)**.
3. (Optional) Enable debug logging in wp-config.php:
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);

== Usage ==
* Live sync works immediately after activation.
* To backfill existing entries:
  1. Go to Tools → GF Date Updated Backfill
  2. Run a **Single Entry Test** first (e.g. Entry ID 634) and check "Apply update".
  3. Use **Batch Backfill** in batches (200–500 is typical).

== Compatibility ==
* Works with both Gravity Forms table layouts:
  * New table structure: `{wp_prefix}gf_entry`
  * Legacy table structure: `{wp_prefix}rg_lead`
  The plugin auto-detects which exists and uses it.
* Not tied to a specific database prefix. It uses WordPress' `$wpdb->prefix`.
* Assumes Gravity Flow uses the standard activity table `{wp_prefix}gravityflow_activity_log`.

== FAQ ==
= Is the plugin tied directly to the specific tables we tested on your site? Will it work on the sister (Mack) site? =
It will work on the sister site. The plugin is not hardcoded to your site's table prefix (like `t80yxi_`). It uses `$wpdb->prefix` at runtime and auto-detects whether the site uses Gravity Forms' modern `gf_entry` table or legacy `rg_lead` table.

As long as the Mack site uses Gravity Forms + Gravity Flow and has the standard Gravity Flow activity log table (`{prefix}gravityflow_activity_log`), it should work without changes.

== Changelog ==

= 1.5.4 =
* Add Gravity Flow completion hooks so date_updated updates on final completion.
* Add ForGravity Entry Automation compatibility to ensure since_last_run uses date_updated (UTC window).

= 1.5.0 =
* Production-ready release:
  * Live workflow-step sync for `date_updated`
  * Backfill tools (single test + batch)
  * Auto-detect Gravity Forms table layout
  * Optional debug.log logging
