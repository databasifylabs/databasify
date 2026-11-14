@if ($engine->id === 'mysql')
@include('engines.mysql._role')
is_writer() {
    local role
    role=$(node_role 2> /dev/null) || return 1
    [ "$role" = primary ]
}
@else
@include('engines.mariadb._state')
is_writer() {
    read_state > /dev/null 2>&1 && [ "$(role_of_state)" = primary ]
}
@endif
