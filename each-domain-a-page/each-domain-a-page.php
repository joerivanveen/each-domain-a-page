<?php
/*
Plugin Name: Each domain a page
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Serves a specific landing page from WordPress depending on the domain used to access the WordPress installation.
Version: 1.6.4
Author: Joeri van Veen
Author URI: https://wp-developer.eu
License: GPLv3
Text Domain: each-domain-a-page
Domain Path: /languages/
*/
defined( 'ABSPATH' ) || die();
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond007.
const RUIGEHOND007_VERSION = '1.6.4';
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook( __FILE__, 'ruigehond007_activate' );
register_deactivation_hook( __FILE__, 'ruigehond007_deactivate' );
register_uninstall_hook( __FILE__, 'ruigehond007_uninstall' );
// Startup the plugin
add_action( 'init', array( new ruigehond007(), 'initialize' ) );

//
class ruigehond007 {
	private $options, $options_changed, $use_canonical, $canonical_prefix, $remove_sitename_from_title = false;
	// @since 1.3.0
	private $slug, $locale, $site_url, $sub_folder; // cached values
	// since 1.6.0
	private $force_redirect = false;
	private $post_types = array();
	private $canonicals = array();

	/**
	 * ruigehond007 constructor
	 * loads settings that are available also based on current url
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->options_changed = false; // if a domain is registered with a slug, this will flag true, and the options must be saved in __shutdown()
		// @since 1.3.0 changed __destruct to __shutdown for stability reasons
		register_shutdown_function( array( $this, '__shutdown' ) );
		// set WP url
		$this->site_url   = $site_url = get_site_url();
		$this->sub_folder = trim( substr( ( $string = str_replace( '://', '', $site_url ) ), strpos( $string, '/' ) ), '/' ) . '/';
		// continue
		$this->options = get_option( 'ruigehond007' );
		if ( isset( $this->options ) && is_array( $this->options ) ) {
			// ATTENTION for the options do not use true === ‘option’, because previous versions work with ‘1’ as a value
			$this->use_canonical  = ( isset( $this->options['use_canonical'] ) && $this->options['use_canonical'] );
			$this->force_redirect = ( isset( $this->options['force_redirect'] ) && $this->options['force_redirect'] );
			if ( isset( $this->options['post_types'] ) && is_array( $this->options['post_types'] ) ) {
				$this->post_types = $this->options['post_types'];
			}
			// fix @since 1.3.4 you need the canonicals for the ajax hook, so load them always
			if ( isset( $this->options['canonicals'] ) && is_array( $this->options['canonicals'] ) ) {
				$this->canonicals = $this->options['canonicals'];
			}
			if ( $this->use_canonical ) {
				if ( isset( $this->options['use_ssl'] ) && $this->options['use_ssl'] ) {
					$this->canonical_prefix = 'https://';
				} else {
					$this->canonical_prefix = 'http://';
				}
				if ( isset( $this->options['use_www'] ) && $this->options['use_www'] ) {
					$this->canonical_prefix .= 'www.';
				}
			}
			$this->remove_sitename_from_title = isset( $this->options['remove_sitename'] ) && $this->options['remove_sitename'];
		} else {
			$this->options         = array(); // set default options (currently none)
			$this->options_changed = true;
		}
		if ( false === isset( $this->canonical_prefix ) ) { // @since 1.3.5 set the prefix to what’s current
			if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
				$this->canonical_prefix = "{$_SERVER['HTTP_X_FORWARDED_PROTO']}://";
			} elseif ( isset( $_SERVER['SERVER_PROTOCOL'] ) && stripos( $_SERVER['SERVER_PROTOCOL'], 'https' ) === 0 ) {
				$this->canonical_prefix = 'https://';
			} else {
				$this->canonical_prefix = 'http://';
			}
		}
		// @since 1.6.0
		if ( $this->force_redirect ) {
			add_action( 'template_redirect', array( $this, 'template_redirect' ), 1, 1 );
		}
		// set slug and locale that are solely based on the requested domain, which is available already
		$this->setSlugAndLocaleFromDomainAndRegister();
		// https://wordpress.stackexchange.com/a/89965
		if ( isset( $this->locale ) ) {
			add_filter( 'locale', array( $this, 'getLocale' ), 1 );
		}
		// @since 1.6.0 remember the types
		// todo: remove this and method postType when everybody has updated to at least 1.6.0
		if ( 0 === count( $this->post_types ) && 0 < count( $this->canonicals ) ) {
			foreach ( $this->canonicals as $slug => $canonical ) {
				$this->post_types[ $slug ] = $this->postType( $slug );
			}
			$this->options['post_types'] = $this->post_types;
			$this->options_changed       = true;
		}
		add_filter( 'post_updated', array( $this, 'post_updated' ), 999, 3 );
	}

	public function template_redirect() {
		$address = $_SERVER['REQUEST_URI'];
		if ( ( $redirect = $this->fixUrl( $address ) ) !== $address ) {
			wp_redirect( $redirect, 301, 'each-domain-a-page' );
			die();
		}
	}

	/**
	 * Makes sure options are saved at the end of the request when they changed since the beginning
	 * @since 1.0.0
	 * @since 1.3.0: generate notice upon fail
	 */
	public function __shutdown() {
		if ( true === $this->options_changed ) {
			if ( false === update_option( 'ruigehond007', $this->options, true ) ) {
				error_log( esc_html__( 'Failed saving options (each domain a page)', 'each-domain-a-page' ) );
			}
		}
	}

	/**
	 * initialize the plugin, sets up necessary filters and actions.
	 * @since 1.0.0
	 */
	public function initialize() {
		if ( is_admin() ) {
			load_plugin_textdomain( 'each-domain-a-page', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			add_action( 'admin_init', array( $this, 'settings' ) );
			add_action( 'admin_menu', array( $this, 'menuitem' ) ); // necessary to have the page accessible to user
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array(
				$this,
				'settings_link'
			) ); // settings link on plugins page
			/* set the title to prevent null errors */
			if ( isset( $_GET['page'] ) && 0 === strpos( $_GET['page'], 'each-domain-a-page' ) ) {
				global $title;
				$title = esc_html__( 'Each domain a page', 'each-domain-a-page' );
			}
		} else {
			// original
			add_action( 'parse_request', array( $this, 'get' ) ); // passes WP_Query object
			if ( $this->use_canonical ) {
				// fix the canonical url for functions that get the url, subject to additions...
				foreach (
					array(
						'post_link',
						'page_link',
						'post_type_link',
						'get_canonical_url',
						'wpseo_opengraph_url', // Yoast
						'wpseo_canonical', // Yoast
					) as $filter
				) {
					add_filter( $filter, array( $this, 'fixUrl' ), 99, 1 );
				}
			}
		}
		// manage the domains
		// reroute standard ajax requests to current domain
		add_filter( 'admin_url', array( $this, 'adminUrl' ) );
		// send a cors header for when it did not work
		$org = trailingslashit( str_replace( array( 'http://', 'https://', 'www.' ), '', get_http_origin() ) );
		if ( true === in_array( $org, $this->canonicals ) ) {
			header( 'Access-Control-Allow-Origin: ' . get_http_origin() );
			header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Vary: Origin', false );
		}
	}

	/**
	 * Returns a correct url for pages that are accessed on a different domain than the original blog enabling
	 * ajax calls without the dreaded cross-origin errors (as long as people use the recommended get_admin_url())
	 *
	 * @param $url
	 *
	 * @return string|string[]
	 * @since 1.3.0
	 * @since 1.3.1 fix: substitute correct new domain rather than make it relative
	 */
	public function adminUrl( $url ) {
		$slug = $this->slug;
		if ( isset( $this->canonicals[ $slug ] ) ) {
			return str_replace( $this->site_url, $this->fixUrl( $slug ), $url );
		}

		return $url;
	}

	/**
	 * Will remove old slug from this plugin when the slug is changed
	 *
	 * @param $post_id
	 * @param $post_after
	 * @param $post_before
	 *
	 * @return void
	 */
	public function post_updated( $post_id, $post_after, $post_before ) {
		if ( ! isset( $post_before->post_name, $post_after->post_name ) ) {
			return;
		}
		// clear slug if it changed
		if ( $post_before->post_name !== $post_after->post_name ) {
			$post = get_post( $post_id );
			// get the old slug to clear
			$slug = substr( get_page_uri( $post ), 0, - 1 * strlen( $post_after->post_name ) ) . $post_before->post_name;
			if ( isset( $this->canonicals[ $slug ] ) ) {
				unset( $this->canonicals[ $slug ] );
				unset( $this->post_types[ $slug ] );
				$this->options['canonicals'] = $this->canonicals;
				$this->options['post_types'] = $this->post_types;
				$this->options_changed       = true;
			}
		}
	}

	/**
	 * Hook for the locale, set with ->initialize() when $this->locale is set
	 * @return string the locale set by each-domain-a-page
	 * @since 1.3.0
	 */
	public function getLocale() {
		return $this->locale;
	}

	/**
	 * ‘get’ is the actual functionality of the plugin
	 *
	 * @param $query Object holding the query prepared by WordPress
	 *
	 * @return mixed Object is returned either unchanged, or the request has been updated with the post to show
	 */
	public function get( $query ) {
		// @since 1.3.4 don’t bother processing if not a page handled by the plugin...
		if ( false === isset( $this->slug ) ) {
			return $query;
		}
		$slug = $this->slug;
		$type = $this->post_types[ $slug ];
		if ( $this->remove_sitename_from_title ) {
			add_filter( 'document_title_parts', array( $this, 'clean_title_parts' ) );
		}
		if ( 'page' === $type ) {
			unset( $query->query_vars['name'] );
			$query->query_vars['pagename'] = $slug;
			$query->request                = $slug;
			$slug                          = urlencode( $slug );
			$query->matched_query          = "pagename=$slug&page="; // TODO paging??
		} else { // @since 1.5.0 works with generic (custom) post type, specifically (WooCommerce) product and cartflows_step
			$query->query_vars['page']      = '';
			$query->query_vars['name']      = $slug;
			$query->query_vars[ $type ]     = $slug;
			$query->query_vars['post_type'] = $type;
			$query->request                 = $slug;
			$query->matched_rule            = '';
			$slug                           = urlencode( $slug );
			$type                           = ( 'post' === $type ) ? 'name' : urlencode( $type );
			$query->matched_query           = "$type=$slug&page="; // TODO paging??
		}
		$query->did_permalink = true;

		return $query;
	}

	public function clean_title_parts( array $title_parts ) {
		unset( $title_parts['site'] );

		return $title_parts;
	}

	/**
	 * substitute title for yoast
	 * @since 1.3.0
	 */
	public function get_title() {
		return get_the_title();
	}

	/**
	 * @param string $url WordPress inputs the url it has calculated for a post
	 *
	 * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
	 * @since 1.0.0
	 * @since 1.3.1 improved, so it also works with relative $url input (e.g. a slug)
	 */
	public function fixUrl( $url ) //, and $post if arguments is set to 2 instead of one in add_filter (during initialize)
	{
		$proposed_slug = trim( substr( ( $string = str_replace( '://', '', $url ) ), strpos( $string, '/' ) ), '/' );
		$sub_folder    = $this->sub_folder;
		if ( '' !== $sub_folder && 0 === strpos( $proposed_slug, $sub_folder ) ) {
			$proposed_slug = substr( $proposed_slug, strlen( $sub_folder ) );
		}
		if ( isset( $this->canonicals[ $proposed_slug ] ) ) {
			$url = "$this->canonical_prefix{$this->canonicals[$proposed_slug]}";
		}

		return $url;
	}

	/**
	 * sets $this->slug based on the domain for which we need to find a page
	 * registers the current page if applicable
	 * also updates $this->locale when requested
	 */
	private function setSlugAndLocaleFromDomainAndRegister() {
		if ( isset( $this->slug ) ) {
			return;
		}
		$domain = $_SERVER['HTTP_HOST'];
		// strip www
		if ( strpos( $domain, 'www.' ) === 0 ) {
			$domain = substr( $domain, 4 );
		}
		// @since 1.4.0 do not bother if this is the main domain
		$site_url = str_replace( 'www.', '', $this->site_url );
		if ( false !== strpos( $site_url, "://$domain" ) ) {
			return;
		}
		// make slug @since 1.3.3, this is the way it is stored in the db as well
		$slug = $utf8_slug = sanitize_title( $domain );
		// add any 'child' / folder url-parts but not the query string
		if ( isset( $_SERVER['REQUEST_URI'] ) && $temp_url = explode( '?', $_SERVER['REQUEST_URI'] )[0] ) {
			$slug .= rtrim( $temp_url, '/' );
		}
		// register here, @since 1.3.4 don’t set $this->slug if not serving a specific page for it
		if ( isset( $this->canonicals[ $slug ] ) ) {
			$this->slug = $slug;
		} else { // if not already in the options table, register it
			$posts = get_posts( array(
				'name'        => basename( $slug ),
				'post_status' => 'publish',
				'post_type'   => 'any'
			) );
			foreach ( $posts as $index => $post ) {
				$post_uri = get_page_uri( $post );
				if ( $slug === $post_uri ) {
					$this->slug = $slug;
					$post_type  = get_post_type( $post );
					break;
				}
			}
			if ( isset( $this->slug ) ) { // only add when the slug exists
				$slugs     = explode( '/', $slug );
				$first     = array_shift( $slugs );
				$path      = implode( '/', $slugs );
				$canonical = trailingslashit( "$domain/$path" );
				// update options for this plugin
				$this->options['canonicals'][ $slug ] = $canonical;
				$this->options['post_types'][ $slug ] = $post_type;
				$this->options_changed                = true; // flag for update (in __shutdown)
				// also remember for current request:
				$this->canonicals[ $slug ] = $canonical;
				$this->post_types[ $slug ] = $post_type;
			}
		}
		if ( isset( $this->slug ) ) { // @since 1.3.4 don’t bother for other pages / posts
			// @since 1.3.0
			if ( isset( $this->options['locales'] ) && ( ( $locales = $this->options['locales'] ) ) ) {
				if ( isset( $locales[ $utf8_slug ] ) ) {
					$this->locale = $locales[ $utf8_slug ];
				}
			}
			// correct the short link for this canonical
			$short_link = $this->canonicals[ $slug ];
			add_filter( 'pre_get_shortlink', static function () use ( $short_link ) {
				return $short_link;
			} );
		}
	}

	/**
	 * Expects a string where each name=>value pair is on a new row and uses = as separator, so:
	 * name-one=value-one
	 * etc. keys and values are trimmed and returned as a proper associative array
	 *
	 * @param $associative_array_as_string
	 *
	 * @return array
	 * @since 1.3.0
	 */
	private function stringToArray( $associative_array_as_string ) {
		if ( is_array( $associative_array_as_string ) ) {
			return $associative_array_as_string;
		}
		$arr = explode( "\n", $associative_array_as_string );
		if ( count( $arr ) > 0 ) {
			$ass = array();
			foreach ( $arr as $index => $str ) {
				$val = explode( '=', $str );
				if ( count( $val ) === 2 ) {
					$ass[ trim( $val[0] ) ] = trim( $val[1] );
				}
			}

			return $ass;
		} else {
			return array();
		}
	}

	/**
	 * the reverse of stringToArray()
	 *
	 * @param $associative_array array to be converted to string
	 *
	 * @return string formatted for textarea
	 * @since 1.3.0
	 */
	private function arrayToString( $associative_array ) {
		$return = array();
		foreach ( $associative_array as $name => $value ) {
			$return[] = "$name = $value";
		}

		return implode( "\n", $return );
	}

	/**
	 * Only used to update pre-1.6.0 installations
	 *
	 * @param $slug
	 *
	 * @return string|false The post-type, or false when not found for this slug
	 */
	private function postType( $slug ) {
		if ( isset( $this->post_types[ $slug ] ) ) {
			return $this->post_types[ $slug ];
		}

		$posts = get_posts( array( 'name' => basename( $slug ), 'post_status' => 'publish', 'post_type' => 'any' ) );
		foreach ( $posts as $index => $post ) {
			$post_uri = get_page_uri( $post );
			if ( $slug === $post_uri ) {
				return get_post_type( $post );
			}
		}

		return false;
	}

	/**
	 * @return bool true if we are currently on the settings page of this plugin, false otherwise
	 */
	private function onSettingsPage() {
		return ( isset( $_GET['page'] ) && 'each-domain-a-page' === $_GET['page'] );
	}

	/**
	 * Checks if the required lines for webfonts to work are present in the htaccess
	 *
	 * @return bool true when the lines are found, false otherwise
	 */
	private function htaccessContainsLines() {
		$htaccess = get_home_path() . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			$str = file_get_contents( $htaccess );
			if ( $start = strpos( $str, '<FilesMatch "\.(eot|ttf|otf|woff|woff2)$">' ) ) {
				if ( strpos( $str, 'Header set Access-Control-Allow-Origin "*"', $start ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * admin stuff
	 */
	public function settings() {
		/**
		 * register a new setting, call this function for each setting
		 * Arguments: (Array)
		 * - group, the same as in settings_fields, for security / nonce etc.
		 * - the name of the options
		 * - the function that will validate the options, valid options are automatically saved by WP
		 */
		register_setting( 'ruigehond007', 'ruigehond007', array( $this, 'settings_validate' ) );
		// register a new section in the page
		add_settings_section(
			'each_domain_a_page_settings', // section id
			esc_html__( 'Set your options', 'each-domain-a-page' ), // title
			function () {
				echo '<!--';
				var_dump( $this->options );
				echo '-->';
				echo '<p>';
				echo esc_html__( 'This plugin matches a slug to the domain used to access your WordPress installation and shows that page or post.', 'each-domain-a-page' );
				echo '<br/><br/><strong>';
				echo esc_html__( 'The rest of your site keeps working as usual.', 'each-domain-a-page' );
				echo '</strong><br/><br/>';
				/* TRANSLATORS: arguments here are '.', '-', 'wp-developer-eu', 'www.wp-developer.eu', 'www' */
				echo sprintf( esc_html__( 'Typing your slug: replace %1$s (dot) with %2$s (hyphen). A page or post with slug %3$s would show for the domain %4$s (with or without the %5$s).', 'each-domain-a-page' ),
					'<strong>.</strong>', '<strong>-</strong>', '<strong>wp-developer-eu</strong>', '<strong>www.wp-developer.eu</strong>', 'www' );
				echo ' ';
				/* TRANSLATORS: arguments here are 'xn-msic-0ra-com' and 'müsic.com' */
				echo sprintf( esc_html__( 'For UTF-8 characters you need to use the punycode for your slug. Example: use %1$s for the domain %2$s.', 'each-domain-a-page' ), '<strong>xn-msic-0ra-com</strong>', '<strong>müsic.com</strong>' );
				echo ' <em>';
				echo esc_html__( 'Of course the domain must reach your WordPress installation as well.', 'each-domain-a-page' );
				echo '</em></p><h2>Canonicals?</h2>';
				echo '<p><strong>';
				echo esc_html__( 'This plugin works out of the box.', 'each-domain-a-page' );
				echo '</strong><br/>';
				echo esc_html__( 'However if you want your landing pages to correctly identify with the domain, you should activate the canonicals option below.', 'each-domain-a-page' );
				echo ' ';
				echo esc_html__( 'This makes the plugin slightly slower, it will however return the domain in most cases.', 'each-domain-a-page' );
				echo ' ';
				echo esc_html__( 'Each canonical is activated by visiting the page once using the domain.', 'each-domain-a-page' );
				echo ' ';
				echo esc_html__( 'SEO plugins like Yoast may or may not interfere with this. If they do, you can probably set the desired canonical for your landing page there.', 'each-domain-a-page' );
				echo '</p><h2>Locales?</h2><p>';
				echo sprintf( esc_html__( 'If the default language of this installation is ‘%s’, you can use different locales for your slugs.', 'each-domain-a-page' ), 'English (United States)' );
				echo ' ';
				echo esc_html__( 'Otherwise this is not recommended since translation files will already be loaded and using a different locale will involve loading them again.', 'each-domain-a-page' );
				echo ' ';
				echo esc_html__( 'Use valid WordPress locales with an underscore, e.g. nl_NL, and make sure they are available in your installation.', 'each-domain-a-page' );
				echo ' <em>';
				echo esc_html__( 'Not all locales are supported by all themes.', 'each-domain-a-page' );
				echo '</em></p><h2>CORS</h2><p>';
				echo esc_html__( 'By default this plugin will configure ajax requests to be sent to the domain currently served, to avoid CORS errors.', 'each-domain-a-page' );
				echo ' ';
				echo esc_html__( 'In addition, CORS headers will be sent for configured domains.', 'each-domain-a-page' );
				echo '</p>';
			}, //callback
			'ruigehond007' // page
		);
		// add the settings (checkboxes)
		foreach (
			array(
				'use_canonical'   => __( 'Use domains as canonical url', 'each-domain-a-page' ),
				'use_www'         => __( 'Canonicals must include www', 'each-domain-a-page' ),
				'use_ssl'         => __( 'All domains have an SSL certificate installed', 'each-domain-a-page' ),
				'remove_sitename' => __( 'Use only post title as document title', 'each-domain-a-page' ),
				'force_redirect'  => __( 'Redirect pages to canonical domain always', 'each-domain-a-page' ),
			) as $setting_name => $short_text
		) {
			add_settings_field(
				"ruigehond007_$setting_name",
				$setting_name, // title
				function ( $args ) {
					$setting_name = $args['option_name'];
					$options      = $args['options'];
					// boolval = bugfix: old versions save ‘true’ as ‘1’
					$checked = boolval( ( isset( $options[ $setting_name ] ) ) ? $options[ $setting_name ] : false );
					// make checkbox that transmits 1 or 0, depending on status
					echo '<label><input type="hidden" name="ruigehond007[';
					echo $setting_name;
					echo ']" value="';
					echo ( true === $checked ) ? '1' : '0';
					echo '"><input type="checkbox"';
					if ( true === $checked ) {
						echo ' checked="checked"';
					}
					echo ' onclick="this.previousSibling.value=1-this.previousSibling.value"/>';
					echo esc_html( $args['label_for'] );
					echo '</label><br/>';
				},
				'ruigehond007',
				'each_domain_a_page_settings',
				[
					'label_for'   => $short_text,
					'class'       => 'ruigehond_row',
					'options'     => $this->options,
					'option_name' => $setting_name,
				]
			);
		}
		// @since 1.3.0 type array with locales
		foreach (
			array(
				'locales' => \sprintf( esc_html__( 'Type relations between urls and locales like ‘%s’', 'each-domain-a-page' ), 'my-slug-ca = en_CA' ),
			) as $setting_name => $short_text
		) {
			add_settings_field(
				"ruigehond007_$setting_name",
				$setting_name, // title
				function ( $args ) {
					$options      = $args['options'];
					$setting_name = $args['option_name'];
					echo '<textarea style="width:50%;" name="ruigehond007[';
					echo $setting_name;
					echo ']">';
					if ( isset( $options[ $setting_name ] ) ) {
						echo esc_html( $this->arrayToString( $options[ $setting_name ] ) );
					}
					echo '</textarea><div><em>';
					echo esc_html( $args['label_for'] );
					echo '</em></div>';
				},
				'ruigehond007',
				'each_domain_a_page_settings',
				[
					'label_for'   => $short_text,
					'options'     => $this->options,
					'option_name' => 'locales',
				]
			);
		}
		// display warning about htaccess conditionally
		if ( $this->onSettingsPage() ) { // show warning only on own options page
			if ( isset( $this->options['htaccess_warning'] ) ) {
				if ( true === $this->htaccessContainsLines() ) { // maybe the user added the lines already by hand
					//@since 1.3.0 bugfix:
					//unset($this->options['htaccess_warning']); <- this results in an error in update_option, hurray for WP :-(
					$this->options['htaccess_warning'] = null; // fortunately also returns false with isset()
					$this->options_changed             = true;
					echo '<div class="notice"><p>', esc_html__( 'Warning status cleared.', 'each-domain-a-page' ), '</p></div>';
				} else {
					echo '<div class="notice notice-warning"><p>', wp_kses_post( $this->options['htaccess_warning'] ), '</p></div>';
				}
			}
		}
	}

	/**
	 * Validates settings, especially formats the locales to an object ready for use before storing the option
	 *
	 * @param $input
	 *
	 * @return array
	 * @since 1.3.0
	 */
	public function settings_validate( $input ) {
		$options = (array) get_option( 'ruigehond007' );
		//$this->options_changed = false;

		foreach ( $input as $key => $value ) {
			switch ( $key ) {
				// on / off flags (1 vs 0 on form submit, true / false otherwise
				case 'use_canonical':
				case 'use_www':
				case 'use_ssl':
				case 'remove_sitename':
				case 'force_redirect':
					//$options[$key] = ($value === '1' or $value === true);
					$value = ( $value === '1' || $value === true ); // normalize
					if ( isset( $options[ $key ] ) && $options[ $key ] !== $value ) {
						$this->clearCacheDir();
					}
					$options[ $key ] = $value;
					break;
				case 'locales':
					$options['locales'] = $this->stringToArray( $value );
					break;
				default:
					$options[ $key ] = $value;
			}
		}

		return $options;
	}

	/**
	 * @since 1.3.1
	 */
	public function clearCacheDir() {
		return; // so far it does nothing
		if ( $this->manage_cache ) {
			if ( is_readable( ( $path = trailingslashit( $this->cache_dir ) ) ) ) {
				ruigehond007_rmdir( $path );
			}
		}
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>', esc_html( get_admin_page_title() ), '</h1><form action="options.php" method="post">';
		// output security fields for the registered setting
		settings_fields( 'ruigehond007' );
		// output setting sections and their fields
		do_settings_sections( 'ruigehond007' );
		// output save settings button
		submit_button( esc_html__( 'Save settings' ) );
		echo '</form></div>';
	}

	public function settings_link( $links ) {
		$url = get_admin_url() . 'options-general.php?page=each-domain-a-page';
		if ( isset( $this->options['htaccess_warning'] ) ) {
			$link_text     = esc_html__( 'Warning', 'each-domain-a-page' );
			$settings_link = "<a href='$url' style='color: #ffb900;'>$link_text</a>";
		} else {
			$link_text     = esc_html__( 'Settings', 'each-domain-a-page' );
			$settings_link = "<a href='$url'>$link_text</a>";
		}
		array_unshift( $links, $settings_link );

		return $links;
	}

	public function menuitem() {
		add_submenu_page(
			'', // this will hide the settings page in the "settings" menu
			'Each domain a page',
			'Each domain a page',
			'manage_options',
			'each-domain-a-page',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * plugin management functions
	 */
	public function activate() {
		if ( true === is_multisite() ) {
			wp_die( sprintf( esc_html__( '%1$s does not work on multisite installs. You should try ‘%2$s’', 'each-domain-a-page' ), 'Each domain a page', '<a href="https://github.com/joerivanveen/multisite-landingpages">Multisite landingpages</a>' ) );
		}
		$this->options_changed = true;  // will save with autoload true, and also the htaccess_warning when generated
		// add cross-origin for fonts to the htaccess
		if ( false === $this->htaccessContainsLines() ) {
			$htaccess = get_home_path() . '.htaccess';
			$lines    = array();
			$lines[]  = '<IfModule mod_headers.c>';
			$lines[]  = '<FilesMatch "\.(eot|ttf|otf|woff|woff2)$">';
			$lines[]  = 'Header set Access-Control-Allow-Origin "*"';
			$lines[]  = '</FilesMatch>';
			$lines[]  = '</IfModule>';
			if ( ! insert_with_markers( $htaccess, 'ruigehond007', $lines ) ) {
				foreach ( $lines as $key => $line ) {
					$lines[ $key ] = htmlentities( $line );
				}
				$warning = '<strong>Each-domain-a-page</strong><br/>';
				$warning .= esc_html__( 'In order for webfonts to work on alternative domains you need to add the following lines to your .htaccess:', 'each-domain-a-page' );
				$warning .= '<br/><em>(';
				$warning .= esc_html__( 'In addition you need to have mod_headers available.', 'each-domain-a-page' );
				$warning .= ')</em><br/>&nbsp;<br/>';
				$warning .= '<CODE>' . implode( '<br/>', $lines ) . '</CODE>';
				// report the lines to the user
				$this->options['htaccess_warning'] = $warning;
			}
		}
	}
}

/**
 * proxy functions for deactivate and uninstall
 */
function ruigehond007_activate() {
	$plugin = new ruigehond007();
	$plugin->activate();
}

function ruigehond007_deactivate() {
	// as a means to clear the canonicals, upon deactivation we remove them from the options
	if ( $option = get_option( 'ruigehond007' ) ) {
		$option['canonicals'] = null;
		$option['post_types'] = null;
		update_option( 'ruigehond007', $option );
	}
}

function ruigehond007_uninstall() {
	// remove settings
	delete_option( 'ruigehond007' );
}

if ( wp_doing_ajax() ) {
	if ( headers_sent() ) {
		error_log( 'Each domain a page: headers already sent, cannot send CORS headers' );
	} else {
		$plugin = new ruigehond007();
		$plugin->initialize();
	}
}

/**
 * @param $dir
 *
 * @since 1.3.1
 */
function ruigehond007_rmdir( $dir ) {
	if ( is_dir( $dir ) ) {
		$handle = opendir( $dir );
		while ( false !== ( $object = readdir( $handle ) ) ) {
			if ( $object !== '.' && $object !== '..' ) {
				$path = $dir . '/' . $object;
				echo $object, ': ', filetype( $path ), '<br/>';
				if ( filetype( $path ) === 'dir' ) {
					ruigehond007_rmdir( $path );
				} else {
					unlink( $path );
				}
			}
		}
		rmdir( $dir );
	}
}
