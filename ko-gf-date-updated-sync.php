<?php
/**
 * Plugin Name: KO – GF Date Updated Sync (Gravity Flow)
 * Plugin URI:  https://example.com/
 * Description: Syncs Gravity Forms entry date_updated with Gravity Flow activity so views/exports reflect workflow progress. Includes a backfill tool and optional debug.log logging.
 * Version:     1.5.4
 * Author:      KO
 * License:     GPL-2.0-or-later
 * Text Domain: ko-gfdu
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KO_GF_Date_Updated_Sync {

	const CAPABILITY   = 'manage_options';
	const NONCE_ACTION = 'ko_gf_du_tools';

	/**
	 * Toggle debug.log logging.
	 * Logging writes to wp-content/debug.log when WP_DEBUG_LOG is enabled.
	 */
	const ENABLE_DEBUG_LOG = true;

	public function __construct() {
		add_action( 'gravityflow_step_complete', [ $this, 'on_step_complete' ], 10, 3 );
		// Fires when the workflow completes (covers the final Complete step).
		add_action( 'gravityflow_workflow_complete', [ $this, 'on_workflow_complete' ], 10, 1 );
		// Safety net on some Gravity Flow versions.
		add_action( 'gravityflow_post_process_workflow', [ $this, 'on_post_process_workflow' ], 10, 2 );
		add_action( 'admin_menu', [ $this, 'register_tools_page' ] );
	}

	/**
	 * LIVE SYNC: bump date_updated on every Gravity Flow step completion.
	 * Writes UTC MySQL datetime into the Gravity Forms entry table.
	 */
	public function on_step_complete( $step, $entry, $form ) {
		if ( empty( $entry['id'] ) ) return;

		$step_id = ( is_object( $step ) && method_exists( $step, 'get_id' ) ) ? $step->get_id() : null;

		$this->db_update_gf_date_updated(
			(int) $entry['id'],
			current_time( 'mysql', true ), // UTC
			'live_step',
			$step_id
		);
	}


	/**
	 * LIVE SYNC: bump date_updated when Gravity Flow reports the workflow is complete.
	 * This is important because the final Complete step does not always fire gravityflow_step_complete.
	 */
	public function on_workflow_complete( $entry_id ) {
		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) return;

		$this->db_update_gf_date_updated(
			$entry_id,
			current_time( 'mysql', true ), // UTC
			'live_workflow_complete',
			null
		);
	}

	/**
	 * LIVE SYNC: extra safety net hook fired after workflow processing on some versions.
	 */
	public function on_post_process_workflow( $entry_id, $form = null ) {
		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) return;

		$this->db_update_gf_date_updated(
			$entry_id,
			current_time( 'mysql', true ), // UTC
			'live_post_process_workflow',
			null
		);
	}

	public function register_tools_page() {
		add_management_page(
			'GF Date Updated Backfill',
			'GF Date Updated Backfill',
			self::CAPABILITY,
			'ko-gf-date-updated-backfill',
			[ $this, 'render_tools_page' ]
		);
	}

	public function render_tools_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		global $wpdb;

		$gf_entry_table = $this->get_gf_entry_table();
		$flow_table     = $wpdb->prefix . 'gravityflow_activity_log';

		$diag = [
			'gf_entry_table' => $gf_entry_table,
			'flow_table'     => $flow_table,
			'gf_entry_rows'  => $this->db_scalar_safe_count( $gf_entry_table ),
			'flow_rows'      => $this->db_scalar_safe_count( $flow_table ),
			'wpdb_last_error'=> $wpdb->last_error,
		];

		$ran_backfill = false;
		$backfill_result = null;

		$ran_test = false;
		$test_result = null;

		if ( isset( $_POST['ko_gf_du_run_backfill'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$ran_backfill = true;

			$limit = isset($_POST['limit']) ? absint($_POST['limit']) : 200;
			if ( $limit < 1 ) $limit = 1;
			if ( $limit > 5000 ) $limit = 5000;

			$dry_run = ! empty( $_POST['dry_run'] );

			$backfill_result = $this->backfill( $limit, $dry_run );
		}

		if ( isset( $_POST['ko_gf_du_run_test'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$ran_test = true;

			$entry_id = isset($_POST['test_entry_id']) ? absint($_POST['test_entry_id']) : 0;
			$apply    = ! empty($_POST['test_apply']);

			$test_result = $this->single_entry_test( $entry_id, $apply );
		}

		?>
		<div class="wrap">
			<h1>GF Date Updated Backfill</h1>
			<p>This tool syncs Gravity Forms <code>date_updated</code> to the latest Gravity Flow activity timestamp (<code>MAX(date_created)</code>).</p>

			<h2>Diagnostics</h2>
			<table class="widefat striped" style="max-width:1100px;">
				<tbody>
					<tr><th>GF Entry Table</th><td><code><?php echo esc_html($diag['gf_entry_table']); ?></code></td></tr>
					<tr><th>Gravity Flow Activity Table</th><td><code><?php echo esc_html($diag['flow_table']); ?></code></td></tr>
					<tr><th>GF Entry Row Count</th><td><code><?php echo esc_html($diag['gf_entry_rows']); ?></code></td></tr>
					<tr><th>Flow Activity Row Count</th><td><code><?php echo esc_html($diag['flow_rows']); ?></code></td></tr>
					<tr><th>DB Last Error</th><td><code><?php echo esc_html($diag['wpdb_last_error'] ?: '(none)'); ?></code></td></tr>
				</tbody>
			</table>

			<hr />

			<h2>Single Entry Test</h2>
			<p>Use this on a known entry (e.g., <code>634</code>) to see what Flow timestamp we detect, and optionally apply it.</p>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation" style="max-width:800px;">
					<tr>
						<th scope="row"><label for="test_entry_id">Entry ID</label></th>
						<td><input name="test_entry_id" id="test_entry_id" type="number" min="1" value="<?php echo isset($_POST['test_entry_id']) ? esc_attr((int)$_POST['test_entry_id']) : 634; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="test_apply">Apply update</label></th>
						<td>
							<label>
								<input type="checkbox" name="test_apply" id="test_apply" <?php checked( ! empty($_POST['test_apply']) ); ?> />
								Update GF <code>date_updated</code> for this entry
							</label>
						</td>
					</tr>
				</table>
				<p>
					<button class="button button-secondary" name="ko_gf_du_run_test" value="1">Run Single Entry Test</button>
				</p>
			</form>

			<?php if ( $ran_test ) : ?>
				<h3>Single Entry Result</h3>
				<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1100px;overflow:auto;"><?php
					echo esc_html( print_r( $test_result, true ) );
				?></pre>
			<?php endif; ?>

			<hr />

			<h2>Batch Backfill</h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation" style="max-width:800px;">
					<tr>
						<th scope="row"><label for="limit">Max entries this run</label></th>
						<td><input name="limit" id="limit" type="number" value="<?php echo isset($_POST['limit']) ? esc_attr((int)$_POST['limit']) : 200; ?>" min="1" max="5000" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="dry_run">Dry run</label></th>
						<td>
							<label>
								<input name="dry_run" id="dry_run" type="checkbox" <?php checked( ! isset($_POST['ko_gf_du_run_backfill']) || ! empty($_POST['dry_run']) ); ?> />
								Don’t write changes; just report what would change
							</label>
						</td>
					</tr>
				</table>

				<p>
					<button class="button button-primary" name="ko_gf_du_run_backfill" value="1">Run Backfill</button>
				</p>
			</form>

			<?php if ( $ran_backfill ) : ?>
				<h3>Backfill Result</h3>
				<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1100px;overflow:auto;"><?php
					echo esc_html( print_r( $backfill_result, true ) );
				?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prefer new Gravity Forms tables if present (gf_entry). Fallback to legacy rg_lead.
	 */
	private function get_gf_entry_table() {
		global $wpdb;

		$new_table    = $wpdb->prefix . 'gf_entry';
		$legacy_table = $wpdb->prefix . 'rg_lead';

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $new_table ) );
		if ( $exists ) return $new_table;

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $legacy_table ) );
		if ( $exists ) return $legacy_table;

		return $new_table;
	}

	private function db_scalar_safe_count( $table ) {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( ! $exists ) return '(missing table)';
		$val = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		return is_null( $val ) ? '(null)' : $val;
	}

	private function single_entry_test( $entry_id, $apply ) {
		global $wpdb;

		$out = [
			'entry_id' => $entry_id,
			'apply'    => (bool) $apply,
		];

		if ( ! $entry_id ) {
			$out['error'] = 'No entry id provided.';
			return $out;
		}

		$gf_table   = $this->get_gf_entry_table();
		$flow_table = $wpdb->prefix . 'gravityflow_activity_log';

		$out['tables'] = [
			'gf'   => $gf_table,
			'flow' => $flow_table,
		];

		$gf_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, date_created, date_updated FROM {$gf_table} WHERE id = %d",
			$entry_id
		), ARRAY_A );

		$out['gf_before'] = $gf_row ?: '(not found)';

		$latest = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(date_created) FROM {$flow_table} WHERE lead_id = %d",
			$entry_id
		) );

		$out['flow_max_date_created'] = $latest ?: '(none)';
		$out['wpdb_last_error'] = $wpdb->last_error ?: '(none)';
		$out['wpdb_last_query'] = $wpdb->last_query ?: '(none)';

		if ( $apply && $latest ) {
			$ts = strtotime( $latest );
			if ( $ts ) {
				$new_utc = gmdate( 'Y-m-d H:i:s', $ts );
				$out['attempt_set_date_updated_to'] = $new_utc;

				$ok = $this->db_update_gf_date_updated( $entry_id, $new_utc, 'single_test', null );
				$out['db_update_ok'] = $ok ? 1 : 0;

				$gf_row2 = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, date_updated FROM {$gf_table} WHERE id = %d",
					$entry_id
				), ARRAY_A );

				$out['gf_after'] = $gf_row2 ?: '(not found)';
				$out['wpdb_last_error_after'] = $wpdb->last_error ?: '(none)';
				$out['wpdb_last_query_after'] = $wpdb->last_query ?: '(none)';
			}
		}

		return $out;
	}

	private function backfill( $limit, $dry_run ) {
		global $wpdb;

		$gf_table   = $this->get_gf_entry_table();
		$flow_table = $wpdb->prefix . 'gravityflow_activity_log';

		$summary = [
			'limit'   => $limit,
			'dry_run' => (bool) $dry_run,
			'checked' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'sample'  => [],
			'wpdb_last_error' => '',
			'wpdb_last_query' => '',
		];

		$entries = $wpdb->get_results(
			"SELECT id, date_updated
			 FROM {$gf_table}
			 ORDER BY date_updated ASC
			 LIMIT " . intval( $limit ),
			ARRAY_A
		);

		if ( ! is_array( $entries ) ) {
			$summary['errors']++;
			$summary['wpdb_last_error'] = $wpdb->last_error ?: '(none)';
			$summary['wpdb_last_query'] = $wpdb->last_query ?: '(none)';
			return $summary;
		}

		foreach ( $entries as $entry ) {
			$summary['checked']++;

			$entry_id   = (int) $entry['id'];
			$current_ts = strtotime( $entry['date_updated'] ?: '1970-01-01 00:00:00' );

			$latest = $wpdb->get_var( $wpdb->prepare(
				"SELECT MAX(date_created) FROM {$flow_table} WHERE lead_id = %d",
				$entry_id
			) );

			if ( ! $latest ) {
				$summary['skipped']++;
				continue;
			}

			$latest_ts = strtotime( $latest );
			if ( ! $latest_ts || $latest_ts <= $current_ts ) {
				$summary['skipped']++;
				continue;
			}

			$new_utc = gmdate( 'Y-m-d H:i:s', $latest_ts );

			if ( $dry_run ) {
				$summary['updated']++;
			} else {
				$ok = $this->db_update_gf_date_updated( $entry_id, $new_utc, 'backfill', null );
				if ( ! $ok ) {
					$summary['errors']++;
					$summary['skipped']++;
					continue;
				}
				$summary['updated']++;
			}

			if ( count( $summary['sample'] ) < 20 ) {
				$summary['sample'][] = [
					'entry_id' => $entry_id,
					'new_date_updated' => $new_utc,
					'flow_max' => $latest,
				];
			}
		}

		$summary['wpdb_last_error'] = $wpdb->last_error ?: '(none)';
		$summary['wpdb_last_query'] = $wpdb->last_query ?: '(none)';

		return $summary;
	}

	/**
	 * Debug.log logger (structured JSON). Only logs when date_updated actually changes.
	 */
	private function log_update( array $data ) {
		if ( ! self::ENABLE_DEBUG_LOG ) return;
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) return;

		$payload = function_exists('wp_json_encode') ? wp_json_encode( $data ) : json_encode( $data );
		error_log( '[KO-GFDU] ' . $payload );
	}

	/**
	 * DB update for GF date_updated, with optional logging.
	 */
	private function db_update_gf_date_updated( $entry_id, $new_date_utc, $source = 'unknown', $step_id = null ) {
		global $wpdb;

		$table = $this->get_gf_entry_table();

		$current = $wpdb->get_var( $wpdb->prepare(
			"SELECT date_updated FROM {$table} WHERE id = %d",
			$entry_id
		) );

		if ( $current === null ) {
			return false;
		}

		if ( (string) $current === (string) $new_date_utc ) {
			return true;
		}

		$updated = $wpdb->update(
			$table,
			[ 'date_updated' => $new_date_utc ],
			[ 'id' => (int) $entry_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $updated === false ) {
			return false;
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $entry_id, 'GFFormsModel' );
			wp_cache_delete( $entry_id, 'gf_entry' );
		}

		$this->log_update( [
			'source'   => $source,
			'entry_id' => (int) $entry_id,
			'old'      => (string) $current,
			'new'      => (string) $new_date_utc,
			'step_id'  => $step_id,
			'user_id'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
		] );

		return true;
	}
}

new KO_GF_Date_Updated_Sync();


/**
 * FORGRAVITY ENTRY AUTOMATION COMPATIBILITY
 * Ensure "All Entries Since Last Task Run" uses date_updated and a UTC window.
 * This prevents approvals on older submissions from being missed.
 */
add_filter( 'fg_entryautomation_search_criteria', function( $search_criteria, $task, $form ) {

	// Only change "Since Last Run" tasks.
	if ( empty( $search_criteria['target'] ) || $search_criteria['target'] !== 'since_last_run' ) {
		return $search_criteria;
	}

	// Force date_updated as the date field.
	$search_criteria['date_field'] = 'date_updated';

	// Force UTC time strings (GF stores dates in UTC in DB).
	$task_id  = ( is_object( $task ) && isset( $task->id ) ) ? (int) $task->id : 0;
	$opt_key  = $task_id ? ( 'entryautomation_last_run_time_' . $task_id ) : '';
	$last_run = $opt_key ? get_option( $opt_key ) : false;

	if ( $last_run && is_numeric( $last_run ) ) {
		$search_criteria['start_date'] = gmdate( 'Y-m-d H:i:s', (int) $last_run );
	}

	// End date should be the task run time (if provided), otherwise "now".
	if ( is_object( $task ) && isset( $task->run_time ) && is_numeric( $task->run_time ) ) {
		$search_criteria['end_date'] = gmdate( 'Y-m-d H:i:s', (int) $task->run_time );
	} else {
		$search_criteria['end_date'] = gmdate( 'Y-m-d H:i:s', time() );
	}

	// Optional debug.log breadcrumb.
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log(
			sprintf(
				'[KO EA Criteria] task=%s target=%s field=%s start=%s end=%s',
				$task_id ?: '(unknown)',
				$search_criteria['target'],
				$search_criteria['date_field'],
				isset( $search_criteria['start_date'] ) ? $search_criteria['start_date'] : '(none)',
				$search_criteria['end_date']
			)
		);
	}

	return $search_criteria;

}, 20, 3 );

