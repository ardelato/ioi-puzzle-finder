# Preparing the repository for public contributions

Keep Issues open and accept pull requests from forks. Review the full diff,
especially workflow and build-script changes, before merging or running any
contributor code. The test workflow runs only on `main`, using GitHub-hosted
runners and a read-only repository token. Keep credentials, game inputs,
user saves and release signing keys outside Actions. Publish releases manually
from a reviewed commit.

## Before changing visibility

- Inspect the identity in every commit and annotated tag, and check existing
  releases, logs and attachments for private details. GitHub's email privacy
  setting and a `noreply` Git address apply to future commits. For private
  metadata already in history, choose a sanitized public repository or agree
  on a history migration while the original repository is private.
- Enable two-factor authentication or a passkey, retain recovery codes
  securely, review connected apps and credential scopes, and enable
  [blocking pushes that expose your email](https://docs.github.com/en/account-and-profile/how-tos/email-preferences/blocking-command-line-pushes-that-expose-my-personal-email-address).
- Check the [visibility consequences](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/managing-repository-settings/setting-repository-visibility)
  before switching. Public readers can clone the published history and forks
  can remain public after the original repository returns to private.

## Public repository settings

- Protect `main` with a ruleset that requires pull requests and blocks force
  pushes and deletion. Require an approving review if another trusted
  maintainer is available. Keep the rules compatible with maintainer review
  before merge and hosted tests after merge. Protect release tags from
  updates and deletion with a separate tag ruleset.
- Limit Actions to the two pinned dependencies listed in
  [the workflow](../.github/workflows/tests.yml). Keep full SHA pinning and
  read-only workflow permissions. If fork PR CI is introduced later, use
  `pull_request` with [approval for all external contributors](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/enabling-features-for-your-repository/managing-github-actions-settings-for-a-repository).
  Keep privileged events and personal credentials out of code execution on PRs.
- Verify secret scanning and push protection are enabled where available. Keep
  account recovery and repository administration with trusted owners.
- Recheck repository visibility and settings, including Issues, branch
  protections and Actions. Confirm with a test fork that opening a PR leaves
  maintainer-side Actions idle.
