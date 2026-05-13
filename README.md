# MonolithCMS

**An AI-powered website builder that lives in a single file.**

This is the CMS engine deployed to every site provisioned by the MonolithCMS platform. It is not configured manually — the platform agent writes a `.managed_config` file alongside `index.php` that puts the CMS into managed mode.

---

## Managed mode

When `.managed_config` is present and contains `MONOLITHCMS_MANAGED=true`, the CMS runs in managed mode:

- **AI is pre-configured** — all AI calls are proxied through the platform's `/api/v1/ai/complete` endpoint using the site token. No API key setup required during the wizard.
- **Setup wizard skips step 3** — the AI configuration step is bypassed automatically.
- **Admin UI shows platform notice** — the AI Provider section displays "AI is managed by MonolithCMS Platform" instead of the API key form.
- **Budget enforcement** — a `402` response from the proxy surfaces as "AI generation limit reached. Please upgrade your plan."

### `.managed_config` format

Written by the server agent (`agent/provision.go → buildManagedConfig`) at provisioning time:

```
MONOLITHCMS_MANAGED=true
MONOLITHCMS_SITE_TOKEN=<site_token>
MONOLITHCMS_APP_URL=https://app.monolithcms.com
MONOLITHCMS_AI_MODEL=claude-sonnet-4-6
MONOLITHCMS_AI_BUDGET_CENTS=<budget>
MONOLITHCMS_STORAGE_LIMIT_BYTES=<limit>
```

---

## Deployment

The agent handles all deployment. On `POST /sites` the agent:

1. Creates `/var/www/monolithcms/<token>/`
2. Copies `index.php` from this directory
3. Writes `.managed_config` with the site's token, model, and budget
4. Creates an Apache vhost and PHP-FPM pool
5. Configures a fail2ban jail for the site's access log
6. Posts a provision callback to the app

Deletion (`DELETE /sites/<token>`) reverses all of the above.

---

## Updating the CMS

To deploy a new version of `index.php` to all future sites, update `cms/index.php` in this repo and rebuild the agent binary so it picks up the new file path, or update the agent's copy step to reference the latest build.

---

## Features

- **AI generation** — describe your business in plain language, get a complete website
- **AI chat wizard** — conversational design assistant that explores your vision before generating
- **Human-in-the-loop** — review and approve AI output before anything goes live
- **Front-end visual editor** — edit your live site directly in the browser (powered by [GrapesJS](https://github.com/GrapesJS/grapesjs))
- **Blog** — full blog with categories, tags, and Markdown support
- **Media library** — upload and manage images and files
- **Multi-user** — admin, editor, and viewer roles
- **Contact form** — SMTP email, ready to go
- **Security** — bcrypt passwords, two-factor authentication, CSRF protection, rate limiting

---

## Credits

| Project | Use | License |
|---|---|---|
| [Bulma](https://bulma.io) | CSS framework for public-facing pages | MIT |
| [Tailwind CSS](https://tailwindcss.com) | Utility CSS for the admin panel | MIT |
| [GrapesJS](https://github.com/GrapesJS/grapesjs) | Front-end on-page visual site editor | BSD-3-Clause |
| [Quill](https://quilljs.com) | Rich text / WYSIWYG editor for the blog | BSD-3-Clause |
| [Material Symbols](https://fonts.google.com/icons) | Icon set throughout the UI | Apache 2.0 |
| [Inter](https://rsms.me/inter/) | Admin UI typeface | SIL OFL 1.1 |
| [SQLite](https://www.sqlite.org) | Embedded database engine | Public Domain |

---

## License

GPL v3 with attribution requirement — see [LICENSE](LICENSE) for full terms.
