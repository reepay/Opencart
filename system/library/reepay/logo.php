<?php

namespace Opencart\System\Library\Extension\Frisbii\Reepay;

/**
 * Location of the payment logos (image/reepay/*.png).
 *
 * OpenCart's extension installer copies the "image/" folder of the extension zip into the shop's
 * own image folder (DIR_IMAGE), not into extension/frisbii/. Only a development checkout of the
 * extension has the logos under extension/frisbii/image/, so that location is the fallback.
 */
class Logo {
    /**
     * Returns the logo path relative to the shop root, e.g. "image/reepay/reepay_visa.png".
     * Prefix it with the shop URL where the page is not served from the shop root (admin).
     */
    public static function path(string $file): string {
        $file = basename($file);

        if (is_file(DIR_IMAGE . 'reepay/' . $file)) {
            return 'image/reepay/' . $file;
        }

        return 'extension/frisbii/image/reepay/' . $file;
    }
}
