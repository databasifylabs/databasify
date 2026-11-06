(() => {
    const admin = db.getSiblingDB('admin');
    if (admin.getRole('pbmAnyAction') === null) {
        admin.createRole({ role: 'pbmAnyAction', privileges: [{ resource: { anyResource: true }, actions: ['anyAction'] }], roles: [] }, { w: 'majority' });
    }
    const roles = ['readWrite', 'backup', 'clusterMonitor', 'restore', 'pbmAnyAction'].map((role) => ({ role, db: 'admin' }));
    const pwd = require('fs').readFileSync('{{ $pbmPasswordFile }}', 'utf8').trim();
    if (admin.getUser('pbm') === null) {
        admin.createUser({ user: 'pbm', pwd, roles, mechanisms: ['SCRAM-SHA-256'] }, { w: 'majority' });
    }
})();
