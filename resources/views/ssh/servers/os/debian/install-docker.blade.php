. /etc/os-release
@include('ssh.servers.docker.daemon')
curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.sources <<SOURCES
Types: deb
URIs: https://download.docker.com/linux/${ID}
Suites: ${VERSION_CODENAME}
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
SOURCES
apt-get update -q > /dev/null
docker_build() {
    apt-cache madison "$1" | awk -v prefix="$2" '!found && index($3, prefix) == 1 { print $3; found = 1 }'
}
engine=$(docker_build docker-ce '5:{{ $dockerVersion }}-')
cli=$(docker_build docker-ce-cli '5:{{ $dockerVersion }}-')
containerd=$(docker_build containerd.io '{{ $containerdVersion }}-')
if [ -z "$engine" ] || [ -z "$cli" ] || [ -z "$containerd" ]; then
    echo "Docker {{ $dockerVersion }} with containerd {{ $containerdVersion }} is not available for ${PRETTY_NAME}."
    exit 1
fi
NEEDRESTART_SUSPEND=1 apt-get install -yq --no-install-recommends --allow-change-held-packages --allow-downgrades \
    "docker-ce=$engine" "docker-ce-cli=$cli" "containerd.io=$containerd" > /dev/null
apt-mark hold docker-ce docker-ce-cli containerd.io > /dev/null
@include('ssh.servers.docker.start')
