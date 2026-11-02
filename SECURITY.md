# Security

## Reporting a problem

Please report a vulnerability privately to **contact@tendry.mg**, with the steps to reproduce it,
rather than in a public issue. You can expect an answer within a few days.

## How the project protects data

- **Secrets** live in `.env.php`, which is ignored by git and prints nothing if requested.
- **The database and the photos** live outside the project folder, and a user's photo is only served
  to its owner, through a controller.
- **Sign-in** is Google's server-side authorization code flow, with `state` and `nonce`; the session
  is an `HttpOnly`, `SameSite=Lax` cookie.
- **Forms** carry a CSRF token, every text is escaped, and a Content-Security-Policy only allows the
  site's own scripts and styles.
- **What the models return** is bounded and validated again before it reaches the browser.
- **Use of the models** is capped: credits on plans and rebuilds, and a limit per hour on scans.

## Scope

The app is a hackathon project and has not had an external security review.
