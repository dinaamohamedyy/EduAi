# The WordPress container, plus the two binaries the video features need.
#
# WHY THIS FILE EXISTS AT ALL. Both tools were installed by hand once, and a
# dependency installed by hand vanishes on the next `docker compose down -v`
# and takes the feature with it — which returns as "it worked yesterday" and
# costs somebody an afternoon. This stack is rebuilt from scratch often enough
# for that to be a when rather than an if, so they live in the image.
#
#   ffmpeg  LOAD-BEARING. Groq's Whisper endpoint caps uploads at 25 MB, and a
#           47 MB lecture the owner uploaded is refused on size alone with
#           nothing wrong with it. Extracting 16 kHz mono audio puts a
#           45-minute recording well under the cap, so this is what makes long
#           uploads transcribable at all rather than an optimisation. It is in
#           the owner's working path, verified end to end.
#
#   yt-dlp  WORKS, EXCEPT FOR YOUTUBE — and the exception is the reason it was
#           installed, so read this before concluding either way.
#
#           YOUTUBE IS CLOSED TO A SERVER. Tested 17 Aug 2026 against the
#           owner's own lesson video: 403 by default and under
#           player_client=android_vr, "the page needs to be reloaded" under tv,
#           and no available format under web_safari, ios and mweb. Five
#           strategies, no audio. The caption route fails from the same cause —
#           /api/timedtext wants a proof-of-origin token, so a server-side
#           fetch returns HTTP 200 with zero bytes, a success carrying no
#           content. Do not build on YouTube ingestion without re-testing;
#           nothing in this image makes it work.
#
#           EVERY OTHER HTTP SOURCE IS FINE, which is why it stays: a direct
#           MP4 on a university media server, Vimeo, anything not fighting
#           bots. Verified rather than assumed — against a locally served MP4
#           it downloaded the file intact, and driving ffmpeg it produced
#           16 kHz mono audio, which is exactly the Whisper input the upload
#           path already uses. So the code path is real and exercised.
FROM wordpress:6.8-php8.3-apache

# ffmpeg from Debian: stable, and its version matters far less than yt-dlp's.
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends ffmpeg ca-certificates curl; \
	rm -rf /var/lib/apt/lists/*

# yt-dlp from its own releases rather than apt: a distribution-frozen build is
# broken on arrival against sites that change extraction, and it fails as an
# empty HTTP 200 rather than as anything naming a version. The _linux asset is
# self-contained, so no Python runtime is needed.
#
# PINNED, and the reasoning inverted on 17 Aug 2026. This was deliberately
# unpinned to trade reproducibility for the ability to fetch a video next
# month. Once YouTube proved closed, that half of the trade was worth nothing,
# so the reproducibility is free.
#
# THE CHECKSUM MATTERS MORE THAN THE PIN. This pulls a 40 MB executable over
# the network into an image that builds as root; unverified, a corrupted or
# substituted artifact installs silently and then runs with everything the web
# server can reach. Pinning a URL protects against a different VERSION, not a
# different FILE. Cross-checked against upstream's published SHA2-256SUMS
# rather than computed from the copy already installed, which would only have
# proved the file agrees with itself.
#
# WHAT THIS DOES NOT DO: it still fetches at build time. Pinning buys
# reproducibility and integrity, not independence from GitHub being reachable.
# That residual risk is accepted deliberately (TM, 17 Aug) rather than solved
# by vendoring 40 MB into a public repo. If the nightly starts going red on it
# often enough to be noise, the answer is a prebuilt image, not vendoring.
ARG YTDLP_VERSION=2026.07.04
ARG YTDLP_SHA256=6bbb3d314cde4febe36e5fa1d55462e29c974f63444e707871834f6d8cc210ae
RUN set -eux; \
	curl -fsSL -o /usr/local/bin/yt-dlp \
		"https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux"; \
	echo "${YTDLP_SHA256}  /usr/local/bin/yt-dlp" | sha256sum -c -; \
	chmod 0755 /usr/local/bin/yt-dlp; \
	test "$(yt-dlp --version)" = "${YTDLP_VERSION}"; \
	yt-dlp --version; \
	ffmpeg -version | head -1
