# Contributing to Havoc WEB Panel

Thank you for helping! Havoc WEB Panel is free, open-source software by [Webolye](https://webolye.com/havocwebpanel/), and every bug report, idea, translation and pull request makes it better.

## Ways to help

- **Report a bug**: open an issue with the *Bug report* template. Include your Debian version, the panel version (shown at the bottom of every page), steps to reproduce and relevant log lines (`/var/log/havoc/panel/`). Remove passwords, IP addresses and other private data first.
- **Suggest a feature**: open an issue with the *Feature request* template and describe the problem you want to solve.
- **Translate**: copy `lang/en.php` to `lang/<code>.php`, translate the values (keep the keys and `:placeholders`) and add the language to `I18n::SUPPORTED`.
- **Improve the docs**: the README and [docs/ADMIN-GUIDE.md](docs/ADMIN-GUIDE.md).
- **Send code**: see below.

Security problems are **not** reported in public issues; see [SECURITY.md](SECURITY.md).

## Development setup

You need PHP 8.2+ (with `pdo_sqlite`, `mbstring`, `sodium`), Node.js 18+ for the CSS/editor build, and a Debian 12/13 **test VM** for anything that touches the system (the root helper in `ctl/`).

```bash
git clone https://github.com/OWNER/havoc-web-panel.git
cd havoc-web-panel
npm install
npm run build          # Tailwind -> public/assets/app.css
npm run build:editor   # CodeMirror -> public/assets/editor.js
```

Never test changes on a production server.

## Code guidelines

- **No frameworks.** The panel deliberately uses its own small core (`src/Core`). New dependencies need a very good reason.
- **PHP**: `declare(strict_types=1);`, PSR-12 style, typed properties and return types.
- **Security first:**
  - every query uses prepared statements (`DB::` helpers),
  - every output is escaped with `e()`,
  - every state-changing form includes `csrf_field()`,
  - every record is loaded through `Core\Scope` so users only reach their own data,
  - inline scripts/styles need the CSP `nonce()`,
  - new privileged operations go into `ctl/` as a whitelisted action (`Actions::ALLOWED`) with strict input validation; never run shell commands with user input from the panel side.
- **Texts**: no hard-coded UI strings; add keys to **both** `lang/en.php` and `lang/tr.php`.
- **Database changes**: add a new numbered file in `database/migrations/`; never edit a released migration.
- Keep changes focused: one feature or fix per pull request.

## Before opening a pull request

```bash
find . -name '*.php' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors'
for f in install/*.sh bin/havoc-*; do bash -n "$f"; done
npm run build
```

Then describe **what** changed, **why**, and **how you tested it** (which Debian version, which pages/actions). Screenshots help for UI changes.

## License of contributions

By contributing you agree that your contribution is released under the project's [MIT License](LICENSE).

## Code of conduct

Be kind and respectful. Help newcomers, assume good intent, criticise code rather than people. Harassment or personal attacks are not tolerated, and maintainers may remove comments or block accounts that break these rules.
