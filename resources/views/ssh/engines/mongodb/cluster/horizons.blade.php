const horizonsOf = (alias) => (alias ? { horizons: { public: alias } } : {});
const aliasIn = (wanted, host) => wanted.find((member) => member.host === host)?.alias ?? null;
const horizonsDrifted = (config, wanted) => {
    const aliases = config.members.map((member) => aliasIn(wanted, member.host));
    const settled = config.members.every((member) => wanted.some((want) => want.host === member.host))
        && (aliases.every((alias) => alias !== null) || aliases.every((alias) => alias === null));

    return settled && config.members.some((member, index) => (member.horizons?.public ?? null) !== aliases[index]);
};
const withHorizons = (config, wanted) => ({
    ...config,
    members: config.members.map(({ newlyAdded, horizons, ...member }) => ({ ...member, ...horizonsOf(aliasIn(wanted, member.host)) })),
});
