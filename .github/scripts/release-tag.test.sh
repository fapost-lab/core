#!/usr/bin/env bash
# Tests for release-tag.sh against crafted commits, pull requests and tags.
#
#   bash .github/scripts/release-tag.test.sh
#
# Runs on bash 3.2 (macOS) and on the CI runner; needs nothing but the script itself.
set -uo pipefail

script="$(cd "$(dirname "$0")" && pwd)/release-tag.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

passed=0
failed=0
parent=06e89c8a5800dc1f6c2ba249e8d554abc799b389

# expect <name> <exit code> <stdout or ""> <command...>
expect() {
    local name=$1 want_code=$2 want_out=$3 out code
    shift 3
    out=$("$@" 2> "$work/stderr")
    code=$?
    if [ "$code" = "$want_code" ] && { [ -z "$want_out" ] || [ "$out" = "$want_out" ]; }; then
        passed=$((passed + 1))
    else
        failed=$((failed + 1))
        echo "FAIL: $name: exit $code (want $want_code), output '$out' (want '$want_out')"
        sed 's/^/  stderr: /' "$work/stderr"
    fi
}

# case_dir <name>: a fresh directory with the parent and the tags up to v0.24.0.
case_dir() {
    local dir="$work/$1"
    mkdir -p "$dir"
    printf '%s\n' "$parent" > "$dir/parent"
    printf '%s\n' v0.23.1 v0.24.0 v0.9.0 vtest v1 > "$dir/tags"
    printf '%s\n' "$dir"
}

release_body() {
    cat <<'EOF'
## Summary

Release notes for **Core v0.25.0** (MINOR).

Release-Version: v0.25.0

---

<!-- release-notes:start -->
## Operator actions

- **Run the migrations** with `platform:update`.

### Breaking changes

- Something breaks.

---

## New

- Something new.
<!-- release-notes:end -->

---

## Review findings
EOF
}

# The smallest release text a release body must carry.
marks=$'<!-- release-notes:start -->\nText.\n<!-- release-notes:end -->'

# refuses <name> <reason fragment> <command...>: exit 1 for that reason, not another.
refuses() {
    local name=$1 reason=$2 code
    shift 2
    "$@" > /dev/null 2> "$work/stderr"
    code=$?
    if [ "$code" = 1 ] && grep -qF -- "$reason" "$work/stderr"; then
        passed=$((passed + 1))
    else
        failed=$((failed + 1))
        echo "FAIL: $name: exit $code (want 1, refused for '$reason')"
        sed 's/^/  stderr: /' "$work/stderr"
    fi
}

# pr_case <name> <title> <body> <commits> [association]: a directory shaped as GitHub
# squashes a pull request into main — the message is the title and the body.
pr_case() {
    local d
    d=$(case_dir "$1")
    printf '%s\n' "$2" > "$d/pr-title"
    printf '%s\n' "$3" > "$d/pr-body"
    printf '%s\n' "$4" > "$d/pr-commits"
    printf '%s\n' "${5-MEMBER}" > "$d/pr-author-association"
    { printf '%s (#600)\n\n' "$2"; printf '%s\n' "$3"; } > "$d/message"
    printf '%s\n' "$d"
}

trailer="Release-Notes-Through: $parent"
notes_commit=$(printf 'docs(release): notes\n\n%s' "$trailer")
title='docs(release): notes for v0.25.0'

# --- not a release -----------------------------------------------------------

d=$(case_dir plain)
printf 'fix(flow): close the P3 findings (#506)\n\nBody.\n' > "$d/message"
expect "an ordinary commit is not a release" 3 "" "$script" detect "$d"

d=$(pr_case plain-pr 'feat(flow): add a node for v2.0.0 clients' 'Body.' 'feat(flow): add a node' NONE)
expect "a version in an ordinary title does not make a release" 3 "" "$script" detect "$d"

d=$(pr_case hidden-only 'docs: fix a typo in the README' \
    $'Fixes a typo.\n<!--\nRelease-Version: v0.25.1\nRelease-Notes-Through: '"$parent"$'\n-->' \
    'docs: fix a typo' NONE)
expect "a hidden comment block does not make a release" 3 "" "$script" detect "$d"

# The reviewer's reproduction: a hidden block with a version, the trailer and the
# markers in an outsider's typo fix. Comments do not nest, so the start marker's `-->`
# ends the block and the rest shows; it reads like a release and is refused.
d=$(pr_case hidden-block 'docs: fix a typo in the README' \
    $'Fixes a typo.\n<!--\nRelease-Version: v0.25.1\nRelease-Notes-Through: '"$parent"$'\n<!-- release-notes:start -->\n.\n<!-- release-notes:end -->\n-->' \
    'docs: fix a typo' NONE)
refuses "a hidden release block in a typo pull request never tags" "is not 'docs(release)" "$script" detect "$d"

# --- the release commit as GitHub squashes it --------------------------------

d=$(pr_case squash 'docs(release): notes and upgrade steps for v0.25.0' "$(release_body)" "$notes_commit")
expect "the squash of a notes pull request releases its version" 0 v0.25.0 "$script" detect "$d"

for association in OWNER MEMBER COLLABORATOR; do
    d=$(pr_case "assoc-$association" "$title" "$marks" "$notes_commit" "$association")
    expect "an $association releases" 0 v0.25.0 "$script" detect "$d"
done

d=$(pr_case title-only "$title" "Body.
$marks" "$notes_commit")
expect "the title alone names the version" 0 v0.25.0 "$script" detect "$d"

d=$(pr_case body-trailer "$title" "$trailer
$marks" 'docs(release): notes')
expect "a trailer line of the body counts" 0 v0.25.0 "$script" detect "$d"

d=$(pr_case short-trailer "$title" "$marks" "Release-Notes-Through: ${parent:0:12}")
expect "an abbreviated trailer matches the parent" 0 v0.25.0 "$script" detect "$d"

d=$(pr_case newest-trailer "$title" "$marks" \
    "$(printf 'docs: first notes\n\nRelease-Notes-Through: 1111111111111111111111111111111111111111\ndocs: notes again\n\n%s' "$trailer")")
expect "the newest trailer wins" 0 v0.25.0 "$script" detect "$d"

d=$(pr_case crlf "$title" "$(printf 'Release-Version: v0.25.0\n%s\n' "$marks" | sed 's/$/\r/')" "$notes_commit")
printf '%s\r\n' "$title" > "$d/pr-title"
expect "CRLF line ends are read like LF" 0 v0.25.0 "$script" detect "$d"

d=$(pr_case prerelease 'docs(release): notes for v1.0.0-rc.1' "$marks" "$notes_commit")
expect "a pre-release above the latest tag is released" 0 v1.0.0-rc.1 "$script" detect "$d"

d=$(pr_case visible-around-hidden "$title" "Release-Version: v0.25.0
<!-- Release-Version: v0.99.0 --> 
$marks" "$notes_commit")
expect "a version hidden in a comment is not read" 0 v0.25.0 "$script" detect "$d"

# A release epic: its pull request squashes into main; the notes pull request was
# squashed into the epic, so its trailer survives only as a line of the epic's body.
d=$(pr_case epic "$title" "$trailer

$(release_body)" "$(printf 'feat(flow): a part (#511)\n\nBody of the part.\ndocs(release): notes for v0.25.0 (#519)\n\nThe notes body.')")
expect "a release epic's squash releases with the trailer line of its body" 0 v0.25.0 "$script" detect "$d"

d=$(case_dir big-body)
printf 'docs: something big\n' > "$d/pr-title"
printf 'MEMBER\n' > "$d/pr-author-association"
{ release_body; i=0; while [ "$i" -lt 3000 ]; do printf 'A long line of filler text to pass the pipe buffer size.\n'; i=$((i + 1)); done; } > "$d/pr-body"
printf 'docs: something big\n' > "$d/pr-commits"
{ printf 'docs: something big (#601)\n\n'; cat "$d/pr-body"; } > "$d/message"
refuses "markers in a body larger than a pipe buffer still refuse an ordinary title" "is not 'docs(release)" "$script" detect "$d"

# --- refusals ----------------------------------------------------------------

d=$(pr_case hidden-in-release 'docs: fix a typo in the README' \
    $'Fixes a typo.\n<!--\nRelease-Version: v0.25.1\n-->\n<!-- release-notes:start -->\n.\n<!-- release-notes:end -->' \
    "$notes_commit" NONE)
refuses "the reviewer's typo pull request with a trailer refuses on its title" "is not 'docs(release)" "$script" detect "$d"

d=$(pr_case outsider "$title" "$marks" "$notes_commit" CONTRIBUTOR)
refuses "a release pull request from a contributor refuses" "only an OWNER, MEMBER or COLLABORATOR" "$script" detect "$d"

d=$(pr_case first-timer "$title" "$marks" "$notes_commit" FIRST_TIME_CONTRIBUTOR)
refuses "a release pull request from a first-time contributor refuses" "only an OWNER, MEMBER or COLLABORATOR" "$script" detect "$d"

d=$(pr_case no-association "$title" "$marks" "$notes_commit")
rm "$d/pr-author-association"
refuses "an unknown author refuses" "association 'unknown'" "$script" detect "$d"

d=$(pr_case untitled-epic 'feat(flow): the release epic' "$trailer
$marks" 'feat(flow): a part')
refuses "a release epic not titled docs(release) refuses" "is not 'docs(release)" "$script" detect "$d"

d=$(case_dir no-pr)
printf 'docs(release): notes for v0.25.0\n\n%s\n%s\n' "$trailer" "$marks" > "$d/message"
refuses "a release-looking commit without a pull request refuses" "no pull request was found" "$script" detect "$d"

d=$(pr_case no-trailer "$title" "$marks" 'docs(release): notes')
refuses "a docs(release) title without a trailer refuses" "Release-Notes-Through line" "$script" detect "$d"

d=$(pr_case hidden-trailer "$title" "<!-- $trailer -->
$marks" 'docs(release): notes')
refuses "a trailer hidden in a comment is not read" "Release-Notes-Through line" "$script" detect "$d"

d=$(pr_case no-trailer-markers 'docs: something' "$(release_body)" 'docs: something')
refuses "release text markers without a docs(release) title refuse" "is not 'docs(release)" "$script" detect "$d"

d=$(pr_case parent-moved "$title" "$marks" 'Release-Notes-Through: 2222222222222222222222222222222222222222')
refuses "a trailer that is not the parent refuses" "something reached main" "$script" detect "$d"

d=$(pr_case epic-moved "$title" "Release-Notes-Through: 3333333333333333333333333333333333333333
$marks" 'feat(flow): a part (#511)')
refuses "a body trailer that is not the parent refuses" "something reached main" "$script" detect "$d"

d=$(pr_case unmarked "$title" '## Release text for v0.25.0

Text.' "$notes_commit")
refuses "a release without release text markers refuses" "no release text" "$script" detect "$d"

d=$(pr_case disagree "$title" "Release-Version: v0.26.0
$marks" "$notes_commit")
refuses "a Release-Version line and a title that disagree refuse" "the versions disagree" "$script" detect "$d"

d=$(pr_case two-in-title 'docs(release): notes for v0.25.0 after v0.24.1' "$marks" "$notes_commit")
refuses "two versions in the title refuse" "the versions disagree" "$script" detect "$d"

d=$(pr_case no-version 'docs(release): the notes' "Release-Version: v0.25.0
$marks" "$notes_commit")
refuses "a title without a version refuses" "is not 'docs(release)" "$script" detect "$d"

d=$(pr_case malformed "$title" "Release-Version: 0.25.0
$marks" "$notes_commit")
refuses "a Release-Version without the v refuses" "is not a version" "$script" detect "$d"

d=$(pr_case leading-zero 'docs(release): notes for v0.025.0' "$marks" "$notes_commit")
refuses "a version with a leading zero refuses" "is not a version" "$script" detect "$d"

d=$(pr_case exists 'docs(release): notes for v0.24.0' "$marks" "$notes_commit")
refuses "an existing tag refuses" "already exists" "$script" detect "$d"

d=$(pr_case not-above 'docs(release): notes for v0.23.2' "$marks" "$notes_commit")
refuses "a version below the latest tag refuses" "is not above the latest tag" "$script" detect "$d"

d=$(pr_case rc-after-release 'docs(release): notes for v1.0.0-rc.2' "$marks" "$notes_commit")
printf '%s\n' v1.0.0 > "$d/tags"
refuses "a pre-release of a released version refuses" "is not above the latest tag" "$script" detect "$d"

d=$(pr_case broken-markers "$title" '<!-- release-notes:start -->
Text.' "$notes_commit")
refuses "a start marker without an end refuses before the tag" "exactly one" "$script" detect "$d"

refuses "a missing parent file refuses" "is missing" "$script" detect "$work/nowhere"

# --- check -------------------------------------------------------------------

printf '' > "$work/no-tags"
expect "the first tag of a repository" 0 "" "$script" check v0.1.0 "$work/no-tags"
printf 'v0.9.9\n' > "$work/tags-nine"
expect "v0.10.0 is above v0.9.9" 0 "" "$script" check v0.10.0 "$work/tags-nine"
printf 'v0.25.0\nv0.24.0\n' > "$work/tags-25"
expect "the next patch" 0 "" "$script" check v0.25.1 "$work/tags-25"
expect "the next major" 0 "" "$script" check v1.0.0 "$work/tags-25"
expect "a pre-release of the next minor" 0 "" "$script" check v0.26.0-rc.1 "$work/tags-25"
refuses "a typo that skips minors" "skips a version" "$script" check v0.250.0 "$work/tags-25"
refuses "a minor skipped" "skips a version" "$script" check v0.27.0 "$work/tags-25"
refuses "a patch and a minor at once" "skips a version" "$script" check v0.26.1 "$work/tags-25"
printf 'v0.25.0\nv0.26.0-rc.1\n' > "$work/tags-rc"
expect "the next rc after an rc" 0 "" "$script" check v0.26.0-rc.2 "$work/tags-rc"
expect "the final release after its rc" 0 "" "$script" check v0.26.0 "$work/tags-rc"
refuses "an rc below the latest rc" "is not above the latest tag" "$script" check v0.26.0-alpha "$work/tags-rc"

# --- tags --------------------------------------------------------------------

want_tags=$(printf 'v0.24.0\nv0.25.0')
expect "ls-remote output becomes tag names, peeled lines merged" 0 "$want_tags" sh -c "printf '%s\n' \
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa	refs/tags/v0.25.0' \
    'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb	refs/tags/v0.25.0^{}' \
    'cccccccccccccccccccccccccccccccccccccccc	refs/tags/v0.24.0' | '$script' tags"

# --- compare -----------------------------------------------------------------

expect "rc below its release" 0 -1 "$script" compare v1.0.0-rc.1 v1.0.0
expect "release above its rc" 0 1 "$script" compare v1.0.0 v1.0.0-rc.1
expect "rc.2 below rc.10" 0 -1 "$script" compare v1.0.0-rc.2 v1.0.0-rc.10
expect "alpha below beta" 0 -1 "$script" compare v1.0.0-alpha v1.0.0-beta
expect "alpha below alpha.1" 0 -1 "$script" compare v1.0.0-alpha v1.0.0-alpha.1
expect "numeric below alphanumeric" 0 -1 "$script" compare v1.0.0-1 v1.0.0-alpha
expect "minor compared as a number" 0 1 "$script" compare v0.10.0 v0.9.9
expect "equal versions" 0 0 "$script" compare v2.3.4 v2.3.4
expect "major wins over minor" 0 1 "$script" compare v1.0.0 v0.99.99

# --- notes -------------------------------------------------------------------

release_body > "$work/body"
want_notes=$(sed -n '/^<!-- release-notes:start -->$/,/^<!-- release-notes:end -->$/p' "$work/body" | sed '1d;$d')
expect "the text between the markers, headings and rules kept" 0 "$want_notes" "$script" notes "$work/body"

release_body | sed 's/$/\r/' > "$work/body-crlf"
expect "markers on CRLF lines are found" 0 "" "$script" notes "$work/body-crlf"

printf '<!-- release-notes:start -->\nShown.\n<!-- hidden\nline -->\nAlso <!-- not --> shown.\n\nEnd.\n<!-- release-notes:end -->\n' > "$work/body-comments"
expect "comments inside the release text are dropped, blank lines kept" 0 "$(printf 'Shown.\nAlso  shown.\n\nEnd.')" "$script" notes "$work/body-comments"

# shellcheck disable=SC2016 # backticks of a code fence, not an expansion
printf '<!-- release-notes:start -->\nBefore.\n```html\n<!-- keep this -->\n```\n~~~\n<!-- and this -->\n~~~\n<!-- gone -->\nAfter.\n<!-- release-notes:end -->\n' > "$work/body-fences"
# shellcheck disable=SC2016 # backticks of a code fence, not an expansion
expect "comments inside fenced code are kept, outside dropped" 0 "$(printf 'Before.\n```html\n<!-- keep this -->\n```\n~~~\n<!-- and this -->\n~~~\nAfter.')" "$script" notes "$work/body-fences"

# shellcheck disable=SC2016 # backticks of a code fence, not an expansion
printf '<!-- release-notes:start -->\n````md\n```\n<!-- k -->\n````\n<!-- gone -->\n~~~\n```\n<!-- kept -->\n~~~\nEnd.\n<!-- release-notes:end -->\n' > "$work/body-nested"
# shellcheck disable=SC2016 # backticks of a code fence, not an expansion
expect "a shorter or other fence inside a fence does not close it" 0 "$(printf '````md\n```\n<!-- k -->\n````\n~~~\n```\n<!-- kept -->\n~~~\nEnd.')" "$script" notes "$work/body-nested"

# shellcheck disable=SC2016 # backticks of inline code, not an expansion
printf '<!-- release-notes:start -->\n```x``` inline.\n<!-- hidden -->\nEnd.\n<!-- release-notes:end -->\n' > "$work/body-inline"
# shellcheck disable=SC2016 # backticks of inline code, not an expansion
expect "a backtick run with a backtick after it is inline code, not a fence" 0 "$(printf '```x``` inline.\nEnd.')" "$script" notes "$work/body-inline"

printf '<!--\n<!-- release-notes:start -->\nText.\n<!-- release-notes:end -->\n' > "$work/body-commented-start"
refuses "a start marker inside a comment is not a marker" "exactly one" "$script" notes "$work/body-commented-start"

printf 'Just a body.\n' > "$work/body-none"
expect "a body without markers has no release text" 3 "" "$script" notes "$work/body-none"

printf '<!-- release-notes:start -->\n\n<!-- release-notes:end -->\n' > "$work/body-empty"
refuses "empty release text refuses" "is empty" "$script" notes "$work/body-empty"

printf '<!-- release-notes:end -->\nText.\n<!-- release-notes:start -->\n' > "$work/body-reversed"
refuses "an end marker before the start refuses" "is empty" "$script" notes "$work/body-reversed"

printf '<!-- release-notes:start -->\nA\n<!-- release-notes:end -->\n<!-- release-notes:start -->\nB\n<!-- release-notes:end -->\n' > "$work/body-twice"
refuses "two release texts refuse" "exactly one" "$script" notes "$work/body-twice"

echo "release-tag: $passed passed, $failed failed"
[ "$failed" = 0 ]
