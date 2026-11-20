# Security Policy

## Reporting a vulnerability

Please don't report security issues in public issues, discussions or pull requests.

Report them privately through GitHub: go to the **Security** tab of this repository and choose **Report a vulnerability**. Include:

- what's affected and how bad it is
- steps or a proof of concept to reproduce it
- the version or commit you tested

We aim to reply within 3 business days and will keep you updated until it's fixed. Please give us a reasonable chance to release a fix before disclosing it publicly. We're happy to credit you in the advisory.

## Supported versions

Only the latest `main` gets security fixes.

## Running Snack safely

- Never commit `.env`, `storage/oauth-*.key` or other credentials.
- The LiveKit keys in `livekit.yaml` and `.env.example` are throwaway local values. Replace them in every real deployment.
- Bite is off by default because it sends workspace messages to an AI provider. Turn it on only if that's acceptable for your workspace.
