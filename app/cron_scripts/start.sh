#!/usr/bin/env sh

set -e

echo 'initialize haproxy script'
php /opt/checker/haproxy.php

echo 'start crond'
exec crond -f -L /dev/stdout
