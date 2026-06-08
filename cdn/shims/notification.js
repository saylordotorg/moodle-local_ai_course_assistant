/**
 * Shim for Moodle's core/notification module (CDN bundle).
 *
 * SOLA's bundled modules use only Notification.exception() (in a couple of
 * rejected-Promise handlers, e.g. the learning-path panel fetch). Moodle's real
 * notification modal isn't available in CDN mode, so we surface the error to the
 * console instead. Keeping the same `exception` shape means the bundled modules
 * need no CDN-specific changes.
 */

function exception(error) {
    if (window.console && window.console.error) {
        window.console.error('SOLA:', error);
    }
}

export default {exception: exception};
export {exception};
