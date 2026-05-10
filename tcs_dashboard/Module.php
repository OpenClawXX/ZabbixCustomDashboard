<?php declare(strict_types=1);

namespace Modules\TcsDashboard;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

/**
 * Adds a "TCS Dashboard" entry under the Monitoring menu and exposes the
 * tcs.dashboard.view + tcs.dashboard.data actions.
 *
 * Tested against Zabbix 6.0 LTS / 6.4 / 7.0. The menu API has shifted across
 * majors; if you target an older version, see README "Version notes".
 */
class Module extends CModule {

    public function init(): void {
        $main_menu = APP::Component()->get('menu.main');

        if ($main_menu === null) {
            return;
        }

        $monitoring = $main_menu->find(_('Monitoring'));

        if ($monitoring === null) {
            return;
        }

        $monitoring
            ->getSubmenu()
            ->add(
                (new CMenuItem(_('TCS Dashboard')))
                    ->setAction('tcs.dashboard.view')
            );
    }
}
