(() => {
    const admin = db.getSiblingDB('admin');
    const user = {!! json_encode($name) !!};
    admin.getUser(user) === null || admin.dropUser(user, { w: 'majority' });
})();
