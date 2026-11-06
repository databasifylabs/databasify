(() => {
    const admin = db.getSiblingDB('admin');
    const name = {!! json_encode($name) !!};
    db.getSiblingDB(name).dropDatabase({ w: 'majority' });
    admin.getUsers().users
        .map((user) => ({ user: user.user, roles: user.roles.filter((role) => role.db === name) }))
        .filter((grant) => grant.roles.length > 0)
        .forEach((grant) => admin.revokeRolesFromUser(grant.user, grant.roles, { w: 'majority' }));
})();
