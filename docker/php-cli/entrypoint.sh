#!/bin/sh
set -eu

# One image runs three containers: the forum parsers that fill the database, the
# queue that moves publications through the channel, and the queue that sends
# text to the proxy. CRON_ROLE says which third of the periodic work this
# container is started for.
role="${CRON_ROLE:?CRON_ROLE is not set: parser, telegram or llm}"
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
        # The daily sweep of the pictures this queue downloaded for its albums:
        # only this role writes them, and /tmp is separate per container.
        printf '%s php /var/www/html/yii telegram/clean-temp\n' "${TELEGRAM_TEMP_CLEAN_CRON_SCHEDULE:-0 4 * * *}" >> "$crontab_file"
        ;;
    llm)
        printf '%s php /var/www/html/yii llm/process-jobs\n' "${LLM_JOB_CRON_SCHEDULE:-* * * * *}" > "$crontab_file"
        ;;
    *)
        printf 'CRON_ROLE must be parser, telegram or llm, got: %s\n' "$role" >&2
        exit 1
        ;;
esac

exec supercronic -passthrough-logs "$crontab_file"
