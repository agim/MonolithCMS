# MonolithCMS

**An AI-powered website builder that lives in a single file.**

You describe your website. The AI builds the website. You approve it. Done.

---

## How it works

1. **Upload `index.php`** to any PHP web host
2. **Run the setup wizard** — create your admin account
3. **Add your AI API key** — OpenAI, Anthropic, or Google
4. **Describe your business** — name, audience, style, pages you need
5. **Review and approve** — the AI generates everything, you click approve
6. **Your website is live**

No coding. No design tools. No complicated setup. One file, and you're done.

---

## What the AI builds for you

- Every page — home, about, services, contact, and more
- Navigation menu
- Header and footer
- All written content
- Color palette and typography
- SEO metadata for every page
- A blog (optional)
- A contact form (optional)

You can edit anything after generation — through the admin panel or directly on the page with the built-in front-end visual editor.

---

## See it live

[monolithcms.com](https://monolithcms.com) is itself built and running on MonolithCMS — the marketing site, the blog, the contact form, everything. It's the most complete example of what the CMS produces in production.

### One brief, three AI providers

The [Examples page](https://monolithcms.com/examples) shows three real, production-deployed sites all generated from the same brief: a fictional architecture firm called Arkitects. The same prompt was submitted to three different AI providers — no templates, no hand-edits.

| Build | Provider | Pages | Live site |
|---|---|---|---|
| Arkitects — Claude | Anthropic Claude Sonnet | 4 | [arkitects-claude.monolithcms.com](https://arkitects-claude.monolithcms.com/) |
| Arkitects — GPT | OpenAI GPT-4 | 6 | [arkitects-gpt.monolithcms.com](https://arkitects-gpt.monolithcms.com/) |
| Arkitects — Gemini | Google Gemini | 7 | [arkitects-gemini.monolithcms.com](https://arkitects-gemini.monolithcms.com/) |

The brief specified a green colour palette — all three picked up on that — but each AI made completely different structural and copy decisions. Claude built four narrative-dense pages and anchors the contact section on the homepage. GPT added a dedicated FAQ page and a quieter editorial typographic style. Gemini went broadest — seven pages including a separate Firm page and auto-generated Privacy Policy and Terms of Service — with a crisper, more minimal layout. Placeholder images throughout are served by [picsum.photos](https://picsum.photos). All three were generated in under five minutes each, then approved and applied with one click.

---

## Why one file?

Most website software requires you to install packages, set up a build system, manage dependencies, and deal with version conflicts. With MonolithCMS, there is nothing to install. One file contains everything — the application logic, the admin panel, the AI integration, the block editor, the blog, the contact form, all of it.

Upload it to any web host that runs PHP, and it works.

---

## Why PHP?

PHP runs on virtually every web host on the planet — shared hosting, VPS, managed WordPress hosts, and everything in between. No special server configuration required. No runtime to install. If your host can serve a website, it can run MonolithCMS.

---

## Why SQLite?

MonolithCMS stores everything — your pages, content, media files, settings, and user accounts — in a single database file called `site.sqlite` that sits alongside `index.php`. No database server to configure, no credentials to manage, no connection strings to worry about.

`site.sqlite` is created automatically on first run. To deploy, upload just `index.php`. To back up your entire site, copy `index.php` and `site.sqlite`. To migrate to a new host, bring both files.

---

## Why caching?

Every time someone visits your site, the page gets saved to disk. The next visitor gets that saved version instantly, without any database queries or template rendering. This means your site loads fast even on the cheapest shared hosting plan, and your server can handle traffic spikes without breaking a sweat.

Caching is automatic. You never have to think about it.

---

## Requirements

- A web host with PHP 8.4 or newer
- An API key from [OpenAI](https://platform.openai.com), [Anthropic](https://console.anthropic.com), or [Google AI](https://aistudio.google.com)

That's the complete list.

---

## Getting started

1. Download `index.php` from the [latest release](../../releases/latest)
2. Upload it to your web host (public folder, e.g. `public_html`)
3. **Protect your database** — block direct access to `site.sqlite` (see [Server Setup](#server-setup) below)
4. Visit `yourdomain.com`
5. Follow the setup wizard

---

## Server setup

The database file `site.sqlite` must not be publicly accessible. Configure your server to block direct access to it.

**Apache** — add this to your `.htaccess`:

```apache
<FilesMatch "\.sqlite$">
    Require all denied
</FilesMatch>
```

**Nginx** — add this to your server block:

```nginx
location ~* \.sqlite$ {
    deny all;
}
```

All page URLs are routed through `index.php`. On Apache this happens automatically if `mod_rewrite` is enabled. On Nginx, add this to your server block:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

---

## Features

- **AI generation** — describe your business in plain language, get a complete website
- **AI chat wizard** — conversational design assistant that explores your vision before generating
- **Human-in-the-loop** — review and approve AI output before anything goes live
- **Front-end visual editor** — edit your live site directly in the browser, drag and reorder blocks, tweak content in place (powered by [GrapesJS](https://github.com/GrapesJS/grapesjs))
- **Blog** — full blog with categories, tags, and Markdown support, built in
- **Media library** — upload and manage images and files
- **Multi-user** — admin, editor, and viewer roles
- **Contact form** — SMTP email, ready to go
- **Security** — bcrypt passwords, two-factor authentication, CSRF protection, rate limiting
- **Multi-provider AI** — switch between OpenAI, Anthropic, and Google at any time
- **No external dependencies** — nothing to install, nothing to update, nothing to break

---

## AI providers

| Provider | Model |
|---|---|
| OpenAI | GPT-5.4 Pro |
| Anthropic | Claude Sonnet 4.6 |
| Google | Gemini 3 Flash |

Configure your preferred provider and API key from the admin panel after setup.

---

## Credits

MonolithCMS is built on the shoulders of great open-source projects:

| Project | Use | License |
|---|---|---|
| [Bulma](https://bulma.io) | CSS framework for public-facing pages | MIT |
| [Tailwind CSS](https://tailwindcss.com) | Utility CSS for the admin panel | MIT |
| [GrapesJS](https://github.com/GrapesJS/grapesjs) | Front-end on-page visual site editor | BSD-3-Clause |
| [Quill](https://quilljs.com) | Rich text / WYSIWYG editor for the blog | BSD-3-Clause |
| [Material Symbols](https://fonts.google.com/icons) | Icon set throughout the UI | Apache 2.0 |
| [Inter](https://rsms.me/inter/) | Admin UI typeface | SIL OFL 1.1 |
| [SQLite](https://www.sqlite.org) | Embedded database engine | Public Domain |
| [picsum.photos](https://picsum.photos) | Placeholder image service used in demo sites | Free to use |

---

## License

GPL v3 with attribution requirement — see [LICENSE](LICENSE) for full terms.

The short version: you can use, modify, and redistribute MonolithCMS freely, but any public distribution or hosted service must include a visible "Powered by MonolithCMS" attribution.
