<?php
/**
 * The report endpoint, as it is actually served.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Pro\Admin\Screen;
use Debloater\Pro\Entitlement\FixtureEntitlementProvider;
use Debloater\Pro\Pro;

/**
 * `BeforeAfterReport::render()` had two tests and both called it directly. The
 * reason the endpoint exists is what happens around it: the report is a whole
 * `<!doctype html>` document, and an earlier version printed it from inside an
 * admin page, where it arrived wrapped in the admin's own markup and another
 * plugin's "activate your licence" notice.
 *
 * So this runs the request: `admin_post_debloater_pro_report`, with the nonce
 * and the capability, with another plugin's callback attached to the same hook
 * behind ours — which is what `admin-post.php` would run next, because that
 * file ends on `do_action( "admin_post_{$action}" )`.
 */
final class ReportEndpointTest extends IntegrationTestCase {

	/**
	 * What the stand-in for `exit` throws, so a real RuntimeException from the
	 * code under test is not mistaken for the endpoint finishing.
	 */
	private const SENT = 'debloater-pro: the report was sent';

	/**
	 * Pro, with everything unlocked.
	 *
	 * @var Pro
	 */
	private Pro $pro;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		$this->pro = new Pro( $this->plugin, FixtureEntitlementProvider::everything(), null );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_actions( 'admin_post_debloater_pro_report' );

		delete_option( 'debloater_pro_report_branding' );

		unset( $_GET['run'], $_GET['_wpnonce'], $_REQUEST['run'], $_REQUEST['_wpnonce'] );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The response is the report, and nothing else at all.
	 *
	 * @return void
	 */
	public function test_the_endpoint_serves_the_document_alone(): void {
		$run = $this->appliedRun();

		$this->pro->report()->setBranding( 'Acme Agency' );

		$screen = new Screen(
			$this->pro,
			static function (): void {
				// In place of the endpoint's `exit`, which would take the test
				// runner with it. Caught below; the message is the marker.
				throw new \RuntimeException( self::SENT );
			}
		);

		$screen->boot();

		// Another plugin, on the same hook, after us.
		add_action(
			'admin_post_debloater_pro_report',
			static function (): void {
				echo '<div class="notice">Activate your licence</div>';
			},
			20
		);

		// Both, because the handler reads $_GET for the run and
		// check_admin_referer() reads $_REQUEST for the nonce. A real request
		// has the superglobals populated from one another; a test does not.
		$id    = (string) $run;
		$nonce = wp_create_nonce( 'debloater_pro_report' );

		$_GET['run']          = $id;
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['run']      = $id;
		$_REQUEST['_wpnonce'] = $nonce;

		$sent = false;

		ob_start();

		try {
			do_action( 'admin_post_debloater_pro_report' );
		} catch ( \RuntimeException $stopped ) {
			$sent = self::SENT === $stopped->getMessage();

			if ( ! $sent ) {
				ob_end_clean();

				throw $stopped;
			}
		}

		$response = (string) ob_get_clean();

		$this->assertStringStartsWith( '<!doctype', $response );
		$this->assertStringEndsWith( '</html>', $response );

		// It is the report: the agency's name, this site, and a measured row.
		$this->assertStringContainsString( 'Acme Agency — site changes: before and after', $response );
		$this->assertStringContainsString( esc_html( home_url() ), $response );
		$this->assertStringContainsString( 'db.autoload_bytes', $response );

		// And carries none of the admin, nor anything from the callback behind us.
		foreach ( array( 'Activate your licence', 'wpadminbar', 'id="wpbody"', 'wp-admin.css', 'adminmenu' ) as $chrome ) {
			$this->assertStringNotContainsString( $chrome, $response, 'the response carries ' . $chrome );
		}

		// Last, so that a run with the exit removed fails on the appended
		// markup above rather than only on this.
		$this->assertTrue( $sent, 'the endpoint must end the request once it has sent the document' );
	}

	/**
	 * Apply something, and hand back the run to report on.
	 *
	 * @return int
	 */
	private function appliedRun(): int {
		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$applied = $this->plugin->apply( $preview->plan );

		$this->unregisterHandlers( array( 'core.remove_generator' ) );

		return (int) $applied->run_id;
	}
}
