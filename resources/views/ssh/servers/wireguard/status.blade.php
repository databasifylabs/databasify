if ! ip link show databasify > /dev/null 2>&1; then
    echo "interface=down"
    exit 0
fi
echo "interface=up"
echo "now=$(date +%s)"
wg show databasify dump | tail -n +2 | awk -F '\t' '{ print "peer=" $1 " " $5 }'
