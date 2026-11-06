(() => {
    const admin = db.getSiblingDB('admin');
    const roles = ['readWrite', 'dbAdmin'].map((role) => ({ role, db: {!! json_encode($name) !!} }));
    {!! json_encode($users) !!}.forEach((user) => admin.grantRolesToUser(user, roles, { w: 'majority' }));
})();
