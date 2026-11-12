@include('engines.mongodb._mongo')
mongo_js root <<'JS'
@include('engines.mongodb._replset')
main(() => {
  const primary = primaryOf(hosts());
  const config = configOf(primary.admin);
  if (memberOf(config, env.GONE)) reconfig(primary.admin, withoutHost(config, env.GONE), false);
  result({});
});
JS
