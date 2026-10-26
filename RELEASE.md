# Release Process

## Branch model

- `develop` — integration branch (the repo's default branch). Land all day-to-day work here.
- `master` — release branch. **Pushing to `master` is what triggers a release and the WordPress.org deploy.** Pushing to `develop` does not.

## First release (after WordPress.org approval)

This plugin isn't on WordPress.org yet. `master` doesn't exist until the first release, because pushing it deploys.

1. Wait for the approval email, and confirm the approved slug is `key-warden` (update `SLUG` in both workflows if not).
2. Add the repo secrets `SVN_USERNAME` and `SVN_PASSWORD` (your WordPress.org login).
3. Create `master` from `develop` and push it (see Release below). That first push tags `v1.0.0`, creates the GitHub Release, and fills the WordPress.org SVN repo.

## Pre-release

Do this work on `develop`:

1. Ensure all changes are merged into `develop`
2. Update the version number in **all three** locations:
   - `key-warden.php` — `Version: X.Y.Z` in the plugin header
   - `readme.txt` — `Stable tag: X.Y.Z`
   - `readme.txt` — add a changelog entry under `== Changelog ==`
3. Update `Tested up to` in `readme.txt` if WordPress has released a new version
4. Commit and push `develop`

## Release

Promote `develop` to `master`:

```bash
git checkout master
git merge --ff-only develop
git push origin master
```

Pushing `master` triggers the **Release** workflow (`.github/workflows/release.yml`), which handles everything from here:

- Detects the version from `key-warden.php`
- Skips if a tag for that version already exists
- Builds a zip excluding dev files
- Creates a git tag (`vX.Y.Z`) and GitHub Release with auto-generated notes
- Deploys to WordPress.org SVN (only if the GitHub Release succeeds)

> A separate **Update wp.org assets** workflow (`.github/workflows/assets.yml`) also runs on push to `master`, but only when `.wordpress-org/**` or `readme.txt` are the *only* files changed — otherwise it defers to the release workflow.

## Post-release

1. Verify the GitHub Release appears at https://github.com/cartpauj/key-warden/releases
2. Verify the new version appears on https://wordpress.org/plugins/key-warden/

## Troubleshooting

- **Release skipped?** The workflow skips if the tag already exists. Make sure you bumped the version number.
- **WP.org deploy failed?** Check that `SVN_USERNAME` and `SVN_PASSWORD` are set in the repo secrets. Review the workflow run logs for details.
- **Assets not showing on WP.org?** Add banner and icon images to a `.wordpress-org` directory in the repo root. The 10up action uploads these automatically.
