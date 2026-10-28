// WebCodecs prototype (2026-10-28): the stage's video decoded by the page itself and drawn on a canvas that turns with
// the stage. The player's own <video> elements lose their source and are hidden, so nothing can go to the Fire TV's
// plane. Needs MP4Box and DataStream (mp4box.js) loaded first. window.__wc holds the numbers; __wc.stop() takes it off.
(async () => {
    if (window.__wc) return 'already installed';
    if (typeof MP4Box === 'undefined' || typeof DataStream === 'undefined') return 'mp4box.js is not loaded';

    const wc = window.__wc = { drawn: 0, late: 0, decoded: 0, loops: 0, silenced: 0, errors: [], state: 'starting' };
    const stage = document.getElementById('stage');

    // The player's own videos: no source, never shown.
    const silence = (video) => {
        if (video.dataset.wc) return;
        video.dataset.wc = '1';
        const src = video.currentSrc || video.src || video.getAttribute('src');
        if (src) wc.lastUrl = src;
        try { video.pause(); } catch (error) { /* nothing */ }
        video.removeAttribute('src');
        try { video.load(); } catch (error) { /* nothing */ }
        video.style.display = 'none';
        wc.silenced++;
    };
    const pickUp = () => stage.querySelectorAll('video:not([data-wc])').forEach(silence);
    pickUp();
    wc.observer = new MutationObserver(pickUp);
    wc.observer.observe(stage, { childList: true, subtree: true });

    // The canvas: above every layer, inside the stage, so the stage's CSS turn turns it.
    if (getComputedStyle(stage).position === 'static') stage.style.position = 'relative';
    const canvas = document.createElement('canvas');
    canvas.id = 'wc-canvas';
    canvas.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;z-index:50;background:#000;display:block;';
    stage.appendChild(canvas);
    const context = canvas.getContext('2d');

    // The file, opened with mp4box.js: its decoder configuration and every frame as an EncodedVideoChunk.
    const load = async (url) => {
        const buffer = await (await fetch(url)).arrayBuffer();
        return new Promise((resolve, reject) => {
            const file = MP4Box.createFile();
            const chunks = [];
            let config = null;
            let total = 0;
            file.onError = (error) => reject(new Error('mp4box: ' + error));
            file.onReady = (info) => {
                const track = info.videoTracks[0];
                if (!track) return reject(new Error('no video track'));
                const entry = file.getTrackById(track.id).mdia.minf.stbl.stsd.entries[0];
                const box = entry.avcC || entry.hvcC || entry.vpcC || entry.av1C;
                const stream = new DataStream(undefined, 0, DataStream.BIG_ENDIAN);
                box.write(stream);
                config = {
                    codec: track.codec,
                    codedWidth: track.video.width,
                    codedHeight: track.video.height,
                    description: new Uint8Array(stream.buffer, 8),
                    hardwareAcceleration: 'prefer-hardware',
                };
                total = track.nb_samples;
                file.setExtractionOptions(track.id, null, { nbSamples: Infinity });
                file.start();
            };
            file.onSamples = (id, user, samples) => {
                for (const sample of samples) {
                    chunks.push(new EncodedVideoChunk({
                        type: sample.is_sync ? 'key' : 'delta',
                        timestamp: (1e6 * sample.cts) / sample.timescale,
                        duration: (1e6 * sample.duration) / sample.timescale,
                        data: sample.data,
                    }));
                }
                if (chunks.length >= total) resolve({ config, chunks });
            };
            buffer.fileStart = 0;
            file.appendBuffer(buffer);
            file.flush();
        });
    };

    // Decode a few frames ahead, draw each when its time comes, loop at the end.
    const play = async (url) => {
        const { config, chunks } = await load(url);
        wc.config = { codec: config.codec, width: config.codedWidth, height: config.codedHeight, frames: chunks.length };
        canvas.width = config.codedWidth;
        canvas.height = config.codedHeight;

        const queue = [];
        let next = 0;
        let start = 0;
        let flushed = false;
        const decoder = new VideoDecoder({
            output: (frame) => { queue.push(frame); wc.decoded++; },
            error: (error) => { wc.errors.push(String(error)); },
        });
        wc.decoder = decoder;
        decoder.configure(config);
        start = performance.now();
        const last = chunks[chunks.length - 1];
        const end = last.timestamp + (last.duration || 33333);

        const restart = () => {
            queue.splice(0).forEach((frame) => frame.close());
            decoder.reset();
            decoder.configure(config);
            next = 0;
            flushed = false;
            start = performance.now();
            wc.loops++;
        };

        const tick = () => {
            if (wc.stopped || decoder.state === 'closed') return;
            while (next < chunks.length && decoder.decodeQueueSize < 4 && queue.length < 8) decoder.decode(chunks[next++]);
            if (next >= chunks.length && !flushed) {
                flushed = true;
                decoder.flush().catch(() => { /* reset by a restart */ });
            }

            const elapsed = (performance.now() - start) * 1000;
            queue.sort((a, b) => a.timestamp - b.timestamp);
            let due = null;
            while (queue.length && queue[0].timestamp <= elapsed) {
                if (due) { due.close(); wc.late++; }
                due = queue.shift();
            }
            if (due) {
                context.drawImage(due, 0, 0, canvas.width, canvas.height);
                due.close();
                wc.drawn++;
            }

            if (flushed && queue.length === 0 && decoder.decodeQueueSize === 0 && elapsed >= end) restart();
            wc.raf = requestAnimationFrame(tick);
        };
        wc.raf = requestAnimationFrame(tick);
        wc.state = 'playing';
    };

    wc.stop = () => {
        wc.stopped = true;
        cancelAnimationFrame(wc.raf);
        try { if (wc.decoder && wc.decoder.state !== 'closed') wc.decoder.close(); } catch (error) { /* nothing */ }
        wc.observer.disconnect();
        canvas.width = canvas.height = 0;
        canvas.remove();
        delete window.__wc;
        return 'removed';
    };

    for (let i = 0; i < 80 && !wc.lastUrl; i++) await new Promise((resolve) => setTimeout(resolve, 250));
    if (!wc.lastUrl) { wc.state = 'no video seen in 20 s'; return wc.state; }
    play(wc.lastUrl).catch((error) => { wc.state = 'failed'; wc.errors.push(String(error)); });
    return 'installed for ' + wc.lastUrl.replace(/^.*\//, '');
})()
