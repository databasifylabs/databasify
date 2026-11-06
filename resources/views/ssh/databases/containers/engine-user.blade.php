if [ "$engine_uid" != {{ $engineUid }} ] || [ "$engine_gid" != {{ $engineUid }} ]; then
    echo "$image runs {{ $engineUser }} as $engine_uid:$engine_gid, but databasify engines run as {{ $engineUid }}:{{ $engineUid }}; this image cannot be deployed"
    exit 1
fi
uid_holder=$(getent passwd {{ $engineUid }} | cut -d: -f1 || true)
gid_holder=$(getent group {{ $engineUid }} | cut -d: -f1 || true)
if [ -n "$uid_holder" ] && [ "$uid_holder" != {{ $engineHostUser }} ]; then
    echo "uid {{ $engineUid }} belongs to $uid_holder on this server, the database engine needs it to itself"
    exit 1
fi
if [ -n "$gid_holder" ] && [ "$gid_holder" != {{ $engineHostUser }} ]; then
    echo "gid {{ $engineUid }} belongs to group $gid_holder on this server, the database engine needs it to itself"
    exit 1
fi
[ -n "$gid_holder" ] || groupadd -r -K SYS_GID_MAX={{ $engineUid }} -g {{ $engineUid }} {{ $engineHostUser }}
[ -n "$uid_holder" ] || useradd -r -K SYS_UID_MAX={{ $engineUid }} -u {{ $engineUid }} -g {{ $engineUid }} -M -d /nonexistent -s /usr/sbin/nologin {{ $engineHostUser }}
