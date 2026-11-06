db.getSiblingDB('admin').grantRolesToUser({!! json_encode($name) !!}, [{ role: 'read', db: 'local' }], { w: 'majority' });
