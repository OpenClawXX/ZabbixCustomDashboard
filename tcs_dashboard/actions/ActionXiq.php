<?php declare(strict_types=1);

namespace Modules\TcsDashboard\Actions;

use CControllerResponseData;
use CControllerResponseFatal;

/**
 * GET zabbix.php?action=tcs.xiq.view
 *
 * Renders the XIQ Wireless Status fleet overview. The page is data-source-
 * truthy: ActionXiq returns a minimal boot envelope and xiq-bridge.jsx
 * fetches the real payload from tcs.xiq.data after first paint, mirroring
 * the pattern used by ActionSwitches + switches-bridge.jsx.
 */
class ActionXiq extends ActionBase {

    protected function checkInput(): bool {
        $ret = $this->validateInput([]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function doAction(): void {
        // SSR boot — first paint renders against the same synthetic payload
        // tcs.xiq.data serves, so the page is fully populated before the
        // bridge's async refresh fires. When ActionXiqData later swaps in
        // real XIQ calls the boot snapshot stays in lock-step automatically.
        $boot = ActionXiqData::syntheticPayload() + ['async' => true];

        $data = [
            'title' => _('TCS Wireless · XIQ Status'),
            'boot'  => $boot
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('TCS Wireless · XIQ Status'));
        $this->setResponse($response);
    }
}
