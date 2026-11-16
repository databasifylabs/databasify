main(() => {
  try {
    run(@json($name), { create: '_databasify' });
  } catch (error) {
    if (!hasCode(error, 48)) throw error;
  }
  emit('Created the database.');
});
