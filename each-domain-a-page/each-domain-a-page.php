<?php
/*
Plugin Name: Each domain a page
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Serves a specific page from Wordpress depending on the domain used to access the Wordpress installation.
Version: 0.0.1
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: ruigehond
Domain Path: /languages
*/
defined('ABSPATH') or die();
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond007.
Define('RUIGEHOND007_VERSION', '0.0.1');
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
        load_plugin_textdomain('ruigehond', null, dirname(plugin_basename(__FILE__)) . '/languages/');
        add_action('admin_init', 'ruigehond007_settings');
        add_action('admin_menu', 'ruigehond007_menuitem');
    } else {
        // choose modus operandi based on options
        $options = get_option('ruigehond007');
        if (isset($options['mode']) && $options['mode'] === 'query_vars') { // this is the default
            add_action('parse_request', 'ruigehond007_get');
        } else { // this is probably more stable, but also uglier
            add_action('wp', 'ruigehond007_redirect');
        }
    }
}

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

function ruigehond007_get_slug()
{
    $domain = $_SERVER['HTTP_HOST'];
    if (strpos($domain, 'www.') === 0) $domain = substr($domain, 4);

    return str_replace('.', '_', $domain);
}

function ruigehond007_get_url()
{
    $domain = $_SERVER['HTTP_HOST'];

    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . '://' . $domain;
}

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
            echo '<p>' . __('Displays a single page based on the domain used to access your Wordpress installation.', 'ruigehond') .
                '<br/>' . __('The slug of the page or (custom)post must match the domain, without www., and the dots must be replaced by underscores.', 'ruigehond') .
                '<br/>' . __('E.g., your post with slug "example_com" would be displayed when someone typed in www.example.com and reached your Wordpress installation.', 'ruigehond') .
                '</p>';
        }, //callback
        'ruigehond007' // page
    );
    $option = get_option('ruigehond007');
    if ($option === false) {
        if (isset($_GET['page']) && $_GET['page'] === 'each-domain-a-page') { // set in add_options_page
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo __(sprintf('No options found, please deactivate %s and then activate it again.', 'Each domain a page'), 'ruigehond');
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

function ruigehond007_menuitem()
{
    add_options_page(
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