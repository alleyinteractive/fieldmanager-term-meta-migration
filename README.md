# Fieldmanager Term Meta Migration

A WordPress plugin to migrate [Fieldmanager](http://fieldmanager.org) term meta
to WordPress core term meta.

WordPress 4.4 introduces term meta to core, allowing terms to carry abstract
metadata like posts and users do. Fieldmanager has offered term meta for years
by creating a hidden post type behind-the-scenes to leverage post meta. Now that
WordPress supports term meta natively, Fieldmanager is deprecating its term meta
solution. This plugin aims to help in that transition.

Activating this plugin adds a [WP-CLI](http://wp-cli.org) subcommand,
`fm-term-meta`. Run `wp help fm-term-meta` for instructions and additional
information.