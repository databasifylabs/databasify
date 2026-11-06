dnf install -y -q ca-certificates jq openssl nftables wireguard-tools dnf-plugins-core python3-dnf-plugin-versionlock > /dev/null
command -v curl > /dev/null 2>&1 || dnf install -y -q curl > /dev/null
jq --version
openssl version
