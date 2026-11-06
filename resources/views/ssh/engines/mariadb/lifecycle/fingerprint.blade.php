config_fingerprint() { { cat {{ $configFile }} {{ $serverScript }} 2> /dev/null || true; mariadbd --version; dpkg-query -W -f '${Version}' galera-4 2> /dev/null || true; } | sha256sum | cut -d ' ' -f 1; }
record_running_config() { config_fingerprint > {{ $runningConfig }}.new && mv {{ $runningConfig }}.new {{ $runningConfig }}; }
