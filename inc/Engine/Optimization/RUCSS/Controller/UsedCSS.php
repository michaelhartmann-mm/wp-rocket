<?php
declare( strict_types=1 );

namespace WP_Rocket\Engine\Optimization\RUCSS\Controller;

use WP_Rocket\Admin\Options_Data;
use WP_Rocket\Engine\Common\Queue\QueueInterface;
use WP_Rocket\Engine\Optimization\CSSTrait;
use WP_Rocket\Engine\Optimization\RegexTrait;
use WP_Rocket\Engine\Optimization\RUCSS\Database\Queries\ResourcesQuery;
use WP_Rocket\Engine\Optimization\RUCSS\Database\Row\UsedCSS as UsedCSS_Row;
use WP_Rocket\Engine\Optimization\RUCSS\Database\Queries\UsedCSS as UsedCSS_Query;
use WP_Rocket\Engine\Optimization\RUCSS\Frontend\APIClient;
use WP_Rocket\Logger\Logger;
use WP_Admin_Bar;

class UsedCSS {
	use RegexTrait, CSSTrait;

	/**
	 * UsedCss Query instance.
	 *
	 * @var UsedCSS_Query
	 */
	private $used_css_query;

	/**
	 * Resources Query instance.
	 *
	 * @var ResourcesQuery
	 */
	private $resources_query;

	/**
	 * Plugin options instance.
	 *
	 * @var Options_Data
	 */
	protected $options;

	/**
	 * APIClient instance
	 *
	 * @var APIClient
	 */
	private $api;

	/**
	 * Queue instance.
	 *
	 * @var QueueInterface
	 */
	private $queue;

	/**
	 * Inline CSS attributes exclusions patterns to be preserved on the page after treeshaking.
	 *
	 * @var string[]
	 */
	private $inline_atts_exclusions = [
		'rocket-lazyload-inline-css',
		'divi-style-parent-inline-inline-css',
		'gsf-custom-css',
		'extra-style-inline-inline-css',
		'woodmart-inline-css-inline-css',
		'woodmart_shortcodes-custom-css',
		'rs-plugin-settings-inline-css', // For revolution slider, it saves settings for each slider.
		'divi-style-inline-inline-css',
	];

	/**
	 * Inline CSS content exclusions patterns to be preserved on the page after treeshaking.
	 *
	 * @var string[]
	 */
	private $inline_content_exclusions = [
		'.wp-container-',
		'.wp-elements-',
		'#wpv-expandable-',
		'#ultib3-',
		'.uvc-wrap-',
		'.jet-listing-dynamic-post-',
		'.vcex_',
		'.wprm-advanced-list-',
	];

	/**
	 * Instantiate the class.
	 *
	 * @param Options_Data   $options         Options instance.
	 * @param UsedCSS_Query  $used_css_query  Usedcss Query instance.
	 * @param ResourcesQuery $resources_query Resources Query instance.
	 * @param APIClient      $api             APIClient instance.
	 * @param QueueInterface $queue           Queue instance.
	 */
	public function __construct(
		Options_Data $options,
		UsedCSS_Query $used_css_query,
		ResourcesQuery $resources_query,
		APIClient $api,
		QueueInterface $queue
	) {
		$this->options         = $options;
		$this->used_css_query  = $used_css_query;
		$this->resources_query = $resources_query;
		$this->api             = $api;
		$this->queue           = $queue;
	}

	/**
	 * Determines if we treeshake the CSS.
	 *
	 * @return boolean
	 */
	public function is_allowed(): bool {
		if ( rocket_get_constant( 'DONOTROCKETOPTIMIZE' ) ) {
			return false;
		}

		if ( rocket_bypass() ) {
			return false;
		}

		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( $this->is_password_protected() ) {
			return false;
		}

		if ( is_rocket_post_excluded_option( 'remove_unused_css' ) ) {
			return false;
		}

		// Bailout if user is logged in.
		if ( is_user_logged_in() ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if RUCSS option is enabled.
	 *
	 * Used inside the CRON so post object isn't there.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->options->get( 'remove_unused_css', 0 );
	}

	/**
	 * Can optimize url.
	 *
	 * @return bool
	 */
	private function can_optimize_url() {
		if ( rocket_bypass() ) {
			return false;
		}

		if ( ! $this->is_enabled() ) {
			return false;
		}

		return ! is_rocket_post_excluded_option( 'remove_unused_css' );
	}

	/**
	 * Checks if on a single post and if it is password protected
	 *
	 * @since 3.11
	 *
	 * @return bool
	 */
	private function is_password_protected(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		return ! empty( $post->post_password );
	}

	/**
	 * Start treeshaking the current page.
	 *
	 * @param string $html Buffet HTML for current page.
	 *
	 * @return string
	 */
	public function treeshake( string $html ): string {
		if ( ! $this->is_allowed() ) {
			return $html;
		}

		global $wp;
		$url       = untrailingslashit( home_url( add_query_arg( [], $wp->request ) ) );
		$is_mobile = $this->is_mobile();
		$used_css  = $this->used_css_query->get_row( $url, $is_mobile );

		if ( empty( $used_css ) ) {
			// Send the request to add this url into the queue and get the jobId and queueName.

			/**
			 * Filters the RUCSS safelist
			 *
			 * @since 3.11
			 *
			 * @param array $safelist Array of safelist values.
			 */
			$safelist = apply_filters( 'rocket_rucss_safelist', $this->options->get( 'remove_unused_css_safelist', [] ) );

			$config = [
				'treeshake'      => 1,
				'rucss_safelist' => $safelist,
				'is_mobile'      => $is_mobile,
				'is_home'        => $this->is_home( $url ),
			];

			$add_to_queue_response = $this->api->add_to_queue( $url, $config );
			if ( 200 !== $add_to_queue_response['code'] ) {
				Logger::error(
					'Error when contacting the RUCSS API.',
					[
						'rucss error',
						'url'     => $url,
						'code'    => $add_to_queue_response['code'],
						'message' => $add_to_queue_response['message'],
					]
				);

				return $html;
			}

			// We got jobid and queue name so save them into the DB and change status to be pending.
			$this->used_css_query->create_new_job(
				$url,
				$add_to_queue_response['contents']['jobId'],
				$add_to_queue_response['contents']['queueName'],
				$is_mobile
			);

			return $html;
		}

		if ( 'completed' !== $used_css->status || empty( $used_css->css ) ) {
			return $html;
		}

		$html = $this->remove_used_css_from_html( $html );
		$html = $this->add_used_css_to_html( $html, $used_css );
		$html = $this->add_used_fonts_preload( $html, $used_css->css );
		$html = $this->remove_google_font_preconnect( $html );
		$this->used_css_query->update_last_accessed( (int) $used_css->id );

		return $html;
	}

	/**
	 * Delete used css based on URL.
	 *
	 * @param string $url The page URL.
	 *
	 * @return boolean
	 */
	public function delete_used_css( string $url ): bool {
		$used_css_arr = $this->used_css_query->query( [ 'url' => $url ] );

		if ( empty( $used_css_arr ) ) {
			return false;
		}

		$deleted = true;

		foreach ( $used_css_arr as $used_css ) {
			if ( empty( $used_css->id ) ) {
				continue;
			}

			$deleted = $deleted && $this->used_css_query->delete_item( $used_css->id );
		}

		return $deleted;
	}

	/**
	 * Alter HTML and remove all CSS which was processed from HTML page.
	 *
	 * @param string $html HTML content.
	 *
	 * @return string HTML content.
	 */
	private function remove_used_css_from_html( string $html ): string {
		$clean_html = $this->hide_comments( $html );
		$clean_html = $this->hide_noscripts( $clean_html );
		$clean_html = $this->hide_scripts( $clean_html );

		$link_styles = $this->find(
			'<link\s+([^>]+[\s"\'])?href\s*=\s*[\'"]\s*?(?<url>[^\'"]+(?:\?[^\'"]*)?)\s*?[\'"]([^>]+)?\/?>',
			$clean_html,
			'Uis'
		);

		$inline_styles = $this->find(
			'<style(?<atts>.*)>(?<content>.*)<\/style\s*>',
			$clean_html
		);

		$preserve_google_font = apply_filters( 'rocket_rucss_preserve_google_font', false );

		foreach ( $link_styles as $style ) {
			if (

				! (bool) preg_match( '/rel=[\'"]stylesheet[\'"]/is', $style[0] )
				&&
				! ( (bool) preg_match( '/rel=[\'"]preload[\'"]/is', $style[0] ) && (bool) preg_match( '/as=[\'"]style[\'"]/is', $style[0] ) )
				||
				( $preserve_google_font && strstr( $style['url'], '//fonts.googleapis.com/css' ) )
			) {
				continue;
			}
			$html = str_replace( $style[0], '', $html );
		}

		$inline_atts_exclusions = (array) array_map(
			function ( $item ) {
				return preg_quote( $item, '/' );
			},
			/**
			 * Filters the array of inline CSS attributes patterns to preserve
			 *
			 * @since 3.11
			 *
			 * @param array $inline_atts_exclusions Array of patterns used to match against the inline CSS attributes.
			 */
			apply_filters( 'rocket_rucss_inline_atts_exclusions', $this->inline_atts_exclusions )
		);

		$inline_content_exclusions = (array) array_map(
			function ( $item ) {
				return preg_quote( $item, '/' );
			},
			/**
			 * Filters the array of inline CSS content patterns to preserve
			 *
			 * @since 3.11
			 *
			 * @param array $inline_atts_exclusions Array of patterns used to match against the inline CSS content.
			 */
			apply_filters( 'rocket_rucss_inline_content_exclusions', $this->inline_content_exclusions )
		);

		foreach ( $inline_styles as $style ) {
			if ( ! empty( $inline_atts_exclusions ) && $this->find( implode( '|', $inline_atts_exclusions ), $style['atts'] ) ) {
				continue;
			}

			if ( ! empty( $inline_content_exclusions ) && $this->find( implode( '|', $inline_content_exclusions ), $style['content'] ) ) {
				continue;
			}

			$html = str_replace( $style[0], '', $html );
		}

		return $html;
	}

	/**
	 * Alter HTML string and add the used CSS style in <head> tag,
	 *
	 * @param string      $html     HTML content.
	 * @param UsedCSS_Row $used_css Used CSS DB row.
	 *
	 * @return string HTML content.
	 */
	private function add_used_css_to_html( string $html, UsedCSS_Row $used_css ): string {
		$replace = preg_replace(
			'#</title>#iU',
			'</title>' . $this->get_used_css_markup( $used_css ),
			$html,
			1
		);

		if ( null === $replace ) {
			return $html;
		}

		return $replace;
	}

	/**
	 * Return Markup for used_css into the page.
	 *
	 * @param UsedCSS_Row $used_css Used CSS DB Row.
	 *
	 * @return string
	 */
	private function get_used_css_markup( UsedCSS_Row $used_css ): string {
		/**
		 * Filters Used CSS content before saving into DB.
		 *
		 * @since 3.9.0.2
		 *
		 * @param string $usedcss Used CSS.
		 */
		$css = apply_filters( 'rocket_usedcss_content', $used_css->css );

		$css               = str_replace( '\\', '\\\\', $css );// Guard the backslashes before passing the content to preg_replace.
		$used_css_contents = $this->handle_charsets( $css, false );
		return sprintf(
			'<style id="wpr-usedcss">%s</style>',
			$used_css_contents
		);
	}

	/**
	 * Determines if the page is mobile and separate cache for mobile files is enabled.
	 *
	 * @return boolean
	 */
	private function is_mobile(): bool {
		return $this->options->get( 'cache_mobile', 0 )
			&&
			$this->options->get( 'do_caching_mobile_files', 0 )
			&&
			wp_is_mobile();
	}

	/**
	 * Check if current page is the home page.
	 *
	 * @param string $url Current page url.
	 *
	 * @return bool
	 */
	private function is_home( string $url ): bool {
		return untrailingslashit( $url ) === untrailingslashit( home_url() );
	}

	/**
	 * Process pending jobs inside cron iteration.
	 *
	 * @return void
	 */
	public function process_pending_jobs() {
		Logger::debug( 'RUCSS: Start processing pending jobs inside cron.' );

		if ( ! $this->is_enabled() ) {
			Logger::debug( 'RUCSS: Stop processing cron iteration because option is disabled.' );

			return;
		}

		// Get some items from the DB with status=pending & job_id isn't empty.

		/**
		 * Filters the pending jobs count.
		 *
		 * @since 3.11
		 *
		 * @param int $rows Number of rows to grab with each CRON iteration.
		 */
		$rows = apply_filters( 'rocket_rucss_pending_jobs_cron_rows_count', 100 );

		Logger::debug( "RUCSS: Start getting number of {$rows} pending jobs." );

		$pending_jobs = $this->used_css_query->get_pending_jobs( $rows );
		if ( ! $pending_jobs ) {
			Logger::debug( 'RUCSS: No pending jobs are there.' );

			return;
		}

		foreach ( $pending_jobs as $used_css_row ) {
			Logger::debug( "RUCSS: Send the job for url {$used_css_row->url} to Async task to check its job status." );

			// Change status to in-progress.
			$this->used_css_query->make_status_inprogress( (int) $used_css_row->id );

			$this->queue->add_job_status_check_async( (int) $used_css_row->id );
		}
	}

	/**
	 * Check job status by DB row ID.
	 *
	 * @param int $id DB Row ID.
	 *
	 * @return void
	 */
	public function check_job_status( int $id ) {
		Logger::debug( 'RUCSS: Start checking job status for row ID: ' . $id );

		$row_details = $this->used_css_query->get_item( $id );
		if ( ! $row_details ) {
			Logger::debug( 'RUCSS: Row ID not found ', compact( 'id' ) );

			// Nothing in DB, bailout.
			return;
		}

		// Send the request to get the job status from SaaS.
		$job_details = $this->api->get_queue_job_status( $row_details->job_id, $row_details->queue_name, $this->is_home( $row_details->url ) );
		if (
			200 !== $job_details['code']
			||
			empty( $job_details['contents'] )
			||
			! isset( $job_details['contents']['shakedCSS'] )
		) {
			Logger::debug( 'RUCSS: Job status failed for url: ' . $row_details->url, $job_details );

			// Failure, check the retries number.
			if ( $row_details->retries >= 3 ) {
				Logger::debug( 'RUCSS: Job failed 3 times for url: ' . $row_details->url );

				$this->used_css_query->make_status_failed( $id );

				return;
			}

			// Increment the retries number with 1 and Change status to pending again.
			$this->used_css_query->increment_retries( $id, $row_details->retries );
			// @Todo: Maybe we can add this row to the async job to get the status before the next cron

			return;
		}

		// Everything is fine, save the usedcss into DB, change status to completed and reset queue_name and job_id.
		Logger::debug( 'RUCSS: Save used CSS for url: ' . $row_details->url );

		$css = $this->apply_font_display_swap( $job_details['contents']['shakedCSS'] );

		$this->used_css_query->make_status_completed( $id, $css );

		do_action( 'rocket_rucss_complete_job_status', $row_details->url, $job_details );

	}

	/**
	 * Add clear UsedCSS adminbar item.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Adminbar object.
	 *
	 * @return void
	 */
	public function add_clear_usedcss_bar_item( WP_Admin_Bar $wp_admin_bar ) {
		if ( 'local' === wp_get_environment_type() ) {
			return;
		}

		if ( ! current_user_can( 'rocket_remove_unused_css' ) ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		if ( ! $this->can_optimize_url() ) {
			return;
		}

		$referer = '';
		$action  = 'rocket_clear_usedcss_url';

		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$referer_url = filter_var( wp_unslash( $_SERVER['REQUEST_URI'] ), FILTER_SANITIZE_URL );
			$referer     = '&_wp_http_referer=' . rawurlencode( remove_query_arg( 'fl_builder', $referer_url ) );
		}

		/**
		 * Clear usedCSS for this URL (frontend).
		 */
		$wp_admin_bar->add_menu(
			[
				'parent' => 'wp-rocket',
				'id'     => 'remove-usedcss-url',
				'title'  => __( 'Clear Used CSS of this URL', 'rocket' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . $referer ), 'remove_usedcss_url' ),
			]
		);
	}

	/**
	 * Clear specific url.
	 *
	 * @param string $url Page url.
	 *
	 * @return void
	 */
	public function clear_url_usedcss( string $url ) {
		$this->used_css_query->delete_by_url( $url );

		/**
		 * Fires after clearing usedcss for specific url.
		 *
		 * @since 3.11
		 *
		 * @param string $url Current page URL.
		 */
		do_action( 'rocket_rucss_after_clearing_usedcss', $url );
	}

	/**
	 * Remove all completed rows one by one.
	 *
	 * @return void
	 */
	public function remove_all_completed_rows() {
		$this->used_css_query->remove_all_completed_rows();
	}

	/**
	 * Get the count of not completed rows.
	 *
	 * @return int
	 */
	public function get_not_completed_count() {
		return $this->used_css_query->get_not_completed_count();
	}

	/**
	 * Add preload links for the fonts in the used CSS
	 *
	 * @param string $html HTML content.
	 * @param string $used_css Used CSS content.
	 *
	 * @return string
	 */
	private function add_used_fonts_preload( string $html, string $used_css ): string {
		/**
		 * Filters the fonts preload from the used CSS
		 *
		 * @since 3.11
		 *
		 * @param bool $enable True to enable, false to disable.
		 */
		if ( ! apply_filters( 'rocket_enable_rucss_fonts_preload', true ) ) {
			return $html;
		}

		if ( ! preg_match_all( '/@font-face\s*{\s*(?<content>[^}]+)}/is', $used_css, $font_faces, PREG_SET_ORDER ) ) {
			return $html;
		}

		if ( empty( $font_faces ) ) {
			return $html;
		}

		$urls = [];

		foreach ( $font_faces as $font_face ) {
			if ( empty( $font_face['content'] ) ) {
				continue;
			}

			$font_url = $this->extract_first_font( $font_face['content'] );

			if ( empty( $font_url ) ) {
				continue;
			}

			$urls[] = $font_url;
		}

		if ( empty( $urls ) ) {
			return $html;
		}

		$urls = array_unique( $urls );

		$replace = preg_replace(
			'#</title>#iU',
			'</title>' . $this->preload_links( $urls ),
			$html,
			1
		);

		if ( null === $replace ) {
			return $html;
		}

		return $replace;
	}

	/**
	 * Remove preconnect tag for google api.
	 *
	 * @param string $html html content.
	 * @return string
	 */
	protected function remove_google_font_preconnect( string $html ): string {
		$clean_html = $this->hide_comments( $html );
		$clean_html = $this->hide_noscripts( $clean_html );
		$clean_html = $this->hide_scripts( $clean_html );
		$links      = $this->find(
			'<link\s+([^>]+[\s"\'])?rel\s*=\s*[\'"]((preconnect)|(dns-prefetch))[\'"]([^>]+)?\/?>',
			$clean_html,
			'Uis'
		);

		foreach ( $links as $link ) {
			if ( preg_match( '/href=[\'"](https:)?\/\/fonts.googleapis.com\/?[\'"]/', $link[0] ) ) {
				$html = str_replace( $link[0], '', $html );
			}
		}

		return $html;
	}

	/**
	 * Extracts the first font URL from the font-face declaration
	 *
	 * Skips .eot fonts if it exists
	 *
	 * @since 3.11
	 *
	 * @param string $font_face Font-face declaration content.
	 *
	 * @return string
	 */
	private function extract_first_font( string $font_face ): string {
		if ( ! preg_match_all( '/src:\s*(?<urls>[^;}]*)/is', $font_face, $sources, PREG_SET_ORDER ) ) {
			return '';
		}

		foreach ( $sources as $src ) {
			if ( empty( $src['urls'] ) ) {
				continue;
			}

			$urls = explode( ',', $src['urls'] );

			foreach ( $urls as $url ) {
				if ( false !== strpos( $url, '.eot' ) ) {
					continue;
				}

				if ( ! preg_match( '/url\(\s*[\'"]?(?<url>[^\'")]+)[\'"]?\)/is', $url, $matches ) ) {
					continue;
				}

				return trim( $matches['url'] );
			}
		}

		return '';
	}

	/**
	 * Converts an array of URLs to preload link tags
	 *
	 * @param array $urls An array of URLs.
	 *
	 * @return string
	 */
	private function preload_links( array $urls ): string {
		$links = '';

		foreach ( $urls as $url ) {
			$links .= '<link rel="preload" as="font" href="' . esc_url( $url ) . '" crossorigin>';
		}

		return $links;
	}
}
