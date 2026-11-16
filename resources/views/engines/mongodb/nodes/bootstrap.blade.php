@include('engines.mongodb._initiate')
@include('engines.mongodb._service-users')
main(() => {
  initiate(@json($host));
  saveServiceUsers(@json($adminPassword), @json($agentPassword));
  emit('Bootstrapped the admin and backup users.');
});
