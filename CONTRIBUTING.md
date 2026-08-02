# Contributing to Alghazali ERP

Thank you for your interest in contributing. This document explains how to submit issues and pull requests, coding standards, and the branching model.

## Getting started
- Fork the repository and create branches from `develop`.
- Branch names:
  - `feature/your-feature-name`
  - `hotfix/issue-description`
  - `chore/description`

## Submitting changes
1. Create a branch from `develop`.
2. Make atomic commits with clear messages.
3. Ensure PHP syntax passes: `php -l` on modified files.
4. If there are automated tests, add/modify tests accordingly.
5. Open a Pull Request to `develop` and include a clear description and testing steps.

## Code style & guidelines
- Follow existing project conventions (PSR-like style, spacing similar to existing files).
- Keep changes minimal and focused.
- Write clear commit messages: `type(scope): concise description`.

## Pull request checklist
- [ ] I have read the `CONTRIBUTING.md` document.
- [ ] My code follows the project style.
- [ ] I have added tests that prove my fix/feature.
- [ ] All existing tests pass locally.
- [ ] I added documentation when necessary.

## Security
If you discover a security vulnerability, do not open a public issue. See `SECURITY.md` for responsible disclosure instructions.
