@include('ssh.engines.mongodb.helpers')
position=$(mongo_js 'const applied = db.adminCommand({ replSetGetStatus: 1 }).optimes.appliedOpTime.ts; print(applied.getHighBits() * 1000000 + Math.min(applied.getLowBits(), 999999))' || true)
printf 'position=%s\n' "${position:--1}"
