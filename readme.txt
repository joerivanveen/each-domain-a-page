=== Each Domain a Page ===
Contributors: ruigehond
Tags: landing page, domain, page, mapping, slug, single, multisite, landingpages
Donate link: https://paypal.me/ruigehond
Requires at least: 5.0
Tested up to: 5.6
Requires PHP: 5.5
Stable tag: trunk
License: GPLv3

Serves a specific page or post from Wordpress depending on the domain used to access your Wordpress site.

== Description ==

Easily manage a large number of landing pages or one-page websites from a single Wordpress site.

This plugin is intended as an easy way to map different domains to different landing pages from your Wordpress site. That way you can easily maintain a large number of one-page sites from a single Wordpress installation.

You don't have to set anything up, it works out of the box.

Just point a domain that you own to your Wordpress installation. In Wordpress, create a page for that domain. The slug should be the domain name without 'www' and with the .'s replaced by hyphens.

You can see it working on my own domain: wordpresscoder.nl, which shows a special page with slug 'wordpresscoder-nl' on my joerivanveen.com blog.

= Benefits: =

1. the rest of your website keeps working as always

2. you can easily reuse and maintain elements like forms on several domains at once

3. bring in more traffic using landing pages for multiple domains without hassle

= Caveats: =

- the one-page sites all look quite similar to your main site, if you want more flexibility (and more work) there is Wordpress Multisite

- some themes use webfonts, for them to work a couple of rows are added to your .htaccess, these are clearly marked #ruigehond007 (this is my seventh plugin)

- it does not work for custom post-types yet, only regular pages and posts

- if your blog is in a subfolder of the main site (e.g. my-site.com/blog) you need to take an extra step for this to work, see installation tab

I put special care in making the plugin very lighweight, you will notice it has virtually no effect on the speed of your installation.

Feel free to fork it on Github, if you want to play with the code.

There is a paid multisite version available, where each subsite can assign additional domains to slugs, similar to this free version. Contact me if you’re interested.

Regards,
Joeri (ruige hond)

== Installation ==

Install the plugin by clicking 'Install now' below, or the 'Download' button, and put the each-domain-a-page folder in your plugins folder. Don't forget to activate it.

During activation the plugin attempts to add a few lines to your .htaccess, for compatibility reasons with webfonts. These lines will still be there after you remove the plugin. You may remove the lines (clearly marked with #ruigehond007) yourself at any time of course.

If this failed the plugin will warn you, but function properly nonetheless. If you notice webfonts are not loading for the extra domains you might want to add the lines yourself. The lines are at the bottom of this page.

= Example of setting up the plugin: =

Suppose you have a Wordpress website 'my-website.com' on ip address 12.34.56.789, and you want a landing page for 'www.example.com'

1. adjust the DNS A records of your domain 'www.example.com' to point to the same ip-address as your main domain, 12.34.56.789 in this example

2. in your hosting environment the extra domain must point to the Wordpress directory, this is called domain alias, virtual hosting, domain mapping, multidomain or something similar

3. create a page or post with a slug 'example-com'

If your Wordpress sits in the root of your main domain, you are done. Visit your 'www.example.com' domain to see it work.

= Wordpress is installed in a subfolder =

If your Wordpress is installed in a subfolder of your main website (as with my own blog joerivanveen.com/blog) it only works when the domain is accessed without path (www.example.com), but will give an error when a user tries to reach www.example.com/path-to-something. This is because Wordpress redirects to the path it is installed in (in this case: /blog/blog), which doesn't exist, so it goes back into a loop. So make sure the subfolder exists and put an index.php in it that redirects back to the domain without the path.

So if your blog is in my-site.com/news, you have to create a subfolder 'news' in your subfolder 'news': my-site.com/news/news and put the index.php in that second deepest folder: my-site.com/news/news/index.php

This is the contents of the index.php file:

    <?php
    header ('Location: https://' . $_SERVER['HTTP_HOST'], true, 301);

(replace https:// with http:// if you don't use ssl)

You only have to do this once of course, it works for all domains that you point at this installation.

= Canonicals? =

Standard, pages will identify with the main site url and their own slug (and permalink structure). You can see that in the head of the page in the canonical and og:url properties.

Some SEO plugins let you specify another 'canonical' for a page. This may be a good option for you to use.

Alternatively, you can check the 'canonicals' option of each-domain-a-page. It will attempt to return the domain for the landing page / post everywhere within Wordpress. This has the added benefit that users will be sent to that domain when they click on the link for your landing page.

I have tested the 'canonicals' functionality on several installations and it works consistently there. Please let me know if this does not work in your installation.

= Locales? =

If you need (some of the) landing pages to use a different locale, you can specify that in the settings. This will (re)load all translation files that are available in that locale. If you use this it is best to have the default locale of your installation set to ‘English (United States)’ to avoid reloading all the files.

For instance my wordpresscoder.nl site is in Dutch, while the rest of my site is in English (United States). I have added one row to the ‘locales’ textarea: wordpresscoder-nl = nl_NL. Leave this textarea empty if you don’t need it, it will not affect your installation at all then.

= htaccess =

In case the plugin was not able to update your .htaccess, these are the lines for your .htaccess to make webfonts function properly, you can add them right after '&#35;END Wordpress':

    &#35; BEGIN ruigehond007
    <IfModule mod_headers.c>
    <FilesMatch ".(eot|ttf|otf|woff)$">
    Header set Access-Control-Allow-Origin "*"
    </FilesMatch>
    </IfModule>
    &#35; END ruigehond007

Contact me if you have any questions.

== Screenshots ==
1. Settings screen

== Changelog ==

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