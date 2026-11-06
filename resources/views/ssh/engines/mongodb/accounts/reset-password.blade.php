db.getSiblingDB('admin').updateUser({!! json_encode($name) !!}, { pwd: {!! json_encode($password) !!}, mechanisms: ['SCRAM-SHA-256'] }, { w: 'majority' });
