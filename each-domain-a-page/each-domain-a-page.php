<?php
/*
Plugin Name: Each domain a page
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Serves a specific landing page from WordPress depending on the domain used to access the WordPress installation.
Version: 1.5.1
Author: Joeri van Veen
Author URI: https://wp-developer.eu
License: GPLv3
Text Domain: each-domain-a-page
Domain Path: /languages/
*/
defined('ABSPATH') || die();
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond007.
define('RUIGEHOND007_VERSION', '1.5.1');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, 'ruigehond007_activate');
register_deactivation_hook(__FILE__, 'ruigehond007_deactivate');
register_uninstall_hook(__FILE__, 'ruigehond007_uninstall');
// Startup the plugin
add_action('init', array(new ruigehond007(), 'initialize'));

//
class ruigehond007
{
    private $options, $options_changed, $use_canonical, $canonicals, $canonical_prefix, $remove_sitename_from_title = false;
    // @since 1.3.0
    private $slug, $locale, $post_types = array(); // cached values

    /**
     * ruigehond007 constructor
     * loads settings that are available also based on current url
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->options_changed = false; // if a domain is registered with a slug, this will flag true, and the options must be saved in __shutdown()
        // @since 1.3.0 changed __destruct to __shutdown for stability reasons
        register_shutdown_function(array(&$this, '__shutdown'));
        // continue
        $this->options = get_option('ruigehond007');
        if (isset($this->options)) {
            // ATTENTION for the options do not use true === ‘option’, because previous versions work with ‘1’ as a value
            $this->use_canonical = (isset($this->options['use_canonical']) && $this->options['use_canonical']);
            // fix @since 1.3.4 you need the canonicals for the ajax hook, so load them always
            if (isset($this->options['canonicals']) && is_array($this->options['canonicals'])) {
                $this->canonicals = $this->options['canonicals'];
            } else {
                $this->canonicals = array();
            }
            if ($this->use_canonical) {
                if (isset($this->options['use_ssl']) && $this->options['use_ssl']) {
                    $this->canonical_prefix = 'https://';
                } else {
                    $this->canonical_prefix = 'http://';
                }
                if (isset($this->options['use_www']) && $this->options['use_www']) $this->canonical_prefix .= 'www.';
            }
            $this->remove_sitename_from_title = isset($this->options['remove_sitename']) && $this->options['remove_sitename'];
        } else {
            $this->options = array(); // set default options (currently none)
            $this->options_changed = true;
        }
        if (false === isset($this->canonical_prefix)) { // @since 1.3.5 set the prefix to what’s current
            if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $this->canonical_prefix = "{$_SERVER['HTTP_X_FORWARDED_PROTO']}://";
            } elseif (isset($_SERVER['SERVER_PROTOCOL']) && stripos($_SERVER['SERVER_PROTOCOL'], 'https') === 0) {
                $this->canonical_prefix = 'https://';
            } else {
                $this->canonical_prefix = 'http://';
            }
        }
        // set slug and locale that are solely based on the requested domain, which is available already
        $this->setSlugAndLocaleFromDomainAndRegister();
        // https://wordpress.stackexchange.com/a/89965
        if (isset($this->locale)) add_filter('locale', array($this, 'getLocale'), 1, 1);
    }

    /**
     * Makes sure options are saved at the end of the request when they changed since the beginning
     * @since 1.0.0
     * @since 1.3.0: generate notice upon fail
     */
    public function __shutdown()
    {
        if (true === $this->options_changed) {
            if (false === update_option('ruigehond007', $this->options, true)) {
                error_log(__('Failed saving options (each domain a page)', 'each-domain-a-page'));
            }
        }
    }

    /**
     * initialize the plugin, sets up necessary filters and actions.
     * @since 1.0.0
     */
    public function initialize()
    {
        if (is_admin()) {
            load_plugin_textdomain('each-domain-a-page', false, dirname(plugin_basename(__FILE__)) . '/languages/');
            add_action('admin_init', array($this, 'settings'));
            add_action('admin_menu', array($this, 'menuitem')); // necessary to have the page accessible to user
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'settings_link')); // settings link on plugins page
        } else {
            // original
            add_action('parse_request', array($this, 'get')); // passes WP_Query object
            if ($this->use_canonical) {
                // fix the canonical url for functions that get the url, subject to additions...
                foreach (array(
                             'post_link',
                             'page_link',
                             'post_type_link',
                             'get_canonical_url',
                             'wpseo_opengraph_url', // Yoast
                             'wpseo_canonical', // Yoast
                         ) as $filter) {
                    add_filter($filter, array($this, 'fixUrl'), 99, 1);
                }
            }
        }
        // manage the domains
        // reroute standard ajax requests to current domain
        add_filter('admin_url', array($this, 'adminUrl'));
        // send a cors header for when it did not work
        $org = trailingslashit(str_replace(array('http://', 'https://', 'www.'), '', get_http_origin()));
        if (true === in_array($org, $this->canonicals)) {
            header('Access-Control-Allow-Origin: ' . get_http_origin());
            header('Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE');
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin', false);
        }
    }

    /**
     * Returns a correct url for pages that are accessed on a different domain than the original blog enabling
     * ajax calls without the dreaded cross-origin errors (as long as people use the recommended get_admin_url())
     * @param $url
     * @return string|string[]
     * @since 1.3.0
     * @since 1.3.1 fix: substitute correct new domain rather than make it relative
     */
    public function adminUrl($url)
    {
        $slug = $this->slug;
        if (isset($this->canonicals[$slug])) {
            return str_replace(get_site_url(), $this->fixUrl($slug), $url);
        }

        return $url;
    }

    /**
     * Hook for the locale, set with ->initialize() when $this->locale is set
     * @return string the locale set by each-domain-a-page
     * @since 1.3.0
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * ‘get’ is the actual functionality of the plugin
     *
     * @param $query Object holding the query prepared by WordPress
     * @return mixed Object is returned either unchanged, or the request has been updated with the post to show
     */
    public function get($query)
    {
        // @since 1.3.4 don’t bother processing if not a page handled by the plugin...
        if (false === isset($this->slug)) return $query;
        $slug = $this->slug;
        if (($type = $this->postType($slug))) { // falsy when slug not found
            if ($this->remove_sitename_from_title) {
                if (false !== has_action('wp_head', '_wp_render_title_tag')) {
                    remove_action('wp_head', '_wp_render_title_tag', 1);
                    add_action('wp_head', array($this, 'render_title_tag'), 1);
                }
                add_filter('wpseo_title', array($this, 'get_title'), 1);
            }
            if ('page' === $type) {
                unset($query->query_vars['name']);
                $query->query_vars['pagename'] = $slug;
                $query->request = $slug;
                $slug = urlencode($slug);
                $query->matched_query = "pagename=$slug&page="; // TODO paging??
            } else { // @since 1.5.0 works with generic (custom) post type, specifically (WooCommerce) product and cartflows_step
                $query->query_vars['page'] = '';
                $query->query_vars['name'] = $slug;
                $query->query_vars[$type] = $slug;
                $query->query_vars['post_type'] = $type;
                $query->request = $slug;
                $query->matched_rule = '';
                $slug = urlencode($slug);
                $type = ('post' === $type) ? 'name' : urlencode($type);
                $query->matched_query = "$type=$slug&page="; // TODO paging??
            }
            $query->did_permalink = true;
        }

        return $query;
    }

    /**
     * substitute for standard wp title rendering to remove the site name
     * @since 1.2.2
     */
    public function render_title_tag()
    {
        echo '<title>', get_the_title(), '</title>';
    }

    /**
     * substitute title for yoast
     * @since 1.3.0
     */
    public function get_title()
    {
        return get_the_title();
    }

    /**
     * @param string $url WordPress inputs the url it has calculated for a post
     * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
     * @since 1.0.0
     * @since 1.3.1 improved so it also works with relative $url input (e.g. a slug)
     */
    public function fixUrl($url) //, and $post if arguments is set to 2 instead of one in add_filter (during initialize)
    {
        $proposed_slug = basename($url);
        if (isset($this->canonicals[$proposed_slug])) {
            $url = "{$this->canonical_prefix}{$this->canonicals[$proposed_slug]}";
        } else {
            // @since 1.4.0: also check if the slug is the last part of the url, for child pages
            $proposed_slug = "/$proposed_slug";
            $length = -strlen($proposed_slug); // negative length counts from the end
            foreach ($this->canonicals as $slug => $canonical) {
                if (substr($slug, $length) === $proposed_slug) {
                    $url = "{$this->canonical_prefix}$canonical";
                    break;
                }
            }
        }

        return $url;
    }

    /**
     * sets $this->slug based on the domain for which we need to find a page
     * registers the current page if applicable
     * also updates $this->locale when requested
     */
    private function setSlugAndLocaleFromDomainAndRegister()
    {
        if (isset($this->slug)) return;
        $domain = $_SERVER['HTTP_HOST'];
        // strip www
        if (strpos($domain, 'www.') === 0) $domain = substr($domain, 4);
        // @since 1.4.0 do not bother if this is the main domain
        $site_url = str_replace('www.', '', get_site_url());
        if (false !== strpos($site_url, "://$domain")) return;
        // make slug @since 1.3.3, this is the way it is stored in the db as well
        $slug = sanitize_title($domain);
        $path = '';
        // @since 1.4.0 add support for child pages, get the final slug from the url, which is the child
        if (isset($_SERVER['REQUEST_URI']) && '' !== ($child = basename($_SERVER['REQUEST_URI']))) {
            $args = array(
                'name' => $child,
                'post_type' => array('page'),
                'post_status' => 'publish',
                'numberposts' => 1
            );
            $posts = get_posts($args);
            if (isset($posts[0])) {
                $page = get_page_uri($posts[0]);
                // if the ultimate parent is indeed the domain, set the path and slug accordingly
                if (0 === strpos($page, "$slug/")) {
                    $slug = $page; // the whole thing WordPress uses to find the page
                    $path = substr($page, strpos($page, '/') + 1); // strip the domain part
                } else {
                    // this page does not belong to this domain, so don’t bother
                    $domain = get_site_url();
                    header('HTTP/1.1 301 Moved Permanently');
                    header("Location: $domain/$page");
                    die();
                }
            }
        }
        // register here, @since 1.3.4 don’t set $this->slug if not serving a specific page for it
        if (isset($this->canonicals[$slug])) {
            $this->slug = $slug;
        } else { // if not already in the options table
            if ('' !== $path || $this->postType($slug)) { // @since 1.3.2 only add when the slug exists
                $canonical = "$domain/$path";
                $this->options['canonicals'][$slug] = $canonical;
                $this->options_changed = true; // flag for update (in __shutdown)
                $this->canonicals[$slug] = $canonical; // also remember for current request
                $this->slug = $slug;
            }
        }
        if (isset($this->slug)) { // @since 1.3.4 don’t bother for other pages / posts
            // @since 1.3.0
            if (isset($this->options['locales']) && ($locales = $this->options['locales'])) {
                $utf8_slug = str_replace('.', '-', $domain); // @since 1.3.6
                if (isset($locales[$utf8_slug])) $this->locale = $locales[$utf8_slug];
            }
            // @since 1.3.2 correct the shortlink for this canonical
            if ('' === $path) add_filter('pre_get_shortlink', static function () use ($domain) {
                // todo, add shortlinks for child pages?
                return $domain;
            });
        }
    }

    /**
     * Expects a string where each name=>value pair is on a new row and uses = as separator, so:
     * name-one=value-one
     * etc. keys and values are trimmed and returned as a proper associative array
     * @param $associative_array_as_string
     * @return array
     * @since 1.3.0
     */
    private function stringToArray($associative_array_as_string)
    {
        if (is_array($associative_array_as_string)) return $associative_array_as_string;
        $arr = explode("\n", $associative_array_as_string);
        if (count($arr) > 0) {
            $ass = array();
            foreach ($arr as $index => $str) {
                $val = explode('=', $str);
                if (count($val) === 2) {
                    $ass[trim($val[0])] = trim($val[1]);
                }
            }

            return $ass;
        } else {
            return array();
        }
    }

    /**
     * the reverse of stringToArray()
     * @param $associative_array array to be converted to string
     * @return string formatted for textarea
     * @since 1.3.0
     */
    private function arrayToString($associative_array)
    {
        $return = array();
        foreach ($associative_array as $name => $value) {
            $return[] = "$name = $value";
        }

        return implode("\n", $return);
    }

    /**
     * @param $slug
     * @return string|null The post-type, or null when not found for this slug
     */
    private function postType($slug)
    {
        if (isset($this->post_types[$slug])) return $this->post_types[$slug];
        global $wpdb;
        $safe_slug = addslashes(basename($slug)); // NOTE only search for the last part in the path, basename
        $type = $wpdb->get_var("SELECT post_type FROM {$wpdb->prefix}posts 
            WHERE post_name = '$safe_slug' AND post_status = 'publish';");
        $this->post_types[$slug] = $type;

        return $type;
    }

    /**
     * @return bool true if we are currently on the settings page of this plugin, false otherwise
     */
    private function onSettingsPage()
    {
        return (isset($_GET['page']) && 'each-domain-a-page' === $_GET['page']);
    }

    /**
     * Checks if the required lines for webfonts to work are present in the htaccess
     *
     * @return bool true when the lines are found, false otherwise
     */
    private function htaccessContainsLines()
    {
        $htaccess = get_home_path() . '.htaccess';
        if (file_exists($htaccess)) {
            $str = file_get_contents($htaccess);
            if ($start = strpos($str, '<FilesMatch "\.(eot|ttf|otf|woff|woff2)$">')) {
                if (strpos($str, 'Header set Access-Control-Allow-Origin "*"', $start)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * admin stuff
     */
    public function settings()
    {
        /**
         * register a new setting, call this function for each setting
         * Arguments: (Array)
         * - group, the same as in settings_fields, for security / nonce etc.
         * - the name of the options
         * - the function that will validate the options, valid options are automatically saved by WP
         */
        register_setting('ruigehond007', 'ruigehond007', array($this, 'settings_validate'));
        // register a new section in the page
        add_settings_section(
            'each_domain_a_page_settings', // section id
            __('Set your options', 'each-domain-a-page'), // title
            function () {
                echo '<p>';
                echo __('This plugin matches a slug to the domain used to access your WordPress installation and shows that page or post.', 'each-domain-a-page');
                echo '<br/>';
                echo '<br/><strong>';
                echo __('The rest of your site keeps working as usual.', 'each-domain-a-page');
                echo '</strong><br/><br/>';
                /* TRANSLATORS: arguments here are '.', '-', 'wp-developer-eu', 'www.wp-developer.eu', 'www' */
                echo sprintf(__('Typing your slug: replace %1$s (dot) with %2$s (hyphen). A page or post with slug %3$s would show for the domain %4$s (with or without the %5$s).', 'each-domain-a-page'),
                    '<strong>.</strong>', '<strong>-</strong>', '<strong>wp-developer-eu</strong>', '<strong>www.wp-developer.eu</strong>', 'www');
                echo ' ';
                /* TRANSLATORS: arguments here are 'xn-msic-0ra-com' and 'müsic.com' */
                echo sprintf(__('For UTF-8 characters you need to use the punycode for your slug. Example: use %1$s for the domain %2$s.', 'each-domain-a-page'), '<strong>xn-msic-0ra-com</strong>', '<strong>müsic.com</strong>');
                echo ' <em>';
                echo __('Of course the domain must reach your WordPress installation as well.', 'each-domain-a-page');
                echo '</em></p><h2>Canonicals?</h2>';
                echo '<!--';
                var_dump($this->options['canonicals']);
                echo '-->';
                echo '<p><strong>';
                echo __('This plugin works out of the box.', 'each-domain-a-page');
                echo '</strong><br/>';
                echo __('However if you want your landing pages to correctly identify with the domain, you should activate the canonicals option below.', 'each-domain-a-page');
                echo ' ';
                echo __('This makes the plugin slightly slower, it will however return the domain in most cases.', 'each-domain-a-page');
                echo ' ';
                echo __('Each canonical is activated by visiting the page once using the domain.', 'each-domain-a-page');
                echo ' ';
                echo __('SEO plugins like Yoast may or may not interfere with this. If they do, you can probably set the desired canonical for your landing page there.', 'each-domain-a-page');
                echo '</p><h2>Locales?</h2><p>';
                echo sprintf(__('If the default language of this installation is ‘%s’, you can use different locales for your slugs.', 'each-domain-a-page'), 'English (United States)');
                echo ' ';
                echo __('Otherwise this is not recommended since translation files will already be loaded and using a different locale will involve loading them again.', 'each-domain-a-page');
                echo ' ';
                echo __('Use valid WordPress locales with an underscore, e.g. nl_NL, and make sure they are available in your installation.', 'each-domain-a-page');
                echo ' <em>';
                echo __('Not all locales are supported by all themes.', 'each-domain-a-page');
                echo '</em></p><h2>CORS</h2><p>';
                echo __('By default this plugin will configure ajax requests to be sent to the domain currently served, to avoid CORS errors.', 'each-domain-a-page');
                echo ' ';
                echo __('In addition, CORS headers will be sent for configured domains.', 'each-domain-a-page');
                echo '</p>';
            }, //callback
            'ruigehond007' // page
        );
        // add the settings (checkboxes)
        foreach (array(
                     'use_canonical' => __('Use domains as canonical url', 'each-domain-a-page'),
                     'use_www' => __('Canonicals must include www', 'each-domain-a-page'),
                     'use_ssl' => __('All domains have an SSL certificate installed', 'each-domain-a-page'),
                     'remove_sitename' => __('Use only post title as document title', 'each-domain-a-page'),
                 ) as $setting_name => $short_text) {
            add_settings_field(
                "ruigehond007_$setting_name",
                $setting_name, // title
                function ($args) {
                    $setting_name = $args['option_name'];
                    $options = $args['options'];
                    // boolval = bugfix: old versions save ‘true’ as ‘1’
                    $checked = boolval((isset($options[$setting_name])) ? $options[$setting_name] : false);
                    // make checkbox that transmits 1 or 0, depending on status
                    echo '<label><input type="hidden" name="ruigehond007[';
                    echo $setting_name;
                    echo ']" value="';
                    echo (true === $checked) ? '1' : '0';
                    echo '"><input type="checkbox"';
                    if (true === $checked) echo ' checked="checked"';
                    echo ' onclick="this.previousSibling.value=1-this.previousSibling.value"/>';
                    echo $args['label_for'];
                    echo '</label><br/>';
                },
                'ruigehond007',
                'each_domain_a_page_settings',
                [
                    'label_for' => $short_text,
                    'class' => 'ruigehond_row',
                    'options' => $this->options,
                    'option_name' => $setting_name,
                ]
            );
        }
        // @since 1.3.0 type array with locales
        foreach (array(
                     'locales' => \sprintf(__('Type relations between urls and locales like ‘%s’', 'each-domain-a-page'), 'my-slug-ca = en_CA'),
                 ) as $setting_name => $short_text) {
            add_settings_field(
                "ruigehond007_$setting_name",
                $setting_name, // title
                function ($args) {
                    $options = $args['options'];
                    $setting_name = $args['option_name'];
                    echo '<textarea style="width:50%;" name="ruigehond007[';
                    echo $setting_name;
                    echo ']">';
                    if (isset($options[$setting_name])) echo $this->arrayToString($options[$setting_name]);
                    echo '</textarea><div><em>';
                    echo $args['label_for'];
                    echo '</em></div>';
                },
                'ruigehond007',
                'each_domain_a_page_settings',
                [
                    'label_for' => $short_text,
                    'options' => $this->options,
                    'option_name' => 'locales',
                ]
            );
        }
        // display warning about htaccess conditionally
        if ($this->onSettingsPage()) { // show warning only on own options page
            if (isset($this->options['htaccess_warning'])) {
                if (true === $this->htaccessContainsLines()) { // maybe the user added the lines already by hand
                    //@since 1.3.0 bugfix:
                    //unset($this->options['htaccess_warning']); <- this results in an error in update_option, hurray for WP :-(
                    $this->options['htaccess_warning'] = null; // fortunately also returns false with isset()
                    $this->options_changed = true;
                    echo '<div class="notice"><p>', __('Warning status cleared.', 'each-domain-a-page'), '</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>', $this->options['htaccess_warning'], '</p></div>';
                }
            }
        }
    }

    /**
     * Validates settings, especially formats the locales to an object ready for use before storing the option
     * @param $input
     * @return array
     * @since 1.3.0
     */
    public function settings_validate($input)
    {
        $options = (array)get_option('ruigehond007');
        foreach ($input as $key => $value) {
            switch ($key) {
                // on / off flags (1 vs 0 on form submit, true / false otherwise
                case 'use_canonical':
                case 'use_www':
                case 'use_ssl':
                case 'remove_sitename':
                    //$options[$key] = ($value === '1' or $value === true);
                    $value = ($value === '1' || $value === true); // normalize
                    if (isset($options[$key]) && $options[$key] !== $value) {
                        $this->clearCacheDir();
                    }
                    $options[$key] = $value;
                    break;
                case 'locales':
                    $options['locales'] = $this->stringToArray($value);
                    break;
                default:
                    $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * @since 1.3.1
     */
    public function clearCacheDir()
    {
        return; // so far it does nothing
        if ($this->manage_cache) {
            if (is_readable(($path = trailingslashit($this->cache_dir)))) {
                ruigehond007_rmdir($path);
            }
        }
    }

    public function settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>', esc_html(get_admin_page_title()), '</h1><form action="options.php" method="post">';
        // output security fields for the registered setting
        settings_fields('ruigehond007');
        // output setting sections and their fields
        do_settings_sections('ruigehond007');
        // output save settings button
        submit_button(__('Save settings', 'each-domain-a-page'));
        echo '</form></div>';
    }

    public function settings_link($links)
    {
        $url = get_admin_url() . 'options-general.php?page=each-domain-a-page';
        if (isset($this->options['htaccess_warning'])) {
            $link_text = __('Warning', 'each-domain-a-page');
            $settings_link = "<a href='$url' style='color: #ffb900;'>$link_text</a>";
        } else {
            $link_text = __('Settings', 'each-domain-a-page');
            $settings_link = "<a href='$url'>$link_text</a>";
        }
        array_unshift($links, $settings_link);

        return $links;
    }

    public function menuitem()
    {
        add_submenu_page(
            null, // this will hide the settings page in the "settings" menu
            'Each domain a page',
            'Each domain a page',
            'manage_options',
            'each-domain-a-page',
            array($this, 'settings_page')
        );
    }

    /**
     * plugin management functions
     */
    public function activate()
    {
        if (true === is_multisite()) wp_die(sprintf(__('%1$s does not work on multisite installs. You should try ‘%2$s’', 'each-domain-a-page'), 'Each domain a page', '<a href="https://github.com/joerivanveen/multisite-landingpages">Multisite landingpages</a>'));
        $this->options_changed = true;  // will save with autoload true, and also the htaccess_warning when generated
        // add cross-origin for fonts to the htaccess
        if (false === $this->htaccessContainsLines()) {
            $htaccess = get_home_path() . '.htaccess';
            $lines = array();
            $lines[] = '<IfModule mod_headers.c>';
            $lines[] = '<FilesMatch "\.(eot|ttf|otf|woff|woff2)$">';
            $lines[] = 'Header set Access-Control-Allow-Origin "*"';
            $lines[] = '</FilesMatch>';
            $lines[] = '</IfModule>';
            if (!insert_with_markers($htaccess, 'ruigehond007', $lines)) {
                foreach ($lines as $key => $line) {
                    $lines[$key] = htmlentities($line);
                }
                $warning = '<strong>Each-domain-a-page</strong><br/>';
                $warning .= __('In order for webfonts to work on alternative domains you need to add the following lines to your .htaccess:', 'each-domain-a-page');
                $warning .= '<br/><em>(';
                $warning .= __('In addition you need to have mod_headers available.', 'each-domain-a-page');
                $warning .= ')</em><br/>&nbsp;<br/>';
                $warning .= '<CODE>' . implode('<br/>', $lines) . '</CODE>';
                // report the lines to the user
                $this->options['htaccess_warning'] = $warning;
            }
        }
    }
}

/**
 * proxy functions for deactivate and uninstall
 */
function ruigehond007_activate()
{
    $plugin = new ruigehond007();
    $plugin->activate();
}

function ruigehond007_deactivate()
{
    // as a means to clear the canonicals, upon deactivation we remove them from the options
    if ($option = get_option('ruigehond007')) {
        $option['canonicals'] = null;
        update_option('ruigehond007', $option);
    }
}

function ruigehond007_uninstall()
{
    // remove settings
    delete_option('ruigehond007');
}

if (wp_doing_ajax()) {
    if (headers_sent()) {
        error_log('Each domain a page: headers already sent, cannot send CORS headers');
    } else {
        $plugin = new ruigehond007();
        $plugin->initialize();
    }
}

/**
 * @param $dir
 * @since 1.3.1
 */
function ruigehond007_rmdir($dir)
{
    if (is_dir($dir)) {
        $handle = opendir($dir);
        while (false !== ($object = readdir($handle))) {
            if ($object !== '.' && $object !== '..') {
                $path = $dir . '/' . $object;
                echo $object, ': ', filetype($path), '<br/>';
                if (filetype($path) === 'dir') {
                    ruigehond007_rmdir($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }
}