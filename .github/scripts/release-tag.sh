#!/usr/bin/env bash
# Decides whether a commit on main is a release and which version it releases.
#
#   release-tag.sh detect <dir>          prints the version to tag; exit 3: not a release
#   release-tag.sh check <version> <tags-file>
#   release-tag.sh tags                  reads `git ls-remote --tags` on stdin, prints tag names
#   release-tag.sh notes <pr-body-file>  prints the release text; exit 3: the body has none
#   release-tag.sh compare <a> <b>       prints -1, 0 or 1 in semantic-version order
#
# Exit 1 is a refusal, with the reason on stderr: the workflow turns it into a red run
# and no tag. A pushed tag publishes images to operators and is never moved or deleted,
# so every doubt refuses; only a commit nothing points to as a release is let through
# quietly (exit 3).
#
# `detect` reads plain files from <dir>, so it is tested without GitHub
# (release-tag.test.sh):
#   message     the commit's message (required)
#   parent      the full sha of its first parent (required)
#   tags        the existing tags, one per line (required, may be empty)
#   pr-title    the title of the pull request merged as this commit (when there is one)
#   pr-body     its body
#   pr-commits  the messages of its commits, oldest first
#   pr-author-association  the pull request author's association (OWNER, MEMBER, ...)
#
# A release commit is the squash merge of a release notes pull request: titled
# `docs(release): ... vX.Y.Z` and opened by an OWNER, MEMBER or COLLABORATOR. Anything
# else that reads like a release is refused, and comments GitHub does not render are
# dropped before anything is read, so a hidden block cannot make a release. `jig task ship`
# writes `Release-Notes-Through: <sha>` into the branch commit, but the squash commit on
# main takes the pull request's title and body (the repository's squash setting), so the
# trailer is looked for in the pull request's commits as well as in the message.
# The trailer may also stand as a line of the body (a release epic's pull request).
# The version comes from a `Release-Version: vX.Y.Z` line in the body and from the
# version in the title; every one found must agree. The release text, required, is the
# part of the body between the two markers below, since the text carries `##` headings
# of its own.
#
# Kept to bash 3.2 and POSIX tools, so it runs the same on a macOS checkout and on the
# runner.
set -euo pipefail

readonly NOTES_START='<!-- release-notes:start -->'
readonly NOTES_END='<!-- release-notes:end -->'
readonly SEMVER_RE='^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$'
readonly NOT_A_RELEASE=3

refuse() {
    echo "release-tag: $*" >&2
    exit 1
}

is_semver() {
    printf '%s\n' "$1" | grep -Eq "$SEMVER_RE"
}

# Compares two pre-release strings (the part after the first `-`) by SemVer 2.0 §11.
compare_prerelease() {
    local a=$1 b=$2 x y
    while :; do
        if [ -z "$a" ] && [ -z "$b" ]; then echo 0; return; fi
        if [ -z "$a" ]; then echo -1; return; fi
        if [ -z "$b" ]; then echo 1; return; fi
        x=${a%%.*}
        y=${b%%.*}
        if [ "$x" = "$a" ]; then a=""; else a=${a#*.}; fi
        if [ "$y" = "$b" ]; then b=""; else b=${b#*.}; fi
        [ "$x" = "$y" ] && continue
        case "$x" in *[!0-9]*) x_num=false ;; *) x_num=true ;; esac
        case "$y" in *[!0-9]*) y_num=false ;; *) y_num=true ;; esac
        if $x_num && $y_num; then
            if [ "$x" -lt "$y" ]; then echo -1; else echo 1; fi
            return
        fi
        if $x_num; then echo -1; return; fi
        if $y_num; then echo 1; return; fi
        if [ "$(printf '%s\n%s\n' "$x" "$y" | LC_ALL=C sort | head -n 1)" = "$x" ]; then
            echo -1
        else
            echo 1
        fi
        return
    done
}

# Prints -1, 0 or 1 for two valid `vX.Y.Z[-pre]` versions.
compare() {
    local a=${1#v} b=${2#v} a_core b_core a_pre b_pre i a_part b_part
    a_core=${a%%-*}
    b_core=${b%%-*}
    a_pre=${a#"$a_core"}
    a_pre=${a_pre#-}
    b_pre=${b#"$b_core"}
    b_pre=${b_pre#-}
    for i in 1 2 3; do
        a_part=$(printf '%s' "$a_core" | cut -d. -f"$i")
        b_part=$(printf '%s' "$b_core" | cut -d. -f"$i")
        if [ "$a_part" -lt "$b_part" ]; then echo -1; return; fi
        if [ "$a_part" -gt "$b_part" ]; then echo 1; return; fi
    done
    # A version without a pre-release ranks above any pre-release of it.
    if [ -z "$a_pre" ] && [ -z "$b_pre" ]; then echo 0; return; fi
    if [ -z "$a_pre" ]; then echo 1; return; fi
    if [ -z "$b_pre" ]; then echo -1; return; fi
    compare_prerelease "$a_pre" "$b_pre"
}

# Refuses a version that is malformed, already tagged, not above every valid `v*` tag,
# or not the next patch, minor or major of the latest final release (a pre-release of
# one is fine): a typo such as v0.250.0 would otherwise be tagged for good.
check() {
    local version=$1 tags_file=$2 tag latest="" stable="" core major minor patch
    is_semver "$version" || refuse "'$version' is not a version of the form vMAJOR.MINOR.PATCH[-pre-release]"
    while IFS= read -r tag || [ -n "$tag" ]; do
        [ -n "$tag" ] || continue
        [ "$tag" = "$version" ] && refuse "the tag $version already exists"
        # A tag that is not a version (v1, vtest) takes no part in the order.
        is_semver "$tag" || continue
        if [ -z "$latest" ] || [ "$(compare "$tag" "$latest")" = 1 ]; then
            latest=$tag
        fi
        case "$tag" in *-*) continue ;; esac
        if [ -z "$stable" ] || [ "$(compare "$tag" "$stable")" = 1 ]; then
            stable=$tag
        fi
    done < "$tags_file"
    if [ -n "$latest" ] && [ "$(compare "$version" "$latest")" != 1 ]; then
        refuse "$version is not above the latest tag $latest"
    fi
    [ -n "$stable" ] || return 0
    core=${version#v}
    core=v${core%%-*}
    major=$(printf '%s' "${stable#v}" | cut -d. -f1)
    minor=$(printf '%s' "${stable#v}" | cut -d. -f2)
    patch=$(printf '%s' "${stable#v}" | cut -d. -f3)
    case "$core" in
        "v$major.$minor.$((patch + 1))" | "v$major.$((minor + 1)).0" | "v$((major + 1)).0.0") ;;
        *) refuse "$version skips a version: after $stable comes v$major.$minor.$((patch + 1)), v$major.$((minor + 1)).0 or v$((major + 1)).0.0 (or a pre-release of one)" ;;
    esac
}

# Prints the tag names of `git ls-remote --tags` output, peeled duplicates merged.
tags() {
    sed -E 's#^[0-9a-fA-F]+[[:space:]]+refs/tags/##; s#\^\{\}$##' | LC_ALL=C sort -u
}

# Drops what GitHub does not render: every `<!-- ... -->` comment, on one line or
# across several, except the two marker lines themselves. A hidden comment could
# otherwise carry a version, a trailer or release text nobody reading the pull
# request sees. A fenced code block (``` or ~~~) is shown as written, so it is kept
# whole; a comment inside inline code is still dropped.
strip_comments() {
    awk -v start="$NOTES_START" -v end="$NOTES_END" '
        {
            sub(/\r$/, "")
            line = $0
            if (!inside && (line == start || line == end)) { print line; next }
            # A fence opens with three or more ` or ~ and closes on a line of the same
            # character, at least as long, with nothing after it; a backtick opener has
            # no backtick in the rest of its line (CommonMark).
            if (!inside && match(line, /^ ? ? ?(```+|~~~+)/)) {
                mark = substr(line, RSTART, RLENGTH)
                sub(/^ +/, "", mark)
                rest = substr(line, RSTART + RLENGTH)
                # A backtick run with another backtick after it is inline code.
                if (fence == "" && !(substr(mark, 1, 1) == "`" && index(rest, "`") > 0)) {
                    fence = mark; print line; next
                }
                if (substr(mark, 1, 1) == substr(fence, 1, 1) && length(mark) >= length(fence) \
                    && rest ~ /^[ \t]*$/) { fence = ""; print line; next }
            }
            if (fence != "") { print line; next }
            out = ""
            touched = inside
            while (line != "") {
                if (inside) {
                    i = index(line, "-->")
                    if (i == 0) { line = ""; break }
                    line = substr(line, i + 3)
                    inside = 0
                } else {
                    i = index(line, "<!--")
                    if (i == 0) { out = out line; line = ""; break }
                    out = out substr(line, 1, i - 1)
                    line = substr(line, i + 4)
                    inside = 1
                    touched = 1
                }
            }
            # A line that held only a comment goes; a line that was empty stays.
            if (touched && out ~ /^[[:space:]]*$/) next
            print out
        }
    '
}

# Prints the text between the markers, comments dropped; exit 3 when the body has
# neither marker.
notes() {
    local body=$1 clean starts ends text
    clean=$(strip_comments < "$body")
    starts=$(printf '%s\n' "$clean" | awk -v m="$NOTES_START" '$0 == m { n++ } END { print n + 0 }')
    ends=$(printf '%s\n' "$clean" | awk -v m="$NOTES_END" '$0 == m { n++ } END { print n + 0 }')
    if [ "$starts" = 0 ] && [ "$ends" = 0 ]; then
        return "$NOT_A_RELEASE"
    fi
    if [ "$starts" != 1 ] || [ "$ends" != 1 ]; then
        refuse "the body needs exactly one '$NOTES_START' line and one '$NOTES_END' line (found $starts and $ends)"
    fi
    text=$(printf '%s\n' "$clean" | awk -v start="$NOTES_START" -v end="$NOTES_END" '
        $0 == end { inside = 0; done = 1 }
        inside { print }
        $0 == start && !done { inside = 1 }
    ')
    if [ -z "$(printf '%s' "$text" | tr -d '[:space:]')" ]; then
        refuse "the release text between '$NOTES_START' and '$NOTES_END' is empty, or the end marker comes first"
    fi
    printf '%s\n' "$text"
}

detect() {
    local dir=$1 message="$1/message" parent title clean trailers through association
    local versions="" stated title_versions count version looks=false
    [ -f "$message" ] || refuse "$message is missing"
    [ -f "$dir/parent" ] || refuse "$dir/parent is missing"
    [ -f "$dir/tags" ] || refuse "$dir/tags is missing"
    parent=$(tr -d '[:space:]' < "$dir/parent" | tr 'A-F' 'a-f')

    # What a reader of the pull request sees: its body (or, with no pull request, the
    # commit's message) without the comments GitHub does not render.
    clean=$(mktemp -d)
    # shellcheck disable=SC2064 # the directory is fixed now, on purpose
    trap "rm -rf '$clean'" EXIT
    if [ -f "$dir/pr-body" ]; then
        strip_comments < "$dir/pr-body" > "$clean/body"
    else
        strip_comments < "$message" > "$clean/body"
    fi
    strip_comments < "$message" > "$clean/message"
    : > "$clean/commits"
    [ -f "$dir/pr-commits" ] && sed 's/\r$//' "$dir/pr-commits" > "$clean/commits"

    if [ -f "$dir/pr-title" ]; then
        title=$(head -n 1 "$dir/pr-title" | sed 's/\r$//')
    else
        title=$(head -n 1 "$clean/message")
    fi

    # The newest trailer wins, as in Jig: the pull request's commits oldest first, then
    # its body (the squash of a notes pull request into a release epic keeps the body,
    # not the branch commit, so the epic's pull request states the trailer there), then
    # the commit itself.
    trailers=$(cat "$clean/commits" "$clean/body" "$clean/message" \
        | grep -E '^Release-Notes-Through:[[:space:]]*[0-9a-fA-F]{7,40}[[:space:]]*$' || true)
    stated=$(grep -E '^Release-Version:' "$clean/body" \
        | sed -E 's/^Release-Version:[[:space:]]*//; s/[[:space:]]*$//' || true)

    case "$title" in "docs(release)"*) looks=true ;; esac
    [ -n "$trailers" ] && looks=true
    [ -n "$stated" ] && looks=true
    grep -qxF -- "$NOTES_START" "$clean/body" && looks=true
    grep -qxF -- "$NOTES_END" "$clean/body" && looks=true
    # Nothing points to a release: an ordinary commit, let through quietly.
    $looks || return "$NOT_A_RELEASE"

    # Something reads like a release: from here on every doubt refuses.
    [ -f "$dir/pr-title" ] || refuse "the commit reads like a release, but no pull request was found for it: a release is only the merge of a release notes pull request"
    printf '%s\n' "$title" | grep -Eq '^docs\(release\): .*v[0-9]+\.[0-9]+\.[0-9]+' \
        || refuse "the pull request reads like a release (a trailer, a Release-Version line or release text markers), but its title '$title' is not 'docs(release): ... vX.Y.Z'"
    association=""
    [ -f "$dir/pr-author-association" ] && association=$(tr -d '[:space:]' < "$dir/pr-author-association")
    case "$association" in
        OWNER | MEMBER | COLLABORATOR) ;;
        *) refuse "the release pull request was opened by an author with association '${association:-unknown}'; only an OWNER, MEMBER or COLLABORATOR releases" ;;
    esac
    [ -n "$trailers" ] || refuse "the pull request reads like a release, but neither its commits nor its body carry a Release-Notes-Through line"

    through=$(printf '%s\n' "$trailers" | tail -n 1 \
        | sed -E 's/^Release-Notes-Through:[[:space:]]*//; s/[[:space:]]*$//' | tr 'A-F' 'a-f')
    case "$parent" in
        "$through"*) ;;
        *) refuse "the release notes describe main through $through, but the commit's parent is $parent: something reached main after the notes were written" ;;
    esac

    # Every stated version must agree: the Release-Version lines and the title's.
    if [ -n "$stated" ]; then
        while IFS= read -r version; do
            is_semver "$version" || refuse "Release-Version '$version' is not a version of the form vMAJOR.MINOR.PATCH[-pre-release]"
        done <<< "$stated"
        versions=$stated
    fi
    title_versions=$(printf '%s\n' "$title" \
        | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?' || true)
    if [ -n "$title_versions" ]; then
        versions=$(printf '%s\n%s\n' "$versions" "$title_versions")
    fi
    versions=$(printf '%s\n' "$versions" | sed '/^$/d' | LC_ALL=C sort -u)
    count=$(printf '%s\n' "$versions" | sed '/^$/d' | wc -l | tr -d ' ')
    [ "$count" = 1 ] || refuse "the versions disagree: $(printf '%s' "$versions" | tr '\n' ' ')"

    check "$versions" "$dir/tags"

    # The release text is required: without it the release would quietly get generated
    # notes instead of the ones written for it, and broken markers would leave it
    # without its text after the tag is out. Refuse before tagging instead.
    notes "$clean/body" > /dev/null || refuse "no release text: the pull request body needs the release text between a line '$NOTES_START' and a line '$NOTES_END'"

    printf '%s\n' "$versions"
}

usage() {
    echo "usage: release-tag.sh detect <dir> | check <version> <tags-file> | tags | notes <pr-body-file> | compare <a> <b>" >&2
    exit 2
}

[ "$#" -ge 1 ] || usage
command=$1
shift
case "$command" in
    detect) [ "$#" -eq 1 ] || usage; detect "$1" ;;
    check) [ "$#" -eq 2 ] || usage; check "$1" "$2" ;;
    tags) [ "$#" -eq 0 ] || usage; tags ;;
    notes) [ "$#" -eq 1 ] || usage; notes "$1" ;;
    compare)
        [ "$#" -eq 2 ] || usage
        is_semver "$1" || refuse "'$1' is not a version"
        is_semver "$2" || refuse "'$2' is not a version"
        compare "$1" "$2"
        ;;
    *) usage ;;
esac
