<?php
/*
Plugin Name: Each domain a page
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Serves a specific landing page from Wordpress depending on the domain used to access the Wordpress installation.
Version: 1.3.6
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: each-domain-a-page
Domain Path: /languages/
*/
defined('ABSPATH') or die();
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond007.
Define('RUIGEHOND007_VERSION', '1.3.6');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, array(new ruigehond007(), 'activate'));
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
    // @since 1.3.5
    private $supported_post_types = ['page', 'post', 'cartflows_step'];

    /**
     * ruigehond007 constructor
     * loads settings that are available also based on current url
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->options_changed = false; // if a domain is registered with a slug, this will flag true, and the options must be saved in __shutdown()
        // @since 1.3.0 changed __destruct to __shutdown for stability reasons
        \register_shutdown_function(array(&$this, '__shutdown'));
        // continue
        $this->options = get_option('ruigehond007');
        if (isset($this->options)) {
            // ATTENTION for the options do not use true === ‘option’, because previous versions
            // work with ‘1’ as a value (thank you WP...)
            $this->use_canonical = (isset($this->options['use_canonical']) and ($this->options['use_canonical']));
            // fix @since 1.3.4 you need the canonicals for the ajax hook, so load them always
            if (isset($this->options['canonicals']) and is_array($this->options['canonicals'])) {
                $this->canonicals = $this->options['canonicals'];
            } else {
                $this->canonicals = array();
            }
            if ($this->use_canonical) {
                if (isset($this->options['use_ssl']) and ($this->options['use_ssl'])) {
                    $this->canonical_prefix = 'https://';
                } else {
                    $this->canonical_prefix = 'http://';
                }
                if (isset($this->options['use_www']) and ($this->options['use_www'])) $this->canonical_prefix .= 'www.';
            } else { // @since 1.3.5 set the prefix to what’s current
                $this->canonical_prefix = \stripos($_SERVER['SERVER_PROTOCOL'],'https') === 0 ? 'https://' : 'http://';
            }
            $this->remove_sitename_from_title = (isset($this->options['remove_sitename']) and ($this->options['remove_sitename']));
        } else {
            $this->options = array(); // set default options (currently none)
            $this->options_changed = true;
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
        if (!\defined('RUIGEHOND007_SHUTDOWN')) {
            \define('RUIGEHOND007_SHUTDOWN', true); // apparently it calls shutdown twice, we need it only once
            if (\true === $this->options_changed) {
                if (\false === update_option('ruigehond007', $this->options, true)) {
                    \trigger_error(__('Failed saving options (each domain a page)', 'each-domain-a-page'), E_USER_NOTICE);
                }
            }
        }
    }

    /**
     * initialize the plugin, sets up necessary filters and actions.
     * @since 1.0.0
     */
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
    }

    /**
     * Returns a relative url for pages that are accessed on a different domain than the original blog enabling
     * ajax calls without the dreaded cross origin errors (as long as people use the recommended get_admin_url())
     * @param $url
     * @return string|string[]
     * @since 1.3.0
     * @since 1.3.1 fix: substitute correct new domain rather than make it relative
     */
    public function adminUrl($url)
    {
        $slug = $this->slug;
        if (isset($this->canonicals[$slug])) {
            return \str_replace(\get_site_url(), $this->fixUrl($slug), $url);
        }

        return $url;
    }

    public function adminUrl_OLD($url)
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
     * ‘get’ is the actual functionality of the plugin
     *
     * @param $query Object holding the query prepared by Wordpress
     * @return mixed Object is returned either unchanged, or the request has been updated with the post to show
     */
    public function get($query)
    {
        // @since 1.3.4 don’t bother processing if not a page handled by the plugin...
        if (\false === isset($this->slug)) return $query;
        $slug = $this->slug;
        if (($type = $this->postType($slug))) { // fails when slug not found
            if ($this->remove_sitename_from_title) {
                if (has_action('wp_head', '_wp_render_title_tag') == 1) {
                    remove_action('wp_head', '_wp_render_title_tag', 1);
                    add_action('wp_head', array($this, 'render_title_tag'), 1);
                }
                add_filter('wpseo_title', array($this, 'get_title'), 1);
            }
            if ($type === 'page') {
                $query->query_vars['pagename'] = $slug;
                $query->query_vars['request'] = $slug;
                $query->query_vars['did_permalink'] = true;
            } elseif (\in_array($type, $this->supported_post_types)) {
                $query->query_vars['page'] = '';
                $query->query_vars['name'] = $slug;
                $query->request = $slug;
                $query->matched_rule = '';
                $query->matched_query = 'name=' . $slug . '$page='; // TODO paging??
                $query->did_permalink = true;
            } // does not work with custom post types or products etc. (yet)
        }

        return $query;
    }

    /**
     * substitute for standard wp title rendering to remove the site name
     * @since 1.2.2
     */
    public function render_title_tag()
    {
        echo '<title>' . get_the_title() . '</title>';
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
     * @param string $url Wordpress inputs the url it has calculated for a post
     * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
     * @since 1.0.0
     * @since 1.3.1 improved so it also works with relative $url input (e.g. a slug)
     */
    public function fixUrl($url) //, and $post if arguments is set to 2 in stead of one in add_filter (during initialize)
    {
        // -2 = skip over trailing slash, if no slashes are found, $url must be a clean slug, else, extract the last part
        $proposed_slug = (\false === ($index = \strrpos($url, '/', -2))) ? $url : \substr($url, $index + 1);
        $proposed_slug = \trim($proposed_slug, '/');
        if (isset($this->canonicals[$proposed_slug])) {
            $url = $this->canonical_prefix . $this->canonicals[$proposed_slug];
        }

        return $url;
    }

    public function fixUrl_DEPRECATED($url) //, and $post if arguments is set to 2 in stead of one in add_filter (during initialize)
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
        if (\strpos($domain, 'www.') === 0) $domain = \substr($domain, 4);
        // @since 1.3.3: handle punycode
        if (\strpos($domain, 'xn--') === 0) {
            if (\function_exists('idn_to_utf8')) {
                $domain = \idn_to_utf8($domain, 0, INTL_IDNA_VARIANT_UTS46);
            } else {
                \trigger_error('Each domain a page received a punycoded domain but idn_to_utf8() is unavailable');
            }
        }
        // make slug @since 1.3.3, this is the way it is stored in the db as well
        $slug = \sanitize_title($domain);
        // register here, @since 1.3.4 don’t set $this->slug if not serving a specific page for it
        if (isset($this->canonicals[$slug])) {
            $this->slug = $slug;
        } else { // if not already in the options table
            if ($this->postType($slug)) { // @since 1.3.2 only add when the slug exists
                $this->options['canonicals'][$slug] = $domain; // remember original domain for slug
                $this->options_changed = true; // flag for update (in __shutdown)
                $this->canonicals[$slug] = $domain; // also remember for current request
                $this->slug = $slug;
            }
        }
        if (isset($this->slug)) { // @since 1.3.4 don’t bother for other pages / posts
            // @since 1.3.0
            if (isset($this->options['locales']) and ($locales = $this->options['locales'])) {
                $utf8_slug = str_replace('.', '-', $domain); // @since 1.3.6
                if (isset($locales[$utf8_slug])) $this->locale = $locales[$utf8_slug];
            }
            // @since 1.3.2 correct the shortlink for this canonical
            if (isset($this->canonicals[$slug])) add_filter('pre_get_shortlink', static function () use ($domain) {
                return $domain;
            });
        }
    }

    /**
     * Expects a string where each name=>value pair is on a new row and uses = as separator, so:
     * name-one=value-one
     * etc. keys and values are trimmed and returned as a proper named array / associative array
     * @param $associative_array_as_string
     * @return array
     * @since 1.3.0
     */
    private function stringToArray($associative_array_as_string)
    {
        if (\is_array($associative_array_as_string)) return $associative_array_as_string;
        $arr = \explode("\n", $associative_array_as_string);
        if (\count($arr) > 0) {
            $ass = array();
            foreach ($arr as $index => $str) {
                $val = \explode('=', $str);
                if (\count($val) === 2) {
                    $ass[\trim($val[0])] = \trim($val[1]);
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
            $return[] = $name . ' = ' . $value;
        }

        return \implode("\n", $return);
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
        WHERE post_name = \'' . \addslashes($slug) . '\' AND post_status = \'publish\';';
        $type = $wpdb->get_var($sql);
        $this->post_types[$slug] = $type;

        return $type;
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
        if (\file_exists($htaccess)) {
            $str = \file_get_contents($htaccess);
            if ($start = \strpos($str, '<FilesMatch "\.(eot|ttf|otf|woff)$">')) {
                if (\strpos($str, 'Header set Access-Control-Allow-Origin "*"', $start)) {
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
                echo __('This plugin matches a slug to the domain used to access your Wordpress installation and shows that page or post.', 'each-domain-a-page');
                echo '<br/>';
                // #translators: %s is the name of the function needed: idn_to_utf8()
                echo \sprintf(__('Regarding UTF-8 domains and punycode: if this plugin encounters such a domain it will emit a notice when %s is unavailable, otherwise it will just work.', 'each-domain-a-page'), 'idn_to_utf8()');
                echo '<br/><strong>';
                echo __('The rest of your site keeps working as usual.', 'each-domain-a-page');
                echo '</strong><br/><br/>';
                /* TRANSLATORS: arguments here are '.', '-', 'example-com', 'www.example.com', 'www' */
                echo sprintf(__('Typing your slug: replace %1$s (dot) with %2$s (hyphen). A page or post with slug %3$s would show for the domain %4$s (with or without the %5$s).', 'each-domain-a-page'),
                    '<strong>.</strong>', '<strong>-</strong>', '<strong>example-com</strong>', '<strong>www.example.com</strong>', 'www');
                echo ' <em>';
                echo __('Of course the domain must reach your Wordpress installation as well.', 'each-domain-a-page');
                echo '</em></p><h2>Canonicals?</h2><p><strong>';
                echo __('This plugin works out of the box.', 'each-domain-a-page');
                echo '</strong><br/>';
                echo __('However if you want your landing pages to correctly identify with the domain, you should activate the canonicals option below.', 'each-domain-a-page');
                echo ' ';
                echo __('This makes the plugin slightly slower, it will however return the domain in most cases.', 'each-domain-a-page');
                echo ' ';
                echo __('Each canonical is activated by visiting your site once using that domain.', 'each-domain-a-page');
                echo '<!--';
                \var_dump($this->options['canonicals']);
                echo '-->';
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
                echo '</em></p>';
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
                'ruigehond007_' . $setting_name,
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
                    echo((true === $checked) ? '1' : '0');
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
                'ruigehond007_' . $setting_name,
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
                if ($this->htaccessContainsLines()) { // maybe the user added the lines already by hand
                    //@since 1.3.0 bugfix:
                    //unset($this->options['htaccess_warning']); <- this results in an error in update_option, hurray for WP :-(
                    $this->options['htaccess_warning'] = null; // fortunately also returns false with isset()
                    $this->options_changed = true;
                    echo '<div class="notice"><p>' . __('Warning status cleared.', 'each-domain-a-page') . '</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>' . $this->options['htaccess_warning'] . '</p></div>';
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
                    $value = ($value === '1' or $value === true); // normalize
                    if (isset($options[$key]) and $options[$key] !== $value) {
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
            if (\is_readable(($path = \trailingslashit($this->cache_dir)))) {
                ruigehond007_rmdir($path);
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
        \array_unshift($links, $settings_link);

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
    public function activate()
    {
        if (\true === is_multisite()) wp_die(\sprintf(__('%1$s does not work on multisite installs. You should try ‘%2$s’', 'each-domain-a-page'), 'Each domain a page', '<a href="https://wordpresscoder.nl">Multisite landingpages</a>'));
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
                    $lines[$key] = \htmlentities($line);
                }
                $warning = '<strong>Each-domain-a-page</strong><br/>';
                $warning .= __('In order for webfonts to work on alternative domains you need to add the following lines to your .htaccess:', 'each-domain-a-page');
                $warning .= '<br/><em>(';
                $warning .= __('In addition you need to have mod_headers available.', 'each-domain-a-page');
                $warning .= ')</em><br/>&nbsp;<br/>';
                $warning .= '<CODE>' . \implode('<br/>', $lines) . '</CODE>';
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

/**
 * @param $dir
 * @since 1.3.1
 */
function ruigehond007_rmdir($dir)
{
    if (\is_dir($dir)) {
        $handle = \opendir($dir);
        while (\false !== ($object = \readdir($handle))) {
            if ($object !== '.' and $object !== '..') {
                $path = $dir . '/' . $object;
                echo $object . ': ' . filetype($path) . '<br/>';
                if (\filetype($path) === 'dir') {
                    ruigehond007_rmdir($path);
                } else {
                    \unlink($path);
                }
            }
        }
        \rmdir($dir);
    }
}