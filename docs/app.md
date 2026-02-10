# Detailed Plan for the AI-Driven Website Builder

This document outlines the architecture and feature set of the OneCMS single-file PHP application.

## 0. System Architecture & Kernel Implementation
The application is built as a **Single-File PHP Monolith** located in `index.php`. This design ensures maximum portability and zero-configuration deployment. The kernel is organized into 16 discrete sections that handle the request lifecycle from boot to response.

### Core Kernel Sections (`index.php`)
1.  **Configuration & Boot:** Sets up error reporting, timezone, constants, and environment constraints.
2.  **Database Layer:** Initializes PDO SQLite connection and handles migrations (automatic schema creation).
3.  **Security & Authentication:** Manages sessions, password hashing (`PASSWORD_BCRYPT`), CSRF protection, and nonces.
4.  **Settings Management:** Loads global site settings from the `settings` table.
5.  **Input Sanitization:** Recursive cleaning of `$_GET`, `$_POST`, and `$_COOKIE`.
6.  **Request & Response Helpers:** Utilities for JSON responses, redirects, and flash messages.
7.  **Routing:** Front-controller pattern that maps URIs to controller functions.
8.  **Templating Engine:** Custom lightweight function-based renderer supporting partials (`render_partial()`) and components.
9.  **Template Definitions:** Contains all UI components (Navigation, Footer, Cards, Admin Modals).
10. **Cache Management:** Handles full-page caching and partial caching to disk (`cache/`). Implements **CSP Nonce Injection** to allow caching of secure pages.
11. **CSS Generator:** Dynamic CSS generation tailored to the theme settings.
12. **Asset Management:** Serves and caches blob assets (images/files) from the DB.
13. **Controllers:** Business logic for standard pages and admin actions.
    - **13B. Visual Editor & Media API:** Handles the drag-and-drop editor and file operations.
    - **13C. AI Page Generation API:** Interface for AI-driven content creation.
14. **AI Integration:** Connects to LLM providers for generating site copy and structure.
15. **Email & Contact:** SMTP integration and form handling.
16. **Route Definitions:** The actual map of URL patterns to Controller logic.

### File Structure
- `index.php`: The complete application logic.
- `site.sqlite`: The convention-based database.
- `cache/`: Directory for file-based caching.
  - `pages/`: Full HTML page caches.
  - `partials/`: Reusable HTML fragments.
  - `assets/`: Extracted binary files (images/docs).

## 1. Production Environment
The system is designed to operate even in a production environment. All AI-driven features (content generation, dynamic page assembly, caching, etc.) will work reliably on live servers, not just in development. This ensures that the website can be updated or even regenerated on the fly in production if needed, without compromising stability. In short, the AI integration is robust and safe for use on real, public-facing sites.

## 2. Full AI Integration
Yes – the platform will leverage AI extensively for both site design and content creation. The AI will be integrated into all major aspects of the site-building process, from generating textual content to suggesting layout and style elements. This comprehensive integration means the system doesn’t just create a skeletal framework; it actually produces written copy, chooses imagery, and helps style the site using AI. By doing so, we ensure that the output is a fully realized website rather than a half-finished template.

## 3. All Website Components Generated
The goal is for the AI to generate all key components of the website. This includes the navigation menu, the header section, the main content blocks of each page, any sidebars or forms, and the footer. Essentially every part of the site’s pages will be handled by the system. The user won’t need to manually code or design any section, as the AI will create a cohesive set of pages with consistent structure and style. Navigation links (for all the planned pages/routes) will be generated, header and footer sections will be created to match the theme, and content sections (text, images, forms, etc.) will be built out for each page. This holistic approach, where everything from top to bottom is AI-built, ensures a unified design language across the site.

## 4. File Upload Management
The platform will include a built-in file upload manager to handle images and documents. This means users (or the AI on behalf of users) can upload images (like logos, banners, etc.) and documents (PDFs or other files) directly into the system. The file upload manager will store these assets in an organized way in the sqlite database as BLOBs. We’ll ensure it supports common image formats (JPEG, PNG, SVG, etc.) and document types (PDF, DOCX, etc.). It will also handle tasks like generating thumbnails or optimizing images for web use if needed. By having an integrated upload manager, the content generation process can incorporate user-provided images or files seamlessly (for example, inserting an uploaded company logo into the site’s header). This feature simplifies asset management and ensures that all media content is conveniently accessible through the app’s interface.

## 5. Database Structure (Convention-Based)
The application uses a predefined, convention-driven SQLite database schema. The schema is automatically managed via migrations running at boot.

**Key Tables:**
- **`nav`**: Stores navigation menu items (label, URL, order).
- **`pages`**: Defines the site's routing structure (slug, title, meta tags).
- **`content_blocks`**: The core content table. Instead of rigid columns, it uses a flexible `block_json` column to store component data (hero sections, features, team grids). Each block is linked to a `page_id` and has a specific `type` (e.g., 'hero', 'team').
- **`theme_header` & `theme_footer`**: Dedicated tables for global header/footer configuration (logo, links, social icons).
- **`assets`**: Stores binary files (images, documents) as BLOBs, which are served via the `assets/` route and cached to disk.
- **`settings`**: Global site configuration (site name, SEO defaults, API keys).
- **`migrations`**: Tracks executed database migrations to ensure schema consistency across updates.

This structure allows the AI to simply "insert a block" of a certain type into `content_blocks` without needing to alter the database schema for new component designs.

## 6. Manual Editing and Overrides
Certainly, the system will allow human administrators to edit or override content when needed. While the AI will generate the initial content and layout, an admin interface will let users tweak the results. For instance, after generation, an admin might want to reword a headline, swap out an image, or adjust a color – the platform will support these changes. We plan to include a simple CMS-like editor where the admin can go into the content that was generated and modify text, images, or settings. By making the content editable, we combine the speed of AI generation with the precision of human oversight. All edits made by humans would be saved back into the respective tables, so they persist and become part of the site content.

## 7. SEO and Metadata
The website builder will account for all the SEO meta tags and other modern web metadata that a contemporary site needs. This includes:
- **SEO Meta Tags:** Page title, meta description, and keywords.
- **Open Graph Tags:** `og:title`, `og:description`, `og:image` for social media sharing cards.
- **Twitter Cards:** For optimized rendering on Twitter.
- **Scripts:** The ability to include scripts in the page head or footer (e.g., Google Analytics, chat widgets).

The AI can generate a meta description for each page summarizing its content and choose an appropriate `og:image`. This ensures the generated sites are search-engine-friendly and ready for social media sharing out of the box.

## 8. Communication (Email)
For now, email will be the primary communication and notification method integrated into the system. This refers to features like contact forms or system notifications. For example, if the site includes a “Contact Us” form, the platform will handle sending those submissions to a specified email address. Likewise, if the system needs to send out an alert (such as a welcome email to a new user or an admin notification), it will do so via email. Focusing on email keeps things simple and universal. We will ensure that the email integration is configurable (SMTP details, etc.) during setup.

## 9. Optional Configuration at Setup
Certain features of the system will be made configurable during the initial setup process via a setup wizard. These will remain optional:
- **Email SMTP Server:** Configure now or use a default/postpone.
- **Analytics Keys:** Optionally input Google Analytics ID during setup.
- **SEO Customization:** Skip to let AI generate defaults or customize up front.

This approach ensures the system can run out-of-the-box with sensible defaults while allowing advanced users to configure integrations up front.

## 10. Role-Based Access Control (RBAC)
The platform will implement RBAC for managing user permissions. Users are assigned roles such as:
- **Administrator:** Full access (generate sites, edit content, configure settings, manage users).
- **Editor:** Modify content and pages, but not system settings.
- **Viewer/Guest:** Read-only access.

RBAC keeps the system secure by limiting potential damage from accidents or breaches. Only authorized users can perform sensitive actions, such as publishing AI-generated content or altering security settings.

## 11. Content Approval Workflow
All AI-generated content will go through a human approval step before it becomes publicly visible. New content remains in a draft or pending state until an authorized human (Admin or Editor) reviews and approves it. This ensures high quality and prevents incorrect or inappropriate material from going live. The admin dashboard will include a simple queue for reviewing and publishing these changes.

## 12. Smart Caching with Partials
The caching system will use partials (fragmented caching) to maximize efficiency. Instead of caching entire pages, individual sections (nav bar, footer, content blocks) will be cached separately.
- **Granular Updates:** If only the footer changes, only the footer cache is invalidated.
- **Event-Driven:** Whenever content is edited or approved, the corresponding partial cache is automatically cleared.
- **Performance:** Users get the speed of cached content while seeing updates promptly.

## 13. Extensibility for Additional Features
The architecture is designed to be modular and flexible. Future modules like e-commerce or blogs can be integrated without a complete overhaul. The database design using separate tables facilitates adding new features easily. The system is built with longevity and adaptability in mind.

## 14. Self-Contained App with External Assets
The application consists of a single code file and a SQLite database file, making deployment and portability extremely easy.
- **Single File:** The entire web app (logic, templates, AI code) resides in one main executable/script.
- **SQLite Database:** A local file on disk, removing the need for a separate database server.
- **External Assets:** Images, CSS, and JS are stored in the app's folder structure but handled externally to the database for performance.

## 15. Templating with Partials/Includes
The templating engine will use partials and includes (header, footer, content blocks) to construct pages.
- **Reusable Components:** Define markup once and reuse across the site.
- **AI-Driven Assembly:** The AI decides which partials to use and how to arrange them.
- **Separation of Concerns:** Clearly separates structure, content, and style.

## 16. Optimized CSS (Caching Only Used Styles)
The system will generate minimal CSS by including only the styles actually used on the rendered pages.
- **PurgeCSS/Tree Shaking:** Remove unused styles from the final CSS file.
- **Dynamic Generation:** If a new component is added or a theme changes, the CSS is regenerated and the cache is busted.
- **Performance:** Smaller CSS files mean faster load times.
- **Dark Mode Support:** All generated CSS must include dark mode compatible classes using `prefers-color-scheme: dark` media queries, with appropriate color variables for both light and dark themes.
- **Color Palette Guidelines:** Avoid purple tones in default palettes. Prefer professional blue, green, or neutral color schemes that work well in both light and dark modes.

## 17. Content Security Policy (Nonce Handling)
To ensure security while maintaining performance with caching, the system implements a strict Content Security Policy (CSP).
- **Nonce Generation:** A unique `nonce` is generated for every request.
- **CSP Headers:** The `Content-Security-Policy` header is set with this nonce, allowing only trusted inline scripts to run.
- **Cache Compatibility:** Since cached HTML pages are static, they cannot contain a hardcoded nonce (which would be invalid for future requests).
  - When caching a page, the real nonce is replaced with a placeholder: `{{CSP_NONCE_PLACEHOLDER}}`.
  - When serving a cached page, this placeholder is swapped on-the-fly with the fresh nonce for the current request.
  - This ensures comprehensive security without breaking the caching mechanism.

## 18. Database Schema Updates
The database schema has evolved to support richer content types:
- **`content_blocks` Table:** Now uses a `type` column (e.g., 'hero', 'features', 'team', 'contact_info') and a JSON `block_json` column to store structured data. This replaces simple text blobs, allowing for flexible, design-aware rendering of components like team grids, feature cards, and contact details.

## 19. Dark Mode & Theming
The visual theme system is fully dynamic:
- **Auto-Detection:** Uses `matchMedia` to detect system preference.
- **Manual Toggle:** A user-accessible toggle in the navbar allows forcing light/dark mode.
- **Persistence:** User preference is saved to `localStorage` to persist across sessions.
- **Admin Interface:** The admin panel now supports a proper dark mode (no longer forced to light mode), with its own toggle in the sidebar.
- **Component Styling:** All components (cards, badges, text) use Tailwind's `text-base-content`, `bg-base-100/200/300` hierarchy, and border utilities to ensure visibility and aesthetics in both modes.

### Admin UI Theme Requirements
The admin interface (dashboard, page editor, settings, etc.) must **always use a light theme** regardless of the user's system preferences. This is necessary because Pico CSS (loaded via CDN) auto-applies dark mode based on system settings.

**Required implementation for all admin templates:**
1. Add `data-theme="light"` to the `<html>` tag
2. Add `:root { color-scheme: light; }` in the `<style>` block
3. Apply explicit CSS overrides for form inputs:
   ```css
   input, select, textarea {
       background: #fff !important;
       color: #1e293b !important;
       border: 1px solid #cbd5e1 !important;
   }
   input:focus, select:focus, textarea:focus {
       border-color: #3b82f6 !important;
       box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
   }
   ```
4. Ensure all text colors, headings, and labels have explicit light mode colors

This applies to: admin layout, login page, MFA page, and setup wizard templates.

## 17. Use of Advanced AI Models
We will leverage advanced models like GPT-4 for high-quality content generation and design suggestions.
- **Contextual Content:** Generating headlines, paragraphs, and descriptions.
- **Structured Output:** The AI will output structured results (like JSON) that slot directly into our templates and database.
- **Iterative Improvement:** The AI can refine content based on user feedback.

## 18. User Input for Personalization
An onboarding questionnaire will collect user needs to guide the AI:
- **Industry/Domain:** Café, Law Firm, etc.
- **Tone/Style:** Professional, Friendly, Humorous.
- **Key Features:** Contact forms, galleries, FAQs.
- **Technical Preferences:** Choice of JS frameworks or libraries (defaults to simple vanilla JS).

## 19. User-Provided Content Option
The system allows users to provide their own content during the Q&A if they choose.
- **Hybrid Approach:** User can author specific sections while letting AI handle others.
- **Asset Management:** Users can upload their own logos and photos via the file manager.
- **Flexibility:** Users keep control over their brand's voice while benefiting from AI speed.

## 20. Maximum Security
Security is a core design principle, following industry best practices:
- **Secure Coding:** Input validation and sanitization to prevent SQL injection and XSS.
- **RBAC:** Limiting permissions via user roles.
- **Least Privilege:** Running the app with minimal necessary permissions.
- **Data Protection:** Hashing passwords with bcrypt and recommending HTTPS.
- **Security Updates:** Keeping all dependencies patched.
- **OWASP Compliance:** Protecting against CSRF, broken sessions, and other top threats.
- **Sanitized Uploads:** Ensuring uploaded files are safe and stripping metadata.

## Conclusion
This detailed plan ensures that the AI-driven website builder will be robust, flexible, full-featured, and secure. By combining the speed of advanced AI with human oversight and modular architecture, we can deliver high-quality, production-ready websites with ease.

---

# Technical Architecture & Implementation Guide

This section details the realistic architecture of the system as a single-file PHP application, addressing technical components and implementation strategies.

## 1. Core Technical Components

### A. Routing & Front-Controller
The application uses a front-controller pattern. All requests are routed through `index.php`, which handles routing based on `$_SERVER['REQUEST_URI']`. This eliminates the need for heavy frameworks while maintaining clean URLs.

### B. Database Layer (SQLite)
PHP’s PDO with the `sqlite:` driver provides a robust, zero-configuration database. The single `site.sqlite` file stores:
- Content and Block JSON
- Navigation menus and Page metadata
- Theme configuration and Settings
- Build queue and Revision history
- User accounts (hashed passwords + roles)

### C. Tiered Caching Strategy
To ensure maximum performance, the system implements disk-based caching:
- **Partial Cache:** HTML fragments for reusable sections (nav, footer).
- **Page Shell Cache:** The base layout with `{{PARTIAL:name}}` placeholders.
- **Asset Cache:** Generated CSS and minified JS bundles.
- **Request Flow:** Route Resolution → Cache Check → Serve Fresh or Rebuild Stale.

### D. Security & Communication
- **SMTP & MFA:** Integrated SMTP for outbound mail, supporting Email-based Multi-Factor Authentication (OTP codes stored with expiry in SQLite).
- **Admin UI:** Accessible via a restricted route prefix (e.g., `/admin/*`) within the same file.

## 2. Shared Kernel Structure (index.php)
The single application file is organized into conceptual "Kernel" sections:
- **Boot/Config:** Error handling, environment detection, secure headers (CSP, HSTS).
- **DB Layer:** PDO initialization and automatic schema migrations.
- **Security:** Session management, CSRF protection, and rate limiting.
- **Auth/RBAC:** Role-based permission checks and MFA verification.
- **Renderer:** Template assembly from DB blocks and the partial pipeline.
- **Cache/Upload Managers:** Invalidation logic and file validation.
- **AI Integration:** Provider configuration and prompt → plan processing.

## 3. Handling Technical Constraints

### WYSIWYG & Rich Text
Since the goal is a self-contained code file, heavy editors are integrated via CDN (e.g., Quill or TipTap). Content is stored as structured **Block JSON** to facilitate safe rendering and easier parsing for the AI.

### External Framework Integration
Users can choose their preferred JS frameworks (Alpine.js, Vue, React). The system doesn't bundle these; it links to CDN scripts based on page metadata (`js_framework` attribute), allowing for framework-agnostic theming.

### Personalization vs. Performance
To maintain static-fast speeds:
- Public pages are served as fully cached HTML.
- Admin or authenticated states utilize the "Layered Cache" to inject dynamic bits into the shell.

## 4. Portability & Migration
The system aims for the "copy-and-go" experience:
- **Required Files:** `index.php` (logic) and `site.sqlite` (data).
- **Assets:** All assets wil be saved inside the sqlite database as BLOBs. They will be extracted to a designated `cache/assets` directory regularly.

## 5. Automated Setup Wizard Flow
1. **Uninitialized Detection:** If no admin exists, redirect to local setup.
2. **Admin Creation:** Establish the primary account.
3. **AI Discovery Interview:** Questionnaire regarding industry, tone, and features.
4. **Plan Generation:** AI returns a structured JSON plan (routes, menus, colors).
5. **Human Approval:** Admin reviews the plan before the system populates tables and builds the initial cache.

What to do now so migration is painless later
---------------------------------------------

### 1) Use PDO everywhere, never SQLite-specific APIs

*   Build everything on **PDO** with prepared statements.
    
*   Keep SQL mostly ANSI-standard (SELECT/INSERT/UPDATE/DELETE, simple joins).
    

### 2) Add a tiny “DB adapter” layer inside the one file

Even in one file, separate your code logically:

*   db() returns a PDO instance
    
*   db\_driver() returns sqlite|pgsql|mysql
    
*   query($sql, $params) wrapper
    
*   transaction(fn() => ...) wrapper
    

Later you change only:

*   connection DSN + credentials
    
*   a handful of dialect differences (below)
    

### 3) Design schema to be cross-database

Avoid things that lock you into SQLite:

**Do**

*   primary keys as integer (or UUID stored as text)
    
*   timestamps stored as integer epoch (portable) or ISO text
    
*   JSON stored as TEXT (portable), with optional JSON features later
    

**Avoid**

*   relying on SQLite rowid
    
*   WITHOUT ROWID
    
*   heavy use of SQLite json1 functions in queries
    
*   SQLite FTS as a must-have (treat search as an optional backend)
    

### 4) Put migrations in your app (not manual SQL runouts)

Store migration versions in a schema\_migrations table and run them at boot.When you move to Postgres/MySQL, you either:

*   re-run migrations on an empty DB, then import content
    
*   or write a one-time “export/import” routine
    

### 5) Build an export/import format now

This is the real cheat code.

Have admin tools that can:

*   export all tables (and optionally uploads metadata) to a **portable JSON** or a zipped JSON bundle
    
*   import that bundle into any DB backend
    

If you have export/import, changing DB engines becomes operationally easy.

The real differences you’ll have to handle later (small, but real)
------------------------------------------------------------------

### A) Autoincrement / sequences

*   SQLite: INTEGER PRIMARY KEY auto-increments
    
*   Postgres: SERIAL / GENERATED AS IDENTITY
    
*   MySQL: AUTO\_INCREMENT
    

Solution: wrap it in migrations; don’t depend on autoincrement semantics beyond “unique ID.”

### B) Date/time handling

SQLite is lax; Postgres is strict.Solution:

*   store times as **UTC integer epoch** (recommended), or
    
*   store ISO8601 text consistently
    

### C) Concurrency / locking

SQLite locks the DB on writes; Postgres/MySQL are better under concurrent writes.Good news: you’ll likely _improve_ when you migrate, especially if you have queues/build workers.

### D) Full-text search (if you add it)

SQLite has FTS5; Postgres has tsvector; MySQL has FULLTEXT.Solution: define a search\_index abstraction so the CMS works even without FTS enabled, and each DB can plug in its best option.

### E) JSON querying

SQLite can store JSON but querying varies.Solution: store JSON as TEXT and keep most logic in PHP, not in SQL. Later you can add optional JSON indexes in Postgres/MySQL.

The one-file implication
------------------------

The **only thing** that changes about the “one file” story is configuration:

*   SQLite: no credentials, just a file path
    
*   Postgres/MySQL: host/user/password/DB name
    

You can still keep it “mostly portable” by:

*   reading DB config from environment variables, or
    
*   storing it in SQLite first, then on migration writing it to a .env (but that breaks the strict 2-file dream), or
    
*   supporting “DB config in the SQLite DB until you switch,” then switching to env vars
    

If you want the cleanest future-proof approach: **use env vars for DB config from day one**, but default to SQLite when none are provided.

Recommended strategy (best of both worlds)
------------------------------------------

1.  **Start with SQLite** for single-file deployment.
    
2.  Keep a **DB adapter** and **portable migrations**.
    
3.  Add **export/import** as a first-class feature.
    
4.  When you outgrow SQLite (multi-writer, scale, HA), switch to Postgres with minimal code changes.
    

If you want, I can sketch the exact DB adapter interface and a migration/export/import design that keeps SQLite-first but Postgres-ready.