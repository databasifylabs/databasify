if [ ! -f {{ $path }} ]; then
    echo "problem=The archive settings are missing on this node, redeploy the database."
    exit 0
fi
