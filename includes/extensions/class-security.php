<?php

use Dflydev\DotAccessData\Data;

/**
 * Class 
 */
class WPS_Security {

	/**
	 * hide dashboard update notices
	 */
	public function hideUpdateNotice()
	{
		if (!current_user_can('update_core'))
			remove_action( 'admin_notices', 'update_nag', 3 );
	}

	/**
	 * Allow iframe for editor in WYSIWYG
	 * @param $caps
	 * @param $cap
	 * @param $user_id
	 * @return array
	 */
	public function addUnfilteredHtmlCapabilityToEditors( $caps, $cap, $user_id )
	{
		if ( 'unfiltered_html' === $cap && user_can( $user_id, 'editor' ) )
			$caps = array( 'unfiltered_html' );

		return $caps;
	}


	/**
	 * Clean WP Footer
	 */
	public function cleanFooter()
	{
		wp_deregister_script( 'wp-embed' );
	}


	/**
	 * Clean filename
	 * @param $file
	 * @return mixed
	 */
	function cleanFilename($file) {

		$input = ['ß', '·'];
		$output = ['ss', '.'];

		if($file && isset($file['name'])){
			$path = pathinfo($file['name']);
			$new_filename = preg_replace('/.' . $path['extension'] . '$/', '', $file['name']);
			$new_filename = preg_replace('/-([0-9]+x[0-9]+)$/', '', $new_filename);
			$new_filename = str_replace( $input, $output, $new_filename );
			$file['name'] = sanitize_title($new_filename) . '.' . $path['extension'];
		}

		return $file;
	}


	/**
	 * Recursive chown
	 * @param string $dir
	 * @param string $user
	 */
	private function rchown($dir, $user)
	{
		$dir = rtrim($dir, "/");
		if ($items = glob($dir . "/*")) {
			foreach ($items as $item) {
				if (is_dir($item)) {
					$this->rchown($item, $user);
				} else {
					@chown($item, $user);
				}
			}
		}

		@chown($dir, $user);
	}


	/**
	 * Try to fix permissions
	 * @param string $type
	 */
	private function permissions($type='all')
	{
		if ( current_user_can('administrator') ) {
			$webuser = posix_getpwuid(posix_geteuid())['name'];
			$this->rchown(WP_UPLOADS_DIR, $webuser);
		}

		wp_redirect( get_admin_url(null, 'options-media.php' ));
		exit;
	}
	

	/**
	 * Clean WP Head
	 */
	public function cleanHeader()
	{
		global $_config;

		remove_action('wp_head', 'feed_links', 2);
		remove_action('wp_head', 'feed_links_extra', 3 );
		remove_action('wp_head', 'rsd_link');
		remove_action('wp_head', 'wlwmanifest_link');
		remove_action('wp_head', 'wp_generator');
		remove_action('wp_head', 'wp_shortlink_wp_head');
		remove_action('wp_head', 'print_emoji_detection_script', 7 );
		remove_action('wp_print_styles', 'print_emoji_styles' );
		remove_action('wp_head', 'rest_output_link_wp_head');
		remove_action('wp_head', 'wp_resource_hints', 2 );
		remove_action('wp_head', 'wp_oembed_add_discovery_links');
		remove_action('template_redirect', 'rest_output_link_header', 11 );
		remove_action('template_redirect', 'wp_shortlink_header', 11 );

		/*add_action( 'wp_enqueue_scripts', function(){
			wp_deregister_script( 'regenerator-runtime' );
			wp_deregister_script( 'wp-polyfill' );
		});*/

		if( !$_config->get('gutenberg', false) ) {
			add_action( 'wp_enqueue_scripts', function() {
				wp_dequeue_script('wp-block-library');
			});
		}

		add_filter('wp_headers', function($headers) {

			if(isset($headers['X-Pingback']))
				unset($headers['X-Pingback']);

			if(isset($headers['X-Powered-By']))
				unset($headers['X-Powered-By']);

			if(isset($headers['Server']))
				unset($headers['Server']);

			return $headers;
		});
	}


	/**
	 * add admin parameters
	 */
	public function adminInit()
	{
		if( !current_user_can('administrator') )
			return;

		if( isset($_GET['permissions']) )
			$this->permissions($_GET['type'] ?? 'all');

		add_settings_field('fix_permissions', __('Permissions'), function(){

			echo '<a class="button button-primary" href="'.get_admin_url().'?permissions&type=uploads">'.__('Try to fix it').'</a>';

		}, 'media');
	}


	/**
	 * add admin parameters
	 */
	public function redirect()
	{
		wp_redirect(get_home_url());
		exit;
	}


	/**
	 * SecurityPlugin constructor.
	 */
	public function __construct()
	{
        /* @var Data $_config */
        global $_config;

        //prevent .htaccess writing
		add_filter( 'flush_rewrite_rules_hard', '__return_false');

        //hide login error
		add_filter( 'login_errors', function(){
			return __('Something is wrong!');
		} );

		if( is_admin() )
		{
			add_action( 'admin_init', [$this, 'adminInit'] );
			add_action( 'wp_handle_upload_prefilter', [$this, 'cleanFilename']);
			add_filter( 'map_meta_cap', [$this, 'addUnfilteredHtmlCapabilityToEditors'], 1, 3 );
			add_action( 'admin_head', [$this, 'hideUpdateNotice'], 1 );
		}
		else
		{
			if( !$_config->get('security.rest_api', false) ){

                add_filter('rest_jsonp_enabled', '__return_false');

                add_filter( 'rest_authentication_errors', function( $result ) {

                    if ( true === $result || is_wp_error( $result ) )
                        return $result;

                    if ( ! is_user_logged_in() )
                        $this->redirect();

                    return $result;
                });
            }

			if( !$_config->get('security.xmlrpc', false) )
				add_filter( 'xmlrpc_enabled', '__return_false' );

            if( !$_config->get('security.pings', false) )
                add_filter( 'pings_open', '__return_false');

            foreach (['html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export'] as $type )
                add_filter( 'get_the_generator_'.$type, '__return_empty_string' );

			add_action( 'after_setup_theme', [$this, 'cleanHeader']);
			add_action( 'wp_footer', [$this, 'cleanFooter']);
            add_filter( 'x_redirect_by', '__return_false' );

            add_action('init', function()
			{
				if ( isset($_GET['author']) )
					$this->redirect();

				global $wp_rewrite;

				$wp_rewrite->feeds = array();
			});
		}
	}
}
