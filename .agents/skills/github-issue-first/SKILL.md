---
name: github-issue-first
description: Start GitHub repository change work with an issue and link every pull request to it. Use for implementation, fixes, refactoring, documentation, configuration, infrastructure, or PR creation in GitHub projects. Do not use for read-only questions or reviews.
---

# GitHub issue first

Establish a real GitHub issue before implementation. Every PR must reference at least one issue. Reuse the issue for the same task across chats and PRs; do not create duplicates just because a new session starts.

## Before making changes

1. Identify the intended GitHub repository from the user's request, applicable instructions, and remotes. Prefer the user's fork when that is the working repository. Configuration beside a checkout belongs to the same task when it supports that repository. Limited read-only inspection to identify the repository and scope is allowed before creating the issue.
2. Fetch an issue supplied by the user. Otherwise, search for a matching active issue and create one if none exists. Include the problem or goal, relevant scope, acceptance criteria, and intended validation. Follow existing repository templates and language conventions. Do not invent labels, assignments, or promises.
3. Verify that creation or lookup returned an actual issue number and URL in the intended repository. State that URL in the chat and carry it forward with the task context before editing code, documentation, configuration, or infrastructure.

Applying the user's issue-first policy authorizes issue creation and task-related issue updates in the repository already selected for the work. It does not authorize unrelated communications, commits, pushes, PR publication, deployment, permission changes, or repository administration. Use existing task authorization for those actions.

## During the work

- Keep the same issue associated with the work. Include its number in a new branch name when the repository's branch conventions allow it; do not rename an existing branch solely for this purpose.
- Post meaningful decisions, material blockers, and the final implementation/validation summary to the issue. Avoid narrating every tool call. Prefer a comment to overwriting someone else's issue description.
- Keep credentials, private keys, tokens, personal data, and sensitive internal details out of issue bodies, comments, and PR descriptions. Summarize only the context that belongs in the repository.
- When a task is continued, read the issue and relevant updates before changing scope or creating another issue. If earlier work was done without tracking, describe any later issue honestly as retrospective.

## Before creating or updating a PR

1. Fetch the referenced issue and confirm it covers the final change. If no suitable issue exists, create it before proceeding with the PR. A PR number or an unverified placeholder does not satisfy the requirement.
2. Reference the issue in the PR body. Use `Closes #123` when the PR completes the issue; use `Refs #123` for partial work. Use `owner/repo#123` or the full URL when the issue belongs to another repository. Replace these examples with the verified issue number.
3. Describe the resulting behavior, relevant validation, and remaining limitations. Preserve useful existing PR text when making a narrow update.
4. Read back the saved PR body to verify the reference, then add the PR URL to the issue with a concise work summary. Do not mark the issue completed merely because a PR was opened or local checks passed.

GitHub closing keywords automatically link and close issues only when the PR targets the default branch. For another target branch, retain an explicit issue reference and create a manual development link when supported; do not promise automatic closure. See [GitHub's linking rules](https://docs.github.com/en/issues/tracking-your-work-with-issues/using-issues/linking-a-pull-request-to-an-issue).

## If tracking is unavailable

Check the failure and use another available, authorized GitHub interface when appropriate. After an ambiguous create result, search before retrying to avoid a duplicate. Do not broaden permissions or change repository settings without task authorization.

If no real issue can be obtained, stop implementation and PR creation. Continue useful read-only inspection or prepare a reviewable issue draft, explain the specific access or repository problem, and ask only for the missing decision or access. Never invent a number or silently skip this requirement.
