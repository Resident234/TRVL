#!/bin/sh
set -eu

# One image runs two containers: the forum parsers that fill the database, and
# the queue that moves publications through the channel. CRON_ROLE says which
# half of the periodic work this container is started for.
role="${CRON_ROLE:?CRON_ROLE is not set: parser or telegram}"
crontab_file="/tmp/cron-jobs"

case "$role" in
    parser)
        printf '%s php /var/www/html/yii forum-parser/scan\n' "${PARSER_CRON_SCHEDULE:-0 4 * * *}" > "$crontab_file"
        printf '%s php /var/www/html/yii forum-post-parser/scan\n' "${FORUM_POST_PARSER_CRON_SCHEDULE:-*/10 * * * *}" >> "$crontab_file"
        printf '%s php /var/www/html/yii gallery-parser/scan\n' "${GALLERY_PARSER_CRON_SCHEDULE:-*/10 * * * *}" >> "$crontab_file"
        printf '%s php /var/www/html/yii member-parser/scan\n' "${MEMBER_PARSER_CRON_SCHEDULE:-*/10 * * * *}" >> "$crontab_file"
        ;;
    telegram)
        printf '%s php /var/www/html/yii telegram/publish-due\n' "${TELEGRAM_PUBLISH_CRON_SCHEDULE:-*/5 * * * *}" > "$crontab_file"
        printf '%s php /var/www/html/yii telegram/delete-due\n' "${TELEGRAM_DELETE_CRON_SCHEDULE:-*/5 * * * *}" >> "$crontab_file"
        printf '%s php /var/www/html/yii telegram/edit-due\n' "${TELEGRAM_EDIT_CRON_SCHEDULE:-*/5 * * * *}" >> "$crontab_file"
        ;;
    *)
        printf 'CRON_ROLE must be parser or telegram, got: %s\n' "$role" >&2
        exit 1
        ;;
esac

exec supercronic -passthrough-logs "$crontab_file"
