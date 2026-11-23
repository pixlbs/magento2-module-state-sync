# Magento 2 Module state sync

Updates the module list in Magento's `app/etc/config.php`, in module sequence order, as `setup:upgrade` does. It also
enforces the state of modules you list. It doesn't bootstrap Magento: no database, cache or generated code is needed or
touched, so it can run in CI or right after `composer install` on a server.

## Why

When `config.php` lives on the server, or the committed one is behind, modules that a deploy adds are missing from it.
Magento treats a module that isn't listed as not installed:

- **Its database changes never run.** `setup:db:status` only checks the modules in `config.php`, so it reports that all
  is up to date. `setup:db-schema:upgrade` and `setup:db-data:upgrade`, which deploy tools such as Deployer run, don't
  add modules to `config.php`; only `setup:upgrade` does. The module stays off without any error.
- **Its configuration is not loaded.** `setup:di:compile` does see its classes, but its `di.xml` (preferences, plugins),
  events, routes, layout and `db_schema.xml` are only read for listed modules. Code that relies on them fails at
  runtime, for example with "Cannot instantiate interface" when only that module sets the preference.
- **The order can be wrong.** The order of `config.php` is the order in which `di.xml` and layout files are merged, so
  an outdated order can let the wrong override win.

The result is a build that succeeds but behaves differently from what was tested. Running `setup:upgrade` on the server
fixes the list, but:

- **It means downtime.** It clears the caches and, without `--keep-generated`, the generated code, and it changes the
  database while the live code is serving requests. So it runs in maintenance mode, on every deploy, also when nothing
  in the database changes.
- It needs a bootstrapped Magento: database, cache and a working configuration.
- It enables every new module, also those that should stay off.

This command only edits `config.php`, so it can run on the new release while the old one stays live. It adds new
modules, removes those that are gone and restores the sequence, before `setup:di:compile`. The enforced states then make
sure modules are on or off as intended, whatever the server's `config.php` said. Database upgrades are still needed when
a module brings schema or data changes; the deploy tool runs them, with maintenance mode only when
`setup:db:status` says so.

## Installation

```bash
composer require pixel-labs/magento2-module-state-sync
```

Requires PHP 8.1 or higher. Tested with Magento 2.4.6 to 2.4.9.

## Usage

```bash
vendor/bin/module-state-sync
```

- Modules that are new are enabled, unless enforced otherwise.
- Disabled modules stay disabled.
- Modules that are no longer installed are removed.
- All other sections of `config.php` keep their values. Comments are not kept.

| Option              | Default                           |                                                         |
|---------------------|-----------------------------------|---------------------------------------------------------|
| `--root=<dir>`      | the Composer project              | Magento root                                            |
| `--config=<file>`   | `app/etc/config.php`              | File to update                                          |
| `--enforced=<file>` | `app/etc/enforced-modules.php`    | Enforced states; the default file is optional           |
| `--dry-run`         |                                   | Only show the changes; exit code 2 when there are any   |

Exit codes: 0 done, 1 error (`config.php` is not changed), 2 update needed (`--dry-run`).

To have it as a Composer script, add to the project's `composer.json`:

```json
"scripts": {
    "module-state-sync": "@php vendor/bin/module-state-sync"
}
```

## Enforced modules

Commit `app/etc/enforced-modules.php` with the modules whose state must hold, whatever the server's `config.php` says.
Modules that match nothing in it keep the state they have.

```php
<?php
return [
    'enabled' => [
        'Merchant_*',                    // all of the merchant's own modules...
        'MageOS_PageBuilder{Layout,Ui}',
        'Magento_InventoryApi',          // exception to the pattern below
    ],
    'disabled' => [
        'Merchant_BrandNewFeature',      // ...except this one, not live yet
        'Magento_Inventory*',            // we don't use MSI
        'Swissup_Ignition',
        '/^Magento_.+GraphQl$/',         // regex, when a wildcard isn't enough
    ],
];
```

| Entry                           | Matches                                                                 |
|---------------------------------|-------------------------------------------------------------------------|
| `Magento_Store`                 | that module                                                             |
| `Magento_Inventory*`            | `*` is any number of characters, also none                              |
| `MageOS_PageBuilder{Layout,Ui}` | the same as listing both names                                          |
| `/^Magento_.+GraphQl$/`         | PHP regular expression; not anchored unless you add `^` and `$`         |

Entries are case-sensitive, like module names; a regex can have the `i` flag.

- A module's exact name beats wildcards and regexes, whichever list it is in: with `Merchant_*` enabled and
  `Merchant_BrandNewFeature` disabled, that module is disabled and all other `Merchant_` modules are enabled. Names in
  `{a,b}` without `*` count as exact.
- Otherwise disabled beats enabled. The order of entries doesn't matter.
- An enabled entry that matches no installed module is an error, so a typo or a missing package stops the deploy. With
  `{a,b}`, each alternative has to match. A disabled entry that matches nothing is only reported.
- Patterns also apply to modules that are added later.

Dependencies between modules are not checked, so a disabled module may be one that others need. `--dry-run` shows which
entry changes which module.

## Deployer

Run it before `setup:di:compile`. When `config.php` is a shared file, the release's copy is a symlink: updating it
directly would change the live site while the release is still being built, and leave it changed when the deploy fails.
Update a copy, and publish it when the release is live:

```php
desc("Applies enforced module states and updates the module sequence");
task("magento:module-state-sync", function () {
    $config = "{{magento_dir}}/app/etc/config.php";
    run("cp --remove-destination {{deploy_path}}/shared/$config {{release_path}}/$config");
    run("cd {{release_path}}/{{magento_dir}} && {{bin/php}} vendor/bin/module-state-sync");
});
after("deploy:vendors", "magento:module-state-sync");

desc("Publishes the sequenced config.php to shared");
task("magento:module-state-sync:publish", function () {
    $config = "{{magento_dir}}/app/etc/config.php";
    run("cp -p {{release_path}}/$config {{deploy_path}}/shared/$config.new");
    run("mv -f {{deploy_path}}/shared/$config.new {{deploy_path}}/shared/$config");
    run("{{bin/symlink}} {{deploy_path}}/shared/$config {{release_path}}/$config");
});
after("deploy:symlink", "magento:module-state-sync:publish");
```

After a rollback the shared `config.php` is still that of the newer release.

## Development

```bash
composer update
composer test
```

The sort is a port of Magento's `Magento\Framework\Module\ModuleList\Loader`, which can't be used without bootstrapping
Magento. `Test/Unit/MagentoParityTest.php` runs the same `module.xml` files, including 200 generated module graphs,
through both and requires the same order; CI does so for each supported framework release. `Test/Integration` does it
for all modules of a real install.

## License

MIT
