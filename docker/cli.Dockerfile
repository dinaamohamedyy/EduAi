# wp-cli, with the same two binaries as the web container.
#
# The web container is where the feature runs, so it is the one that must have
# these. This one matters for a different reason: every harness in scripts/ and
# every capability probe this team writes runs through `wp eval` HERE. If the
# binaries exist in one container and not the other, a probe answers correctly
# about a process the feature never uses — which is the measuring-the-wrong-
# object failure this project has produced repeatedly. Same tools, both places,
# so a probe means the same thing wherever it is run.
#
# Alpine base, so apk rather than apt. yt-dlp is in community and is kept
# current there, unlike Debian's.
FROM wordpress:cli-php8.3

USER root
RUN set -eux; \
	apk add --no-cache ffmpeg yt-dlp; \
	yt-dlp --version; \
	ffmpeg -version | head -1
USER www-data
