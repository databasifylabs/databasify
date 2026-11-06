@include('ssh.servers.os.selinux')
swap_file_on() {
    chmod 600 /swapfile
    mkswap /swapfile > /dev/null
    selinux_label swapfile_t /swapfile
    swapon /swapfile 2> /dev/null
}
if [ -n "$(swapon --show --noheadings 2> /dev/null)" ]; then
    echo "swap is already on: $(swapon --show --noheadings --bytes | awk '{ total += $3 } END { printf "%d MB", total / 1048576 }')"
else
    memory_mb=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)
    free_mb=$(df -Pm / | awk 'NR == 2 { print $4 }')
    if [ "$memory_mb" -ge 4096 ]; then
        echo "no swap needed with ${memory_mb} MB of memory"
    elif [ "$free_mb" -lt 6144 ]; then
        echo "skipping swap, only ${free_mb} MB of disk is free"
    else
        fallocate -l 2G /swapfile 2> /dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
        if ! swap_file_on; then
            dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
            swap_file_on
        fi
        grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
        echo "added a 2 GB swap file for ${memory_mb} MB of memory"
    fi
fi
