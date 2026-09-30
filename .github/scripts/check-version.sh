#!/usr/bin/env bash
#
# Checks the version numbers of the plugin: the `Version:` header and the
# version constant in the main plugin file, `Stable tag:` and the newest entry
# of the `== Changelog ==` section in readme.txt, `version` in package.json and
# the newest `## [x.y.z]` header in CHANGELOG.md must be valid
# MAJOR.MINOR.PATCH versions and identical.
#
# Used by the version check on pull requests (version-check.yml) and by the
# release workflow (release.yml). Run it from the plugin root, also locally:
#
#   .github/scripts/check-version.sh [--expect X.Y.Z] [--release] [--newer-than-ref REF]
#
#   --expect X.Y.Z        The version must be X.Y.Z (the version of the release tag).
#   --release             CHANGELOG.md must not have an [Unreleased] section.
#   --newer-than-ref REF  The version must be higher than the `Version:` header of
#                         the main plugin file at the Git ref REF (e.g. origin/main).

set -euo pipefail

# ----------------------------------------------------------------------------
# Files that carry the version – adjust these when reusing the script.
# ----------------------------------------------------------------------------
MAIN_FILE=feichtmedia-imagemanager-acf.php
VERSION_CONSTANT=FM_IMAGEMANAGER_ACF_VERSION
README_FILE=readme.txt
PACKAGE_FILE=package.json
CHANGELOG_FILE=CHANGELOG.md

SEMVER='^[0-9]+\.[0-9]+\.[0-9]+$'
errors=0

# Prints an error as a GitHub Actions annotation and counts it.
fail() {
  echo "::error::$1"
  errors=$((errors + 1))
}

# Prints the usage and exits with an error.
usage() {
  echo "Usage: $0 [--expect X.Y.Z] [--release] [--newer-than-ref REF]" >&2
  exit 2
}

# Prints the value of the `Version:` header of the plugin file read from stdin.
# The header line may be prefixed by comment characters (` * Version: 1.2.3`),
# like in WordPress's get_file_data().
header_version() {
  sed -n '/^[[:space:]/*#@]*Version:/{s/^[^:]*:[[:space:]]*//;s/[[:space:]]*$//;p;q;}'
}

# Returns success if version $1 is higher than version $2 (both MAJOR.MINOR.PATCH).
version_gt() {
  local IFS=.
  local -a a=($1) b=($2)
  local i
  for i in 0 1 2; do
    if ((10#${a[i]} > 10#${b[i]})); then return 0; fi
    if ((10#${a[i]} < 10#${b[i]})); then return 1; fi
  done
  return 1
}

expect=""
release=false
base_ref=""
while [ $# -gt 0 ]; do
  case "$1" in
    --expect)
      [ $# -ge 2 ] || usage
      expect="$2"
      shift 2
      ;;
    --release)
      release=true
      shift
      ;;
    --newer-than-ref)
      [ $# -ge 2 ] || usage
      base_ref="$2"
      shift 2
      ;;
    *) usage ;;
  esac
done

# ----- Read the versions -----
header=""
constant=""
stable_tag=""
readme_changelog=""
package=""
changelog=""
if [ -f "$MAIN_FILE" ]; then
  header=$(header_version < "$MAIN_FILE")
  constant=$(sed -n "/define([[:space:]]*['\"]$VERSION_CONSTANT['\"]/{s/.*,[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/;p;q;}" "$MAIN_FILE")
fi
if [ -f "$README_FILE" ]; then
  stable_tag=$(sed -n '/^Stable tag:/{s/^Stable tag:[[:space:]]*//;s/[[:space:]]*$//;p;q;}' "$README_FILE")
  # First `= x.y.z – YYYY-MM-DD =` entry between `== Changelog ==` and the next section
  readme_changelog=$(sed -n '/^== Changelog ==/,/^== /{/^= [0-9]/{s/^= \([^[:space:]=]*\).*/\1/;p;q;};}' "$README_FILE")
fi
[ -f "$PACKAGE_FILE" ] && package=$(jq -r '.version // empty' "$PACKAGE_FILE")
# The newest version header is the first one starting with a digit, which
# skips an [Unreleased] section above it.
[ -f "$CHANGELOG_FILE" ] && changelog=$(sed -n '/^## \[[0-9]/{s/^## \[\([^]]*\)\].*/\1/;p;q;}' "$CHANGELOG_FILE")

# Label|version per location, the first one is the reference for the others.
locations=(
  "$MAIN_FILE Version header|$header"
  "$MAIN_FILE $VERSION_CONSTANT|$constant"
  "$README_FILE Stable tag|$stable_tag"
  "$README_FILE Changelog|$readme_changelog"
  "$PACKAGE_FILE version|$package"
  "$CHANGELOG_FILE newest version|$changelog"
)

width=0
for entry in "${locations[@]}"; do
  label="${entry%%|*}"
  ((${#label} > width)) && width=${#label}
done
for entry in "${locations[@]}"; do
  version="${entry#*|}"
  printf '%-*s %s\n' $((width + 1)) "${entry%%|*}:" "${version:--}"
done

# ----- Consistency -----
valid=true
mismatch=false
summary=""
for entry in "${locations[@]}"; do
  label="${entry%%|*}"
  version="${entry#*|}"
  if ! [[ "$version" =~ $SEMVER ]]; then
    fail "$label: no valid version in the format MAJOR.MINOR.PATCH (found '${version}')."
    valid=false
  fi
  [ "$version" = "$header" ] || mismatch=true
  summary+="${summary:+, }$label $version"
done

if $valid && $mismatch; then
  fail "Version mismatch: $summary. Set the same version in all of them."
fi

# ----- Release tag -----
if [ -n "$expect" ] && [ "$header" != "$expect" ]; then
  fail "The tag v$expect does not match the version $header in $MAIN_FILE."
fi

# ----- Release-ready changelog -----
if $release && grep -q '^## \[Unreleased\]' "$CHANGELOG_FILE" 2>/dev/null; then
  fail "$CHANGELOG_FILE still has an [Unreleased] section. Rename it to the new version and add the date."
fi

# ----- Version bump -----
if [ -n "$base_ref" ]; then
  base=$(git show "$base_ref:$MAIN_FILE" 2>/dev/null | header_version || true)
  echo "Version on $base_ref: ${base:--}"
  if ! [[ "$base" =~ $SEMVER ]]; then
    fail "No valid version in $MAIN_FILE on $base_ref (found '${base}')."
  elif [[ "$header" =~ $SEMVER ]] && ! version_gt "$header" "$base"; then
    fail "The version $header is not higher than $base on $base_ref. Make the release commit with a new version."
  fi
fi

if [ "$errors" -gt 0 ]; then
  exit 1
fi
echo "Version check passed: $header"
