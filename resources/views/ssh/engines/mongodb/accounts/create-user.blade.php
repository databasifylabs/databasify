(() => {
    const admin = db.getSiblingDB('admin');
    const user = {!! json_encode($name) !!};
    const account = { pwd: {!! json_encode($password) !!}, roles: {!! json_encode($databases) !!}.flatMap((db) => ['readWrite', 'dbAdmin'].map((role) => ({ role, db }))), mechanisms: ['SCRAM-SHA-256'] };
    const existing = admin.getUser(user);
    existing === null
        ? admin.createUser({ user, ...account }, { w: 'majority' })
        : admin.updateUser(user, { ...account, roles: [...account.roles, ...existing.roles.filter(({ db }) => db === 'local').map(({ role, db }) => ({ role, db }))] }, { w: 'majority' });
})();
