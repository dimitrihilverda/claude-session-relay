#!/bin/sh
# Wait for the database, run migrations, then hand over to Apache.
set -e
tries=0
until php /var/www/relay/bin/relay migrate; do
	tries=$((tries + 1))
	if [ "$tries" -ge 30 ]; then
		echo "relay: database not reachable after 30 attempts" >&2
		exit 1
	fi
	sleep 2
done
exec "$@"
