#!/bin/sh
set -eu

awk '
    function paths(command, fields, count, i) {
        if (command !~ /docker build([[:space:]]|$)/) return
        count = split(command, fields, /[[:space:]]+/)
        for (i = 1; i <= count; i++) {
            if (fields[i] == "-f" || fields[i] == "--file") print fields[i + 1]
            else if (fields[i] ~ /^--file=/) { sub(/^--file=/, "", fields[i]); print fields[i] }
        }
    }
    {
        if (folded) {
            indent = match($0, /[^ ]/) - 1
            if (indent > depth || $0 ~ /^[[:space:]]*$/) {
                block = block " " $0
                next
            }
            paths(block)
            folded = 0
        }
        if ($0 ~ /-[[:space:]]*>[-+]?[[:space:]]*$/) {
            depth = match($0, /[^ ]/) - 1
            block = ""
            folded = 1
            next
        }
        if ($0 ~ /docker build([[:space:]]|$)/) {
            while ($0 ~ /\\[[:space:]]*$/ && getline next_line > 0) {
                sub(/\\[[:space:]]*$/, " ")
                $0 = $0 next_line
            }
            paths($0)
        }
    }
    END { if (folded) paths(block) }
' .gitlab-ci.yml | while IFS= read -r file; do
    if [ ! -f "$file" ]; then
        echo "Dockerfile not found: $file" >&2
        exit 1
    fi
done
