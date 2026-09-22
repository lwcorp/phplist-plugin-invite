<?php

/**
 * v0.5 - 2026-09-22 - modified to optionally support a custom monitoring column instead of blacklisting.
 * v0.4 - 2018-02-01 - bug fixes.
 * v0.3 - 2013-08-29 - add config for target list, where subscribers who confirm are added to.
 * v0.2 - 2013-08-28 - set invite via the "sendformat" instead of adding it's own tab.
 * v0.1 - initial.
 */
class inviteplugin extends phplistPlugin
{
    public $name = 'Invite plugin for phpList';
    public $coderoot = '';
    public $version = '0.5';
    public $authors = 'Lior Weissbrod, Michiel Dethmers';
    public $enabled = 1;
    public $description = 'Send an invite to subscribe to the phpList mailing system';
    public $documentationUrl = 'https://resources.phplist.com/plugin/invite';
    
    public $settings = array(); 

    public function __construct()
    {
        parent::__construct();

        $this->settings = array(
            'inviteplugin_subscribepage' => array(
                'value' => 0,
                'description' => 'Subscribe page for invitation responses',
                'type' => 'integer',
                'allowempty' => 0,
                'min' => 0,
                'max' => 999999,
                'category' => 'Invite plugin',
            ),
            'inviteplugin_targetlist' => array(
                'value' => 0,
                'description' => 'Add subscribers confirming an invitation to this list',
                'type' => 'integer',
                'allowempty' => 0,
                'min' => 0,
                'max' => 999999,
                'category' => 'Invite plugin',
            ),
        );

        $attributeOptions = array('' => '-- Default: Use Original Unsubscribe Behavior --');
        
        if (isset($GLOBALS['tables']['attribute'])) {
            $req = @Sql_Query("SELECT id, name FROM {$GLOBALS['tables']['attribute']} WHERE type = 'checkbox' ORDER BY name ASC");
            if ($req) {
                while ($row = Sql_Fetch_Assoc($req)) {
                    $attributeOptions[$row['id']] = $row['name'] . ' (ID: ' . $row['id'] . ')';
                }
            }
        }

        $this->settings['inviteplugin_monitoring_column'] = array(
            'value' => '',
            'description' => 'Select the "re-asked to confirm" checkbox attribute. If left on Default, the original unsubscribe behavior is used.',
            'type' => 'select', 
            'values' => $attributeOptions,
            'allowempty' => 1,
            'category' => 'Invite plugin',
        );
    }

    public function adminmenu()
    {
        return array();
    }

    public function sendFormats()
    {
        return array('invite' => s('Invite'));
    }

    public function allowMessageToBeQueued($messagedata = array())
    {
        // we only need to check if this is sent as an invite
        if ($messagedata['sendformat'] == 'invite') {
            $hasConfirmationLink = false;
            foreach ($messagedata as $key => $val) {
                if (is_string($val)) {
                    $hasConfirmationLink = $hasConfirmationLink || (strpos($val, '[CONFIRMATIONURL]') !== false);
                }
            }
            if (!$hasConfirmationLink) {
                return $GLOBALS['I18N']->get('Your campaign does not contain a the confirmation URL placeholder, which is necessary for an invite mailing. Please add [CONFIRMATIONURL] to the footer or content of the campaign.');
            }
        }

        return '';
    }

    public function canSend($messagedata, $userdata)
    {
        if ($messagedata['sendformat'] == 'invite') {
            $monitorAttrId = getConfig('inviteplugin_monitoring_column');
            
            if (!empty($monitorAttrId) && is_numeric($monitorAttrId)) {
                $req = Sql_Fetch_Row_Query(sprintf(
                    "SELECT value FROM %s WHERE userid = %d AND attributeid = %d",
                    $GLOBALS['tables']['user_attribute'],
                    $userdata['id'],
                    $monitorAttrId
                ));
                
                if ($req && !empty($req[0]) && $req[0] != '0') {
                    return false;
                }
            }
        }
        return true; 
    }

    public function processSendSuccess($messageid, $userdata, $isTestMail = false)
    {
        $messagedata = loadMessageData($messageid);
        if (!$isTestMail && $messagedata['sendformat'] == 'invite') {
            
            $monitorAttrId = getConfig('inviteplugin_monitoring_column');
            
            if (empty($monitorAttrId) || !is_numeric($monitorAttrId)) {
                if (!isBlackListed($userdata['email'])) {
                    addUserToBlackList($userdata['email'], s('Blacklisted by the invitation plugin'));
                }
            } else {
                Sql_Query(sprintf(
                    "INSERT INTO %s (userid, attributeid, value) VALUES (%d, %d, '1') ON DUPLICATE KEY UPDATE value = '1'",
                    $GLOBALS['tables']['user_attribute'],
                    $userdata['id'],
                    $monitorAttrId
                ));
            }

            Sql_Query(sprintf(
                'update %s
                set confirmed = 0
                where id = %d',
                $GLOBALS['tables']['user'],
                $userdata['id']
            ));
            
            // if subscribe page is set, mark this subscriber for that page
            $sPage = getConfig('inviteplugin_subscribepage');
            if (!empty($sPage)) {
                Sql_Query(sprintf(
                    'update %s set subscribepage = %d where id = %d',
                    $GLOBALS['tables']['user'],
                    $sPage,
                    $userdata['id']
                ));
            }
        }
    }

    public function subscriberConfirmation($subscribepageID, $userdata = array())
    {
        $sPage = getConfig('inviteplugin_subscribepage');
        $newList = getConfig('inviteplugin_targetlist');
        if (!empty($sPage) && !empty($newList) && $sPage == $subscribepageID) {
            
            $isInvited = false;

            if ($userdata['blacklisted']) {
                // the subscriber has not been unblacklisted yet at this stage
                $isInvited = true;
            } else {
                $monitorAttrId = getConfig('inviteplugin_monitoring_column');
                if (!empty($monitorAttrId) && is_numeric($monitorAttrId)) {
                    $req = Sql_Fetch_Row_Query(sprintf(
                        "SELECT value FROM %s WHERE userid = %d AND attributeid = %d",
                        $GLOBALS['tables']['user_attribute'],
                        $userdata['id'],
                        $monitorAttrId
                    ));
                    if ($req && !empty($req[0]) && $req[0] != '0') {
                        $isInvited = true;
                    }
                }
            }

            if ($isInvited) {
                Sql_Query(sprintf(
                    'insert ignore into %s (userid,listid) values(%d,%d)',
                    $GLOBALS['tables']['listuser'],
                    $userdata['id'],
                    $newList
                ));
            }
        }
    }
}
