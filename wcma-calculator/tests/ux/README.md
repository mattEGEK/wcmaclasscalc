# Phone audit

Walks the member flow (calculator → sign up → add an ice car → ice tech sheet → submit → pre-tech →
car page → Home → Drivers, Profile and Garage), then the Admin tabs, the Inspector section and the
Media section, at 375px wide, once at normal text size and once at 150%, and fails on: sideways scrolling, tap targets under
44px, radios/checkboxes under 24px, input text under 16px, any text under 16px, contrast under
4.5:1, and a disabled button that looks like an enabled one.

    cd wcma-calculator/tests/ux && npm install        # once
    bash wcma-calculator/tests/ux/run-audit.sh        # from the repo root

It seeds a throwaway SQLite database in a temp folder and serves the app with `php -S` on port
8170 (`UX_PORT` to change). Your real database is never touched. Needs a local `config.php`.
Add `class="no-audit"` to an element only when a rule genuinely doesn't apply to it.

It also checks behaviour the 2026-09-30 UX review added: one tech sheet submit lists every problem,
the calculator keeps the class in view and reports problems in words, an event on Home is folded
until "I'm going" is tapped, "Not going anymore" asks first, and the staff header stays on one line
from 1024px to 1440px.
