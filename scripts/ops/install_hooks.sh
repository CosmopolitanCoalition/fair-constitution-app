#!/bin/sh
# Point git at the tracked hooks directory. Run once per clone.
git config core.hooksPath .githooks && chmod +x .githooks/pre-commit && echo "hooks installed (.githooks)"
