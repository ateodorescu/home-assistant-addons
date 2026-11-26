# Add-on Release Workflow (Single Folder)

This repository ships the add-on from a single folder (`ipmi-server`) on a single branch (`main`). Home Assistant Supervisor reads `ipmi-server/config.yaml` from `main`, so its `version` field is what users see in the add-on store **and** the image tag Supervisor pulls.

`main` therefore always carries the **latest released version**. Never set it to `"dev"`: no `:dev` image is published, so users would be offered a version that cannot be installed.

---

## 1. Development

- Push code changes to `main` (directly or via pull requests).
- Leave `version` in `ipmi-server/config.yaml` at the last released semver.
- CI on `main` builds images tagged `:edge` and `:<short-sha>`. Released tags (`:X.Y.Z`, `:stable`) are never overwritten.

---

## 2. Release

### Step A: Update the version

1. Add a section for the new version to `ipmi-server/CHANGELOG.md`.
2. Set `version: "X.Y.Z"` in `ipmi-server/config.yaml`.
3. Set `ADDON_VERSION` in `ipmi-server/rootfs/app/src/Controller/IpmiController.php` to the same version.
4. **Commit and push** to `main`.

### Step B: Create a GitHub release

1. Go to **Releases** > **Draft a new release**.
2. **Tag:** `vX.Y.Z` (must match the version in `config.yaml`).
3. **Title:** `home-assistant-addons vX.Y.Z`.
4. **Description:** copy the new `CHANGELOG.md` section.
5. **Pre-release checkbox:**
   - **Leave UNCHECKED** for a **stable** release (notifies everyone).
   - **Check it** for a **beta** release, e.g. `X.Y.Zb1` (notifies only Advanced Mode users).
6. Click **Publish release**.

### Step C: Automation

- **Deploy** builds the add-on images (amd64, aarch64) and tags them `:X.Y.Z` and `:stable` (`:beta` for pre-releases).
- **Build Standalone Image** publishes `ghcr.io/ateodorescu/ipmi-server-standalone` for the `vX.Y.Z` tag.

There is no post-release step: leave `version` at the released semver.

---

## Summary

| Version in config | GitHub release type | User experience                                     |
| :---------------- | :------------------ | :-------------------------------------------------- |
| **"2.1.0b1"**     | **Pre-release**     | Notifies users with **Advanced Mode** enabled.      |
| **"2.1.0"**       | **Latest release**  | **Notifies all users** that an update is available. |
