#!/bin/sh
# render.php is pure PHP — no DB, no GLPI bootstrap — and runs anywhere,
# including in CI's bare php:8.3 container. It renders every notification in the
# catalog and checks the three things a mail template can be silently wrong
# about: unbalanced ##IF##/##FOREACH## constructs, markup that does not close,
# and a tag whose case got mangled on the way through.
#
# process.php is the other half and cannot run here: it boots a GLPI kernel so
# that the bodies go through GLPI's *own* template processor, which is the code
# that will actually read them. Run it against the dev instance:
#
#   docker exec glpi-glpi-1 php /var/www/glpi/plugins/glpimail/tests/process.php
#
# There is deliberately no class-load.php here. glpi-pdf ships one — it catches
# the inheritance-signature fatals `php -l` structurally cannot see — and it
# takes a list of plugins, so this one is swept by pointing it here rather than
# by copying it:
#
#   docker exec glpi-glpi-1 php \
#       /var/www/glpi/plugins/glpipdf/tests/class-load.php glpimail
#
# Every class in this plugin is final and extends nothing, so there is not much
# for it to find; it costs one line to keep looking.
#
# What none of them can check is what a mail *looks like* in Outlook. The
# settings page's preview is rendered by the same code the send path uses, and
# that is as close as this gets without a rendering service.
set -e

cd "$(dirname "$0")/.."

status=0
php tests/render.php || status=1

exit $status
