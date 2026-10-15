# conductor

`php-baremetal/conductor` is the Composer plugin behind every php-baremetal firmware project. A firmware is a
Composer project: its packages add functions, classes and chip support to the PHP that runs on the board, and
conductor is what makes Composer understand them. It runs on your computer, never on the board, and is published on
[Packagist](https://packagist.org/packages/php-baremetal/conductor).

It needs PHP 8.4 or later and Composer 2 (`composer-plugin-api ^2.0`), and depends on
[`php-baremetal/spec`](https://packagist.org/packages/php-baremetal/spec) for the manifest model.

## What it does

- **Installs `bm-*` packages into the project.** Packages whose Composer type starts with `bm-` (`bm-ext`,
  `bm-hal`, `bm-lib`, `bm-php-ext`) are installed in `.phpbm/packages/<vendor>/<name>` instead of `vendor/`, where
  the SDK build reads them.
- **Binds the HAL for the project's platform.** A neutral extension requires a virtual such as
  `php-baremetal/i2c-hal`. When `extra.baremetal.sdk` is set, conductor walks the project's requirements, finds
  each `php-baremetal/<x>-hal` virtual, and adds the concrete provider for the SDK's platform
  (`php-baremetal/esp32-<x>-hal` for `esp32@^1.0`) to the project's requirements, so you never require a HAL
  yourself. It warns when the platform has no provider, and for the retired `<x>-transport` requirements.
- **Writes the build manifest.** After every `composer install` and `composer update` it writes
  `.phpbm/manifest.json` (schema 1): the project's settings from `extra.baremetal`, and every installed `bm-*`
  package with its version, install path and `extra.baremetal` data. `phpbm` reads this file to build and flash.
  Problems it can detect (an extension without a `module`, two extensions with the same module, a HAL for another
  platform, a `bm-php-ext` with extra keys) are printed as warnings; an invalid configuration prints an error and
  removes a stale manifest.

All of it applies only to projects whose `composer.json` has an `extra.baremetal` object. Any other project installs
as usual.

## Install

Conductor is installed twice. Once per machine, so the plugin is already active during the very first dependency
solve of a new project:

```bash
composer global require php-baremetal/conductor
```

And in the project, which `phpbm init` writes for you: `require` with `^1.0` and an `allow-plugins` entry, because
Composer 2 does not run a plugin that is not allowed.

```json
{
  "name": "acme/my-firmware",
  "type": "project",
  "repositories": [
    { "type": "composer", "url": "https://repository.php-baremetal.com", "canonical": true }
  ],
  "require": {
    "php-baremetal/conductor": "^1.0",
    "php-baremetal/ext-store": "^1.0"
  },
  "config": {
    "allow-plugins": { "php-baremetal/conductor": true },
    "platform": { "php": "8.5.11" }
  },
  "extra": {
    "baremetal": {
      "sdk": "esp32@^1.0",
      "board": "waveshare-esp32-s3-touch-lcd-1.85b",
      "runtime": "init-loop",
      "storage": "embedded"
    }
  }
}
```

The packages come from the php-baremetal registry (`https://repository.php-baremetal.com`, canonical, so it wins for
`php-baremetal/*`); a package under development comes from a `path` repository next to it.

## Project settings

Read from `extra.baremetal`, plus the PHP version from `config.platform.php`, which is required.

| Key | Meaning |
| --- | --- |
| `sdk` | Required. `<name>@<constraint>`, for example `esp32@^1.0`. The name is the platform. |
| `board` | Required. A board id of the SDK. |
| `runtime` | Required. `init-loop`, `event-driven` or `web-server`. |
| `storage` | Required. `embedded` or `microsd`. |
| `src`, `entry` | Directory of the PHP sources (default `project-src`) and the entry script (default `index.php`). |
| `env` | Optional. Path, relative to the project, of a file of values baked into the firmware (never copied to the microSD). |
| `board-overrides` | Optional. Fields of the board descriptor to replace. |
| `options` | Optional. SDK and package options, each a string, an integer or a boolean. |

The project name is the `name` of `composer.json` without its vendor. The SDK validates the values against its own
descriptors when it builds.

## Commands

Both work on the project in the current directory (or the one given with `composer -d <dir>`).

```bash
composer conductor:generate              # rewrite .phpbm/manifest.json
composer conductor:generate --dry-run    # print it, write nothing
composer conductor:generate --strict     # exit 1 if any problem was reported
composer conductor:info                  # the project and its bm-* packages
```

`conductor:info` prints the SDK, board, runtime, storage and PHP version, then one line per installed `bm-*`
package.

## Not for hand editing

`.phpbm/` is generated: rebuild the manifest with `composer conductor:generate` instead of editing it, and keep the
directory out of version control.

## Development

The tests use `php-baremetal/spec` from Packagist. To test against a local checkout in `../spec` instead,
register it for your working copy and do not commit the change:

```sh
composer config repositories.spec '{"type":"path","url":"../spec","options":{"versions":{"php-baremetal/spec":"1.0.0"}}}'
composer install && vendor/bin/phpunit
```

## License

MIT.
