# 0039. Plugin engines are community engines

- Status: Accepted
- Date: 2026-11-11

## Context

Plugins can register database engines ([0016](0016-app-architecture.md)). Databasify builds, tests and supports only the engines app core registers. An admin installing a plugin engine needs to know who stands behind it before any of its code runs, and a plugin update must not strand databases on an engine it no longer ships.

## Decision

- An engine app core registers (`EngineServiceProvider` calling `RegisterEngine::make()`) is official. Any engine added to `engine.providers` while a plugin loads, however the plugin adds it (`PluginContext::database()`, `RegisterEngine::make()` or a raw `config()` write), is community: `PluginLoader::load()` stamps its catalog entry with the plugin id and author from the plugin row, overwriting anything the plugin set, and `Engine::isCommunity()` is true. The plugin has nothing to set. The author is the first `authors[].name` in the plugin's `composer.json`, else the vendor part of its package name.
- A plugin declares the engines it registers in `composer.json` under `extra.databasify.engines`, a list of ids. `PluginLoader::load()` checks every engine the plugin added against that list; an undeclared one fails the plugin's boot and its config changes are rolled back.
- Installing a plugin that declares engines is refused on `community_engines` until the admin accepts the warning, checked from the staged `composer.json` before any plugin code runs. An update that declares a new engine asks again.
- An update that drops a declared engine any database still uses is refused.
- The Plugins page, the create-page engine card and the database header show a "Community" badge.

## Consequences

- The admin always sees who supports an engine before its code runs.
- Plugin authors must keep `extra.databasify.engines` in step with what they register.
- The plugin row stores `author` and `engines`, so they are known while the plugin is disabled.

## Alternatives considered

- **Detecting engines by loading the plugin first:** rejected, because the warning must come before any plugin code runs.

## Evidence

- `app/Services/Plugins/PluginLoader.php`
- `app/Plugins/PluginContext.php`
- `app/Engines/Engine.php`
- `app/DTOs/Plugins/PluginPackage.php`
- `app/Actions/Plugins/ConfirmCommunityEngines.php`
- `app/Actions/Plugins/InstallPlugin.php`
- `app/Actions/Plugins/UpdatePlugin.php`
