pgrep -x postgres > /dev/null || rm -f /srv/postgres/run/.s.PGSQL.* /srv/postgres/data/postmaster.pid
