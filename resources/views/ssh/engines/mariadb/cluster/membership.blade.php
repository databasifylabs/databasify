@include('ssh.engines.mariadb.helpers')
if [ -f {{ $configPath }} ] && grep -q '^wsrep_cluster_address' {{ $configPath }}; then
  current=0
  runs_current_config && current=1
  sed -i 's|^wsrep_cluster_address = .*|wsrep_cluster_address = gcomm://{{ $hosts }}|' {{ $configPath }}
  [ "$current" = 0 ] || record_running_config
fi
@if ($hosts !== '')
if in_galera; then
  members="'$(sed "s/,/','/g" <<< '{{ $hosts }}')'"
  for stray in $(msql "SELECT node_uuid FROM mysql.wsrep_cluster_members WHERE node_name NOT IN ($members)"); do
    { msql "SET GLOBAL wsrep_provider_evs_evict = '$stray'" || msql "SET GLOBAL wsrep_provider_options = 'evs.evict=$stray'"; } && echo "evicted $stray, which is no longer a member"
  done
fi
@endif
echo "membership updated: members {{ $hosts }}"
