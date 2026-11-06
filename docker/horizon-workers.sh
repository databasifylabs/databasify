host_root=${HOST_ROOT:-}

file_in() {
    [ -r "$host_root$1" ] && cat "$host_root$1" || true
}

smaller_of() {
    if [ -z "$1" ]; then echo "$2"; elif [ -z "$2" ] || [ "$1" -lt "$2" ]; then echo "$1"; else echo "$2"; fi
}

ceil_div() {
    echo $(( ($1 + $2 - 1) / $2 ))
}

cgroup_cpus() {
    set -- $(file_in /sys/fs/cgroup/cpu.max)
    if [ "$#" -eq 2 ] && [ "$1" != max ]; then
        ceil_div "$1" "$2"
        return
    fi
    quota=$(file_in /sys/fs/cgroup/cpu/cpu.cfs_quota_us)
    period=$(file_in /sys/fs/cgroup/cpu/cpu.cfs_period_us)
    if [ -n "$quota" ] && [ -n "$period" ] && [ "$quota" -gt 0 ]; then
        ceil_div "$quota" "$period"
    fi
}

host_cpus() {
    count=$(file_in /proc/cpuinfo | grep -c '^processor' || true)
    [ "$count" -gt 0 ] && echo "$count" || true
}

cgroup_memory_mb() {
    limit=$(file_in /sys/fs/cgroup/memory.max)
    [ -n "$limit" ] || limit=$(file_in /sys/fs/cgroup/memory/memory.limit_in_bytes)
    case "$limit" in
        '' | *[!0-9]*) ;;
        *) echo $(( limit / 1048576 )) ;;
    esac
}

host_memory_mb() {
    kilobytes=$(file_in /proc/meminfo | awk '/^MemTotal:/ { print $2 }')
    [ -n "$kilobytes" ] && echo $(( kilobytes / 1024 )) || true
}

workers_for() {
    share=$1 per_cpu=$2 minimum=$3 maximum=$4
    by_memory=$(( budget * share / 100 ))
    by_cpu=$(( cpus * per_cpu ))
    workers=$(smaller_of "$by_memory" "$by_cpu")
    [ "$workers" -lt "$minimum" ] && workers=$minimum
    [ "$workers" -gt "$maximum" ] && workers=$maximum
    echo "$workers"
}

tune_horizon_workers() {
    cpus=$(smaller_of "$(cgroup_cpus)" "$(host_cpus)")
    memory_mb=$(smaller_of "$(cgroup_memory_mb)" "$(host_memory_mb)")
    cpus=${cpus:-1}
    memory_mb=${memory_mb:-1024}
    reserve_mb=$(( memory_mb / 4 ))
    [ "$reserve_mb" -lt 512 ] && reserve_mb=512
    budget=$(( (memory_mb - reserve_mb) / 128 ))
    [ "$budget" -lt 5 ] && budget=5

    for spec in DEFAULT:10:1:1:8 MONITORING:25:2:2:32 PROVISIONING:15:2:1:16 DATABASES:35:3:2:64 MIGRATIONS:15:2:1:16 TERMINALS:10:2:2:16; do
        IFS=: read -r group share per_cpu minimum maximum <<SPEC
$spec
SPEC
        eval "current=\${HORIZON_${group}_MAX_PROCESSES:-}"
        [ -n "$current" ] || export "HORIZON_${group}_MAX_PROCESSES=$(workers_for "$share" "$per_cpu" "$minimum" "$maximum")"
    done
}
