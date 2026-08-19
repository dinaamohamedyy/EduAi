# The WordPress container, plus the two binaries the video features need.
#
# WHY THIS FILE EXISTS AT ALL. Both tools were installed by hand once, and a
# dependency installed by hand vanishes on the next `docker compose down -v`
# and takes the feature with it — which returns as "it worked yesterday" and
# costs somebody an afternoon. This stack is rebuilt from setup.sh often
# enough for that to be a when rather than an if, so they live in the image.
#
#   ffmpeg  Groq's Whisper endpoint caps uploads at 25 MB. A 47 MB lecture the
#           owner uploaded is refused on size alone, with nothing wrong with
#           it. Extracting 16 kHz mono audio puts a 45-minute recording well
#           under the cap, so this is what makes long uploads transcribable at
#           all rather than an optimisation.
#
#   yt-dlp  Lessons embed YouTube URLs. YouTube requires a proof-of-origin
#           token on /api/timedtext, so a plain server-side fetch of a caption
#           track returns HTTP 200 with zero bytes — a success that carries no
#           content, which is the worst shape of failure to debug. yt-dlp
#           handles that, and can pull the audio for Whisper when a video has
#           no usable captions.
FROM wordpress:6.8-php8.3-apache

# ffmpeg from Debian: stable, and the version matters far less than yt-dlp's.
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends ffmpeg ca-certificates curl; \
	rm -rf /var/lib/apt/lists/*

# yt-dlp from its own releases rather than apt.
#
# Deliberately NOT `apt-get install yt-dlp`: YouTube changes its extraction
# often enough that a distribution-frozen build is broken more or less on
# arrival, and the failure it produces is the empty-200 above rather than
# anything that names a version. The _linux asset is a self-contained binary
# with no Python runtime needed.
#
# Unpinned, and that is a trade rather than an oversight: pinning buys
# reproducibility and costs the ability to fetch a video next month. Rebuild
# with --no-cache to pick up a newer one when extraction starts failing.
RUN set -eux; \
	curl -fsSL -o /usr/local/bin/yt-dlp \
		https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux; \
	chmod 0755 /usr/local/bin/yt-dlp; \
	yt-dlp --version; \
	ffmpeg -version | head -1
