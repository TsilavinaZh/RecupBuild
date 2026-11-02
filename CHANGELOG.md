# Changelog

## 2026-11-02

### Added
- The instant demo opens with one click at `/try`, with no account and no key: nothing is stored but
  the session, and a visitor reaches no model and no credit.
- SAM 3.1 traces the real edges of every piece, with Muse Spark naming them and estimating thickness.
- Muse Image shows the finished object in the user's own wood, once per plan.
- Optional local route for the text-only steps, with Muse Glimmer 30B on the user's own hardware.
- Benchmarks on photos of known sizes, an open dataset on the Hugging Face Hub, and CI that builds
  and checks the Docker image.
- A limit on scans per hour, security headers, a share card, `robots.txt` and `sitemap.xml`.

### Fixed
- The instant demo offers buildable projects, with their steps and 3D view.
- The simple project fits wide pieces.
- Deleting a plan deletes its generated picture.
- Two clicks can no longer pay for two pictures, and a busy image model is asked again.
- New CSS and JavaScript reach returning visitors right after a deployment.

### Changed
- The app runs with a bare `php -S localhost:8080`: the site's files moved to the project root, the
  secrets to `.env.php`, and the data outside the project.
- Released under the MIT License.

## 2026-10-27

### Added
- First version: from a photo and an A4 sheet to projects, a cutting plan, a 3D render and steps.
- Reverse build, Google sign-in, history, profile, credits and pricing.
