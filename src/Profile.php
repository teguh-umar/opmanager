<?php

namespace GlpiPlugin\Opmanager;

use CommonGLPI;
use Html;

/**
 * "OpManager" rights tab on Administration > Profiles. Posts to core
 * front/profile.form.php; \Profile::prepareInputForUpdate() already handles every
 * right in ProfileRight::getAllPossibleRights() - no controller of our own (hub §4).
 */
class Profile extends \Profile
{
    public static function getTypeName($nb = 0)
    {
        return 'OpManager';
    }

    // Non-static: core \Profile::getTabNameForItem() is an instance method (hub §4).
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0 && $withtemplate === 0) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-activity');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getID() > 0) {
            (new self())->showRightsForm((int) $item->getID());
        }
        return true;
    }

    public function showRightsForm(int $profiles_id): void
    {
        $profile = new \Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }
        $canedit = self::canUpdate();

        $rights = [];
        foreach (RightDefinition::names() as $name) {
            $rights[] = [
                'itemtype' => '',
                'rights'   => RightDefinition::rightsMatrix($name),
                'label'    => $name === RightDefinition::CONFIG ? __('Configuration') : __('Monitoring', 'opmanager'),
                'field'    => $name,
            ];
        }

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "' data-track-changes='true'>";
        }
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);
        if ($canedit) {
            echo "<div class='center mt-2'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }
}
