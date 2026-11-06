id -u {{ $username }} > /dev/null 2>&1 && userdel --remove {{ $username }} 2>/dev/null || true
echo "user {{ $username }} removed"
