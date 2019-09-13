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
//register_activation_hook(__FILE__, 'ruigehond007_install');
//register_deactivation_hook(__FILE__, 'ruigehond007_deactivate');
//register_uninstall_hook(__FILE__, 'ruigehond007_uninstall');
// Startup the plugin
//add_action('the_post', 'ruigehond007_post');
//add_action('wp', 'ruigehond007_redirect');
add_action('parse_request', 'ruigehond007_get');
function ruigehond007_get($query)
{
    $domain = $_SERVER['HTTP_HOST'];
    // remove www as subdomain
    if (strpos($domain,'www.') === 0) $domain = substr($domain, 4);
    $slug = str_replace('.', '_', $domain);
    $posts = get_posts(array(
        'name' => $slug,
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1
    ));
    if ($posts) {
        $post_name = $posts[0]->post_name;
        $query->query_vars['pagename'] = $post_name;
        $query->query_vars['request'] = $post_name;
        $query->query_vars['did_permalink'] = true;
    }

    return $query;
}

function ruigehond007_redirect()
{
    $domain = $_SERVER['HTTP_HOST'];
    // TODO cleanup subdomain from domain?
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . '://' . $domain;
    $slug = str_replace('.', '_', $domain);
    $posts = get_posts(array(
        'name' => $slug,
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1
    ));
    if ($posts):
        if (str_replace('/', '', $_SERVER['REQUEST_URI']) !== $slug) {
            header('Location: ' . $url . '/' . $slug, '');
            die();
        }
    endif;
}