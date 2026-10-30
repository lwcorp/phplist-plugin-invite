<?php

/**
 * v0.5 - 2026-09-22 - modified to optionally support a custom monitoring column instead of blacklisting, and added Remind format for unconfirmed subscribers.
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
            $req = @Sql_Query("SELECT id, name FROM {$GLOBALS['tables']['attribute']} WHERE type IN ('textline', 'select', 'radio') ORDER BY name ASC");
            if ($req) {
                while ($row = Sql_Fetch_Assoc($req)) {
                    $attributeOptions[$row['id']] = $row['name'] . ' (ID: ' . $row['id'] . ')';
                }
            }
        }

        $this->settings['inviteplugin_monitoring_column'] = array(
            'value' => '',
            'description' => 'Select the "re-asked to confirm" attribute (use a textline attribute). If left on Default, the original unsubscribe behavior is used.',
            'type' => 'select', 
            'values' => $attributeOptions,
            'allowempty' => 1,
            'category' => 'Invite plugin',
        );

        $this->settings['inviteplugin_remind_min_time'] = array(
            'value' => '1 week',
            'description' => 'Minimum unconfirmed time before allowing to remind',
            'type' => 'select',
            'values' => array(
                '1 day' => '1 day',
                '1 week' => '1 week',
                '1 month' => '1 month',
                '1 year' => '1 year',
            ),
            'allowempty' => 0,
            'category' => 'Invite plugin',
        );
    }

    public function adminmenu()
    {
        return array();
    }

    public function sendFormats()
    {
        return array(
            'invite' => s('Invite'),
            'remind' => s('Remind'),
        );
    }

    // Gray out the "Remind" radio button on the Format tab if no monitoring attribute is selected
    public function displayEditpage($messagedata)
    {
        $monitorAttrId = getConfig('inviteplugin_monitoring_column');
        if (empty($monitorAttrId) || !is_numeric($monitorAttrId)) {
            return '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    var remindInput = document.querySelector("input[name=\'sendformat\'][value=\'remind\']");
                    if (remindInput) {
                        remindInput.disabled = true;
                        if (remindInput.parentElement) {
                            remindInput.parentElement.style.color = "#999";
                            remindInput.parentElement.style.cursor = "not-allowed";
                            remindInput.parentElement.title = "Enable the monitoring attribute in settings to use Remind";
                        }
                    }
                });
            </script>';
        }
        return '';
    }

    public function allowMessageToBeQueued($messagedata = array())
    {
        // we only need to check if this is sent as an invite or remind
        if ($messagedata['sendformat'] == 'invite' || $messagedata['sendformat'] == 'remind') {
            if ($messagedata['sendformat'] == 'remind') {
                $monitorAttrId = getConfig('inviteplugin_monitoring_column');
                if (empty($monitorAttrId) || !is_numeric($monitorAttrId)) {
                    return 'The Remind format requires a monitoring attribute to be configured in Settings.';
                }
            }

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

    // Momentarily confirm eligible unconfirmed users and mark them as 'pending' when campaign starts
    public function campaignStarted($messagedata = array())
    {
        if (isset($messagedata['sendformat']) && $messagedata['sendformat'] == 'remind') {
            $monitorAttrId = getConfig('inviteplugin_monitoring_column');
            if (!empty($monitorAttrId) && is_numeric($monitorAttrId)) {
                $minTime = getConfig('inviteplugin_remind_min_time');
                $intervals = array(
                    '1 day' => '1 DAY',
                    '1 week' => '1 WEEK',
                    '1 month' => '1 MONTH',
                    '1 year' => '1 YEAR',
                );
                $sqlInterval = isset($intervals[$minTime]) ? $intervals[$minTime] : '1 WEEK';

                // Find unconfirmed, non-blacklisted users on the target lists older than the minimum time
                // whose attribute is either empty or already 'pending' (not 'manually asked')
                $query = sprintf(
                    "SELECT DISTINCT u.id
                     FROM %s u
                     INNER JOIN %s lu ON u.id = lu.userid
                     INNER JOIN %s lm ON lu.listid = lm.listid
                     LEFT JOIN %s ua ON u.id = ua.userid AND ua.attributeid = %d
                     WHERE lm.messageid = %d
                       AND u.confirmed = 0
                       AND u.blacklisted = 0
                       AND u.entered <= DATE_SUB(NOW(), INTERVAL %s)
                       AND (ua.value IS NULL OR ua.value = '' OR ua.value = 'pending')",
                    $GLOBALS['tables']['user'],
                    $GLOBALS['tables']['listuser'],
                    $GLOBALS['tables']['listmessage'],
                    $GLOBALS['tables']['user_attribute'],
                    $monitorAttrId,
                    $messagedata['id'],
                    $sqlInterval
                );

                $req = Sql_Query($query);
                while ($row = Sql_Fetch_Row($req)) {
                    $userid = (int)$row[0];
                    // Momentarily set confirmed = 1 so phpList queues them
                    Sql_Query(sprintf(
                        "UPDATE %s SET confirmed = 1 WHERE id = %d",
                        $GLOBALS['tables']['user'],
                        $userid
                    ));
                    // Set attribute to 'pending'
                    Sql_Query(sprintf(
                        "INSERT INTO %s (userid, attributeid, value) VALUES (%d, %d, 'pending') ON DUPLICATE KEY UPDATE value = 'pending'",
                        $GLOBALS['tables']['user_attribute'],
                        $userid,
                        $monitorAttrId
                    ));
                }
            }
        }
    }

    public function canSend($messagedata, $userdata)
    {
        $monitorAttrId = getConfig('inviteplugin_monitoring_column');
        
        if (!empty($monitorAttrId) && is_numeric($monitorAttrId)) {
            if ($messagedata['sendformat'] == 'invite' || $messagedata['sendformat'] == 'remind') {
                $req = Sql_Fetch_Row_Query(sprintf(
                    "SELECT value FROM %s WHERE userid = %d AND attributeid = %d",
                    $GLOBALS['tables']['user_attribute'],
                    $userdata['id'],
                    $monitorAttrId
                ));
                $val = $req ? $req[0] : '';

                if ($messagedata['sendformat'] == 'invite') {
                    // For Invite: skip anyone already marked as 'manually asked' or 'pending'
                    if ($val === 'manually asked' || $val === 'pending') {
                        return false;
                    }
                } elseif ($messagedata['sendformat'] == 'remind') {
                    // For Remind: ONLY send to those marked as 'pending' (strictly excludes normal confirmed users and 'manually asked')
                    if ($val !== 'pending') {
                        return false;
                    }
                }
            }
        }
        return true; 
    }

    public function processSendSuccess($messageid, $userdata, $isTestMail = false)
    {
        $messagedata = loadMessageData($messageid);
        if (!$isTestMail && ($messagedata['sendformat'] == 'invite' || $messagedata['sendformat'] == 'remind')) {
            
            $monitorAttrId = getConfig('inviteplugin_monitoring_column');
            
            if ($messagedata['sendformat'] == 'invite' && (empty($monitorAttrId) || !is_numeric($monitorAttrId))) {
                if (!isBlackListed($userdata['email'])) {
                    addUserToBlackList($userdata['email'], s('Blacklisted by the invitation plugin'));
                }
            } else {
                Sql_Query(sprintf(
                    "INSERT INTO %s (userid, attributeid, value) VALUES (%d, %d, 'manually asked') ON DUPLICATE KEY UPDATE value = 'manually asked'",
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
                    if ($req && ($req[0] === 'manually asked' || $req[0] === 'pending')) {
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
