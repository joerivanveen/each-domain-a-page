<?php
/*
Plugin Name: Each domain a page
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Serves a specific landing page from Wordpress depending on the domain used to access the Wordpress installation.
Version: 1.2.0
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: each-domain-a-page
Domain Path: /languages/
*/
defined('ABSPATH') or die();
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond007.
Define('RUIGEHOND007_VERSION', '1.2.0');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, 'ruigehond007_install');
register_deactivation_hook(__FILE__, 'ruigehond007_deactivate');
register_uninstall_hook(__FILE__, 'ruigehond007_uninstall');
// Startup the plugin
add_action('init', Array(new ruigehond007(), 'initialize'));

//
class ruigehond007
{
    private $options, $options_changed, $use_canonical, $canonicals, $canonical_prefix, $warning;

    public function __construct()
    {
        $this->options_changed = false; // if a domain is registered with a slug, this will flag true, and the options must be saved in __destruct()
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
        } else {
            /* TRANSLATORS: argument is the plugin name */
            $this->warning = sprintf(__('No options found, please deactivate %s and then activate it again.', 'each-domain-a-page'), 'Each domain a page');
        }
    }

    public function __destruct()
    {
        if ($this->options_changed === true) {
            update_option('ruigehond007', $this->options);
        }
    }

    private function onSettingsPage()
    {
        return (isset($_GET['page']) && $_GET['page'] === 'each-domain-a-page');
    }

    public function initialize()
    {
        if (is_admin()) {
            load_plugin_textdomain('each-domain-a-page', false, dirname(plugin_basename(__FILE__)) . '/languages/');
            add_action('admin_notices', 'ruigehond007_display_warning');
            add_action('admin_init', 'ruigehond007_settings');
            add_action('admin_menu', 'ruigehond007_menuitem'); // necessary to have the page accessible to user
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ruigehond007_settingslink'); // settings link on plugins page
        } else {
            add_action('parse_request', array($this, 'get'));
            if ($this->use_canonical) {
                // fix the canonical url, for get_canonical_url, post_link
                foreach (array(
                             'post_link',
                             'get_canonical_url',
                         ) as $filter) {
                    add_filter($filter, array($this, 'canonicalFix'), 10, 1);
                }
            }
        }
    }

    /**
     * 'Get' is the actual functionality of the plugin
     *
     * @param $query Object holding the query prepared by Wordpress
     * @return mixed Object is returned either unchanged, or the request has been updated with the page_name to display
     */
    public function get($query)
    {
        $slug = $this->slugFromDomainAndRegister();
        if ($this->postExists($slug)) {
            $query->query_vars['pagename'] = $slug;
            $query->query_vars['request'] = $slug;
            $query->query_vars['did_permalink'] = true;
        }

        return $query;
    }

    /**
     * @param string $url Wordpress inputs the url it has calculated for a post
     * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
     */
    public function canonicalFix($url) //, and $post if arguments is set to 2 in stead of one in add_filter (during initialize)
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
     * @return string The slug based on the domain for which we need to find a page
     */
    private function slugFromDomainAndRegister()
    {
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
                $this->options_changed = true; // flag for update (in __destruct)
            }
        }

        return $slug;
    }

    /**
     * Lightweight function using "EXISTS" in the database, does not get the post or any data, just checks if it exists
     * based on post_name (the slug) which has an index in MySql
     *
     * @param $slug string The slug to find a post for
     * @return bool true when a published post is found (any post_type), false when not
     */
    private function postExists($slug)
    {
        global $wpdb;
        $sql = 'SELECT EXISTS (
        SELECT 1 FROM ' . $wpdb->prefix . 'posts 
        WHERE post_name = \'' . addslashes($slug) . '\' AND post_status = \'publish\'
        );';

        return (bool)$wpdb->get_var($sql);
    }

}

/**
 * admin stuff
 */
function ruigehond007_settings()
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
        __('Set your options', 'ruigehond'), // title
        function () {
            echo '<p>' . __('A great way to manage one-page sites for a large number of domains from one simple Wordpress installation.', 'each-domain-a-page') .
                '<br/>' . __('This plugin matches a slug to the domain used to access your Wordpress installation and shows that page.', 'each-domain-a-page') .
                '<br/><strong>' . __('The rest of your site keeps working as usual.', 'each-domain-a-page') . '</strong>' .
                '<br/>' .
                /* TRANSLATORS: arguments here are '.', '-', 'example-com', 'www.example.com', 'www' */
                '<br/>' . sprintf(__('Typing your slug: replace %1$s (dot) with %2$s (hyphen). A page with slug %3$s would show for the domain %4$s (with or without the %5$s).', 'each-domain-a-page'),
                    '<strong>.</strong>', '<strong>-</strong>', '<strong>example-com</strong>', '<strong>www.example.com</strong>', 'www') .
                '<br/><em>' . __('Of course the domain must reach your Wordpress installation as well.', 'each-domain-a-page') . '</em>' .
                '</p>';
        }, //callback
        'ruigehond007' // page
    );
    $option = get_option('ruigehond007');
    if ($option === false) {
        if (isset($_GET['page']) && $_GET['page'] === 'each-domain-a-page') { // set in add_options_page
            echo '<div class="notice notice-error is-dismissible"><p>';
            /* TRANSLATORS: argument is the plugin name */
            echo sprintf(__('No options found, please deactivate %s and then activate it again.', 'each-domain-a-page'), 'Each domain a page');
            echo '</p></div>';
        }
    } else {
        add_settings_field(
            'ruigehond007_canonicals',
            __('Use domains as canonical', 'each-domain-a-page'),
            function ($args) {
                echo '<label><input type="checkbox" name="ruigehond007[use_canonical]" value="1"';
                if (isset($args['option']['use_canonical'])) {
                    echo ' checked="checked"';
                }
                echo '/> use canonical</label><br/>';
            },
            'ruigehond007',
            'each_domain_a_page_settings',
            [
                'label_for' => '',
                'class' => 'ruigehond_row',
                'option' => $option, // $args
            ]
        );
        add_settings_field(
            'ruigehond007_use_www',
            __('Use www in domain', 'each-domain-a-page'),
            function ($args) {
                echo '<label><input type="checkbox" name="ruigehond007[use_www]" value="1"';
                if (isset($args['option']['use_www'])) {
                    echo ' checked="checked"';
                }
                echo '/> use www</label><br/>';
            },
            'ruigehond007',
            'each_domain_a_page_settings',
            [
                'label_for' => '',
                'class' => 'ruigehond_row',
                'option' => $option, // $args
            ]
        );
        add_settings_field(
            'ruigehond007_use_ssl',
            __('Always use ssl', 'each-domain-a-page'),
            function ($args) {
                echo '<label><input type="checkbox" name="ruigehond007[use_ssl]" value="1"';
                if (isset($args['option']['use_ssl'])) {
                    echo ' checked="checked"';
                }
                echo '/> use ssl</label><br/>';
            },
            'ruigehond007',
            'each_domain_a_page_settings',
            [
                'label_for' => '',
                'class' => 'ruigehond_row',
                'option' => $option, // $args
            ]
        );
        if (isset($_GET['page']) && $_GET['page'] === 'each-domain-a-page') { // set in add_options_page, show warning only on own options page
            if (isset($option['warning'])) {
                $htaccess = get_home_path() . ".htaccess";
                if (file_exists($htaccess)) {
                    $str = file_get_contents($htaccess);
                    if ($start = strpos($str, '<FilesMatch "\.(eot|ttf|otf|woff)$">')) {
                        if (strpos($str, 'Header set Access-Control-Allow-Origin "*"', $start)) {
                            unset($option['warning']);
                            update_option('ruigehond007', $option);
                        }
                    }
                }
                if (isset($option['warning'])) { // double check
                    echo '<div class="notice notice-warning"><p>' . $option['warning'] . '</p></div>';
                }
            }
        }
    }
}

function ruigehond007_settingspage()
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
    submit_button(__('Save Settings', 'each-domain-a-page'));
    echo '</form></div>';
}

function ruigehond007_settingslink($links)
{
    $url = get_admin_url() . 'options-general.php?page=each-domain-a-page';
    $options = get_option('ruigehond007');
    if (isset($options['warning'])) {
        $settings_link = '<a style="color: #ffb900;" href="' . $url . '">' . __('Warning', 'each-domain-a-page') . '</a>';
    } else {
        $settings_link = '<a href="' . $url . '">' . __('Settings', 'each-domain-a-page') . '</a>';
    }
    array_unshift($links, $settings_link);

    return $links;
}

function ruigehond007_menuitem()
{
    add_submenu_page(
        null, // this will hide the settings page in the "settings" menu
        'Each domain a page',
        'Each domain a page',
        'manage_options',
        'each-domain-a-page',
        'ruigehond007_settingspage'
    );
}

/**
 * plugin management functions
 */
function ruigehond007_install()
{
    if (!get_option('ruigehond007')) { // insert default settings:
        add_option('ruigehond007', array(
            'mode' => 'query_vars',
        ), null, true);
    } else { // set it to autoload always
        $option = get_option('ruigehond007');
        if ($option) {
            update_option('ruigehond007', $option, true);
        }
    }
    // add cross origin for fonts to the htaccess
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
        set_transient('ruigehond007_warning', $warning, 5);
    }
}

function ruigehond007_deactivate()
{
    // set it to not autoload anymore
    $option = get_option('ruigehond007');
    if ($option) {
        update_option('ruigehond007', $option, false);
    }
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