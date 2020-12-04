<?php
/*
Plugin Name: Each domain a page
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Serves a specific landing page from Wordpress depending on the domain used to access the Wordpress installation.
Version: 1.2.3
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: each-domain-a-page
Domain Path: /languages/
*/
defined('ABSPATH') or die();
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond007.
Define('RUIGEHOND007_VERSION', '1.3.0');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, array(new ruigehond007(), 'install'));
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

    public function __construct()
    {
        $this->options_changed = false; // if a domain is registered with a slug, this will flag true, and the options must be saved in __destruct()
        // @since 1.3.0 changed __destruct to __shutdown for stability reasons
        register_shutdown_function(array(&$this, '__shutdown'));
        // continue
        $this->options = get_option('ruigehond007');
        if (isset($this->options)) {
            $this->use_canonical = isset($this->options['use_canonical']);
            if ($this->use_canonical) {
                if (isset($this->options['canonicals']) and is_array($this->options['canonicals'])) {
                    $this->canonicals = $this->options['canonicals'];
                } else {
                    $this->canonicals = array();
                }
                if (isset($this->options['use_ssl'])) {
                    $this->canonical_prefix = 'https://';
                } else {
                    $this->canonical_prefix = 'http://';
                }
                if (isset($this->options['use_www'])) $this->canonical_prefix .= 'www.';
            }
            $this->remove_sitename_from_title = isset($this->options['remove_sitename']);
        } else {
            $this->options = array(); // set default options (currently none)
            $this->options_changed = true;
        }
        // set slug and locale that are solely based on the requested domain, which is available already
        $this->setSlugAndLocaleFromDomainAndRegister();
    }

    public function __shutdown()
    {
        if ($this->options_changed === true) {
            update_option('ruigehond007', $this->options, true);
        }
    }

    public function initialize()
    {
        // for ajax requests that (hopefully) use get_admin_url() you need to set them to the current domain if
        // applicable to avoid cross origin errors
        add_filter('admin_url', array($this, 'adminUrl'));
        if (is_admin()) {
            load_plugin_textdomain('each-domain-a-page', false, dirname(plugin_basename(__FILE__)) . '/languages/');
            add_action('admin_init', array($this, 'settings'));
            add_action('admin_menu', array($this, 'menuitem')); // necessary to have the page accessible to user
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'settingslink')); // settings link on plugins page
        } else {
            // https://wordpress.stackexchange.com/a/89965
            add_filter('locale', array($this, 'getLocale'), 10);
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
                         ) as $filter) {
                    add_filter($filter, array($this, 'fixUrl'), 10, 1);
                }
            }
        }
    }

    /**
     * Returns a relative url for pages that are accessed on a different domain than the original blog enabling
     * ajax calls without the dreaded cross origin errors (as long as people use the recommended get_admin_url())
     * @param $url
     * @return string|string[]
     * @since 1.3.0
     */
    public function adminUrl($url)
    {
        if ($this->postType($this->slug)) return str_replace(get_site_url(), '', $url);

        return $url;
    }

    /**
     * Hook for the locale, set with ->initialize()
     * @param $locale
     * @return string the locale set by each-domain-a-page, fallback to the current one (just) set by Wordpress
     * @since 1.3.0
     */
    public function getLocale($locale)
    {
        return isset($this->locale) ? $this->locale : $locale;
    }

    /**
     * 'Get' is the actual functionality of the plugin
     *
     * @param $query Object holding the query prepared by Wordpress
     * @return mixed Object is returned either unchanged, or the request has been updated with the post to show
     */
    public function get($query)
    {
        $slug = $this->slug;
        if ($type = $this->postType($slug)) { // fails when post not found, null is returned which is falsy
            if ($this->remove_sitename_from_title) {
                if (has_action('wp_head', '_wp_render_title_tag') == 1) {
                    remove_action('wp_head', '_wp_render_title_tag', 1);
                    add_action('wp_head', array($this, 'render_title_tag'), 1);
                }
            }
            if ($type === 'page') {
                $query->query_vars['pagename'] = $slug;
                $query->query_vars['request'] = $slug;
                $query->query_vars['did_permalink'] = true;
            } elseif ($type === 'post') {
                $query->query_vars['name'] = $slug;
                $query->request = $slug;
                $query->matched_query = 'name=' . $slug . '$page='; // TODO paging??
                $query->did_permalink = true;
            } // does not work with custom post types (yet) TODO redirect to homepage?
        }

        return $query;
    }

    /**
     * substitute for standard wp title rendering to remove the site name
     * @since 1.2.2
     */
    public function render_title_tag()
    {
        $title = get_the_title();
        echo '<title>' . $title . '</title>';
    }

    /**
     * @param string $url Wordpress inputs the url it has calculated for a post
     * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
     */
    public function fixUrl($url) //, and $post if arguments is set to 2 in stead of one in add_filter (during initialize)
    {
        if ($index = strrpos($url, '/', -2)) { // skip over the trailing slash
            $proposed_slug = str_replace('/', '', str_replace('www-', '', substr($url, $index + 1)));
            if (isset($this->canonicals[$proposed_slug])) {
                $url = $this->canonical_prefix . $this->canonicals[$proposed_slug];
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
        // make slug by replacing dot with hyphen
        $slug = str_replace('.', '-', $domain);
        /**
         * And register here if applicable:
         */
        if ($this->use_canonical) {
            if (!isset($this->canonicals[$slug])) { // if not already in the options table
                $this->options['canonicals'][$slug] = $domain; // remember original domain for slug
                $this->options_changed = true; // flag for update (in __shutdown)
            }
        }
        // @since 1.3.0
        $locales = array(
            'wordpresscoder-nl' => 'nl_NL',
        );
        $this->slug = $slug;
        if (isset($locales[$slug])) $this->locale = $locales[$slug];
    }

    /**
     * @param $slug
     * @return string|null The post-type, or null when not found for this slug
     */
    private function postType($slug)
    {
        if (isset($this->post_types[$slug])) return $this->post_types[$slug];
        global $wpdb;
        $sql = 'SELECT post_type FROM ' . $wpdb->prefix . 'posts 
        WHERE post_name = \'' . addslashes($slug) . '\' AND post_status = \'publish\';';
        $slug = $wpdb->get_var($sql);
        $this->post_types[$slug] = $slug;

        return $slug;
    }

    /**
     * @return bool true if we are currently on the settings page of this plugin, false otherwise
     */
    private function onSettingsPage()
    {
        return (isset($_GET['page']) && $_GET['page'] === 'each-domain-a-page');
    }

    /**
     * Checks if the required lines for webfonts to work are present in the htaccess
     *
     * @return bool true when the lines are found, false otherwise
     */
    private function htaccessContainsLines()
    {
        $htaccess = get_home_path() . ".htaccess";
        if (file_exists($htaccess)) {
            $str = file_get_contents($htaccess);
            if ($start = strpos($str, '<FilesMatch "\.(eot|ttf|otf|woff)$">')) {
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
        register_setting('ruigehond007', 'ruigehond007', 'ruigehond007_settings_validate');
        // register a new section in the page
        add_settings_section(
            'each_domain_a_page_settings', // section id
            __('Set your options', 'each-domain-a-page'), // title
            function () {
                echo '<p>' . __('This plugin matches a slug to the domain used to access your Wordpress installation and shows that page or post.', 'each-domain-a-page') .
                    '<br/><strong>' . __('The rest of your site keeps working as usual.', 'each-domain-a-page') . '</strong>' .
                    '<br/>' .
                    /* TRANSLATORS: arguments here are '.', '-', 'example-com', 'www.example.com', 'www' */
                    '<br/>' . sprintf(__('Typing your slug: replace %1$s (dot) with %2$s (hyphen). A page or post with slug %3$s would show for the domain %4$s (with or without the %5$s).', 'each-domain-a-page'),
                        '<strong>.</strong>', '<strong>-</strong>', '<strong>example-com</strong>', '<strong>www.example.com</strong>', 'www') .
                    ' <em>' . __('Of course the domain must reach your Wordpress installation as well.', 'each-domain-a-page') . '</em>' .
                    '</p><h2>Canonicals?</h2><p>' .
                    '<strong>' . __('This plugin works out of the box.', 'each-domain-a-page') . '</strong>' .
                    '&nbsp;' . __('However if you want your landing pages to correctly identify with the domain, you should activate the canonicals option below.', 'each-domain-a-page') .
                    '&nbsp;' . __('This makes the plugin slightly slower, it will however return the domain in most cases.', 'each-domain-a-page') .
                    '&nbsp;' . __('SEO plugins like Yoast may or may not interfere with this. If they do, you can probably set the desired canonical for your landing page there.', 'each-domain-a-page') .
                    '<br/><em>' . __('The canonicals work after you visited the page once with the domain in your address bar (so not the first time).', 'each-domain-a-page') .
                    '</em></p>';
            }, //callback
            'ruigehond007' // page
        );
        // add the settings (checkboxes)
        foreach (array(
                     'use_canonical' => __('Use domains as canonical url', 'each-domain-a-page'),
                     'use_www' => __('Canonicals must include www', 'each-domain-a-page'),
                     'use_ssl' => __('All domains have an SSL certificate installed', 'each-domain-a-page'),
                     'remove_sitename' => __('Remove site title from document title', 'each-domain-a-page'),
                 ) as $setting_name => $short_text) {
            add_settings_field(
                'ruigehond007_' . $setting_name,
                '',
                function ($args) {
                    $options = $args['options'];
                    $setting_name = $args['option_name'];
                    echo '<label><input type="checkbox" name="ruigehond007[' . $setting_name . ']" value="1"';
                    if (isset($options[$setting_name])) {
                        echo ' checked="checked"';
                    }
                    echo '/> ' . $args['label_for'] . '</label><br/>';
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
        // display warning about htaccess conditionally
        if ($this->onSettingsPage()) { // show warning only on own options page
            if (isset($this->options['htaccess_warning'])) {
                if ($this->htaccessContainsLines()) { // maybe the user added the lines already by hand
                    unset($this->options['htaccess_warning']);
                    $this->options_changed = true;
                    echo '<div class="notice"><p>' . __('Warning status cleared.', 'each-domain-a-page') . '</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>' . $this->options['htaccess_warning'] . '</p></div>';
                }
            }
        }
    }

    public function settingspage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html(get_admin_page_title()) . '</h1><form action="options.php" method="post">';
        // output security fields for the registered setting
        settings_fields('ruigehond007');
        // output setting sections and their fields
        do_settings_sections('ruigehond007');
        // output save settings button
        submit_button(__('Save settings', 'each-domain-a-page'));
        echo '</form></div>';
    }

    public function settingslink($links)
    {
        $url = get_admin_url() . 'options-general.php?page=each-domain-a-page';
        if (isset($this->options['htaccess_warning'])) {
            $settings_link = '<a style="color: #ffb900;" href="' . $url . '">' . __('Warning', 'each-domain-a-page') . '</a>';
        } else {
            $settings_link = '<a href="' . $url . '">' . __('Settings', 'each-domain-a-page') . '</a>';
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
            array($this, 'settingspage')
        );
    }

    /**
     * plugin management functions
     */
    public function install()
    {
        $this->options_changed = true;  // will save with autoload true, and also the htaccess_warning when generated
        // add cross origin for fonts to the htaccess
        if (!$this->htaccessContainsLines()) {
            $htaccess = get_home_path() . ".htaccess";
            $lines = array();
            $lines[] = '<IfModule mod_headers.c>';
            $lines[] = '<FilesMatch "\.(eot|ttf|otf|woff)$">';
            $lines[] = 'Header set Access-Control-Allow-Origin "*"';
            $lines[] = '</FilesMatch>';
            $lines[] = '</IfModule>';
            if (!insert_with_markers($htaccess, "ruigehond007", $lines)) {
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
function ruigehond007_deactivate()
{
    // nothing to do here, you can keep the original settings
}

function ruigehond007_uninstall()
{
    // remove settings
    delete_option('ruigehond007');
}

function ruigehond007_display_warning()
{
    /* Check transient, if available display it */
    if ($warning = get_transient('ruigehond007_warning')) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . $warning . '</p></div>';
        /* Delete transient, only display this notice once. */
        delete_transient('ruigehond007_warning');
        /* remember it as an option though, for the settings page as reference */
        $option = get_option('ruigehond007');
        $option['warning'] = $warning;
        update_option('ruigehond007', $option, true);
    }
}