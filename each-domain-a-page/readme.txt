=== Each Domain a Page ===
Contributors: ruigehond
Tags: landing page, domain, page, mapping, slug, single, multisite, landingpages
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=hallo@ruigehond.nl&lc=US&item_name=Each+domain+a+page+plugin&no_note=0&cn=&currency_code=USD&bn=PP-DonationsBF:btn_donateCC_LG.gif:NonHosted
Requires at least: 5.0
Tested up to: 6.3
Requires PHP: 5.6
Stable tag: 1.6.1
License: GPLv3

Serves a specific page or post from WordPress depending on the domain used to access your WordPress site.

== Description ==

Easily manage a large number of landing pages or one-page websites from a single WordPress site. (For multisites use Multisite Landingpages.)

This plugin is intended as an easy way to map different domains to different landing pages from your WordPress site. That way you can easily maintain a large number of small sites from a single WordPress installation.

You don't have to set anything up, it works out of the box.

Just point a domain that you own to your WordPress installation. In WordPress, create a page for that domain. The slug should be the domain name without 'www' and with the .'s replaced by hyphens.

You can see it working on my own domain: wp-developer.eu, which shows a special page with slug 'wp-developer-eu' on my joerivanveen.com blog (joerivanveen.com/blog/wp-developer-eu is the same).

Since 1.5.0 it works with custom post types as well (e.g. WooCommerce products, FAQ posts, portfolio posts), let me know if you have a custom post type that doesn't work, so I can investigate.

= Benefits: =

1. the rest of your website keeps working as usual

2. you can easily reuse and maintain elements like forms on several domains at once

3. bring in more traffic using landing pages for multiple domains without hassle

= Caveats: =

- the one-page sites all look quite similar to your main site, if you want more flexibility (and more work) there is WordPress Multisite

- some themes use webfonts, for them to work a couple of rows are added to your .htaccess, these are clearly marked #ruigehond007 (this is my seventh plugin)

- if your blog is in a subfolder of the main site (e.g. my-site.com/blog) you need to take an extra step for this to work, see installation

- not all custom post types are guaranteed to work as landing page, e.g. WooCommerce products need additional work to be able to access the main cart

I put special care in making the plugin very lighweight, you will notice it has virtually no effect on the speed of your installation.

== Installation ==

Install the plugin by clicking ‘Install now’ below, or the ‘Download’ button, and put the each-domain-a-page folder in your plugins folder. Don’t forget to activate it.

During activation the plugin attempts to add a few lines to your .htaccess, for compatibility reasons with webfonts. These lines will still be there after you remove the plugin. You may remove the lines (clearly marked with #ruigehond007) yourself at any time of course.

If this failed the plugin will warn you, but function properly nonetheless. If you notice webfonts are not loading for the extra domains you might want to add the lines yourself. The lines are at the bottom of this page.

= Example of setting up the plugin: =

Suppose you have a WordPress website ‘my-website.com’ on ip address 123.45.67.89, and you want a landing page for ‘www.example.com’

1. adjust the DNS A records of your domain ‘www.example.com’ to point to the same ip-address as your main domain, 123.45.67.89 in this example

2. in your hosting environment the extra domain must point to the WordPress directory, this is called domain alias, virtual hosting, domain mapping, multidomain or something similar

3. create a page or post with a slug ‘example-com’

If your WordPress sits in the root of your main domain, you are done. Visit your 'www.example.com' domain to see it work.

= WordPress is installed in a subfolder =

If your WordPress installation is in a subfolder of your main domain (as with my site: joerivanveen.com/blog) and you point your domains to that subfolder (as you probably should), you need to take an extra step for this to work.

Create a subfolder with the same name as your blog, in this case ‘blog’, copy the index.php file from your main folder to that subfolder, and change the reference to the wp-blog-header.php file to the correct location.

So if your blog is in my-site.com/news, you have to create a subfolder ‘news’ in your subfolder ‘news’: my-site.com/news/news and put the index.php in that deepest folder: my-site.com/news/news/index.php

In the index.php file you have to change the line:

    require __DIR__ . '/wp-blog-header.php';

to

    require __DIR__ . '/../wp-blog-header.php';

You only have to do this once of course, it works for all domains that you point at this installation.

Note: before version 1.4 of this plugin this worked differently, that way continues to work, only without support for child pages.

= Canonicals? =

Standard, pages will identify with the main site url and their own slug (and permalink structure). You can see that in the head of the page in the canonical and og:url properties.

Some SEO plugins let you specify another 'canonical' for a page. This may be a good option for you to use.

Alternatively, you can check the 'canonicals' option of each-domain-a-page. It will attempt to return the domain for the landing page / post everywhere within WordPress. This has the added benefit that users will be sent to that domain when they click on the link for your landing page.

Please note that *you need to visit* each (child) page using your preferred domain for the canonical to be activated.

= Locales? =

If you need (some) landing pages to use a different locale, you can specify that in the settings. This will (re)load all translation files that are available in that locale. If you use this it is best to have the default locale of your installation set to ‘English (United States)’ to avoid reloading all the files.

For instance my joerivanveen.eu site is in Dutch, while the rest of my site is in English (United States). I have added one row to the ‘locales’ textarea: joerivanveen-eu = nl_NL. Leave this textarea empty if you don’t need it, it will not affect your installation at all then.

= CORS? =

By default, this plugin will configure ajax requests to be sent to the domain currently served, to avoid CORS errors.

In addition, CORS headers will be sent for configured domains.

= Child pages =

Version 1.4.0 adds support for child pages. If you have a page with slug ‘example-com’ and a child page with slug ‘child-page’, you can visit ‘www.example.com/child-page’ to see the child page.

Sometimes when you change things up, it seems like it is not working.
This is often due to very aggressive caching of redirects in modern browsers (they keep redirecting even if the site is not anymore) but it can also be there are stale canonicals.
You can empty the canonicals by disabling the plugin and re-enabling it.
You have to visit your pages again to load the canonicals one by one.
Settings will be preserved unless you uninstall the plugin completely.

= .htaccess =

In case the plugin was not able to update your .htaccess, these are the lines for your .htaccess to make webfonts function properly, you can add them right after '&#35;END Wordpress':

    &#35; BEGIN ruigehond007
    <IfModule mod_headers.c>
    <FilesMatch ".(eot|ttf|otf|woff|woff2)$">
    Header set Access-Control-Allow-Origin "*"
    </FilesMatch>
    </IfModule>
    &#35; END ruigehond007

You may need to switch on headers module in your Apache configuration, if it is not already.

=== NGINX ===

NGINX does not process .htaccess files as standard. Best is to add the following to your nginx.conf file:

    location ~* \.(eot|ttf|otf|woff|woff2)$ {
        add_header Access-Control-Allow-Origin *;
    }

Alternatively you can switch on processing of .htaccess files in your nginx.conf file. In your hosting environment this may be done by disabling ‘direct delivery’ or something similar.

== Screenshots ==

1. Example page reacting to a specific url

2. Settings screen (1.5.2)

== Changelog ==

1.6.1: restore php 5.6 compatibility

1.6.0: add force-redirect option

1.5.2: remove unnecessary punycode conversion and update docs

1.5.1: reroute ajax and send cors headers for increased compatibility

1.5.0: added support for custom post types, e.g. woocommerce products

1.4.0: added support for child pages on the landing domains

1.3.6: add cartflows_step to supported post types, use utf-8 version of tag when matching for locale

1.3.5: fix admin url could be wrong if main site url is used as a slug

1.3.4: improved: less processing on other pages

1.3.3: support for intl domain names (punycode)

1.3.2: several fixes and improvements regarding the canonical cache

1.3.1: ajax url updated with the correct current domain name

1.3.0: improved stability, ajax url made relative to avoid CORS errors, added locale option

1.2.3: readme updated

1.2.2: now cleans title for targeted pages

1.2.1: added translation

1.2.0: added support for posts, fixed canonical for pages and posts

1.1.0: removed modes, added canonical options

1.0.1: changed text-domain for translations to work properly

1.0.0: fixed readme and display of .htaccess warning

0.3.0: fixed webfonts issue with .htaccess

0.2.0: added languages and .pot

0.1.0: settings page and two modes optimised

0.0.1: setup a working example / proof of concept for two modes

== Upgrade Notice ==

= 1.3.2 =

When you upgrade from a version prior to 1.3.2 it is recommended to deactivate and re-activate Each domain a page to clear the canonicals cache. Note: your settings will be preserved.