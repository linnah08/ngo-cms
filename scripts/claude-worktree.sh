#!/usr/bin/env bash
#
# claude-worktree.sh — give each parallel Claude session its own branch.
#
# Two Claude sessions in the same directory share one git index, so a `git add`
# in one and a `git commit` in the other bundle each other's work into the wrong
# commit. A worktree gives each session an independent working tree + index +
# branch over the *same* shared repo, which is exactly what we want.
#
# Worktrees do NOT inherit git-ignored files, so this script symlinks the
# config files and vendor/ that the app + tests need back to the main checkout.
#
# Usage:
#   scripts/claude-worktree.sh <name>     # create/reuse worktree, print cd path
#   scripts/claude-worktree.sh --list     # list worktrees
#   scripts/claude-worktree.sh --remove <name>
#
set -euo pipefail

# Resolve the main checkout (the dir holding this script's parent).
MAIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WT_ROOT="$(cd "$MAIN/.." && pwd)/oddminds-wt"

# Ignored files every worktree needs to run + test. vendor/ is symlinked (huge,
# shared); the config files are symlinked so they stay in sync with main.
LINKS=(
  db.config.php
  smtp.config.php
  graph.config.php
  local.config.php
  courier.config.php
  .mcp.json
  vendor
)

link_configs() {
  local dir="$1"
  for f in "${LINKS[@]}"; do
    if [ -e "$MAIN/$f" ] && [ ! -e "$dir/$f" ]; then
      ln -s "$MAIN/$f" "$dir/$f"
    fi
  done
}

case "${1:-}" in
  --list|-l)
    git -C "$MAIN" worktree list
    exit 0
    ;;
  --remove|-r)
    name="${2:?usage: claude-worktree.sh --remove <name> [--force]}"
    # Drop the symlinks we created so git sees a clean tree and only blocks on
    # *real* uncommitted work (not the always-untracked vendor symlink).
    for f in "${LINKS[@]}"; do
      [ -L "$WT_ROOT/$name/$f" ] && rm -f "$WT_ROOT/$name/$f"
    done
    git -C "$MAIN" worktree remove "$WT_ROOT/$name" ${3:+"$3"}
    git -C "$MAIN" branch -d "claude/$name" 2>/dev/null || true
    echo "Removed worktree $name (branch claude/$name kept if unmerged)."
    exit 0
    ;;
  ""|--help|-h)
    grep '^#' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 0
    ;;
esac

NAME="$1"
DIR="$WT_ROOT/$NAME"
BRANCH="claude/$NAME"

mkdir -p "$WT_ROOT"

if [ -d "$DIR" ]; then
  echo "Worktree already exists; reusing it."
else
  # Branch off the current main tip. Reuse the branch if it already exists.
  if git -C "$MAIN" show-ref --verify --quiet "refs/heads/$BRANCH"; then
    git -C "$MAIN" worktree add "$DIR" "$BRANCH"
  else
    git -C "$MAIN" worktree add -b "$BRANCH" "$DIR" main
  fi
fi

link_configs "$DIR"

echo
echo "Worktree ready:"
echo "  branch:  $BRANCH"
echo "  path:    $DIR"
echo
echo "Launch a Claude session there with:"
echo "  cd \"$DIR\" && claude"
echo
echo "When done, merge into main from the main checkout:"
echo "  git -C \"$MAIN\" merge $BRANCH"
echo "  scripts/claude-worktree.sh --remove $NAME"
