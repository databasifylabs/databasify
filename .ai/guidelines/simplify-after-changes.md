# Simplify After Every Change

This project ships a `laravel-simplifier` agent in `.claude/agents/laravel-simplifier.md`.

- After each change to PHP/Laravel code, and before you report the work as done, run the `laravel-simplifier` agent on the files you changed.
- Give it the changed files as its scope. It must keep behaviour and public signatures unchanged.
- After it finishes, run Pint, PHPStan and the affected feature tests again.
