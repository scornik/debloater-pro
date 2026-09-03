<?php
/**
 * The one implementation that actually talks to a server.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Cloud;

/**
 * Reads data from Hakeemify Cloud, over one host, and refuses to do anything
 * else with what comes back.
 *
 * Off unless switched on. `isAvailable()` is false until a site opts in, and
 * every feature checks it before offering a cloud-backed option — so the
 * default install makes no outbound request at all, which is what the free
 * plugin already promises and what Pro must not quietly change
 * (BUILD-SPEC §13 rule 9).
 *
 * Three things this deliberately cannot do:
 *
 * - **Execute anything.** The response is decoded as JSON into an array. There
 *   is no `eval`, no `require`, no script enqueue from a remote URL, and no
 *   path where a field in a response becomes code. A compromised server can
 *   send this plugin wrong data; it cannot send it instructions.
 * - **Be redirected.** `redirection => 0`. A 301 to somewhere else is a failed
 *   request, not a followed one, which keeps the one-host rule true at runtime
 *   and not only in the resolver.
 * - **Hang.** A short timeout, because this may run during an admin page load,
 *   and a slow third party must not become a slow dashboard.
 *
 * Nothing identifying is sent. No site URL, no admin email, no licence key —
 * the request carries a product, a version, and whatever the feature asked for.
 * Licensing lives on a different platform entirely and never travels here.
 */
final class HakeemifyCloudClient implements CloudServiceClient {

	/**
	 * Long enough for a healthy server, short enough not to stall a page.
	 */
	private const TIMEOUT = 8;

	/**
	 * A response larger than this is refused rather than decoded.
	 */
	private const MAX_BYTES = 1048576;

	/**
	 * Where URLs come from.
	 *
	 * @var EndpointResolver
	 */
	private EndpointResolver $resolver;

	/**
	 * Whether the site has opted in.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * The plugin version, sent so a server can tell what it is talking to.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param bool                  $enabled  Whether the site opted in.
	 * @param string                $version  Plugin version.
	 * @param EndpointResolver|null $resolver URL builder.
	 */
	public function __construct( bool $enabled = false, string $version = '', ?EndpointResolver $resolver = null ) {
		$this->enabled  = $enabled;
		$this->version  = $version;
		$this->resolver = $resolver ?? new EndpointResolver();
	}

	/**
	 * Whether the cloud is worth trying.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return $this->enabled;
	}

	/**
	 * Ask a service for something.
	 *
	 * @param string              $service Service name.
	 * @param string              $path    Path within the service.
	 * @param array<string,scalar> $query  Query parameters.
	 * @return CloudResponse
	 */
	public function get( string $service, string $path = '', array $query = array() ): CloudResponse {
		if ( ! $this->enabled ) {
			return CloudResponse::unavailable( 'The cloud is switched off for this site.' );
		}

		try {
			$url = $this->resolver->url( $service, $path, $query );
		} catch ( \Throwable $error ) {
			return CloudResponse::unavailable( $error->getMessage() );
		}

		$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- One host, opt-in, read-only, and the result is data that is never executed.
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Debloater/' . $this->version,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return CloudResponse::unavailable( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::MAX_BYTES ) {
			return CloudResponse::unavailable( 'The response was larger than this plugin will read.' );
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new CloudResponse( $status, array(), 'The response was not a JSON object.' );
		}

		return new CloudResponse( $status, $decoded );
	}
}
