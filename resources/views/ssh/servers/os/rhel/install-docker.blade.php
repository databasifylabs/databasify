. /etc/os-release
@include('ssh.servers.docker.daemon')
@include('ssh.servers.os.selinux')
selinux_restore /etc/docker
curl -fsSL https://download.docker.com/linux/centos/docker-ce.repo -o /etc/yum.repos.d/docker-ce.repo
docker_build() {
    dnf list --showduplicates --quiet --repo docker-ce-stable "$1" 2> /dev/null \
        | awk -v prefix="$2" '{ sub(/^[0-9]+:/, "", $2) } index($2, prefix) == 1 { print $2 }' | sort -V | tail -n 1
}
engine=$(docker_build docker-ce '{{ $dockerVersion }}-')
cli=$(docker_build docker-ce-cli '{{ $dockerVersion }}-')
containerd=$(docker_build containerd.io '{{ $containerdVersion }}-')
if [ -z "$engine" ] || [ -z "$cli" ] || [ -z "$containerd" ]; then
    echo "Docker {{ $dockerVersion }} with containerd {{ $containerdVersion }} is not available for ${PRETTY_NAME}."
    exit 1
fi
dnf versionlock delete docker-ce docker-ce-cli containerd.io > /dev/null 2>&1 || true
dnf install -y -q --allowerasing \
    "docker-ce-$engine" "docker-ce-cli-$cli" "containerd.io-$containerd" > /dev/null
dnf versionlock add docker-ce docker-ce-cli containerd.io > /dev/null
@include('ssh.servers.docker.start')
