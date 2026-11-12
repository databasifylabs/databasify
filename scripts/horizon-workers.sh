host_root=${HOST_ROOT:-}

file_in() {
    [ -r "$host_root$1" ] && cat "$host_root$1" || true
}

smaller_of() {
    if [ -z "$1" ]; then echo "$2"; elif [ -z "$2" ] || [ "$1" -lt "$2" ]; then echo "$1"; else echo "$2"; fi
}

clamp() {
    value=$1 low=$2 high=$3
    [ "$value" -lt "$low" ] && value=$low
    [ "$value" -gt "$high" ] && value=$high
    echo "$value"
}

ceil_div() {
    echo $(( ($1 + $2 - 1) / $2 ))
}

cgroup_cpus() {
    # shellcheck disable=SC2046
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
        *) [ "$limit" -lt 4611686018427387904 ] && echo $(( limit / 1048576 )) ;;
    esac
}

host_memory_mb() {
    kilobytes=$(file_in /proc/meminfo | awk '/^MemTotal:/ { print $2 }')
    [ -n "$kilobytes" ] && echo $(( kilobytes / 1024 )) || true
}

workers_for() {
    share=$1 per_cpu=$2 maximum=$3 minimum=$4
    by_memory=$(( worker_slots * share / 100 ))
    cap=$(smaller_of "$(( cpus * per_cpu ))" "$maximum")
    [ "$cap" -lt "$minimum" ] && cap=$minimum
    clamp "$by_memory" "$minimum" "$cap"
}

cpu_count() {
    cpus=${DATABASIFY_CPUS:-$(smaller_of "$(cgroup_cpus)" "$(host_cpus)")}
    echo "${cpus:-1}"
}

memory_size_mb() {
    memory_mb=${DATABASIFY_MEMORY_MB:-$(smaller_of "$(cgroup_memory_mb)" "$(host_memory_mb)")}
    echo "${memory_mb:-1024}"
}

horizon_workers() {
    cpus=$1 memory_mb=$2

    reserve_mb=${DATABASIFY_RESERVE_MB:-256}
    stack_mb=${DATABASIFY_STACK_MB:-200}
    process_mb=${DATABASIFY_PHP_PROCESS_MB:-45}
    worker_mb=${DATABASIFY_WORKER_RSS_MB:-90}
    groups="DEFAULT:35:3:40:2 SSH:75:9:112:4"

    horizon_mb=$(( (1 + 2) * process_mb ))
    headroom_mb=$(( memory_mb * 15 / 100 ))
    available_mb=$(( memory_mb - reserve_mb - stack_mb - headroom_mb - horizon_mb ))
    [ "$available_mb" -lt 0 ] && available_mb=0
    worker_slots=$(( available_mb / worker_mb ))

    for spec in $groups; do
        IFS=: read -r group share per_cpu maximum queues <<SPEC
$spec
SPEC
        eval "per_queue=\${HORIZON_${group}_MIN_PROCESSES:-1}"
        echo "HORIZON_${group}_MAX_PROCESSES=$(workers_for "$share" "$per_cpu" "$maximum" "$(( per_queue * queues ))")"
    done
}
