dir={{ $directory }}
nproc | sed 's/^/cpus=/'
read -r _ user1 nice1 system1 idle1 wait1 irq1 softirq1 steal1 _ < /proc/stat
sleep 1
read -r _ user2 nice2 system2 idle2 wait2 irq2 softirq2 steal2 _ < /proc/stat
busy=$(( (user2 + nice2 + system2 + irq2 + softirq2 + steal2) - (user1 + nice1 + system1 + irq1 + softirq1 + steal1) ))
idle=$(( (idle2 + wait2) - (idle1 + wait1) ))
awk -v busy="$busy" -v idle="$idle" 'BEGIN { total = busy + idle; printf "cpu_percent=%.1f\n", (total > 0 ? busy * 100 / total : 0) }'
awk '{ print "load_1m=" $1; print "load_5m=" $2 }' /proc/loadavg
awk '/^MemTotal:/ { print "memory_total_mb=" int($2 / 1024) } /^MemAvailable:/ { print "memory_available_mb=" int($2 / 1024) }' /proc/meminfo
df -Pm "$dir" | awk 'NR == 2 { print "disk_total_mb=" $2; print "disk_used_mb=" $3 }'
