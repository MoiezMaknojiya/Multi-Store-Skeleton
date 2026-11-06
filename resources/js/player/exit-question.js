/**
 * The Samsung TV app's exit question (owner, 2026-11-06; the Seller Office's Key Policy, docs/TIZEN-APP-PLAN.md §8).
 *
 * Samsung requires RETURN to do something on every screen, and a shop's TV must not close at a stray press. So on the
 * Samsung app RETURN asks "Exit …?" over whatever plays, with Stay chosen: LEFT and RIGHT move between Stay and Exit,
 * ENTER presses the chosen one, and RETURN again or 10 seconds with no key close the question, the TV going on where it
 * was. EXIT the TV handles itself: it closes the app.
 *
 * Exit goes BACK to the app's own page, which closes the app: this page comes from the server, and the Tizen runtime
 * gives such a page no tizen API (seen on the TV 10.0 emulator, 2026-11-06), while the app's page has it.
 *
 * Only on Samsung (Tizen in the user agent), or where this set says otherwise (EXIT_QUESTION_KEY '1' or '0'), which is
 * how a browser test tries it. Other televisions keep their own ways: the Android app's menu holds Back.
 */
const EXIT_QUESTION_KEY = 'signage.exit_question';
const RETURN_KEY = 10009;
const LEFT_KEY = 37;
const RIGHT_KEY = 39;
const ENTER_KEY = 13;
const STAYS_OPEN_MS = 10000;

/** @param {{get: function(string): (string|null)}} store */
export function registerExitQuestion(store) {
    const own = store.get(EXIT_QUESTION_KEY);
    const wanted = own === '1' || (own !== '0' && /\bTizen\b/i.test(navigator.userAgent));
    const box = document.getElementById('exit-question');

    if (! wanted || ! box) return;

    const buttons = [document.getElementById('exit-question-stay'), document.getElementById('exit-question-exit')];
    // The page the player was opened from is this many entries back once anything is written after it.
    const lengthAtStart = window.history.length;
    let chosen = 0;
    let timer = null;

    function choose(index) {
        chosen = index;
        buttons.forEach((button, at) => {
            button.classList.toggle('chosen', at === index);
            button.setAttribute('aria-pressed', at === index ? 'true' : 'false');
        });
        buttons[index].focus();
    }

    function wait() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(close, STAYS_OPEN_MS);
    }

    function open() {
        box.hidden = false;
        choose(0);
        wait();
    }

    function close() {
        if (timer) clearTimeout(timer);
        timer = null;
        box.hidden = true;
        buttons.forEach((button) => button.blur());
    }

    function leave() {
        close();
        try {
            // The app's own page, should a runtime ever give this one the API.
            if (window.tizen && window.tizen.application) {
                window.tizen.application.getCurrentApplication().exit();

                return;
            }
        } catch (e) {
            // Not there after all: go back instead.
        }
        if (lengthAtStart > 1) {
            // Back past whatever was written since the player started (a frame's own page, should one ever write one),
            // to the page it was opened from: one step when nothing was.
            window.history.go(-(window.history.length - lengthAtStart + 1));

            return;
        }
        window.close();
    }

    buttons[0].addEventListener('click', close);
    buttons[1].addEventListener('click', leave);
    // A pointer remote's press beside the question keeps the TV playing, as Stay does.
    box.addEventListener('click', (event) => {
        if (event.target === box) close();
    });

    // The keyboard taken into an Ad Builder page's frame is taken back: RETURN pressed there never reaches this page.
    window.addEventListener('blur', () => {
        setTimeout(() => {
            const focused = document.activeElement;

            if (focused && focused.tagName === 'IFRAME') focused.blur();
        }, 0);
    });

    document.addEventListener('keydown', (event) => {
        const isReturn = event.keyCode === RETURN_KEY || event.key === 'XF86Back';

        if (box.hidden) {
            if (! isReturn) return;
            event.preventDefault();
            event.stopPropagation();
            // A held RETURN repeats: the question opens once.
            if (! event.repeat) open();

            return;
        }

        event.preventDefault();
        event.stopPropagation();
        if (event.repeat && (isReturn || event.keyCode === ENTER_KEY)) return;
        if (isReturn) {
            close();
        } else if (event.keyCode === LEFT_KEY || event.keyCode === RIGHT_KEY) {
            choose(event.keyCode === LEFT_KEY ? 0 : 1);
            wait();
        } else if (event.keyCode === ENTER_KEY) {
            if (chosen === 1) leave();
            else close();
        } else {
            wait();
        }
    }, true);
}
