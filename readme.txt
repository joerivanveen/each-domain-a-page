=== Each Domain a Page ===
Contributors: ruigehond
Tags: landing page, domain, page, mapping, slug, single
Donate link: https://paypal.me/ruigehond
Requires at least: 4.5
Tested up to: 5.2.3
Requires PHP: 5.4
Stable tag: trunk
License: GPLv3

Serves a specific page from Wordpress depending on the domain used to access your Wordpress site.

== Description ==

Easily manage a large number of landingpages or one-page websites from a single Wordpress site.

This plugin is intended as an easy way to map different domains to different landingpages from your Wordpress site. That way you can easily maintain a large number of one-page sites from a single Wordpress installation.

You don't have to set anything up, it works out of the box.

Just point a domain that you own to your Wordpress installation. In Wordpress, create a page for that domain. The slug should be the domain name without 'www' and with the .'s replaced by hyphens.

You can see it working on my own domain: joerivanveen.eu, which shows a special page with slug 'joerivanveen-eu' on my joerivanveen.com blog.

Benefits:

1. the rest of your website keeps working as always

2. you can easily reuse and maintain elements like forms on several domains at once

3. bring in more traffic using landingpages for multiple domains without hassle

Caveats:

- the one-page sites all look quite similar to your main site, if you want more flexibility (and more work) there is Wordpress Multisite

- some themes use webfonts, for them to work a couple of rows are added to your .htaccess, these are clearly marked #ruigehond007 (this is my seventh plugin)

- if your blog is in a subfolder of the main site (e.g. my-site.com/blog) you need to take an extra step for this to work, see installation

I put special care in making the plugin very lighweight, you will notice it has virtually no effect on the speed of your installation.

Feel free to fork it on Github, if you want to play with the code

Regards,
Joeri (ruige hond)

== Installation ==

Install the plugin by clicking 'Install now' below, or the 'Download' button, and put the each-domain-a-page folder in your plugins folder. Don't forget to activate it.

During install the plugin attempts to add a few lines to your .htaccess, for compatibility reasons with webfonts. These lines will still be there after you remove the plugin. You may remove the lines (clearly marked with #ruigehond007) yourself at any time of course.

If this failed the plugin will warn you, but function properly nonetheless. If you notice webfonts are not loading for the extra domains you might want to add the lines yourself. The lines are at the bottom of this page.

Example of setting up the plugin:

Suppose you have a Wordpress website 'my-website.com' on ip address 12.34.56.789, and you want a landingpage for 'www.example.com'

1. adjust the DNS A records for you domain 'www.example.com' to point to the same ip-address as your main domain, 12.34.56.789

2. in your hosting environment the extra domain must point to the Wordpress directory, this is called domain alias, virtual hosting, domain mapping, multidomain

3. create a page titled 'Example' with a slug 'example-com'

If your Wordpress sits in the root of your main domain, it works now.

If not (as with my own blog joerivanveen.com/blog) it only works when the domain is accessed without path (www.example.com), but will give an error when a user tries to reach www.example.com/path-to-something. This is because Wordpress redirects to the path it is installed in (in this case: /blog/blog), which doesn't exist, so it goes back into a loop. So make sure the subfolder exists and put an index.php in it that redirects back to the domain without the path.

So if your blog is in my-site.com/news, you have to create a subfolder 'news' in your subfolder 'news': my-site.com/news/news and put the index.php in that second deepest folder: my-site.com/news/news/index.php

This is the contents of the index.php file:

&lt;?php
header ('Location: https://' . $_SERVER['HTTP_HOST'], true, 301);

(replace https:// with http:// if you don't use ssl)

You only have to do this once of course, it works for all domains that you point at it.

In case the plugin was not able to update your .htaccess, these are the lines for your .htaccess to make webfonts function properly, you can add them right after '&#35;END Wordpress':

&#35; BEGIN ruigehond007
&lt;IfModule mod_headers.c&gt;
&lt;FilesMatch &quot;.(eot|ttf|otf|woff)$&quot;&gt;
Header set Access-Control-Allow-Origin &quot;*&quot;
&lt;/FilesMatch&gt;
&lt;/IfModule&gt;
&#35; END ruigehond007

Contact me if you have any questions.

== Screenshots ==
1. No screenshot necessary, it just works

== Changelog ==

1.0.1: changed text-domain for translations to work properly

1.0.0: fixed readme and display of .htaccess warning

0.3.0: fixed webfonts issue with .htaccess

0.2.0: added languages and .pot

0.1.0: settings page and two modes optimised

0.0.1: setup a working example / proof of concept for two modes