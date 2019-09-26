<?php
/*
Plugin Name: Each domain a page
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Serves a specific page from Wordpress depending on the domain used to access the Wordpress installation.
Version: 1.0.0
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: ruigehond
Domain Path: /languages
*/
defined('ABSPATH') or die();
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond007.
Define('RUIGEHOND007_VERSION', '1.0.0');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, 'ruigehond007_install');
register_deactivation_hook(__FILE__, 'ruigehond007_deactivate');
register_uninstall_hook(__FILE__, 'ruigehond007_uninstall');
// Startup the plugin
add_action('init', 'ruigehond007_init');
//
function ruigehond007_init()
{
    if (is_admin()) {
        $options = get_option('ruigehond007');
        load_plugin_textdomain('ruigehond', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        add_action('admin_notices', 'ruigehond007_display_warning');
        add_action('admin_init', 'ruigehond007_settings');
        add_action('admin_menu', 'ruigehond007_menuitem'); // necessary to have the page accessible to user
        if (isset($options['warning'])) {
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ruigehond007_settingslink'); // settings link on plugins page
        }
    } else {
        // choose modus operandi based on options
        $options = get_option('ruigehond007');
        if (isset($options['mode']) && $options['mode'] === 'query_vars') { // this is the default
            add_action('parse_request', 'ruigehond007_get');
        } else { // redirect only works reliably with Wordpress installed in root, in addition it's ugly
            add_action('wp', 'ruigehond007_redirect');
        }
    }
}

/**
 * @param $query Object holding the query prepared by Wordpress
 * @return mixed Object is returned either unchanged, or the request has been updated with the page_name to display
 */
function ruigehond007_get($query)
{
    $slug = ruigehond007_get_slug();
    if (ruigehond007_post_exists($slug)) {
        $query->query_vars['pagename'] = $slug;
        $query->query_vars['request'] = $slug;
        $query->query_vars['did_permalink'] = true;
    }

    return $query;
}

/**
 * Redirects the user to the page when one is found for the domain, then dies, or else does nothing.
 */
function ruigehond007_redirect()
{
    $slug = ruigehond007_get_slug();
    if (ruigehond007_post_exists($slug)) {
        if (str_replace('/', '', $_SERVER['REQUEST_URI']) !== $slug) {
            header('Location: ' . ruigehond007_get_url() . '/' . $slug, '');
            die();
        }
    }
}

/**
 * @return string The slug based on the domain for which we need to find a page
 */
function ruigehond007_get_slug()
{
    $domain = $_SERVER['HTTP_HOST'];
    if (strpos($domain, 'www.') === 0) $domain = substr($domain, 4);

    return str_replace('.', '-', $domain);
}

/**
 * @return string The url part WITHOUT THE PATH of the current request
 */
function ruigehond007_get_url()
{
    $domain = $_SERVER['HTTP_HOST'];

    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . '://' . $domain;
}

/**
 * Lightweight function using "EXISTS" in the database, does not get the post or any data, just checks if it exists
 *
 * @param $slug string The slug to find a post for
 * @return bool true when a published post is found (any post_type), false when not
 */
function ruigehond007_post_exists($slug)
{
    global $wpdb;
    $sql = 'SELECT EXISTS (
        SELECT 1 FROM ' . $wpdb->prefix . 'posts 
        WHERE post_name = \'' . addslashes($slug) . '\' AND post_status = \'publish\'
        );';

    return (bool)$wpdb->get_var($sql);
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
            echo '<p>' . __('A great way to manage one-page sites for a large number of domains from one simple Wordpress installation.', 'ruigehond') .
                '<br/>' . __('This plugin matches a slug to the domain used to access your Wordpress installation and shows that page.', 'ruigehond') .
                '<br/><strong>' . __('The rest of your site keeps working as usual.', 'ruigehond') . '</strong>' .
                '<br/>' .
                /* TRANSLATORS: arguments here are '.', '-', 'example-com', 'www.example.com', 'www' */
                '<br/>' . sprintf(__('Typing your slug: replace %1$s (dot) with %2$s (hyphen). A page with slug %3$s would show for the domain %4$s (with or without the %5$s).', 'ruigehond'),
                    '<strong>.</strong>', '<strong>-</strong>', '<strong>example-com</strong>', '<strong>www.example.com</strong>', 'www') .
                '<br/><em>' . __('Of course the domain must reach your Wordpress installation as well.', 'ruigehond') . '</em>' .
                '<br/>' .
                /* TRANSLATORS: arguments are 1 the preferred mode (query_vars) and 2 the not to be used mode redirect */
                '<br/>' . sprintf(__('There are two modes: you should always use %1$s, but if it does not work you can try %2$s.', 'ruigehond'), 'query_vars', 'redirect') .
                '</p>';
        }, //callback
        'ruigehond007' // page
    );
    $option = get_option('ruigehond007');
    if ($option === false) {
        if (isset($_GET['page']) && $_GET['page'] === 'each-domain-a-page') { // set in add_options_page
            echo '<div class="notice notice-error is-dismissible"><p>';
            /* TRANSLATORS: argument is the plugin name */
            echo sprintf(__('No options found, please deactivate %s and then activate it again.', 'ruigehond'), 'Each domain a page');
            echo '</p></div>';
        }
    } else {
        add_settings_field(
            'ruigehond007_mode',
            __('Choose the mode', 'ruigehond'),
            function ($args) {
                $mode = false;
                $modes = array('query_vars', 'redirect');
                if (isset($args['option']['mode'])) {
                    $mode = $args['option']['mode'];
                }
                if (!in_array($mode, $modes)) $mode = $modes[0]; // default if illegal value
                foreach ($modes as $key => $value) {
                    echo '<label><input type="radio" name="ruigehond007[mode]" value="' . $value . '"';
                    if ($mode === $value) {
                        echo ' checked="checked"';
                    }
                    echo '/> ' . $value . '</label><br/>';
                }
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
    submit_button(__('Save Settings', 'ruigehond'));
    echo '</form></div>';
}

function ruigehond007_settingslink($links)
{
    $url = get_admin_url() . 'options-general.php?page=each-domain-a-page';
    $settings_link = '<a style="color: #ffb900;" href="' . $url . '">' . __('Warning', 'ruigehond') . '</a>';
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
        $warning .= __('In order for webfonts to work on alternative domains you need to add the following lines to your .htaccess:', 'ruigehond');
        $warning .= '<br/><em>(';
        $warning .= __('In addition you need to have mod_headers available.', 'ruigehond');
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