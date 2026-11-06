@include('ssh.engines.mongodb.helpers')
source_uri=$(echo '{{ base64_encode($sourceUri) }}' | base64 -d)
report=$(HOME=/tmp timeout 30 mongosh --quiet --norc "$source_uri" --eval '
    const hello = db.hello();
    const listed = db.adminCommand({ listDatabases: 1, nameOnly: false });
    const user = listed.databases.map((database) => database.name).filter((name) => !["admin", "local", "config"].includes(name));
    let oplog = hello.setName ? "yes" : "no";
    if (hello.setName) {
        try { db.getSiblingDB("local").getCollection("oplog.rs").find({}, { ts: 1 }).limit(1).toArray(); } catch (error) { if (error.codeName !== "Unauthorized") { throw error; } oplog = "no"; }
    }
    print("reachable=yes");
    print("version=" + db.version());
    print("replica_set=" + (hello.setName ?? ""));
    print("size_mb=" + Math.round(listed.totalSize / 1048576));
    print("databases=" + user.join(","));
    print("oplog=" + oplog);
' </dev/null 2>&1 || true)
if ! grep -q '^reachable=yes' <<< "$report"; then
    printf 'error=%s\n' "$(grep -v '^\s*$' <<< "$report" | tail -n 2 | tr '\n' ' ')"
    exit 0
fi
printf '%s\n' "$report"
wanted='{{ implode(',', $databases) }}'
[ -n "$wanted" ] || wanted=$(sed -n 's/^databases=//p' <<< "$report")
occupied=$(WANTED="$wanted" mongo_js 'print(process.env.WANTED.split(",").filter((name) => name !== "" && db.getSiblingDB(name).getCollectionNames().some((collection) => !collection.startsWith("system.") && db.getSiblingDB(name).getCollection(collection).findOne() !== null)).join(","))' || true)
printf 'occupied=%s\n' "$occupied"
