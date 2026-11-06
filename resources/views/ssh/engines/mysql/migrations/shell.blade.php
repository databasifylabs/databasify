command -v mysqlsh > /dev/null 2>&1 || { echo "MySQL Shell is missing from this node's engine image"; exit 1; }
export SOURCE_PASSWORD
SOURCE_PASSWORD=$(echo '{{ base64_encode($password) }}' | base64 -d)
source_shell() {
    script=$(mktemp)
    cat > "$script"
    status=0
    mysqlsh --no-defaults --no-wizard --py --quiet-start=2 --file="$script" || status=$?
    rm -f "$script"
    return $status
}
