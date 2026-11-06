os_family() {
    (
        . /etc/os-release
        case " ${ID:-} ${ID_LIKE:-} " in
            *" debian "* | *" ubuntu "*) echo debian ;;
            *" rhel "* | *" fedora "* | *" centos "*) echo rhel ;;
            *) echo unknown ;;
        esac
    )
}

install_packages() {
    if [ "$(os_family)" = rhel ]; then
        dnf install -y -q "$@" > /dev/null
        return
    fi
    apt-get update -q > /dev/null
    apt-get install -yq "$@" > /dev/null
}
